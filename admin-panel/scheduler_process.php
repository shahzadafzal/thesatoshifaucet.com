<?php
/**
 * scheduler_process.php
 *
 * LNURL-only faucet scheduler:
 * 1) pick pending rows
 * 2) decode LNURL
 * 3) fetch LNURL-pay data
 * 4) verify min/max
 * 5) request BOLT11 invoice for reward
 * 6) pay BOLT11 (example: LNbits)
 * 7) update DB row status and tx_reference/reason
 *
 * Run via CLI / cron:
 *   php scheduler_process.php
 */

if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/plain');
    http_response_code(403);
    echo "Forbidden: CLI access only.\n";
    exit(1);
}

// -- Log header: each scheduler run writes a separator so you can track it in scheduler.log --
echo "\n" . str_repeat('-', 60) . "\n";
echo "[SCHEDULER] Started at " . date('Y-m-d H:i:s') . " UTC\n";
echo str_repeat('-', 60) . "\n";

$configFile = __DIR__ . '/../config.local.php';
if (!file_exists($configFile)) {
    echo "ERROR: config.local.php missing\n";
    exit(1);
}
require $configFile;

$REWARD_SATS = 100; // faucet reward
$REWARD_MSAT = $REWARD_SATS * 1000;

date_default_timezone_set('UTC');

