<?php
// claim.php
header('Content-Type: application/json');

// Load local config
$configFile = __DIR__ . '/config.local.php';
if (!file_exists($configFile)) {
    echo json_encode(['status'=>'error','message'=>'Server config missing.']); exit;
}
require $configFile;

// --- helper: connect to DB ---
function get_pdo(): PDO {
    global $DB_HOST,$DB_NAME,$DB_USER,$DB_PASS;
    static $pdo = null;
    if ($pdo) return $pdo;
    $dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4";
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
}

// --- read POST data from JS ---
$invoice      = isset($_POST['address']) ? trim($_POST['address']) : '';  // BOLT11 invoice
$captchaToken = $_POST['g-recaptcha-response'] ?? '';
$userIp       = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$userAgent    = $_SERVER['HTTP_USER_AGENT'] ?? '';

if ($invoice === '') {
    echo json_encode(['status'=>'error','message'=>'Please paste a Lightning invoice (BOLT11).']); exit;
}

// quick sanity check (not strict)
if (stripos($invoice, 'lnbc') !== 0 && stripos($invoice, 'ln') !== 0) {
    echo json_encode([
        'status'  => 'error',
        'message' => 'For now the faucet only accepts Lightning invoices (starting with lnbc...).'
    ]);
    exit;
}

if ($captchaToken === '') {
    echo json_encode(['status'=>'error','message'=>'Please complete the reCAPTCHA.']); exit;
}

// --- verify reCAPTCHA (using test secret key for local) ---
$verifyUrl = 'https://www.google.com/recaptcha/api/siteverify';
$payload   = http_build_query([
    'secret'   => $RECAPTCHA_SECRET_KEY,
    'response' => $captchaToken,
    'remoteip' => $userIp,
]);

$context = stream_context_create([
    'http' => [
        'method'  => 'POST',
        'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
        'content' => $payload,
        'timeout' => 10,
    ],
]);

$response = file_get_contents($verifyUrl, false, $context);
$data     = $response ? json_decode($response, true) : null;

if (!$data || empty($data['success'])) {
    echo json_encode(['status'=>'error','message'=>'reCAPTCHA verification failed.']); exit;
}

// --- DB logic ---
try {
    $pdo = get_pdo();

    // check if this invoice OR IP already has a record
    $check = $pdo->prepare("
        SELECT invoice, ip_address, status, sats_sent, created_at
        FROM faucet_claims
        WHERE invoice = :inv OR ip_address = :ip
        LIMIT 1
    ");
    $check->execute([':inv' => $invoice, ':ip' => $userIp]);
    $row = $check->fetch();

    if ($row) {
        $msg = ($row['status'] === 'paid')
            ? 'You already claimed — sats were sent to your invoice.'
            : 'You already have a request in progress. Please check back later.';
        echo json_encode([
            'status'        => 'already_claimed',
            'message'       => $msg,
            'invoice'       => $row['invoice'],
            'ipAddress'     => $row['ip_address'],
            'satsSent'      => (int)$row['sats_sent'],
            'claimedAt'     => $row['created_at'],
            'currentStatus' => $row['status'],
        ]);
        exit;
    }

    // new request -> insert as pending
    $reward = 100; // sats (or 1000 if you want)
    $ins = $pdo->prepare("
        INSERT INTO faucet_claims (invoice, ip_address, sats_requested, status, user_agent)
        VALUES (:inv, :ip, :sats, 'pending', :ua)
    ");
    $ins->execute([
        ':inv'  => $invoice,
        ':ip'   => $userIp,
        ':sats' => $reward,
        ':ua'   => $userAgent,
    ]);

    echo json_encode([
        'status'  => 'queued',
        'message' => 'Your request has been queued. Your sats are on the way. 🎉',
        'invoice' => $invoice,
        'ip'      => $userIp,
        'sats'    => $reward,
    ]);
    exit;

} catch (Throwable $e) {
    echo json_encode([
        'status'  => 'error',
        'message' => 'Server error while recording your request.',
        // 'debug' => $e->getMessage(), // enable if you need to see errors locally
    ]);
    exit;
}
?>