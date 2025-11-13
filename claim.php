<?php
// claim.php
header('Content-Type: application/json');

$configFile = __DIR__ . '/config.local.php';
if (!file_exists($configFile)) {
  echo json_encode(['status'=>'error','message'=>'Server config missing.']); exit;
}
require $configFile;

/* --- helpers --- */
function pdo(): PDO {
  global $DB_HOST,$DB_NAME,$DB_USER,$DB_PASS;
  static $pdo=null;
  if ($pdo) return $pdo;
  $dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4";
  $pdo = new PDO($dsn,$DB_USER,$DB_PASS,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
  ]);
  return $pdo;
}

/* --- read request --- */
$invoice      = isset($_POST['address']) ? trim($_POST['address']) : '';  // BOLT11
$captchaToken = $_POST['g-recaptcha-response'] ?? '';
$userIp       = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$userAgent    = $_SERVER['HTTP_USER_AGENT'] ?? '';

if ($invoice === '' || (stripos($invoice,'lnbc')!==0 && stripos($invoice,'ln')!==0)) {
  echo json_encode(['status'=>'error','message'=>'Please paste a valid Lightning invoice (BOLT11).']); exit;
}
if ($captchaToken === '') {
  echo json_encode(['status'=>'error','message'=>'Please complete the reCAPTCHA.']); exit;
}

/* --- verify reCAPTCHA --- */
$verifyUrl = 'https://www.google.com/recaptcha/api/siteverify';
$verifyRes = file_get_contents($verifyUrl, false, stream_context_create([
  'http'=>[
    'method'=>'POST',
    'header'=>"Content-type: application/x-www-form-urlencoded\r\n",
    'content'=>http_build_query([
      'secret'=>$RECAPTCHA_SECRET_KEY, 'response'=>$captchaToken, 'remoteip'=>$userIp
    ]),
    'timeout'=>10
  ]
]));
if (!$verifyRes || empty(json_decode($verifyRes,true)['success'])) {
  echo json_encode(['status'=>'error','message'=>'reCAPTCHA failed. Try again.']); exit;
}

/* --- check duplicates: invoice OR ip exists --- */
$db = pdo();
$exists = $db->prepare("SELECT invoice, ip_address, status, sats_sent, created_at
                        FROM faucet_claims WHERE invoice=:i OR ip_address=:ip LIMIT 1");
$exists->execute([':i'=>$invoice, ':ip'=>$userIp]);
$row = $exists->fetch();

if ($row) {
  // Already requested/claimed
  $msg = ($row['status']==='paid')
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
  ]); exit;
}

/* --- insert pending request --- */
$reward = 100;  // or 1000 — set your faucet reward here
$ins = $db->prepare("INSERT INTO faucet_claims
  (invoice, ip_address, sats_requested, status, user_agent)
  VALUES (:i,:ip,:s,'pending',:ua)");
try {
  $ins->execute([':i'=>$invoice, ':ip'=>$userIp, ':s'=>$reward, ':ua'=>$userAgent]);
} catch (PDOException $e) {
  // Unique index might have raced
  echo json_encode(['status'=>'already_claimed','message'=>'Request already registered.']); exit;
}

/* --- ok --- */
echo json_encode([
  'status'  => 'queued',
  'message' => 'Your request has been queued. Your sats are on the way. 🎉',
  'invoice' => $invoice,
  'ip'      => $userIp,
  'sats'    => $reward
]);

?> 

<!-- <?php
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
?> -->