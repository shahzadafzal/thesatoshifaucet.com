<?php
// admin-7f39c2b5.php
//
// Simple admin panel for The Satoshi Faucet.
// - Login with password stored in config.local.php
// - View & filter transactions
// - Change status, sats_sent, tx_reference

session_start();

$helpterFile = __DIR__ . '/../helper.php';

if (!file_exists($helpterFile)) {
    http_response_code(500);
    echo "Server helper file missing.";
    exit;
}
require $helpterFile;


// If admin file is in SAME folder as config.local.php:
$configFile = __DIR__ . '/../config.local.php';
// If it's in a subfolder like /admin-panel/, use this instead:
// $configFile = __DIR__ . '/../config.local.php';

if (!file_exists($configFile)) {
    http_response_code(500);
    echo "Server config missing.";
    exit;
}
require $configFile;

if (empty($DB_HOST) || empty($DB_NAME) || empty($DB_USER)) {
    http_response_code(500);
    echo "Database config missing.";
    exit;
}

if (empty($ADMIN_PASSWORD)) {
    http_response_code(500);
    echo "Admin password not set in config.local.php.";
    exit;
}

// --- DB helper ---
try {
    $dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4";
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo "Could not connect to DB.";
    exit;
}

$DEFAULT_REWARD_SATS = isset($REWARD_SATS) ? (int) $REWARD_SATS : 100;
if ($DEFAULT_REWARD_SATS <= 0) {
    $DEFAULT_REWARD_SATS = 100;
}

// --- Simple login logic ---
$isLoggedIn = !empty($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;
$loginError = '';

if (isset($_POST['admin_login'])) {
    $pass = $_POST['admin_password'] ?? '';
    if (hash_equals($ADMIN_PASSWORD, $pass)) {
        $_SESSION['is_admin'] = true;
        $isLoggedIn = true;
    } else {
        $loginError = 'Invalid password.';
    }
}

// --- Logout ---
if (isset($_POST['logout']) && $isLoggedIn) {
    $_SESSION['is_admin'] = false;
    session_destroy();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// --- If not logged in: show login form ---
if (!$isLoggedIn) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="UTF-8" />
      <title>Faucet Admin Login</title>
      <meta name="viewport" content="width=device-width, initial-scale=1" />
      <style>
        body {
          margin: 0;
          font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
          background: #f5f5f5;
          display: flex;
          align-items: center;
          justify-content: center;
          min-height: 100vh;
        }
        .login-card {
          background: #fff;
          border-radius: 8px;
          padding: 20px 24px;
          box-shadow: 0 2px 8px rgba(0,0,0,0.12);
          max-width: 360px;
          width: 100%;
        }
        h1 {
          margin-top: 0;
          font-size: 1.4rem;
          margin-bottom: 8px;
        }
        .subtitle {
          font-size: 0.9rem;
          color: #666;
          margin-bottom: 14px;
        }
        label {
          font-size: 0.9rem;
        }
        input[type="password"] {
          padding: 8px 10px;
          margin-top: 4px;
          margin-bottom: 12px;
          border-radius: 4px;
          border: 1px solid #ccc;
          font-size: 0.95rem;
        }
        button {
          padding: 8px 14px;
          border-radius: 4px;
          border: none;
          background: #c9302c;
          color: #fff;
          font-size: 0.9rem;
          cursor: pointer;
        }
        button:hover {
          background: #a72824;
        }
        .error {
          color: #b30000;
          font-size: 0.85rem;
          margin-bottom: 8px;
        }
      </style>
    </head>
    <body>
      <div class="login-card">
        <h1>Faucet Admin</h1>
        <div class="subtitle">Enter your secret admin password.</div>
        <?php if ($loginError): ?>
          <div class="error"><?php echo htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <form method="post">
          <label for="admin_password">Password</label>
          <input type="password" id="admin_password" name="admin_password" autocomplete="current-password" />
          <button type="submit" name="admin_login" value="1">Login</button>
        </form>
      </div>
    </body>
    </html>
    <?php
    exit;
}

// --- Admin logged in from here down ---

// Current filters (default: processing, last24 unchecked, 20 records)
$allowedStatuses = ['pending','processing','paid','failed','blocked'];
$filterStatuses = ['processing'];
$filterLast24 = false;
$filterLimit = 2;
$filterSort = 'ASC';

function normalizeStatusList($input, $allowedStatuses) {
    $statuses = [];
    if (is_array($input)) {
        $statuses = $input;
    } elseif (is_string($input) && trim($input) !== '') {
        $statuses = explode(',', $input);
    }
    $normalized = [];
    foreach ($statuses as $status) {
        $status = strtolower(trim($status));
        if (in_array($status, $allowedStatuses, true)) {
            $normalized[] = $status;
        }
    }
    return array_values(array_unique($normalized));
}

if (isset($_GET['filter_status'])) {
    $tmpStatuses = normalizeStatusList($_GET['filter_status'], $allowedStatuses);
    if (!empty($tmpStatuses)) {
        $filterStatuses = $tmpStatuses;
    }
} elseif (isset($_POST['filter_status'])) {
    $tmpStatuses = normalizeStatusList($_POST['filter_status'], $allowedStatuses);
    if (!empty($tmpStatuses)) {
        $filterStatuses = $tmpStatuses;
    }
}

if (isset($_GET['filter_last24'])) {
    $filterLast24 = ($_GET['filter_last24'] === '1');
} elseif (isset($_POST['filter_last24'])) {
    $filterLast24 = ($_POST['filter_last24'] === '1');
}

if (isset($_GET['filter_limit'])) {
    $filterLimit = max(1, min(500, (int)$_GET['filter_limit']));
} elseif (isset($_POST['filter_limit'])) {
    $filterLimit = max(1, min(500, (int)$_POST['filter_limit']));
}

// Sort order: only allow ASC or DESC (default DESC)
if (isset($_GET['filter_sort'])) {
  $tmpSort = strtoupper(trim($_GET['filter_sort']));
  if (in_array($tmpSort, ['ASC','DESC'], true)) {
    $filterSort = $tmpSort;
  }
} elseif (isset($_POST['filter_sort'])) {
  $tmpSort = strtoupper(trim($_POST['filter_sort']));
  if (in_array($tmpSort, ['ASC','DESC'], true)) {
    $filterSort = $tmpSort;
  }
}

// --- Advanced search: wildcard (IP / lnurl_username / domain-host) + date range + stat_n ---
$searchIp        = trim((string)($_GET['search_ip'] ?? $_POST['search_ip'] ?? ''));
$searchLnurlUser = trim((string)($_GET['search_lnurl_user'] ?? $_POST['search_lnurl_user'] ?? ''));
$searchDomain    = trim((string)($_GET['search_domain'] ?? $_POST['search_domain'] ?? ''));
$searchId        = trim((string)($_GET['search_id'] ?? $_POST['search_id'] ?? ''));
$searchAmount    = trim((string)($_GET['search_amount'] ?? $_POST['search_amount'] ?? ''));
$dateFrom        = trim((string)($_GET['date_from'] ?? $_POST['date_from'] ?? ''));
$dateTo          = trim((string)($_GET['date_to'] ?? $_POST['date_to'] ?? ''));

$statN = 1;
if (isset($_GET['stat_n'])) {
    $statN = max(1, min(500, (int)$_GET['stat_n']));
} elseif (isset($_POST['stat_n'])) {
    $statN = max(1, min(500, (int)$_POST['stat_n']));
}

// Translate a user-typed '*' wildcard (and optional leading '~' negation) into a safe,
// parameterized SQL LIKE pattern. Any literal backslash/percent/underscore the user typed
// is escaped first, so only an actual '*' becomes a wildcard. No '*' typed => pattern has
// no '%' => exact match. A leading '~' (e.g. "~112.110.*") flips the match to NOT LIKE.
// Returns null when the input (after stripping '~') is empty (caller adds no WHERE condition).
function parse_wildcard_search(string $input): ?array {
    $input = trim($input);
    if ($input === '') {
        return null;
    }
    $negate = false;
    if ($input[0] === '~') {
        $negate = true;
        $input = ltrim(substr($input, 1));
        if ($input === '') {
            return null;
        }
    }
    $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $input);
    $pattern = str_replace('*', '%', $escaped);
    return ['negate' => $negate, 'pattern' => $pattern];
}

