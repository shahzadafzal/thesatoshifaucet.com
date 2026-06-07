<?php
// claim.php
// Buffer everything so we can send Content-Length and close the connection
// before the background scheduler starts — giving users an instant response.
ob_start();
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

function classify_lightning_target(string $value): ?array {
    $value = trim($value);
    $lower = strtolower($value);

    // LNURL: lnurl1...
    if (strpos($lower, 'lnurl1') === 0) {
        // length sanity
        $len = strlen($lower);
        if ($len < 30 || $len > 2048) {
            return null;
        }
        // basic bech32-ish check
        if (!preg_match('/^lnurl1[02-9ac-hj-np-z]+$/', $lower)) {
            return null;
        }
        return ['type' => 'lnurl', 'normalized' => $value];
    }

    // BOLT11 (mainnet only here): lnbc1...
    if (strpos($lower, 'lnbc1') === 0) {
        $len = strlen($lower);
        if ($len < 50 || $len > 2048) {
            return null;
        }
        if (!preg_match('/^lnbc1[02-9ac-hj-np-z]+$/', $lower)) {
            return null;
        }
        return ['type' => 'bolt11', 'normalized' => $value];
    }

    // You could also allow testnet etc with: ^ln(tb|bcrt)1...
    return null;
}

// --- read POST data from JS ---
$invoice      = isset($_POST['address']) ? trim($_POST['address']) : '';  // BOLT11 invoice
$captchaToken = $_POST['g-recaptcha-response'] ?? '';
$userIp       = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$userAgent    = $_SERVER['HTTP_USER_AGENT'] ?? '';


$invoice = isset($_POST['address']) ? trim($_POST['address']) : '';

$invoice = trim($invoice);
$invLower = strtolower($invoice);

if (strpos($invLower, 'lnurl1') !== 0) {
    echo json_encode([
        'status'  => 'error',
        'message' => 'This faucet accepts LNURL only (starts with lnurl1...). '
                   . 'Please copy your LNURL from your wallet and paste it here.'
    ]);
    exit;
}



// Length sanity (LNURLs vary but should not be super short)
$len = strlen($invLower);
if ($len < 30 || $len > 2048) {
    echo json_encode([
        'status'  => 'error',
        'message' => 'That LNURL looks too short/long. Please copy it again from your wallet.'
    ]);
    exit;
}


// Bech32 charset check (simple)
if (!preg_match('/^lnurl1[02-9ac-hj-np-z]+$/', $invLower)) {
    echo json_encode([
        'status'  => 'error',
        'message' => 'Invalid LNURL format. Please paste a valid LNURL (lnurl1...) from your wallet.'
    ]);
    exit;
}

if ($captchaToken === '') {
    echo json_encode(['status'=>'error','message'=>'Please complete the reCAPTCHA.']); exit;
}

// --- verify reCAPTCHA via cURL (faster, 5s timeout) ---
// This runs synchronously BEFORE queuing — bots are rejected instantly.
$verifyUrl = 'https://www.google.com/recaptcha/api/siteverify';
$payload   = http_build_query([
    'secret'   => $RECAPTCHA_SECRET_KEY,
    'response' => $captchaToken,
    'remoteip' => $userIp,
]);

$ch = curl_init($verifyUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_TIMEOUT        => 5,   // fail fast — don't keep user waiting
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_USERAGENT      => 'TheSatoshiFaucet/1.0',
]);
$response = curl_exec($ch);
curl_close($ch);
$data = $response ? json_decode($response, true) : null;

if (!$data || empty($data['success'])) {
    echo json_encode(['status'=>'error','message'=>'reCAPTCHA verification failed. Please try again.']); exit;
}

