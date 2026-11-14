<?php
// admin-session.php — one session setup for both admin pages (admin-7f39c2b5s.php and manual-payments/manual-payout.php).
//
// PHP's default session dies after ~24 minutes without a request, and on shared hosting other sites' cleanup can
// delete it even sooner. Here the admin stays logged in for $ADMIN_SESSION_HOURS (config, default 12) of
// inactivity; every page load or action restarts that clock. Logging out still ends it straight away.
//
// Call admin_session_start() after config.local.php is loaded and before any output.

function admin_session_start(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;

    $hours = (float)($GLOBALS['ADMIN_SESSION_HOURS'] ?? 12);
    $lifetime = (int)max(900, min($hours, 24 * 30) * 3600);   // between 15 minutes and 30 days

    // Our own session folder, outside the website (e.g. /home/<user>/tsf_admin_sessions on cPanel), so the server's
    // shared cleanup with its 24-minute rule can't remove the session. Falls back to PHP's default if not writable.
    $dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'tsf_admin_sessions';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    if (is_dir($dir) && is_writable($dir)) session_save_path($dir);

    ini_set('session.gc_maxlifetime', (string)$lifetime);
    ini_set('session.use_strict_mode', '1');
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    session_name('TSF_ADMIN');
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    // Idle limit enforced on the server too (the cookie alone could be kept around longer).
    $now = time();
    if (isset($_SESSION['last_seen']) && $now - (int)$_SESSION['last_seen'] > $lifetime) {
        $_SESSION = [];
    }
    $_SESSION['last_seen'] = $now;

    // Sliding expiry: push the cookie's expiry forward on every request.
    setcookie(session_name(), session_id(), [
        'expires'  => $now + $lifetime,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// Call right after a successful login: new session id, so an id seen before login is useless afterwards.
function admin_session_login(): void {
    session_regenerate_id(true);
    $_SESSION['is_admin'] = true;
    $_SESSION['last_seen'] = time();
}