// Parse a transaction-ID search: a single id ("3842") or an inclusive range ("3800-3810",
// spaces around the dash are OK). Returns null when empty/unrecognized (caller adds no
// WHERE condition in that case, so a stray typo doesn't accidentally clear the search).
function parse_id_search(string $input): ?array {
    $input = trim($input);
    if ($input === '') {
        return null;
    }
    if (preg_match('/^\d+$/', $input)) {
        return ['type' => 'single', 'id' => (int)$input];
    }
    if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $input, $m)) {
        $from = (int)$m[1];
        $to   = (int)$m[2];
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        return ['type' => 'range', 'from' => $from, 'to' => $to];
    }
    return null;
}

// Parse an amount-range search: a single value ("50" => exact 50), a bounded range
// ("10-50"), or an open-ended range ("10-" => >=10, "-50" => <=50). Reversed bounds are
// swapped (same convention as parse_id_search above). Returns null when empty/unrecognized.
function parse_amount_range(string $input): ?array {
    $input = trim($input);
    if ($input === '') {
        return null;
    }
    $input = preg_replace('/\s+/', '', $input);

    if (preg_match('/^(\d+)-(\d+)$/', $input, $m)) {
        $min = (int)$m[1];
        $max = (int)$m[2];
        if ($min > $max) {
            [$min, $max] = [$max, $min];
        }
        return ['min' => $min, 'max' => $max];
    }
    if (preg_match('/^(\d+)-$/', $input, $m)) {
        return ['min' => (int)$m[1], 'max' => null];
    }
    if (preg_match('/^-(\d+)$/', $input, $m)) {
        return ['min' => null, 'max' => (int)$m[1]];
    }
    if (preg_match('/^\d+$/', $input)) {
        $val = (int)$input;
        return ['min' => $val, 'max' => $val];
    }
    return null;
}

// Validate a datetime-local ("Y-m-d\TH:i") string; returns null if empty/invalid.
function parse_datetime_local(string $input): ?string {
    $input = trim($input);
    if ($input === '') {
        return null;
    }
    $dt = DateTime::createFromFormat('Y-m-d\TH:i', $input);
    if (!$dt || $dt->format('Y-m-d\TH:i') !== $input) {
        return null;
    }
    return $dt->format('Y-m-d H:i:00');
}

