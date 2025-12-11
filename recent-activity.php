<?php
// recent-activity.php
// Returns a small JSON list of recent faucet requests for the homepage sidebar.

header('Content-Type: application/json');

$configFile = __DIR__ . '/config.local.php';
if (!file_exists($configFile)) {
    echo json_encode(['items' => [], 'error' => 'config missing']);
    exit;
}
require $configFile;

try {
    $dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4";
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    echo json_encode(['items' => [], 'error' => 'db error']);
    exit;
}

$limit = 10;

$stmt = $pdo->prepare("
    SELECT id, invoice, sats_requested, sats_sent, status, created_at, updated_at
    FROM faucet_claims
    ORDER BY created_at DESC
    LIMIT :lim
");
$stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

function short_invoice_for_sidebar(string $inv): string {
    $inv = trim($inv);
    $len = strlen($inv);
    if ($len <= 18) return $inv;
    return substr($inv, 0, 6) . '…' . substr($inv, -6);
}

function status_label_short(string $status): string {
    $status = strtolower($status);
    switch ($status) {
        case 'pending':
            return 'Queued';
        case 'processing':
            return 'Processing';
        case 'paid':
            return 'Paid';
        case 'failed':
            return 'Failed';
        case 'blocked':
            return 'Blocked';
        default:
            return ucfirst($status);
    }
}

function status_key(string $status): string {
    return strtolower($status); // pending, processing, paid, failed, blocked
}

$items = [];
foreach ($rows as $r) {
    $items[] = [
        'id'            => (int) $r['id'],
        'invoiceShort'  => short_invoice_for_sidebar($r['invoice']),
        'invoiceFull'   => $r['invoice'],
        'status'        => status_label_short($r['status']),
        'statusKey'     => status_key($r['status']),
        'satsRequested' => (int) $r['sats_requested'],
        'satsSent'      => (int) $r['sats_sent'],
        'createdAt'     => $r['created_at'],
        'updatedAt'     => $r['updated_at'],
    ];
}

echo json_encode(['items' => $items]);
?>