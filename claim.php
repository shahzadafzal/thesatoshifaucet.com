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

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['captcha_config'])) {
    $provider = isset($CAPTCHA_PROVIDER) ? strtolower($CAPTCHA_PROVIDER) : 'hcaptcha';
    if ($provider === 'google') {
        $siteKey = $RECAPTCHA_SITE_KEY ?? '';
    } else {
        $provider = 'hcaptcha';
        $siteKey = $HCAPTCHA_SITE_KEY ?? '';
    }
    echo json_encode([
        'provider' => $provider,
        'siteKey'  => $siteKey,
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['cooldown_status'])) {
    try {
        $pdo = get_pdo();

        $remaining = get_global_cooldown_remaining_seconds($pdo);

        echo json_encode([
            'ok' => true,
            'remaining_seconds' => $remaining,
        ]);
    } catch (Throwable $e) {
        echo json_encode([
            'ok' => false,
            'remaining_seconds' => 0,
        ]);
    }
    exit;
}

// if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['cooldown_status'])) {    
//     try {
//         $pdo = get_pdo();
//         $claimCooldownMinutes = isset($GLOBAL_CLAIM_COOLDOWN_MINUTES) ? (int)$GLOBAL_CLAIM_COOLDOWN_MINUTES : 10;
//         $remaining = 0;
//         if ($claimCooldownMinutes > 0) {
//             $cooldownStmt = $pdo->query(
//                 "SELECT TIMESTAMPDIFF(SECOND, MAX(created_at), NOW()) AS seconds_since_last FROM faucet_claims"
//             );
//             $cooldownRow = $cooldownStmt->fetch();
//             if ($cooldownRow && $cooldownRow['seconds_since_last'] !== null) {
//                 $secondsSinceLast = max(0, (int)$cooldownRow['seconds_since_last']);
//                 $required = $claimCooldownMinutes * 60;
//                 if ($secondsSinceLast < $required) {
//                     $remaining = $required - $secondsSinceLast;
//                 }
//             }
//         }
//         echo json_encode([
//             'ok' => true,
//             'remaining_seconds' => $remaining,
//         ]);
//     } catch (Throwable $e) {
//         echo json_encode(['ok' => false]);
//     }
//     exit;
// }

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

function get_setting_int(PDO $pdo, string $name, int $default): int {
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM faucet_settings WHERE setting_name = :name LIMIT 1");
        $stmt->execute([':name' => $name]);
        $value = $stmt->fetchColumn();

        if ($value === false || $value === null || $value === '') {
            return $default;
        }

        return (int)$value;
    } catch (Throwable $e) {
        return $default;
    }
}

function get_cooldown_range_seconds(PDO $pdo): array {
    global $GLOBAL_CLAIM_COOLDOWN_MIN_SECONDS, $GLOBAL_CLAIM_COOLDOWN_MAX_SECONDS, $GLOBAL_CLAIM_COOLDOWN_MINUTES;

    // Backward-compatible fallback: old 10-minute config.
    $oldDefaultSeconds = isset($GLOBAL_CLAIM_COOLDOWN_MINUTES)
        ? max(0, (int)$GLOBAL_CLAIM_COOLDOWN_MINUTES * 60)
        : 600;

    $defaultMin = isset($GLOBAL_CLAIM_COOLDOWN_MIN_SECONDS)
        ? (int)$GLOBAL_CLAIM_COOLDOWN_MIN_SECONDS
        : max(0, $oldDefaultSeconds - 60); // default 9 minutes if old value is 10

    $defaultMax = isset($GLOBAL_CLAIM_COOLDOWN_MAX_SECONDS)
        ? (int)$GLOBAL_CLAIM_COOLDOWN_MAX_SECONDS
        : max($defaultMin, $oldDefaultSeconds + 60); // default 11 minutes if old value is 10

    // DB overrides. Values are seconds.
    $min = get_setting_int($pdo, 'global_cooldown_min_seconds', $defaultMin);
    $max = get_setting_int($pdo, 'global_cooldown_max_seconds', $defaultMax);

    $min = max(0, $min);
    $max = max(0, $max);

    if ($max < $min) {
        $max = $min;
    }

    return [$min, $max];
}

// function get_global_cooldown_remaining_seconds(PDO $pdo): int {
//     [$minCooldownSeconds, $maxCooldownSeconds] = get_cooldown_range_seconds($pdo);

//     if ($maxCooldownSeconds <= 0) {
//         return 0;
//     }

//     $stmt = $pdo->query("
//         SELECT created_at,
//                COALESCE(cooldown_seconds, {$maxCooldownSeconds}) AS cooldown_seconds
//         FROM faucet_claims
//         ORDER BY created_at DESC
//         LIMIT 1
//     ");

//     $row = $stmt->fetch();
//     if (!$row) {
//         return 0;
//     }

