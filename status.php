<?php
// status.php
header('Content-Type: application/json');

require __DIR__ . '/config.local.php';

$invoice  = isset($_GET['invoice']) ? trim($_GET['invoice']) : '';
$userIp   = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

try {
  $dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4";
  $pdo = new PDO($dsn,$DB_USER,$DB_PASS,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
  ]);

  if ($invoice !== '') {
    $q = $pdo->prepare("SELECT invoice, ip_address, status, sats_sent, tx_reference, created_at, updated_at
                        FROM faucet_claims WHERE invoice=:i LIMIT 1");
    $q->execute([':i'=>$invoice]);
  } else {
    $q = $pdo->prepare("SELECT invoice, ip_address, status, sats_sent, tx_reference, created_at, updated_at
                        FROM faucet_claims WHERE ip_address=:ip LIMIT 1");
    $q->execute([':ip'=>$userIp]);
  }
  $row = $q->fetch();

  if (!$row) {
    echo json_encode(['status'=>'none']); exit;
  }

  echo json_encode([
    'status'     => $row['status'],
    'invoice'    => $row['invoice'],
    'ipAddress'  => $row['ip_address'],
    'satsSent'   => (int)$row['sats_sent'],
    'tx'         => $row['tx_reference'],
    'createdAt'  => $row['created_at'],
    'updatedAt'  => $row['updated_at'],
  ]);

} catch (Throwable $e) {
  echo json_encode(['status'=>'error','message'=>'Status lookup failed.']);
}
?>