function pdo_connect(): PDO {
    global $DB_HOST, $DB_NAME, $DB_USER, $DB_PASS;
    $dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4";
    return new PDO($dsn, $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

/** ---------------------------
 *  HTTP GET JSON helper
 *  --------------------------- */
function http_get_json(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT      => 'TheSatoshiFaucet/1.0',
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException("HTTP error: {$err}");
    }
    if ($code < 200 || $code >= 300) {
        throw new RuntimeException("HTTP status {$code}");
    }

    $json = json_decode($body, true);
    if (!is_array($json)) {
        throw new RuntimeException("Invalid JSON response");
    }
    return $json;
}

/** ---------------------------
 *  BECH32 decode (LNURL)
 *  Minimal, practical implementation
 *  --------------------------- */

function bech32_polymod(array $values): int {
    $gen = [0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3];
    $chk = 1;
    foreach ($values as $v) {
        $b = $chk >> 25;
        $chk = (($chk & 0x1ffffff) << 5) ^ $v;
        for ($i = 0; $i < 5; $i++) {
            if ((($b >> $i) & 1) === 1) {
                $chk ^= $gen[$i];
            }
        }
    }
    return $chk;
}

function bech32_hrp_expand(string $hrp): array {
    $ret = [];
    $len = strlen($hrp);
    for ($i = 0; $i < $len; $i++) $ret[] = ord($hrp[$i]) >> 5;
    $ret[] = 0;
    for ($i = 0; $i < $len; $i++) $ret[] = ord($hrp[$i]) & 31;
    return $ret;
}

function bech32_verify_checksum(string $hrp, array $data): bool {
    return bech32_polymod(array_merge(bech32_hrp_expand($hrp), $data)) === 1;
}

function bech32_decode(string $bech): array {
    $bech = strtolower(trim($bech));
    if ($bech === '' || strpos($bech, '1') === false) {
        throw new RuntimeException("Invalid bech32: missing separator");
    }

    // reject mixed-case
    if ($bech !== strtolower($bech) && $bech !== strtoupper($bech)) {
        throw new RuntimeException("Invalid bech32: mixed case");
    }

    $pos = strrpos($bech, '1');
    $hrp = substr($bech, 0, $pos);
    $dataPart = substr($bech, $pos + 1);

    if ($hrp === '' || strlen($dataPart) < 6) {
        throw new RuntimeException("Invalid bech32: bad length");
    }

    $charset = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
    $map = [];
    for ($i = 0; $i < strlen($charset); $i++) {
        $map[$charset[$i]] = $i;
    }

    $data = [];
    for ($i = 0; $i < strlen($dataPart); $i++) {
        $c = $dataPart[$i];
        if (!isset($map[$c])) {
            throw new RuntimeException("Invalid bech32: bad char");
        }
        $data[] = $map[$c];
    }

    if (!bech32_verify_checksum($hrp, $data)) {
        throw new RuntimeException("Invalid bech32: checksum fail");
    }

    // strip checksum (last 6)
    $dataNoChecksum = array_slice($data, 0, -6);
    return [$hrp, $dataNoChecksum];
}

function convertbits(array $data, int $fromBits, int $toBits, bool $pad = true): array {
    $acc = 0;
    $bits = 0;
    $ret = [];
    $maxv = (1 << $toBits) - 1;

    foreach ($data as $value) {
        if ($value < 0 || ($value >> $fromBits)) {
            throw new RuntimeException("convertbits invalid value");
        }
        $acc = ($acc << $fromBits) | $value;
        $bits += $fromBits;
        while ($bits >= $toBits) {
            $bits -= $toBits;
            $ret[] = ($acc >> $bits) & $maxv;
        }
    }

    if ($pad) {
        if ($bits) $ret[] = ($acc << ($toBits - $bits)) & $maxv;
    } else {
        if ($bits >= $fromBits) throw new RuntimeException("convertbits excess padding");
        if ((($acc << ($toBits - $bits)) & $maxv) !== 0) throw new RuntimeException("convertbits non-zero padding");
    }
    return $ret;
}

function lnurl_to_url(string $lnurl): string {
    $lnurl = strtolower(trim($lnurl));
    if (strpos($lnurl, 'lnurl1') !== 0) {
        throw new RuntimeException("Not an LNURL");
    }

    [$hrp, $data] = bech32_decode($lnurl);
    if ($hrp !== 'lnurl') {
        throw new RuntimeException("Unexpected HRP: {$hrp}");
    }

    $bytes = convertbits($data, 5, 8, false);
    $url = '';
    foreach ($bytes as $b) $url .= chr($b);

    if (!preg_match('#^https?://#i', $url)) {
        throw new RuntimeException("Decoded LNURL is not a URL");
    }
    return $url;
}

/** ---------------------------
 * LNURL-pay flow
 * --------------------------- */

function lnurl_fetch_pay_data(string $lnurl): array {
    $url = lnurl_to_url($lnurl);
    $data = http_get_json($url);

    // LNURL-pay spec: tag should be 'payRequest'
    if (($data['tag'] ?? '') !== 'payRequest') {
        // Some providers return errors like {status:"ERROR", reason:"..."}
        $reason = $data['reason'] ?? 'Not an LNURL-pay request';
        throw new RuntimeException("LNURL not payable: {$reason}");
    }

    if (empty($data['callback']) || !is_string($data['callback'])) {
        throw new RuntimeException("LNURL-pay missing callback");
    }
    if (!isset($data['minSendable'], $data['maxSendable'])) {
        throw new RuntimeException("LNURL-pay missing min/max");
    }

    return $data;
}

function lnurl_request_invoice(array $payData, int $amountMsat): string {
    $min = (int)$payData['minSendable'];
    $max = (int)$payData['maxSendable'];

    if ($amountMsat < $min || $amountMsat > $max) {
        throw new RuntimeException("Amount out of range: {$amountMsat}msat (min={$min}, max={$max})");
    }

    $callback = $payData['callback'];
    $sep = (strpos($callback, '?') === false) ? '?' : '&';
    $invoiceResp = http_get_json($callback . $sep . 'amount=' . $amountMsat);

    if (($invoiceResp['status'] ?? 'OK') === 'ERROR') {
        throw new RuntimeException("LNURL callback error: " . ($invoiceResp['reason'] ?? 'unknown'));
    } 

    $pr = $invoiceResp['pr'] ?? '';
    if (!is_string($pr) || stripos($pr, 'ln') !== 0) {
        throw new RuntimeException("Callback did not return a valid invoice");
    }
    return $pr;
}


/** ---------------------------
 * 
 * --------------------------- */

function lnurl_extract_domain(string $lnurl): string {
    $url = lnurl_to_url($lnurl);          // already validates checksum + decodes
    $parts = parse_url($url);
    $host = $parts['host'] ?? '';
    $host = strtolower(trim($host));
    // basic cleanup: remove trailing dot
    $host = rtrim($host, '.');
    if ($host === '') {
        throw new RuntimeException("LNURL decoded but domain missing");
    }
    return $host;
}

/** ---------------------------
 * PAY step (example: LNbits)
 * You can swap this out later.
 * --------------------------- */
function pay_bolt11_invoice(string $bolt11): array {
    global $LNBITS_URL, $LNBITS_KEY;

    if (empty($LNBITS_URL) || empty($LNBITS_KEY)) {
        // payment disabled
        return ['paid' => false, 'ref' => null, 'error' => 'Payment not configured (LNbits URL/key missing)'];
    }

    $endpoint = rtrim($LNBITS_URL, '/') . '/api/v1/payments';
    $payload = json_encode([
        'out' => true,
        'bolt11' => $bolt11
    ]);

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-Api-Key: ' . $LNBITS_KEY,
        ],
    ]);

    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        return ['paid' => false, 'ref' => null, 'error' => "LNbits curl error: {$err}"];
    }
    if ($code < 200 || $code >= 300) {
        return ['paid' => false, 'ref' => null, 'error' => "LNbits HTTP {$code}: {$body}"];
    }

    $json = json_decode($body, true);
    if (!is_array($json)) {
        return ['paid' => false, 'ref' => null, 'error' => "LNbits invalid JSON"];
    }

    // LNbits typically returns payment_hash or checking_id
    $ref = $json['payment_hash'] ?? ($json['checking_id'] ?? null);

    return ['paid' => true, 'ref' => $ref, 'error' => null];
}


