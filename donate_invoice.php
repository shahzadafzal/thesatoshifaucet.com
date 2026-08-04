<?php
// donate_invoice.php
//
// Generates a Lightning (BOLT11) invoice for a donation amount by resolving the
// faucet's $DONATION_LNURL (LNURL-pay) and requesting an invoice from its callback.
// Used by support.html's "Quick Lightning Donation" widget so a supporter can pick
// a preset (or custom) sat amount and get a scannable/copyable invoice instantly.
//
// GET/POST param: amount (integer, sats)
// Response: { "status": "ok", "invoice": "lnbc...", "amount": 1000 }
//        or { "status": "error", "message": "..." }

header('Content-Type: application/json');

$configFile = __DIR__ . '/config.local.php';
if (!file_exists($configFile)) {
    echo json_encode(['status' => 'error', 'message' => 'Server config missing.']);
    exit;
}
require $configFile;

if (empty($DONATION_LNURL) || !is_string($DONATION_LNURL)) {
    echo json_encode(['status' => 'error', 'message' => 'Donation LNURL is not configured.']);
    exit;
}

// --- amount validation ---
$amountSats = isset($_REQUEST['amount']) ? (int) $_REQUEST['amount'] : 0;
if ($amountSats <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Please enter a valid donation amount.']);
    exit;
}
// Sanity cap so a typo (or abuse) can't request an absurd amount.
if ($amountSats > 21000000) {
    echo json_encode(['status' => 'error', 'message' => 'That amount is too large. Please enter a smaller value.']);
    exit;
}

/** ---------------------------
 *  HTTP GET JSON helper
 *  --------------------------- */
function donate_http_get_json(string $url): array {
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
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
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
 *  --------------------------- */
function donate_bech32_polymod(array $values): int {
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

function donate_bech32_hrp_expand(string $hrp): array {
    $ret = [];
    $len = strlen($hrp);
    for ($i = 0; $i < $len; $i++) $ret[] = ord($hrp[$i]) >> 5;
    $ret[] = 0;
    for ($i = 0; $i < $len; $i++) $ret[] = ord($hrp[$i]) & 31;
    return $ret;
}

function donate_bech32_verify_checksum(string $hrp, array $data): bool {
    return donate_bech32_polymod(array_merge(donate_bech32_hrp_expand($hrp), $data)) === 1;
}

function donate_bech32_decode(string $bech): array {
    $bech = strtolower(trim($bech));
    if ($bech === '' || strpos($bech, '1') === false) {
        throw new RuntimeException("Invalid bech32: missing separator");
    }

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

    if (!donate_bech32_verify_checksum($hrp, $data)) {
        throw new RuntimeException("Invalid bech32: checksum fail");
    }

    $dataNoChecksum = array_slice($data, 0, -6);
    return [$hrp, $dataNoChecksum];
}

function donate_convertbits(array $data, int $fromBits, int $toBits, bool $pad = true): array {
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

function donate_lnurl_to_url(string $lnurl): string {
    $lnurl = strtolower(trim($lnurl));
    if (strpos($lnurl, 'lnurl1') !== 0) {
        throw new RuntimeException("Not an LNURL");
    }

    [$hrp, $data] = donate_bech32_decode($lnurl);
    if ($hrp !== 'lnurl') {
        throw new RuntimeException("Unexpected HRP: {$hrp}");
    }

    $bytes = donate_convertbits($data, 5, 8, false);
    $url = '';
    foreach ($bytes as $b) $url .= chr($b);

    if (!preg_match('#^https?://#i', $url)) {
        throw new RuntimeException("Decoded LNURL is not a URL");
    }
    return $url;
}

/** ---------------------------
 *  LNURL-pay flow
 *  --------------------------- */
function donate_lnurl_fetch_pay_data(string $lnurl): array {
    $url = donate_lnurl_to_url($lnurl);
    $data = donate_http_get_json($url);

    if (($data['tag'] ?? '') !== 'payRequest') {
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

function donate_lnurl_request_invoice(array $payData, int $amountMsat): string {
    $min = (int) $payData['minSendable'];
    $max = (int) $payData['maxSendable'];

    if ($amountMsat < $min || $amountMsat > $max) {
        throw new RuntimeException("Amount out of range: {$amountMsat}msat (min={$min}, max={$max})");
    }

    $callback = $payData['callback'];
    $sep = (strpos($callback, '?') === false) ? '?' : '&';
    $invoiceResp = donate_http_get_json($callback . $sep . 'amount=' . $amountMsat);

    if (($invoiceResp['status'] ?? 'OK') === 'ERROR') {
        throw new RuntimeException("LNURL callback error: " . ($invoiceResp['reason'] ?? 'unknown'));
    }

    $pr = $invoiceResp['pr'] ?? '';
    if (!is_string($pr) || stripos($pr, 'ln') !== 0) {
        throw new RuntimeException("Callback did not return a valid invoice");
    }
    return $pr;
}

// --- generate the invoice ---
try {
    $payData    = donate_lnurl_fetch_pay_data($DONATION_LNURL);
    $amountMsat = $amountSats * 1000;

    $min = (int) $payData['minSendable'];
    $max = (int) $payData['maxSendable'];
    if ($amountMsat < $min || $amountMsat > $max) {
        $minSats = (int) ceil($min / 1000);
        $maxSats = (int) floor($max / 1000);
        echo json_encode([
            'status'  => 'error',
            'message' => "Please choose an amount between {$minSats} and " . number_format($maxSats) . " sats.",
        ]);
        exit;
    }

    $bolt11 = donate_lnurl_request_invoice($payData, $amountMsat);

    echo json_encode([
        'status'  => 'ok',
        'invoice' => $bolt11,
        'amount'  => $amountSats,
    ]);
} catch (Throwable $e) {
    echo json_encode([
        'status'  => 'error',
        'message' => 'Could not generate an invoice right now. Please try again in a moment.',
        'debug'   => $e->getMessage(), // enable if you need to see errors locally
    ]);
}
exit;
