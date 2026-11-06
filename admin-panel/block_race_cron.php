<?php
/**
 * block_race_cron.php
 *
 * Grades Block Race "play for sats" entries once their block is found, creates the faucet claims for
 * entries that have an LNURL, expires unclaimed ones, then fires the normal scheduler for the new claims.
 * Players' browsers also trigger grading when they check their result; this job makes it happen even
 * if they closed the tab.
 *
 * cPanel cron (every minute):
 *   * * * * * php /home/<user>/public_html/admin-panel/block_race_cron.php >> /home/<user>/block_race_cron.log 2>&1
 */
if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/plain');
    http_response_code(403);
    echo "Forbidden: CLI access only.\n";
    exit(1);
}

$configFile = __DIR__ . '/../config.local.php';
if (!file_exists($configFile)) {
    echo "ERROR: config.local.php missing\n";
    exit(1);
}
require $configFile;
require __DIR__ . '/../block-race-lib.php';

date_default_timezone_set('UTC');

if (!br_settings()['enabled']) exit(0);

try {
    $pdo = br_pdo();
    $created = br_process_all($pdo);
    if ($created > 0) {
        echo '[' . date('Y-m-d H:i:s') . "] Block Race: {$created} claim(s) created\n";
        br_trigger_scheduler($created);
    }
} catch (Throwable $e) {
    echo '[' . date('Y-m-d H:i:s') . '] Block Race cron error: ' . $e->getMessage() . "\n";
    exit(1);
}