// --- DB logic ---
try {
    $pdo = get_pdo();

    // new request -> insert as pending
    $reward = 100; // sats (or 1000 if you want)
    
    // check if this invoice OR IP already has a record
    $check = $pdo->prepare("
        SELECT invoice, ip_address, status, sats_sent, created_at
        FROM faucet_claims
        WHERE invoice = :inv OR (ip_address = :ip AND 1=1)
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


     // ✅ Atomic: reserve/deduct sats when queuing
    $pdo->beginTransaction();

    // Lock balance row so concurrent claims can't overspend
    $balStmt = $pdo->query("SELECT balance_sats FROM faucet_balance WHERE id=1 FOR UPDATE");
    $balRow = $balStmt->fetch();
    $currentBalance = $balRow ? (int)$balRow['balance_sats'] : 0;

    if ($currentBalance < $reward) {
        $pdo->rollBack();
        echo json_encode([
            'status'  => 'error',
            'message' => 'Faucet is empty right now. Please try again later.',
            'balance' => $currentBalance
        ]);
        exit;
    }


    

    $ins = $pdo->prepare("
        INSERT INTO faucet_claims (invoice, ip_address, sats_requested, status, user_agent, payment_type)
        VALUES (:inv, :ip, :sats, 'pending', :ua, 'lnurl')
    ");

    $ins->execute([
        ':inv'  => $invoice,
        ':ip'   => $userIp,
        ':sats' => $reward,
        ':ua'   => $userAgent,
    ]);

    // Deduct balance
    $upd = $pdo->prepare("
        UPDATE faucet_balance
        SET balance_sats = balance_sats - :amt
        WHERE id = 1
    ");
    $upd->execute([':amt' => $reward]);

    $pdo->commit();

    // --- Build the "queued" response and send it to the browser immediately ---
    // We use Content-Length + Connection: close so the browser receives the full
    // response and closes the connection BEFORE we fire the background scheduler.
    // This gives users an instant "Your request has been queued" with zero wait
    // for LNURL network processing (which happens asynchronously after this).
    $jsonResponse = json_encode([
        'status'  => 'queued',
        'message' => '⚡ Your request has been queued! Sats are on their way — sit tight. 🎉',
        'invoice' => $invoice,
        'ip'      => $userIp,
        'sats'    => $reward,
    ]);

    // Capture everything buffered so far, then close the connection
    $buffered = ob_get_clean();
    $fullBody = $buffered . $jsonResponse;

    // Tell the browser the exact content length so it knows the response is complete
    header('Content-Length: ' . strlen($fullBody));
    header('Connection: close');  // signal the server to close after this response

    echo $fullBody;

    // Flush to the web server / client
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();   // FastCGI: releases browser immediately
    } else {
        // Apache/mod_php fallback: sending Content-Length above is what actually
        // lets the browser disconnect; flush moves bytes into the kernel buffer.
        @flush();
    }

    // ----------------------------------------------------------------
    // Phase 2: Fire-and-forget — the browser is already done waiting.
    // The scheduler decodes the LNURL, fetches the pay-data, requests
    // the BOLT11 invoice and (optionally) pays it asynchronously.
    // ----------------------------------------------------------------
    if (!empty($SCHEDULER_TRIGGER_ENABLED) && !empty($SCHEDULER_FILE)) {
        // Use escapeshellarg for BOTH paths — escapeshellcmd can mangle Windows backslashes.
        $php  = escapeshellarg($PHP_CLI_PATH ?? 'php');
        $file = escapeshellarg($SCHEDULER_FILE);
        $logFile = escapeshellarg(__DIR__ . '/scheduler.log');

        if (stripos(PHP_OS, 'WIN') === 0) {
            // Windows: correct syntax is  start "title" /B  (title BEFORE /B)
            // cmd /c is required because 'start' is a cmd.exe shell builtin.
            // Output is redirected to scheduler.log so you can verify it ran.
            $cmd = 'cmd /c start "" /B ' . $php . ' ' . $file . ' --batch=1'
                 . ' >> ' . $logFile . ' 2>&1';
            @pclose(@popen($cmd, 'r'));
        } else {
            // Linux / cPanel
            $cmd = $php . ' ' . $file . ' --batch=1 >> ' . $logFile . ' 2>&1 &';
            @exec($cmd);
        }
    }
    exit;

} catch (Throwable $e) {
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    echo json_encode([
        'status'  => 'error',
        'message' => 'Server error while recording your request.',
         'debug' => $e->getMessage(), // enable if you need to see errors locally
    ]);
    exit;
}
?>