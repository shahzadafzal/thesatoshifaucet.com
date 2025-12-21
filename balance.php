<?php
header('Content-Type: application/json');

$configFile = __DIR__ . '/config.local.php';
if (!file_exists($configFile)) {
    echo json_encode(['ok' => false, 'balance' => 0]);
    exit;
}
require $configFile;

try {
    $dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4";
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $row = $pdo->query("SELECT balance_sats, updated_at FROM faucet_balance WHERE id=1")->fetch();
    $balance = $row ? (int)$row['balance_sats'] : 0;

    echo json_encode(['ok' => true, 'balance' => $balance, 'updated_at' => $row['updated_at'] ?? null]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'balance' => 0]);
}
?>