// Build the shared WHERE clause + bound params for the claims filters, reused by both
// the row-fetch query (with LIMIT) and the "current filter totals" aggregate query.
function build_claims_filter(
    array $filterStatuses,
    array $allowedStatuses,
    bool $filterLast24,
    string $dateFrom,
    string $dateTo,
    ?array $ipSearch,
    ?array $lnurlUserSearch,
    ?array $domainSearch,
    ?array $idSearch,
    ?array $amountSearch = null
): array {
    $where = [];
    $params = [];

    if ($amountSearch !== null) {
        // Matches EITHER the requested or the sent amount (either field falling in range
        // counts as a match).
        $conditions = [];
        if ($amountSearch['min'] !== null && $amountSearch['max'] !== null) {
            $conditions[] = "sats_requested BETWEEN :amt_min AND :amt_max";
            $conditions[] = "sats_sent BETWEEN :amt_min AND :amt_max";
            $params[':amt_min'] = $amountSearch['min'];
            $params[':amt_max'] = $amountSearch['max'];
        } elseif ($amountSearch['min'] !== null) {
            $conditions[] = "sats_requested >= :amt_min";
            $conditions[] = "sats_sent >= :amt_min";
            $params[':amt_min'] = $amountSearch['min'];
        } elseif ($amountSearch['max'] !== null) {
            $conditions[] = "sats_requested <= :amt_max";
            $conditions[] = "sats_sent <= :amt_max";
            $params[':amt_max'] = $amountSearch['max'];
        }
        if ($conditions) {
            $where[] = '(' . implode(' OR ', $conditions) . ')';
        }
    }

    if ($idSearch !== null) {
        if ($idSearch['type'] === 'single') {
            $where[] = "id = :search_id";
            $params[':search_id'] = $idSearch['id'];
        } else {
            $where[] = "id BETWEEN :search_id_from AND :search_id_to";
            $params[':search_id_from'] = $idSearch['from'];
            $params[':search_id_to'] = $idSearch['to'];
        }
    }

    if ($filterStatuses !== [] && count($filterStatuses) !== count($allowedStatuses)) {
        $placeholders = [];
        foreach ($filterStatuses as $idx => $status) {
            $key = ":fstatus{$idx}";
            $placeholders[] = $key;
            $params[$key] = $status;
        }
        $where[] = "status IN (" . implode(", ", $placeholders) . ")";
    }

    // Custom date range takes precedence over the "last 24h" checkbox when supplied.
    $from = parse_datetime_local($dateFrom);
    $to   = parse_datetime_local($dateTo);
    if ($from !== null && $to !== null && $from > $to) {
        // Reversed range: ignore both rather than erroring or guessing intent.
        $from = null;
        $to = null;
    }

    if ($from !== null || $to !== null) {
        if ($from !== null) {
            $where[] = "created_at >= :date_from";
            $params[':date_from'] = $from;
        }
        if ($to !== null) {
            $where[] = "created_at <= :date_to";
            $params[':date_to'] = $to;
        }
    } elseif ($filterLast24) {
        $where[] = "created_at >= (NOW() - INTERVAL 1 DAY)";
    }

    if ($ipSearch !== null) {
        $op = $ipSearch['negate'] ? 'NOT LIKE' : 'LIKE';
        $where[] = "ip_address {$op} :search_ip ESCAPE '\\\\'";
        $params[':search_ip'] = $ipSearch['pattern'];
    }

    if ($lnurlUserSearch !== null) {
        $op = $lnurlUserSearch['negate'] ? 'NOT LIKE' : 'LIKE';
        $where[] = "lnurl_username {$op} :search_lnurl_user ESCAPE '\\\\'";
        $params[':search_lnurl_user'] = $lnurlUserSearch['pattern'];
    }

    if ($domainSearch !== null) {
        $params[':search_domain'] = $domainSearch['pattern'];
        if ($domainSearch['negate']) {
            // Exclude rows where either field matches the pattern.
            $where[] = "(receiver_domain NOT LIKE :search_domain ESCAPE '\\\\' AND lnurl_host NOT LIKE :search_domain ESCAPE '\\\\')";
        } else {
            $where[] = "(receiver_domain LIKE :search_domain ESCAPE '\\\\' OR lnurl_host LIKE :search_domain ESCAPE '\\\\')";
        }
    }

    return ['where' => $where, 'params' => $params];
}

// --- Handle status + sats_sent + tx_reference update ---
$updateMessage = '';
if (isset($_POST['update_status'])) {
    $id      = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    $status  = $_POST['status'] ?? '';
    $satsSent = isset($_POST['sats_sent']) ? (int) $_POST['sats_sent'] : 0;
    $txRef   = $_POST['tx_reference'] ?? '';
    $reason  = $_POST['reason'] ?? '';

    if ($satsSent <= 0) {
        $satsSent = $DEFAULT_REWARD_SATS; // default if none set
    }

    if ($id > 0 && in_array($status, $allowedStatuses, true)) {
        $stmt = $pdo->prepare(
            "UPDATE faucet_claims
             SET status = :status,
                 sats_sent = :sats_sent,
                 tx_reference = :tx,
                 reason = :reason
             WHERE id = :id"
        );
        $stmt->execute([
            ':status'    => $status,
            ':sats_sent' => $satsSent,
            ':tx'        => $txRef,
            ':id'        => $id,
            ':reason'    => $reason,
        ]);
        $updateMessage = "Updated transaction #{$id} to status '{$status}' with sats_sent={$satsSent}.";
    }
}

// --- Fetch filtered claims ---
$limit = $filterLimit;

$ipSearch        = parse_wildcard_search($searchIp);
$lnurlUserSearch = parse_wildcard_search($searchLnurlUser);
$domainSearch    = parse_wildcard_search($searchDomain);
$idSearch        = parse_id_search($searchId);
$amountSearch    = parse_amount_range($searchAmount);

$filter = build_claims_filter(
    $filterStatuses,
    $allowedStatuses,
    $filterLast24,
    $dateFrom,
    $dateTo,
    $ipSearch,
    $lnurlUserSearch,
    $domainSearch,
    $idSearch,
    $amountSearch
);
$where = $filter['where'];
$params = $filter['params'];

$sql = "
    SELECT id, invoice, ip_address, sats_requested, sats_sent, status, tx_reference, created_at,
    updated_at, reason, receiver_domain, admin_status, pay_bolt11, claim_source, lnurl_host, lnurl_full_url, lnurl_username
    FROM faucet_claims
";

if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
}

$sql .= " ORDER BY created_at " . $filterSort . " LIMIT :lim";

$claimsStmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
    $claimsStmt->bindValue($k, $v);
}
$claimsStmt->bindValue(':lim', $limit, PDO::PARAM_INT);
$claimsStmt->execute();
$claims = $claimsStmt->fetchAll();

// Totals across ALL rows matching the current filters (not capped by $filterLimit).
$filterTotalSats = 0;
$filterTotalRows = 0;
$totalsSql = "SELECT COALESCE(SUM(sats_sent),0) AS total_sent, COUNT(*) AS row_count FROM faucet_claims";
if ($where) {
    $totalsSql .= " WHERE " . implode(" AND ", $where);
}
$totalsStmt = $pdo->prepare($totalsSql);
foreach ($params as $k => $v) {
    $totalsStmt->bindValue($k, $v);
}
$totalsStmt->execute();
$totalsRow = $totalsStmt->fetch();
if ($totalsRow) {
    $filterTotalSats = (int)$totalsRow['total_sent'];
    $filterTotalRows = (int)$totalsRow['row_count'];
}

