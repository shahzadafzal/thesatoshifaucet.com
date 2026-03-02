<?php
/**
 * run_scheduler.php
 *
 * Admin-only AJAX endpoint that fires scheduler_process.php via PHP CLI
 * and returns its output as plain text so the admin panel can display it.
 *
 * Access is protected by the same session/password as the admin panel.
 */

session_start();
header('Content-Type: text/plain; charset=utf-8');

// --- Auth guard: must be logged-in admin ---
if (empty($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    http_response_code(403);
    echo "403 Forbidden – not logged in as admin.";
    exit;
}

// --- Only allow POST to prevent accidental GETs / browser pre-fetches ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo "405 Method Not Allowed – use POST.";
    exit;
}

// --- Load config for PHP CLI path and scheduler path ---
$configFile = __DIR__ . '/../config.local.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    echo "ERROR: config.local.php not found.";
    exit;
}
require $configFile;

$phpBin      = $PHP_CLI_PATH ?? 'php';
$scheduler   = $SCHEDULER_FILE ?? (__DIR__ . '/scheduler_process.php');
$batchSize   = max(1, min(50, (int)($_POST['batch'] ?? 5)));

if (!file_exists($scheduler)) {
    http_response_code(500);
    echo "ERROR: scheduler_process.php not found at:\n" . $scheduler;
    exit;
}

// --- Build and run the command synchronously so we can capture output ---
$cmd = escapeshellarg($phpBin)
     . ' ' . escapeshellarg($scheduler)
     . ' --batch=' . $batchSize
     . ' 2>&1';   // merge stderr into stdout

echo "=== Scheduler triggered by admin at " . date('Y-m-d H:i:s') . " UTC ===\n";
echo "Command: php scheduler_process.php --batch={$batchSize}\n";
echo str_repeat('-', 60) . "\n";
flush();

// Execute and stream the output back
$output = [];
$exitCode = 0;
exec($cmd, $output, $exitCode);

echo implode("\n", $output);
echo "\n" . str_repeat('-', 60) . "\n";
echo "Exit code: {$exitCode}\n";
echo ($exitCode === 0) ? "✅ Completed successfully.\n" : "⚠️  Completed with errors.\n";
