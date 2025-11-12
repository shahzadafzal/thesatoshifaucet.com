<?php
// claim.php

// 1. CONFIG: set your secret key from Google reCAPTCHA admin
// Load local, non-committed config
require __DIR__ . '/config.local.php';

// Now use the variable from that file
if (!isset($RECAPTCHA_SECRET_KEY) || !$RECAPTCHA_SECRET_KEY) {
    die('Server misconfiguration: reCAPTCHA secret key is not set.');
}

$secretKey = $RECAPTCHA_SECRET_KEY;

if (!$secretKey) {
    // Optional: safer fallback / error
    die('Server misconfiguration: reCAPTCHA secret key is not set.');
}


// 2. Read POST data
$address      = isset($_POST['address']) ? trim($_POST['address']) : '';
$captchaToken = isset($_POST['g-recaptcha-response']) ? $_POST['g-recaptcha-response'] : '';
$userIp       = $_SERVER['REMOTE_ADDR'] ?? '';

// Basic validation
if ($address === '') {
    die('Error: Please provide a Bitcoin or Lightning address.');
}

if ($captchaToken === '') {
    die('Error: Please complete the reCAPTCHA.');
}

// 3. Verify reCAPTCHA with Google
$verifyUrl = 'https://www.google.com/recaptcha/api/siteverify';

$postData = http_build_query([
    'secret'   => $secretKey,
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

$context  = stream_context_create($opts);
$result   = file_get_contents($verifyUrl, false, $context);

if ($result === false) {
    die('Error: Could not contact reCAPTCHA verification server.');
}

$decoded = json_decode($result, true);

// 4. Check verification result
if (empty($decoded['success']) || !$decoded['success']) {
    // Optionally inspect $decoded['error-codes'] for debugging
    die('Error: reCAPTCHA verification failed. Please try again.');
}

// At this point, reCAPTCHA is valid – user is likely human

// 5. TODO: Your faucet logic goes here.
// - Check user/IP/rate limits
// - Check faucet balance
// - Send sats from your wallet / LN node
// - Log the payout

// For now, just show a simple confirmation
$rewardPerClaim = 500; // sats, example

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Satoshi Faucet – Claim Result</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: #ffffff;
            color: #222;
            padding: 24px 16px;
        }
        .box {
            max-width: 600px;
            margin: 0 auto;
            border: 1px solid #ddd;
            padding: 16px 20px;
            background: #fafafa;
        }
        h1 {
            font-size: 1.4rem;
            margin-top: 0;
        }
        .success {
            color: #0a7f00;
            margin-bottom: 8px;
        }
        a {
            color: #1a0dab;
        }
    </style>
</head>
<body>
<div class="box">
    <h1>The Satoshi Faucet</h1>
    <p class="success">
        ✅ reCAPTCHA passed. A payout of <strong><?php echo number_format($rewardPerClaim); ?> sats</strong>
        would now be sent to:
    </p>
    <p><code><?php echo htmlspecialchars($address, ENT_QUOTES, 'UTF-8'); ?></code></p>

    <p style="margin-top:16px;">
        (This is a demo message – hook in your real payout logic here.)
    </p>

    <p style="margin-top:24px;">
        <a href="index.html">&larr; Back to The Satoshi Faucet</a>
    </p>
</div>
</body>
</html>