/** ---------------------------
 * Main processing loop
 * --------------------------- */

$pdo = pdo_connect();

// Turn refund on/off:
$REFUND_ON_FAIL = true;

// Process up to N rows per run
$BATCH = 5;

// Allow CLI override: php scheduler_process.php --batch=1
if (!empty($argv)) {
  foreach ($argv as $arg) {
    if (strpos($arg, '--batch=') === 0) {
      $BATCH = max(1, (int)substr($arg, 8));
    }
  }
}

function mark_failed(PDO $pdo, int $id, string $reason, bool $refundOnFail, string $adminStatus = 'failed_validation'): void {
    // Load sats_requested for refund amount (lock row to be safe)
    $pdo->beginTransaction();

    $row = $pdo->prepare("SELECT sats_requested, status FROM faucet_claims WHERE id=:id FOR UPDATE");
    $row->execute([':id' => $id]);
    $c = $row->fetch();

    if (!$c) {
        $pdo->rollBack();
        return;
    }

    $currentStatus = (string)$c['status'];
    $amount = (int)$c['sats_requested'];

    // If it was already paid or already failed, don't double-refund
    if ($currentStatus === 'paid' || $currentStatus === 'failed') {
        $pdo->rollBack();
        return;
    }

    $pdo->prepare("
        UPDATE faucet_claims
        SET status='failed',
            admin_status=:ast,
            pay_bolt11=NULL,
            sats_sent=0,
            tx_reference=NULL,
            reason=:reason
        WHERE id=:id
    ")->execute([
        ':ast'    => $adminStatus,
        ':reason' => $reason,
        ':id'     => $id
    ]);

    // Optional refund back to faucet balance
    if ($refundOnFail && $amount > 0) {
        $pdo->prepare("UPDATE faucet_balance SET balance_sats = balance_sats + :amt WHERE id=1")
            ->execute([':amt' => $amount]);
    }

    $pdo->commit();
}


