<?php
header('Content-Type: application/json');

$configFile = __DIR__ . '/config.local.php';
if (!file_exists($configFile)) {
    echo json_encode(['ok' => false]); exit;
}
require $configFile;

try {
    $dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4";
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Total sats actually sent (paid)
    $paidRow = $pdo->query("SELECT COALESCE(SUM(sats_sent),0) AS total_sent, COUNT(*) AS paid_count FROM faucet_claims WHERE status = 'paid'")->fetch();

    // Overall totals (all claims)
    $totRow = $pdo->query("SELECT COUNT(*) AS total_claims, COALESCE(SUM(sats_requested),0) AS total_requested FROM faucet_claims")->fetch();

    echo json_encode([
        'ok' => true,
        'total_sent' => (int)($paidRow['total_sent'] ?? 0),
        'paid_count' => (int)($paidRow['paid_count'] ?? 0),
        'total_claims' => (int)($totRow['total_claims'] ?? 0),
        'total_requested' => (int)($totRow['total_requested'] ?? 0),
    ]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false]);
}

?>
