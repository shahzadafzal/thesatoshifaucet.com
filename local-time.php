<?php
// local-time.php — print database times so assets/localtime.js can show them in the visitor's own time zone.
//
// All time columns are MySQL TIMESTAMPs (stored as UTC). Call db_use_utc($pdo) right after connecting so MySQL
// hands them back in UTC whatever the server's own zone is (XAMPP on Windows uses the PC's zone, for example).

function db_use_utc(PDO $pdo): void {
    $pdo->exec("SET time_zone = '+00:00'");
}

// <time> element with the UTC value; shows "YYYY-MM-DD HH:MM:SS UTC" until the script converts it.
function utc_time(?string $value, string $empty = '—'): string {
    $value = trim((string)$value);
    if ($value === '' || $value === '0000-00-00 00:00:00') return htmlspecialchars($empty, ENT_QUOTES, 'UTF-8');
    $ts = strtotime($value . ' UTC');
    if ($ts === false) return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $iso = gmdate('Y-m-d\TH:i:s\Z', $ts);
    return '<time datetime="' . $iso . '" data-utc>' . gmdate('Y-m-d H:i:s', $ts) . ' UTC</time>';
}