$processingCount = 0;
$stmt = $pdo->query(
    "SELECT COUNT(*) FROM faucet_claims WHERE status = 'processing'"
);
$processingCount  = (int)$stmt->fetchColumn();

// --- Always-visible stats, independent of the admin's active filters ---

// (a) Paid in the last 24 hours.
$paid24hSats = 0;
$paid24hCount = 0;
$stmt = $pdo->query(
    "SELECT COALESCE(SUM(sats_sent),0) AS total_sent, COUNT(*) AS paid_count
     FROM faucet_claims
     WHERE status = 'paid' AND updated_at >= (NOW() - INTERVAL 1 DAY)"
);
$row = $stmt->fetch();
if ($row) {
    $paid24hSats = (int)$row['total_sent'];
    $paid24hCount = (int)$row['paid_count'];
}

// (b) Paid across the last N paid transactions (N = $statN, admin-adjustable).
$paidLastNSats = 0;
$paidLastNCount = 0;
$stmt = $pdo->prepare(
    "SELECT COALESCE(SUM(sats_sent),0) AS total_sent, COUNT(*) AS paid_count
     FROM (
         SELECT sats_sent FROM faucet_claims
         WHERE status = 'paid'
         ORDER BY updated_at DESC
         LIMIT :n
     ) AS t"
);
$stmt->bindValue(':n', $statN, PDO::PARAM_INT);
$stmt->execute();
$row = $stmt->fetch();
if ($row) {
    $paidLastNSats = (int)$row['total_sent'];
    $paidLastNCount = (int)$row['paid_count'];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Faucet Admin – Transactions</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <style>
    :root {
      --accent: #c9302c;
      --border-color: #ddd;
      --bg-light: #fafafa;
      --font-main: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    }

    * {
      box-sizing: border-box;
    }

    body {
      margin: 0;
      font-family: var(--font-main);
      background: #f5f5f5;
      color: #222;
      line-height: 1.5;
      overflow-x: hidden;
    }

    .page {
      max-width: 1100px;
      margin: 0 auto;
      padding: 20px 16px 32px;
    }

    header {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      margin-bottom: 16px;
    }

    .header-actions {
      display: flex;
      gap: 10px;
      align-items: center;
      flex-wrap: wrap;
    }

    h1 {
      font-size: 1.6rem;
      margin: 0;
    }

    .subtitle {
      font-size: 0.9rem;
      color: #666;
    }

    .logout-form {
      margin: 0;
    }

    .logout-form button {
      border: none;
      background: #666;
      color: #fff;
      font-size: 0.8rem;
      padding: 6px 10px;
      border-radius: 4px;
      cursor: pointer;
    }

    .logout-form button:hover {
      background: #444;
    }

    .message {
      background: #e6f7e6;
      border: 1px solid #9fd39f;
      color: #0a7f00;
      padding: 6px 10px;
      border-radius: 4px;
      font-size: 0.85rem;
      margin-bottom: 10px;
    }

    .panel {
      background: #fff;
      border-radius: 6px;
      border: 1px solid var(--border-color);
      padding: 10px;
      box-shadow: 0 1px 3px rgba(0,0,0,0.08);
      overflow-x: auto;
    }

    .filter-form {
      display: flex;
      flex-direction: column;
      gap: 8px;
      font-size: 0.8rem;
      margin-bottom: 10px;
    }

    .filter-form label {
      font-size: 0.8rem;
    }

    .filter-row {
      display: flex;
      flex-wrap: wrap;
      gap: 1px 5px;
      align-items: center;
    }

    .filter-row-title {
      font-size: 0.8rem;
      font-weight: 600;
    }

    .filter-status-group {
      display: flex;
      flex-wrap: wrap;
      gap: 4px 10px;
      align-items: center;
    }

    .filter-status-item {
      display: inline-flex;
      align-items: center;
      gap: 3px;
      white-space: nowrap;
      font-size: 0.8rem;
    }

    .filter-sep {
      width: 1px;
      align-self: stretch;
      background: var(--border-color);
    }

    .filter-inline {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      white-space: nowrap;
      font-size: 0.8rem;
      color: #555;
    }

    .filter-note {
      color: #888;
      font-style: italic;
    }

    .filter-select {
      font-size: 0.8rem;
      padding: 2px 4px;
    }

    .filter-checkbox {
      margin: 0;
    }

    .filter-count {
      width: 55px;
      padding: 2px 4px;
      border-radius: 4px;
      border: 1px solid #ccc;
      font-size: 0.8rem;
    }

    .filter-text {
      width: 140px;
      padding: 2px 5px;
      border-radius: 4px;
      border: 1px solid #ccc;
      font-size: 0.8rem;
      box-sizing: border-box;
    }

    .filter-datetime {
      padding: 2px 4px;
      border-radius: 4px;
      border: 1px solid #ccc;
      font-size: 0.78rem;
    }

    .stat-bar {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      margin-bottom: 12px;
    }

    .stat-card {
      background: #fff;
      border: 1px solid var(--border-color);
      border-radius: 6px;
      padding: 8px 14px;
      font-size: 0.85rem;
      box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    }

    .stat-card strong {
      color: #1a7f37;
    }

    .stat-card form {
      display: inline;
    }

    .stat-n-input {
      width: 55px;
      padding: 2px 4px;
      border-radius: 4px;
      border: 1px solid #ccc;
      font-size: 0.8rem;
    }

    .filter-button {
      padding: 4px 10px;
      font-size: 0.82rem;
      border-radius: 4px;
      border: 1px solid var(--accent);
      background: var(--accent);
      color: #fff;
      cursor: pointer;
    }

    .filter-button:hover {
      background: #a72824;
    }

    .filter-clear-btn {
      padding: 4px 10px;
      font-size: 0.82rem;
      border-radius: 4px;
      border: 1px solid #6a737d;
      background: transparent;
      color: inherit;
      cursor: pointer;
      text-decoration: none;
      display: inline-block;
      white-space: nowrap;
      line-height: 1.5;
    }

    .filter-clear-btn:hover {
      background: #6a737d;
      color: #fff;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      font-size: 0.85rem;
    }

    th, td {
      border: 1px solid var(--border-color);
      padding: 6px 8px;
      vertical-align: top;
      text-align: left;
    }

    th {
      background: #f4f4f4;
      white-space: nowrap;
    }

    tr:nth-child(even) td {
      background: #fcfcfc;
    }

    .invoice-full {
      font-family: "SFMono-Regular", Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
      font-size: 0.8rem;
      max-width: 340px;
      word-break: break-all;
    }

    /* IP / domain / lnurl_username / lnurl_full_url cell — cap width regardless of
       how long the domain, username, or callback URL is; wrap instead of stretching
       the whole table. */
    .contact-cell {
      max-width: 260px;
      word-break: break-all;
      overflow-wrap: anywhere;
      min-width: 100px;
    }

    textarea.invoice-copy {
      width: 100%;
      font-family: "SFMono-Regular", Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
      font-size: 0.78rem;
      height: 70px;
    }

    .status-select {
      font-size: 0.8rem;
      padding: 2px 4px;
    }

    .sats-input,
    .tx-input {
      font-size: 0.8rem;
      padding: 2px 4px;
      width: 100%;
      box-sizing: border-box;
      margin-top: 2px;
    }

    .sats-input {
      max-width: 90px;
    }

    .update-btn {
      font-size: 0.8rem;
      padding: 3px 6px;
      border-radius: 4px;
      border: none;
      cursor: pointer;
      background: var(--accent);
      color: #fff;
      margin-top: 4px;
    }

    .update-btn:hover:not(:disabled) {
      background: #a72824;
    }

    .update-btn:disabled {
      background: #888;
      cursor: not-allowed;
    }

    .tiny {
      font-size: 0.8rem;
      color: #666;
    }

    .tinyblock {
      display: block;
    }

    @media (max-width: 720px) {
      table {
        font-size: 0.8rem;
      }
    }

    @media (max-width: 600px) {
      .page {
        padding: 16px 12px 28px;
      }
      h1 {
        font-size: 1.3rem;
      }
      header {
        flex-direction: column;
        align-items: flex-start;
      }
      .header-actions {
        width: 100%;
      }
      .filter-text {
        width: 100%;
      }
      .stat-bar,
      .scheduler-bar {
        flex-direction: column;
        align-items: stretch;
      }
    }

    /* Row highlighting by status (admin table) */
    tr.pending td {
      background: #fff7e0 !important;   /* warm yellow */
    }

    tr.processing td {
      background: #e6f3ff !important;   /* light blue */
    }

    tr.paid td {
      background: #e6f7e6 !important;   /* light green */
    }

    tr.failed td {
      background: #ffe6e6 !important;   /* light red */
    }

    tr.blocked td {
      background: #f2f2f2 !important;   /* light gray */
    }

    /* Optional: stronger left border indicator */
    tr.pending td:first-child {
      border-left: 6px solid #f0cf80;
    }
    tr.processing td:first-child {
      border-left: 6px solid #7ab7ff;
    }
    tr.paid td:first-child {
      border-left: 6px solid #6cc26c;
    }
    tr.failed td:first-child {
      border-left: 6px solid #f0a3a3;
    }
    tr.blocked td:first-child {
      border-left: 6px solid #bbb;
    }

    /* Optional: make hover still visible */
    tbody tr:hover td {
      filter: brightness(0.98);
    }
    /* ---- Run Scheduler panel ---- */
    .scheduler-bar {
      display: flex;
      align-items: center;
      gap: 10px;
      flex-wrap: wrap;
      margin-bottom: 12px;
    }

    .run-scheduler-btn {
      padding: 7px 16px;
      font-size: 0.88rem;
      font-weight: 600;
      border-radius: 5px;
      border: none;
      background: #1a7f37;
      color: #fff;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      transition: background 0.15s;
    }
    .run-scheduler-btn:hover:not(:disabled) { background: #155d28; }
    .run-scheduler-btn:disabled { background: #888; cursor: not-allowed; }

    .btn-bg{
          background-color: #663399;
    }

    .batch-select {
      font-size: 0.85rem;
      padding: 4px 7px;
      border-radius: 4px;
      border: 1px solid #ccc;
    }

    .scheduler-output-wrap {
      display: none;
      margin-bottom: 12px;
    }
    .scheduler-output-wrap.visible { display: block; }

    .scheduler-output {
      background: #0d1117;
      color: #c9d1d9;
      font-family: "SFMono-Regular", Menlo, Monaco, Consolas, "Courier New", monospace;
      font-size: 0.8rem;
      line-height: 1.55;
      border-radius: 6px;
      padding: 12px 14px;
      max-height: 320px;
      overflow-y: auto;
      white-space: pre-wrap;
      word-break: break-all;
      border: 1px solid #30363d;
    }
    .scheduler-status {
      font-size: 0.8rem;
      color: #555;
      margin-top: 4px;
    }

    /* ---- Pay Invoice QR Code ---- */
    .qr-box {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 4px;
      margin-top: 6px;
      padding: 8px;
      background: #fff;
      border: 1px solid #ddd;
      border-radius: 6px;
      width: fit-content;
    }
    .qr-box canvas {
      display: block;
    }
    .qr-label {
      font-size: 0.72rem;
      color: #555;
      text-align: center;
    }
  </style>
  <!-- QR code generator (MIT licence, ~10 KB) -->
  <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
</head>
<body>
  <div class="page">
    <header>
      <div>
        <h1>Faucet Admin(<?php echo count($claims); ?>/<?php echo (int)$processingCount; ?>)</h1>
        <div class="subtitle">Manage transaction statuses, sats_sent, and invoices.</div>
      </div>
      <div class="header-actions">
        <form method="post" class="logout-form">
          <button type="submit" name="logout" value="1">Logout</button>
        </form>
      </div>
    </header>

    <?php if ($updateMessage): ?>
      <div class="message"><?php echo htmlspecialchars($updateMessage, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <!-- ===== Paid-sats stat bar (independent of the filters below) ===== -->
    <div class="stat-bar">
      <div class="stat-card">
        &#9889; Paid last 24h: <strong><?php echo number_format($paid24hSats); ?> sats</strong>
        across <?php echo number_format($paid24hCount); ?> claims
      </div>
      <div class="stat-card">
        Paid last <?php echo (int)$statN; ?> transactions: <strong><?php echo number_format($paidLastNSats); ?> sats</strong>
        (<?php echo number_format($paidLastNCount); ?> found &mdash; change "Paid stat N" below and Apply)
      </div>
    </div>

    <!-- ===== Run Scheduler Panel ===== -->
    <div class="scheduler-bar">
      <button id="run-scheduler-btn" class="run-scheduler-btn" onclick="runScheduler()">
        ▶ Run Scheduler
      </button>
      <label style="font-size:0.85rem;">
        Batch size:
        <select id="scheduler-batch" class="batch-select">
          <option value="1">1 claim</option>
          <option value="5" selected>5 claims</option>
          <option value="10">10 claims</option>
          <option value="25">25 claims</option>
          <option value="50">50 claims</option>
        </select>
      </label>
      <span id="scheduler-status" class="scheduler-status"></span>
      <button id="run-scheduler-iframe-btn" class="run-scheduler-btn btn-bg" onclick="runSchedulerIframe()">
        🪟 Run Scheduler in iframe
      </button>
    </div>

    <div id="scheduler-output-wrap" class="scheduler-output-wrap">
      <pre id="scheduler-output" class="scheduler-output">Running…</pre>
      <button id="run-refresh-btn" class="run-scheduler-btn btn-bg" onclick="location.reload()">🗘 Refresh</button>
    </div>

    <div id="scheduler-iframe-wrap" class="scheduler-output-wrap">
      <iframe
        id="scheduler-frame"
        style="width:100%;height:320px;border:1px solid #30363d;border-radius:6px;background:#0d1117;color:#fff;">
      </iframe>

      <button class="run-scheduler-btn btn-bg" onclick="location.reload()">🗘 Refresh</button>
    </div>

    <div class="panel">

      <!-- Filters -->
      <form method="get" class="filter-form">
        <!-- Row 1: status + result options + apply -->
        <div class="filter-row">
          <span class="filter-row-title">Status:</span>
          <div class="filter-status-group">
            <?php foreach ($allowedStatuses as $st): ?>
              <label class="filter-status-item">
                <input type="checkbox"
                       name="filter_status[]"
                       value="<?php echo $st; ?>"
                       class="filter-checkbox"
                       <?php if (in_array($st, $filterStatuses, true)) echo 'checked'; ?> />
                <?php echo ucfirst($st); ?>
              </label>
            <?php endforeach; ?>
          </div>
          <span class="filter-sep"></span>
          <label class="filter-inline">
            Show
            <input type="number" name="filter_limit" class="filter-count" min="1" max="500" step="1"
                   value="<?php echo (int)$filterLimit; ?>" />
          </label>
          <label class="filter-inline">
            Sort
            <select name="filter_sort" class="filter-select">              
              <option value="ASC"  <?php if ($filterSort==='ASC')  echo 'selected'; ?>>Oldest (ASC)</option>
              <option value="DESC" <?php if ($filterSort==='DESC') echo 'selected'; ?>>Newest (DESC)</option>
            </select>
          </label>
          <label class="filter-inline">
            <input type="checkbox" name="filter_last24" value="1" class="filter-checkbox"
                   <?php if ($filterLast24) echo 'checked'; ?> />
            Last 24h
          </label>
          <label class="filter-inline">
            Paid stat N
            <input type="number" name="stat_n" class="stat-n-input" min="1" max="500" step="1"
                   value="<?php echo (int)$statN; ?>" />
          </label>
          <button type="submit" class="filter-button">Apply</button>
          <a href="?" class="filter-clear-btn">Clear filters</a>
        </div>

        <!-- Row 2: wildcard/exclude search fields -->
        <div class="filter-row">
          <label class="filter-inline">
            ID
            <input type="text" name="search_id" class="filter-text" placeholder="3842 or 3800-3810"
                   value="<?php echo htmlspecialchars($searchId, ENT_QUOTES, 'UTF-8'); ?>" />
          </label>
          <label class="filter-inline">
            IP
            <input type="text" name="search_ip" class="filter-text" placeholder="91.108.* / ~112.110.*"
                   value="<?php echo htmlspecialchars($searchIp, ENT_QUOTES, 'UTF-8'); ?>" />
          </label>
          <label class="filter-inline">
            LNURL user
            <input type="text" name="search_lnurl_user" class="filter-text" placeholder="*satoshi* / ~*satoshi*"
                   value="<?php echo htmlspecialchars($searchLnurlUser, ENT_QUOTES, 'UTF-8'); ?>" />
          </label>
          <label class="filter-inline">
            Domain/host
            <input type="text" name="search_domain" class="filter-text" placeholder="*.wallet.com / ~*.wallet.com"
                   value="<?php echo htmlspecialchars($searchDomain, ENT_QUOTES, 'UTF-8'); ?>" />
          </label>
          <label class="filter-inline">
            Amount (sats)
            <input type="text" name="search_amount" class="filter-text" placeholder="10-50, 10-, -50, or 50"
                   value="<?php echo htmlspecialchars($searchAmount, ENT_QUOTES, 'UTF-8'); ?>" />
          </label>
          <span style="display:none;" class="tiny filter-note">(ID: single or range e.g. 3800-3810 &middot; * = wildcard, ~ = exclude &middot; Amount matches requested OR sent)</span>
        </div>

        <!-- Row 3: date range -->
        <div class="filter-row">
          <label class="filter-inline">
            From
            <input type="datetime-local" name="date_from" class="filter-datetime"
                   value="<?php echo htmlspecialchars($dateFrom, ENT_QUOTES, 'UTF-8'); ?>" />
          </label>
          <label class="filter-inline">
            To
            <input type="datetime-local" name="date_to" class="filter-datetime"
                   value="<?php echo htmlspecialchars($dateTo, ENT_QUOTES, 'UTF-8'); ?>" />
          </label>
          <span class="tiny filter-note">(server time; overrides "Last 24h" when set)</span>
        </div>
      </form>

      <table>
        <thead>
          <tr>
            <th>ID</th>
            <th>Invoice (full)</th>
            <th>IP</th>
            <th>Sats req / sent</th>
            <th>Status / update</th>
            <th>TX ref</th>
            <th>Created</th>
            <th>Updated</th>
            <th>Admin Status</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$claims): ?>
          <tr><td colspan="8">No transactions found for this filter.</td></tr>
        <?php else: ?>
          <?php foreach ($claims as $c): ?>
            <tr class="<?php echo $c['status']; ?>">
              <td><?php echo (int)$c['id']; ?></td>
              <td class="invoice-full">
                <div style="display:flex; flex-direction:column; gap:4px;">
                    <textarea class="invoice-copy" readonly><?php echo htmlspecialchars($c['invoice'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                    <button type="button"
                            class="copy-btn"
                            onclick="copyInvoiceToClipboard(this,'invoice-copy')">
                    Copy invoice
                    </button>
                </div>

                <?php if (!empty($c['pay_bolt11'])): ?>

                  
                  <?php
                    $invoiceAmount = '';

                    try {
                        $decoded = decodeBolt11Invoice($c['pay_bolt11']);
                        $invoiceAmount = $decoded['has_amount']
                            ? number_format($decoded['amount_sats']) . ' sats'
                            : 'Unknown amount';
                    } catch (Throwable $e) {
                        $invoiceAmount = 'Unknown amount';
                    }
                  ?>

                  <div style="display:flex; flex-direction:column; gap:6px;">
                    <textarea class="lnbc-copy" readonly><?php echo htmlspecialchars($c['pay_bolt11'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                    <button type="button" class="copy-btn" onclick="copyInvoiceToClipboard(this,'lnbc-copy')">Copy pay invoice</button>
                    <div class="tiny">
                      Paste into your wallet to pay  <?= htmlspecialchars($invoiceAmount) ?>
                    </div>
                    <!-- QR Code: unique div id per claim row -->
                    <div class="qr-box">
                      <div id="qr-<?php echo (int)$c['id']; ?>"
                           data-invoice="<?php echo htmlspecialchars($c['pay_bolt11'], ENT_QUOTES, 'UTF-8'); ?>"></div>
                      
                      <div class="qr-label">
                          ⚡ Scan to pay <?= htmlspecialchars($invoiceAmount) ?>
                      </div>
                    </div>
                  </div>
                <?php else: ?>
                  <span class="tiny">—</span>
                <?php endif; ?>
              </td>
              <td class="contact-cell">
                <?php if ($c['claim_source'] == "scan") {?>
                <span title="Scan QR Code">📷</span>
                <?php } ?>

                <?php if ($c['claim_source'] == "upload") {?>
                <span title="Upload QR Code">🖼️</span>
                <?php } ?>

                <?php if ($c['claim_source'] == "paste") {?>
                <span title="Copy Paste">✍</span>
                <?php } ?>

                <span><?php echo htmlspecialchars($c['ip_address'], ENT_QUOTES, 'UTF-8'); ?></span>
                <span class="tiny tinyblock"><?php echo $c['receiver_domain']; ?> (<?php echo $c['lnurl_username']; ?>)</span>
                <span class="tiny tinyblock"><a target="_blank" href="<?php echo htmlspecialchars($c['lnurl_full_url'], ENT_QUOTES, 'UTF-8'); ?>" rel="noopener noreferrer"><?php echo htmlspecialchars($c['lnurl_full_url'], ENT_QUOTES, 'UTF-8'); ?></a></span>
              </td>
              <td>
                <div>Req: <?php echo number_format((int)$c['sats_requested']); ?> sats</div>
                <div>Sent: <?php echo number_format((int)$c['sats_sent']); ?> sats</div>
              </td>
              <td>
                <form method="post" style="margin:0; display:flex; flex-direction:column; gap:3px;" onsubmit="return preventDoubleSubmit(this, '.update-btn', 'Updating…');">
                  <input type="hidden" name="id" value="<?php echo (int)$c['id']; ?>" />
                  <!-- carries the "update_status" flag itself so disabling the submit button
                       on click can't drop it from the posted form data (disabled controls are
                       excluded from form submission). -->
                  <input type="hidden" name="update_status" value="1" />
                  <input type="hidden" name="filter_status" value="<?php echo htmlspecialchars(implode(',', $filterStatuses), ENT_QUOTES, 'UTF-8'); ?>" />
                  <input type="hidden" name="filter_last24" value="<?php echo $filterLast24 ? '1' : '0'; ?>" />
                  <input type="hidden" name="filter_limit" value="<?php echo (int)$filterLimit; ?>" />
                  <input type="hidden" name="filter_sort" value="<?php echo htmlspecialchars($filterSort, ENT_QUOTES, 'UTF-8'); ?>" />
                  <input type="hidden" name="search_id" value="<?php echo htmlspecialchars($searchId, ENT_QUOTES, 'UTF-8'); ?>" />
                  <input type="hidden" name="search_ip" value="<?php echo htmlspecialchars($searchIp, ENT_QUOTES, 'UTF-8'); ?>" />
                  <input type="hidden" name="search_lnurl_user" value="<?php echo htmlspecialchars($searchLnurlUser, ENT_QUOTES, 'UTF-8'); ?>" />
                  <input type="hidden" name="search_domain" value="<?php echo htmlspecialchars($searchDomain, ENT_QUOTES, 'UTF-8'); ?>" />
                  <input type="hidden" name="search_amount" value="<?php echo htmlspecialchars($searchAmount, ENT_QUOTES, 'UTF-8'); ?>" />
                  <input type="hidden" name="date_from" value="<?php echo htmlspecialchars($dateFrom, ENT_QUOTES, 'UTF-8'); ?>" />
                  <input type="hidden" name="date_to" value="<?php echo htmlspecialchars($dateTo, ENT_QUOTES, 'UTF-8'); ?>" />
                  <input type="hidden" name="stat_n" value="<?php echo (int)$statN; ?>" />

                  <label class="tiny">Status:</label><small><?php echo strtoupper($c['status']); ?></small>

                  <label class="tiny">Sats sent:</label>
                  <input type="number"
                         name="sats_sent"
                         class="sats-input"
                         min="0"
                         step="1"
                         value="<?php echo ($c['sats_sent'] > 0) ? (int)$c['sats_sent'] : $c['sats_requested']; ?>" />

                  <label class="tiny">TX ref:</label>
                  <input type="text"
                         name="tx_reference"
                         class="tx-input"
                         value="<?php echo htmlspecialchars($c['tx_reference'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" />
                  <span class="reason"><strong>Reason:</strong> <?php echo $c['reason']?></span>
                  
                  <select name="status" class="status-select">
                    <?php foreach ($allowedStatuses as $st): ?>
                      <option value="<?php echo $st; ?>" <?php if ('paid'===$st) echo 'selected'; ?>>
                        <?php echo ucfirst($st); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>

                  <input type="text"
                         name="reason"
                         class="tx-input"
                         value="<?php echo $c['reason']; ?>" />

                  
                  <button type="submit" class="update-btn">Update</button>
                </form>
              </td>
              <td>
                <?php
                $txRef = htmlspecialchars($c['tx_reference'] ?? '', ENT_QUOTES, 'UTF-8');

                if (mb_strlen($txRef) > 30) {
                ?>
                    <span
                        title="<?php echo $txRef; ?>"
                        style="cursor:pointer"
                        onclick="this.hidden=true;this.nextElementSibling.hidden=false;this.nextElementSibling.focus();">
                        <?php echo mb_substr($txRef, 0, 30) . '...'; ?>
                    </span>

                    <textarea
                        hidden
                        readonly
                        onclick="this.select()"
                        style="width:250px;height:60px;"><?php echo $txRef; ?></textarea>
                <?php
                } else {
                    echo $txRef;
                }
                ?>
              </td>
              <td><?php echo htmlspecialchars($c['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
              <td><?php echo htmlspecialchars($c['updated_at'], ENT_QUOTES, 'UTF-8'); ?></td>
              <td><?php echo htmlspecialchars($c['admin_status'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
      <div class="tiny" style="margin-top:6px;">
        Showing latest <?php echo (int)$limit; ?> of <?php echo number_format($filterTotalRows); ?> matching records &mdash;
        <strong><?php echo number_format($filterTotalSats); ?> sats</strong> total (sats_sent) for this filter.
      </div>
    </div>
  </div>
  <script src="../scripts/faucet.js" async defer></script>
  <script>
    // Guards a normal (non-AJAX) form submit against double-clicks / accidental double
    // presses: disables the submit button the instant the form is submitted so a second
    // click (or Enter) can't fire a second POST while the page navigates/reloads. Since
    // this is a real page submit (not fetch), the button re-enables itself automatically
    // when the reloaded page renders fresh, unclicked buttons — no manual re-enable needed.
    function preventDoubleSubmit(form, buttonSelector, busyText) {
      const btn = form.querySelector(buttonSelector);
      if (!btn) return true;
      if (btn.disabled) return false; // already submitting — ignore the repeat click/Enter
      btn.disabled = true;
      if (busyText) btn.textContent = busyText;
      return true; // let this (first) submission proceed
    }

    function runScheduler() {
      const btn    = document.getElementById('run-scheduler-btn');
      const output = document.getElementById('scheduler-output');
      const wrap   = document.getElementById('scheduler-output-wrap');
      const status = document.getElementById('scheduler-status');
      const batch  = document.getElementById('scheduler-batch').value;

      btn.disabled    = true;
      btn.textContent = '⏳ Running…';
      output.textContent = 'Starting scheduler…';
      wrap.classList.add('visible');
      status.textContent = '';

      const fd = new FormData();
      fd.append('batch', batch);

      fetch('run_scheduler.php', { method: 'POST', body: fd })
        .then(r => r.text())
        .then(text => {
          output.textContent = text;
          output.scrollTop   = output.scrollHeight;
          status.textContent = 'Finished at ' + new Date().toLocaleTimeString();
          // Refresh the page so transaction table shows updated statuses
          //setTimeout(() => location.reload(), 1500);
        })
        .catch(err => {
          output.textContent = 'Network error: ' + err;
          status.textContent = 'Failed.';
        })
        .finally(() => {
          btn.disabled    = false;
          btn.textContent = '▶ Run Scheduler';
        });
    }

    // --- Generate QR codes for all pay invoices on page load ---
    document.addEventListener('DOMContentLoaded', function () {
      document.querySelectorAll('[id^="qr-"]').forEach(function (el) {
        var invoice = el.getAttribute('data-invoice');
        if (!invoice) return;
        new QRCode(el, {
          text: invoice,
          width: 180,
          height: 180,
          colorDark: '#000000',
          colorLight: '#ffffff',
          correctLevel: QRCode.CorrectLevel.M
        });
      });
    });
  </script>
  <script>
    function runSchedulerIframe() {
      const btn    = document.getElementById('run-scheduler-iframe-btn');
      const frame  = document.getElementById('scheduler-frame');
      const wrap   = document.getElementById('scheduler-iframe-wrap');
      const status = document.getElementById('scheduler-status');
      const batch  = document.getElementById('scheduler-batch').value;

      btn.disabled = true;
      btn.textContent = '⏳ Running iframe…';
      wrap.classList.add('visible');
      status.textContent = 'Running scheduler via iframe...';

      frame.src = 'scheduler_process.php?admin_run=1&batch='
        + encodeURIComponent(batch)
        + '&t=' + Date.now();

      frame.onload = function () {
        btn.disabled = false;
        btn.textContent = '🪟 Run Scheduler in iframe';
        status.textContent = 'Iframe finished at ' + new Date().toLocaleTimeString();
      };
    }
  </script>
</body>
</html>