/**
 * ============================================================
 * SEND CLAIM EMAIL NOTIFICATION
 * ============================================================
 * Sends an email to admin with full transaction details
 * whenever a Sats claim is successfully paid.
 * 
 * Uses cPanel SMTP server with SSL/TLS encryption.
 * Runs asynchronously - does not block scheduler processing.
 * 
 * DISABLE EMAIL: Set $EMAIL_ENABLED = false in config.local.php
 * or comment out the function call in the main loop below.
 * ============================================================
 */
function send_claim_email(
    string $adminEmail,
    string $lnurl,
    string $domain,
    int $rewardSats,
    string $status,
    ?string $paymentRef = null,
    ?string $userIp = null,
    ?string $invoiceData = null,
    ?string $transactionId = null
): bool {
    global $EMAIL_ENABLED, $EMAIL_SMTP_HOST, $EMAIL_SMTP_PORT, $EMAIL_SMTP_USER, $EMAIL_SMTP_PASS, $EMAIL_SMTP_SECURE, $EMAIL_FROM;

    if (empty($EMAIL_ENABLED) || !$adminEmail) {
        return false;
    }

    try {
        // Build email subject and body
        $subject = "[TheSatoshiFaucet] New Claim Request - " . ($status === 'paid' ? '✅ PAID' : '⏳ PROCESSING');
        
        $body = <<<EOT
=============================================================
         SATOSHI FAUCET - CLAIM NOTIFICATION
=============================================================

TRANSACTION STATUS: {$status}
Timestamp: {$__TS = date('Y-m-d H:i:s')} UTC

=============================================================
RECEIVER DETAILS
=============================================================
Domain/Wallet Provider: {$domain}
User IP Address: {$userIp}
LNURL (Payment Address):
{$lnurl}

=============================================================
REWARD DETAILS
=============================================================
Amount Requested: {$rewardSats} sats
Status: {$status}

=============================================================
PAYMENT INFORMATION
=============================================================
Payment Reference: {$paymentRef}
Transaction ID: {$transactionId}
Invoice Data: {$invoiceData}

=============================================================
This is an automated notification. Do not reply to this email.
=============================================================
EOT;

        // If SMTP is configured, use SMTP with SSL/TLS
        if (!empty($EMAIL_SMTP_HOST)) {
            return send_via_smtp(
                $adminEmail,
                $subject,
                $body,
                $EMAIL_SMTP_HOST,
                $EMAIL_SMTP_PORT,
                $EMAIL_SMTP_USER,
                $EMAIL_SMTP_PASS,
                $EMAIL_SMTP_SECURE,
                $EMAIL_FROM
            );
        } else {
            // Fallback: use PHP's mail() function
            $headers = "From: {$EMAIL_FROM}\r\n";
            $headers .= "Reply-To: {$EMAIL_FROM}\r\n";
            $headers .= "X-Mailer: TheSatoshiFaucet/1.0\r\n";
            $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
            
            return mail($adminEmail, $subject, $body, $headers);
        }
    } catch (Throwable $e) {
        echo "[EMAIL] Error: " . $e->getMessage() . "\n";
        return false;
    }
}


/**
 * Send email via SMTP (cPanel SMTP with SSL/TLS)
 * Non-blocking, handles errors gracefully
 */
