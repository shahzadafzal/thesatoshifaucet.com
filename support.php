<?php
// Dynamic support page that pulls donation addresses from config.local.php
// and renders the existing support.html content with replaced addresses.

$config = __DIR__ . '/config.local.php';
if (!file_exists($config)) {
    http_response_code(500);
    echo "Server configuration missing.";
    exit;
}
require $config;

$html = file_get_contents(__DIR__ . '/support.html');
if ($html === false) {
    http_response_code(500);
    echo "Support template missing.";
    exit;
}

// Replace the static LNURL and BTC addresses if present (simple string replace)
$html = str_replace('lnurl1dp68gurn8ghj7ampd3kx2ar0veekzar0wd5xjtnrdakj7tnhv4kxctttdehhwm30d3h82unvwqhk5mmzd3jhxumvdamx2desxq5etrcc', htmlspecialchars($DONATION_LNURL, ENT_QUOTES, 'UTF-8'), $html);
$html = str_replace('bc1q3edrccaqc8dkm76jkjjp7cwdg57qhnx8e5thkg', htmlspecialchars($DONATION_BTC, ENT_QUOTES, 'UTF-8'), $html);

echo $html;

?>