//     $createdAt = strtotime((string)$row['created_at']);
//     if ($createdAt === false) {
//         return 0;
//     }

//     $requiredSeconds = max(0, (int)$row['cooldown_seconds']);
//     $elapsedSeconds = max(0, time() - $createdAt);

//     return max(0, $requiredSeconds - $elapsedSeconds);
// }
 
function get_global_cooldown_remaining_seconds(PDO $pdo): int {
    [$minCooldownSeconds, $maxCooldownSeconds] = get_cooldown_range_seconds($pdo);

    if ($maxCooldownSeconds <= 0) {
        return 0;
    }

    $stmt = $pdo->query("
        SELECT
            created_at,
            cooldown_seconds,
            TIMESTAMPDIFF(SECOND, created_at, NOW()) AS elapsed_seconds
        FROM faucet_claims
        WHERE status IN ('paid', 'processing', 'pending')
        ORDER BY created_at DESC
        LIMIT 1
    ");

    $row = $stmt->fetch();
    if (!$row) {
        return 0;
    }

    $elapsedSeconds = max(0, (int)$row['elapsed_seconds']);

    if ($row['cooldown_seconds'] !== null && $row['cooldown_seconds'] !== '') {
        $requiredSeconds = max(0, (int)$row['cooldown_seconds']);
    } else {
        // For old rows where cooldown_seconds is NULL,
        // use the normal fixed cooldown from config.
        $requiredSeconds = isset($GLOBALS['GLOBAL_CLAIM_COOLDOWN_MINUTES'])
            ? max(0, (int)$GLOBALS['GLOBAL_CLAIM_COOLDOWN_MINUTES'] * 60)
            : 600;
    }

    return max(0, $requiredSeconds - $elapsedSeconds);
}

function create_claim_cooldown_seconds(PDO $pdo): int {
    [$minCooldownSeconds, $maxCooldownSeconds] = get_cooldown_range_seconds($pdo);

    if ($maxCooldownSeconds <= 0) {
        return 0;
    }

    if ($minCooldownSeconds === $maxCooldownSeconds) {
        return $minCooldownSeconds;
    }

    return random_int($minCooldownSeconds, $maxCooldownSeconds);
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
$claimSource  = isset($_POST['claim_source']) ? trim($_POST['claim_source']) : 'paste';
$captchaToken = $_POST['g-recaptcha-response'] ?? $_POST['h-captcha-response'] ?? '';
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
    echo json_encode(['status'=>'error','message'=>'Please complete the CAPTCHA.']); exit;
}

$captchaProvider = isset($CAPTCHA_PROVIDER) ? strtolower($CAPTCHA_PROVIDER) : 'hcaptcha';
if ($captchaProvider === 'google') {
    $verifyUrl = 'https://www.google.com/recaptcha/api/siteverify';
    $secret = $RECAPTCHA_SECRET_KEY ?? '';
} else {
    $captchaProvider = 'hcaptcha';
    $verifyUrl = 'https://hcaptcha.com/siteverify';
    $secret = $HCAPTCHA_SECRET_KEY ?? '';
}

// --- verify CAPTCHA via cURL (faster, 5s timeout) ---
// This runs synchronously BEFORE queuing — bots are rejected instantly.
$payload   = http_build_query([
    'secret'   => $secret,
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

// Google recaptcha log disabled
if ($response) {
    // file_put_contents(
    //     __DIR__ . '/recaptcha-debug.log',
    //     "\n--------------------------------------------------\n" .
    //     date('c') .
    //     ' IP=' . $userIp .
    //     ' RESPONSE=' . $response . PHP_EOL,
    //     FILE_APPEND
    // );

    file_put_contents(
        __DIR__ . '/hcaptcha-debug.log',
        "\n--------------------------------------------------\n" .
        date('c') .
        "\nIP=" . $userIp .
        "\nUA=" . ($_SERVER['HTTP_USER_AGENT'] ?? '') .
        "\nHCAPTCHA_RESPONSE=" . $response .
        PHP_EOL,
        FILE_APPEND
    );
}

curl_close($ch);

//$data = $response ? json_decode($response, true) : null;
// if (!$data || empty($data['success'])) {
//     echo json_encode(['status'=>'error','message'=>'CAPTCHA verification failed. Please try again.']); exit;
// }

$data = $response ? json_decode($response, true) : null;

$captchaSuccess = !empty($data['success']);
$captchaHost    = strtolower($data['hostname'] ?? '');
$challengeTs    = $data['challenge_ts'] ?? null;
$errorCodes     = $data['error-codes'] ?? [];

$challengeAgeSeconds = null;
if ($challengeTs) {
    $challengeTime = strtotime($challengeTs);
    if ($challengeTime !== false) {
        $challengeAgeSeconds = time() - $challengeTime;
    }
}

if (
    !$captchaSuccess ||
    !in_array($captchaHost, $HCAPTCHA_ALLOWED_HOSTS, true) ||
    $challengeAgeSeconds === null ||
    $challengeAgeSeconds < 0 ||
    $challengeAgeSeconds > $HCAPTCHA_MAX_TOKEN_AGE_SECONDS
) {
    if ($HCAPTCHA_DEBUG_LOG) {
        file_put_contents(
            __DIR__ . '/hcaptcha-debug.log',
            "\n--------------------------------------------------\n" .
            date('c') .
            "\nIP=" . $userIp .
            "\nHOST=" . $captchaHost .
            "\nAGE_SECONDS=" . ($challengeAgeSeconds ?? 'null') .
            "\nERROR_CODES=" . json_encode($errorCodes) .
            "\nRAW_RESPONSE=" . $response . PHP_EOL,
            FILE_APPEND
        );
    }

    echo json_encode([
        'status'  => 'error',
        'message' => 'CAPTCHA verification failed. Please try again.'
    ]);
    exit;
}

// --- DB logic ---
try {
    $pdo = get_pdo();
    
    // Check processing queue size and refuse new claims when full.
    // The limit is configurable via $MAX_PROCESSING_CLAIMS in config.local.php.
    $limit = isset($MAX_PROCESSING_CLAIMS) ? (int)$MAX_PROCESSING_CLAIMS : 50;
    try {
        $cntStmt = $pdo->prepare("SELECT COUNT(*) AS c FROM faucet_claims WHERE status = 'processing' OR status='pending'");
        $cntStmt->execute();
        $cntRow = $cntStmt->fetch();
        $processingCount = $cntRow ? (int)$cntRow['c'] : 0;
    } catch (Throwable $e) {
        // If the count fails for any reason, be conservative and allow the claim to proceed.
        $processingCount = 0;
    }

    if ($processingCount >= $limit) {
        echo json_encode([
            'status' => 'queue_full',
            'message' => 'Queue is full — please try again in a little while. Thank you for your patience.'
        ]);
        exit;
    }

    // Global cooldown between claims.
    // The actual delay is stored per claim in cooldown_seconds, so bots cannot rely on exactly 10 minutes.
    $remaining = get_global_cooldown_remaining_seconds($pdo);
    if ($remaining > 0) {
        $minutes = floor($remaining / 60);
        $seconds = $remaining % 60;
        $waitText = $minutes > 0
            ? sprintf('%d minute%s and %d second%s', $minutes, $minutes === 1 ? '' : 's', $seconds, $seconds === 1 ? '' : 's')
            : sprintf('%d second%s', $seconds, $seconds === 1 ? '' : 's');

        echo json_encode([
            'status'  => 'cooldown',
            'message' => '⛏️ Next drip in ' . $waitText . '. Bitcoin\'s average block time is about 10 minutes. See you after the next block! ⚡',
            'remaining_seconds' => $remaining,
        ]);
        exit;
    }

    // Use configurable reward from config.local.php; fall back to 100 sats.
    $reward = isset($REWARD_SATS) ? (int) $REWARD_SATS : 100;
    if ($reward <= 0) {
        $reward = 60;
    }

    $claimSource = strtolower($claimSource);
    $allowedSources = ['paste', 'scan', 'upload'];
    if (!in_array($claimSource, $allowedSources, true)) {
        $claimSource = 'paste';
    }

    // check if this invoice OR IP already has a record
    $check = $pdo->prepare("
        SELECT invoice, ip_address, status, sats_sent, created_at
        FROM faucet_claims
        WHERE created_at >= (NOW() - INTERVAL 7 DAY)
        AND Status IN ('paid', 'processing', 'pending')
        AND (
                invoice = :inv
                OR ip_address = :ip
            )
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $check->execute([':inv' => $invoice, ':ip' => $userIp]);
    $row = $check->fetch();

    if ($row) {
        $msg = ($row['status'] === 'paid')
            ? '🎉 You already claimed from this faucet within the last 7 days. Thank you for supporting the project! Please come back after 7 days for another chance to catch some sats. ⚡'
            : '⏳ You already have a claim being processed. Please check back in a little while. If it completes successfully, you can claim again after 7 days.';

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

    $claimCooldownSeconds = create_claim_cooldown_seconds($pdo);

    $ins = $pdo->prepare("
        INSERT INTO faucet_claims (invoice, ip_address, sats_requested, status, user_agent, payment_type, claim_source, cooldown_seconds)
        VALUES (:inv, :ip, :sats, 'pending', :ua, 'lnurl', :source, :cooldown_seconds)
    ");

    $ins->execute([
        ':inv'              => $invoice,
        ':ip'               => $userIp,
        ':sats'             => $reward,
        ':ua'               => $userAgent,
        ':source'           => $claimSource,
        ':cooldown_seconds' => $claimCooldownSeconds,
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