function send_via_smtp(
    string $to,
    string $subject,
    string $body,
    string $host,
    int $port,
    string $user,
    string $pass,
    string $secure,
    string $from
): bool {
    try {
        // Determine SSL/TLS context
        $contextOptions = [
            'ssl' => [
                'verify_peer'       => true,
                'verify_peer_name'  => true,
                'allow_self_signed' => false,
            ]
        ];

        if ($secure === 'ssl') {
            $context = stream_context_create($contextOptions);
            $socket = @stream_socket_client(
                "ssl://{$host}:{$port}",
                $errNo,
                $errStr,
                10,
                STREAM_CLIENT_CONNECT,
                $context
            );
        } else {
            // TLS (STARTTLS)
            $socket = @stream_socket_client(
                "tcp://{$host}:{$port}",
                $errNo,
                $errStr,
                10
            );
        }

        if (!$socket) {
            echo "[EMAIL] SMTP connection failed: {$errStr} (Error {$errNo})\n";
            return false;
        }

        // Read SMTP banner
        $response = fgets($socket, 512);
        if (strpos($response, '220') === false) {
            fclose($socket);
            echo "[EMAIL] Invalid SMTP banner: {$response}\n";
            return false;
        }

        // EHLO command
        fwrite($socket, "EHLO localhost\r\n");
        while ($response = fgets($socket, 512)) {
            if (strpos($response, '250 ') === 0) break;
            if (strpos($response, '-') === false && strpos($response, '250') === 0) break;
        }

        // AUTH LOGIN
        fwrite($socket, "AUTH LOGIN\r\n");
        $response = fgets($socket, 512);
        if (strpos($response, '334') === false) {
            fclose($socket);
            echo "[EMAIL] AUTH not supported: {$response}\n";
            return false;
        }

        // Send username (base64)
        fwrite($socket, base64_encode($user) . "\r\n");
        $response = fgets($socket, 512);

        // Send password (base64)
        fwrite($socket, base64_encode($pass) . "\r\n");
        $response = fgets($socket, 512);
        if (strpos($response, '235') === false) {
            fclose($socket);
            echo "[EMAIL] SMTP authentication failed: {$response}\n";
            return false;
        }

        // MAIL FROM
        fwrite($socket, "MAIL FROM:<{$from}>\r\n");
        $response = fgets($socket, 512);
        if (strpos($response, '250') === false) {
            fclose($socket);
            echo "[EMAIL] MAIL FROM rejected: {$response}\n";
            return false;
        }

        // RCPT TO
        fwrite($socket, "RCPT TO:<{$to}>\r\n");
        $response = fgets($socket, 512);
        if (strpos($response, '250') === false) {
            fclose($socket);
            echo "[EMAIL] RCPT TO rejected: {$response}\n";
            return false;
        }

        // DATA
        fwrite($socket, "DATA\r\n");
        $response = fgets($socket, 512);
        if (strpos($response, '354') === false) {
            fclose($socket);
            echo "[EMAIL] DATA rejected: {$response}\n";
            return false;
        }

        // Build complete email
        $date = date('r');
        $emailContent = "Date: {$date}\r\n";
        $emailContent .= "From: {$from}\r\n";
        $emailContent .= "To: {$to}\r\n";
        $emailContent .= "Subject: {$subject}\r\n";
        $emailContent .= "X-Mailer: TheSatoshiFaucet/1.0\r\n";
        $emailContent .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $emailContent .= "\r\n";
        $emailContent .= $body;
        $emailContent .= "\r\n";

        // Send email content (escape dots at line start)
        $lines = explode("\r\n", $emailContent);
        foreach ($lines as $line) {
            if (substr($line, 0, 1) === '.') {
                $line = '.' . $line;
            }
            fwrite($socket, $line . "\r\n");
        }

        // QUIT
        fwrite($socket, ".\r\n");
        $response = fgets($socket, 512);
        if (strpos($response, '250') === false) {
            fclose($socket);
            echo "[EMAIL] Message rejected: {$response}\n";
            return false;
        }

        fwrite($socket, "QUIT\r\n");
        fclose($socket);

        echo "[EMAIL] Successfully sent to {$to}\n";
        return true;

    } catch (Throwable $e) {
        echo "[EMAIL] Exception: " . $e->getMessage() . "\n";
        return false;
    }
}


