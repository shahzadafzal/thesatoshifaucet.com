<?php
// claim.php
header('Content-Type: application/json');

$configFile = __DIR__ . '/config.local.php';
if (!file_exists($configFile)) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Server config missing. Please try again later.'
    ]);
    exit;
}
require $configFile;

// Validate config
if (empty($RECAPTCHA_SECRET_KEY)) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Server misconfiguration (reCAPTCHA).'
    ]);
    exit;
}

if (empty($DB_HOST) || empty($DB_NAME) || empty($DB_USER)) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Server misconfiguration (database).'
    ]);
    exit;
}

// Get POST data
$address      = isset($_POST['address']) ? trim($_POST['address']) : '';
$captchaToken = isset($_POST['g-recaptcha-response']) ? $_POST['g-recaptcha-response'] : '';
$userIp       = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$userAgent    = $_SERVER['HTTP_USER_AGENT'] ?? '';

if ($address === '') {
    echo json_encode([
        'status' => 'error',
        'message' => 'Please provide a Bitcoin or Lightning address.'
    ]);
    exit;
}

if ($captchaToken === '') {
    echo json_encode([
        'status' => 'error',
        'message' => 'Please complete the reCAPTCHA.'
    ]);
    exit;
}

// 1) Verify reCAPTCHA with Google
$verifyUrl = 'https://www.google.com/recaptcha/api/siteverify';

$postData = http_build_query([
    'secret'   => $RECAPTCHA_SECRET_KEY,
    'response' => $captchaToken,
    'remoteip' => $userIp,
]);

$opts = [
    'http' => [
        'method'  => 'POST',
        'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
        'content' => $postData,
        'timeout' => 10,
    ],
];

$context = stream_context_create($opts);
$result  = file_get_contents($verifyUrl, false, $context);

if ($result === false) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Could not contact reCAPTCHA verification server.'
    ]);
    exit;
}

$decoded = json_decode($result, true);

if (empty($decoded['success']) || !$decoded['success']) {
    echo json_encode([
        'status' => 'error',
        'message' => 'reCAPTCHA verification failed. Please try again.'
    ]);
    exit;
}

// 2) Connect to MySQL
try {
    $dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4";
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Database connection failed.'
    ]);
    exit;
}

// 3) Check existing claim by wallet or IP
$checkStmt = $pdo->prepare("
    SELECT wallet_address, ip_address, sats_sent, created_at
    FROM faucet_claims
    WHERE wallet_address = :addr OR ip_address = :ip
    LIMIT 1
");
$checkStmt->execute([
    ':addr' => $address,
    ':ip'   => $userIp,
]);
$existing = $checkStmt->fetch();

if ($existing) {
    // Already claimed – show friendly message + the wallet it was sent to
    echo json_encode([
        'status'        => 'already_claimed',
        'message'       => 'You have already received sats from this faucet.',
        'walletAddress' => $existing['wallet_address'],
        'ipAddress'     => $existing['ip_address'],
        'satsSent'      => (int)$existing['sats_sent'],
        'claimedAt'     => $existing['created_at'],
    ]);
    exit;
}

// 4) New claim allowed
$rewardPerClaim = 500; // sats – your faucet amount

// TODO: here you would send sats via your wallet / Lightning backend
// e.g. call an API, communicate with LN node, etc.
// For now we just simulate success.

try {
    $insertStmt = $pdo->prepare("
        INSERT INTO faucet_claims (wallet_address, ip_address, sats_sent, user_agent)
        VALUES (:addr, :ip, :sats, :ua)
    ");
    $insertStmt->execute([
        ':addr' => $address,
        ':ip'   => $userIp,
        ':sats' => $rewardPerClaim,
        ':ua'   => $userAgent,
    ]);
} catch (PDOException $e) {
    // Could be unique constraint violation (race condition)
    echo json_encode([
        'status'  => 'error',
        'message' => 'Could not record claim (maybe already claimed).',
    ]);
    exit;
}

// If we reached here, claim recorded and sats “sent”
echo json_encode([
    'status'        => 'success',
    'message'       => 'Your sats are on the way. 🎉',
    'walletAddress' => $address,
    'ipAddress'     => $userIp,
    'satsSent'      => $rewardPerClaim,
]);
exit;
?>