for ($i = 0; $i < $BATCH; $i++) {
    $id = 0;

    try {
        // --- Step A: claim one pending row safely ---
        $pdo->beginTransaction();

        $row = $pdo->query("
            SELECT id, invoice, sats_requested
            FROM faucet_claims
            WHERE status='pending'
            ORDER BY created_at ASC
            LIMIT 1
            FOR UPDATE
        ")->fetch();

        if (!$row) {
            $pdo->commit();
            echo "No pending rows.\n";
            break;
        }

        $id = (int)$row['id'];

        // Set to processing
        $pdo->prepare("UPDATE faucet_claims SET status='processing', reason=NULL WHERE id=:id")
            ->execute([':id' => $id]);       

        $pdo->commit();

        $lnurl = trim((string)$row['invoice']);

         // Store decoded domain for sanity
        $domain = lnurl_extract_domain($lnurl);

        $pdo->prepare("UPDATE faucet_claims SET receiver_domain=:d WHERE id=:id")
            ->execute([':d' => $domain, ':id' => $id]);

        // --- Step B: LNURL validate + invoice request ---
        echo "Row #{$id} PROCESSING: LNURL={$lnurl}, domain={$domain}\n";
        $payData = lnurl_fetch_pay_data($lnurl);
        $bolt11  = lnurl_request_invoice($payData, $REWARD_MSAT);

        // ✅ Store the pay invoice for manual payment
        $pdo->prepare("
                UPDATE faucet_claims
                SET pay_bolt11 = :bolt11,
                    admin_status = 'ready_to_pay',
                    reason = NULL
                WHERE id = :id
            ")->execute([
                ':bolt11' => $bolt11,
                ':id'     => $id,
            ]);

        echo "Row #{$id} READY TO PAY: stored BOLT11 invoice for {$REWARD_SATS} sats\n";

        // ============================================================
        // 📧 SEND EMAIL NOTIFICATION - Ready to Pay
        // ============================================================
        // To DISABLE email notifications, set $EMAIL_ENABLED = false 
        // in config.local.php or comment out this function call below.
        // Email is sent asynchronously and does NOT block processing.
        // ============================================================
        send_claim_email(
            $EMAIL_ADMIN,
            $lnurl,
            $domain,
            $REWARD_SATS,
            'ready_to_pay',
            null,
            null,
            $bolt11,
            null
        );
        // ============================================================

        // --- Step C: Pay bolt11 invoice ---
        /* 
         ********** Manual at moment - uncomment to auto-pay **********
        $pay = pay_bolt11_invoice($bolt11);

        if (!$pay['paid']) {
            $reason = $pay['error'] ?? 'Payment failed';
            mark_failed($pdo, $id, $reason, $REFUND_ON_FAIL);
            echo "Row #{$id} FAILED: {$reason}\n";
            continue;
        }

        // --- Step D: Mark paid ---
        $pdo->prepare("
            UPDATE faucet_claims
            SET status='paid',
                sats_sent=:sent,
                tx_reference=:ref,
                reason=NULL
            WHERE id=:id
        ")->execute([
            ':sent' => $REWARD_SATS,
            ':ref'  => (string)$pay['ref'],
            ':id'   => $id,
        ]);

        // ============================================================
        // 📧 SEND EMAIL NOTIFICATION - Payment Successful
        // ============================================================
        // To DISABLE email notifications, set $EMAIL_ENABLED = false 
        // in config.local.php or comment out this function call below.
        // Email is sent asynchronously and does NOT block processing.
        // ============================================================
        send_claim_email(
            $EMAIL_ADMIN,
            $lnurl,
            $domain,
            $REWARD_SATS,
            'paid',
            (string)$pay['ref'],
            null,
            $bolt11,
            (string)$pay['ref']
        );
        // ============================================================

        echo "Row #{$id} PAID: {$REWARD_SATS} sats, ref=" . $pay['ref'] . "\n";
        */

    } catch (Throwable $e) {
        // If any exception occurs after we set processing:
        $reason = $e->getMessage();

        // Rollback if a transaction is open
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($id > 0) {
            // Mark failed + refund
            mark_failed($pdo, $id, $reason, $REFUND_ON_FAIL);
            echo "Row #{$id} FAILED (exception): {$reason}\n";
        } else {
            echo "ERROR (no row claimed yet): {$reason}\n";
        }

        continue;
    }
}
?>