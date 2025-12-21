<?php
// admin-7f39c2b5.php
//
// Simple admin panel for The Satoshi Faucet.
// - Login with password stored in config.local.php
// - View & filter transactions
// - Change status, sats_sent, tx_reference

session_start();

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

// Current filters (default: pending, last24 unchecked)
$allowedStatuses = ['pending','processing','paid','failed','blocked'];
$filterStatus = 'pending';
$filterLast24 = false;

// Prefer GET for filters, but allow POST hidden fields so they persist after updates
if (isset($_GET['filter_status'])) {
    $tmp = strtolower($_GET['filter_status']);
    if ($tmp === 'all' || in_array($tmp, $allowedStatuses, true)) {
        $filterStatus = $tmp;
    }
} elseif (isset($_POST['filter_status'])) {
    $tmp = strtolower($_POST['filter_status']);
    if ($tmp === 'all' || in_array($tmp, $allowedStatuses, true)) {
        $filterStatus = $tmp;
    }
}

if (isset($_GET['filter_last24'])) {
    $filterLast24 = ($_GET['filter_last24'] === '1');
} elseif (isset($_POST['filter_last24'])) {
    $filterLast24 = ($_POST['filter_last24'] === '1');
}

// --- Handle status + sats_sent + tx_reference update ---
$updateMessage = '';
if (isset($_POST['update_status'])) {
    $id     = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    $status = $_POST['status'] ?? '';
    $satsSent = isset($_POST['sats_sent']) ? (int) $_POST['sats_sent'] : 0;
    $txRef  = $_POST['tx_reference'] ?? '';

    if ($satsSent <= 0) {
        $satsSent = 100; // default if none set
    }

    if ($id > 0 && (in_array($status, $allowedStatuses, true))) {
        $stmt = $pdo->prepare("
            UPDATE faucet_claims
            SET status = :status,
                sats_sent = :sats_sent,
                tx_reference = :tx
            WHERE id = :id
        ");
        $stmt->execute([
            ':status'    => $status,
            ':sats_sent' => $satsSent,
            ':tx'        => $txRef,
            ':id'        => $id,
        ]);
        $updateMessage = "Updated transaction #{$id} to status '{$status}' with sats_sent={$satsSent}.";
    }
}

// --- Fetch filtered claims ---
$limit = 50;
$where = [];
$params = [];

if ($filterStatus !== 'all' && in_array($filterStatus, $allowedStatuses, true)) {
    $where[] = "status = :fstatus";
    $params[':fstatus'] = $filterStatus;
}

if ($filterLast24) {
    $where[] = "created_at >= (NOW() - INTERVAL 1 DAY)";
}

$sql = "
    SELECT id, invoice, ip_address, sats_requested, sats_sent, status, tx_reference, created_at, updated_at, reason, receiver_domain
    FROM faucet_claims
";

if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
}

$sql .= " ORDER BY created_at DESC LIMIT :lim";

$claimsStmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
    $claimsStmt->bindValue($k, $v);
}
$claimsStmt->bindValue(':lim', $limit, PDO::PARAM_INT);
$claimsStmt->execute();
$claims = $claimsStmt->fetchAll();

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
    }

    .page {
      max-width: 1100px;
      margin: 0 auto;
      padding: 20px 16px 32px;
    }

    header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 16px;
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
      flex-wrap: wrap;
      gap: 8px;
      align-items: center;
      font-size: 0.85rem;
      margin-bottom: 10px;
    }

    .filter-form label {
      font-size: 0.85rem;
    }

    .filter-select {
      font-size: 0.85rem;
      padding: 3px 6px;
    }

    .filter-checkbox {
      margin-left: 4px;
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

    .update-btn:hover {
      background: #a72824;
    }

    .tiny {
      font-size: 0.8rem;
      color: #666;
    }

    @media (max-width: 720px) {
      table {
        font-size: 0.8rem;
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
  </style>
</head>
<body>
  <div class="page">
    <header>
      <div>
        <h1>Faucet Admin</h1>
        <div class="subtitle">Manage transaction statuses, sats_sent, and invoices.</div>
      </div>
      <form method="post" class="logout-form">
        <button type="submit" name="logout" value="1">Logout</button>
      </form>
    </header>

    <?php if ($updateMessage): ?>
      <div class="message"><?php echo htmlspecialchars($updateMessage, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <div class="panel">

      <!-- Filters -->
      <form method="get" class="filter-form">
        <label>
          Status:
          <select name="filter_status" class="filter-select">
            <option value="pending"   <?php if ($filterStatus==='pending')   echo 'selected'; ?>>Pending only</option>
            <option value="processing"<?php if ($filterStatus==='processing')echo 'selected'; ?>>Processing</option>
            <option value="paid"      <?php if ($filterStatus==='paid')      echo 'selected'; ?>>Paid</option>
            <option value="failed"    <?php if ($filterStatus==='failed')    echo 'selected'; ?>>Failed</option>
            <option value="blocked"   <?php if ($filterStatus==='blocked')   echo 'selected'; ?>>Blocked</option>
            <option value="all"       <?php if ($filterStatus==='all')       echo 'selected'; ?>>All statuses</option>
          </select>
        </label>
        <label>
          <input type="checkbox" name="filter_last24" value="1" class="filter-checkbox"
                 <?php if ($filterLast24) echo 'checked'; ?> />
          Only last 24h
        </label>
        <button type="submit" class="filter-button">Apply</button>
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
                            onclick="copyInvoiceToClipboard(this)">
                    Copy invoice
                    </button>
                </div>
              </td>
              <td><?php echo htmlspecialchars($c['ip_address'], ENT_QUOTES, 'UTF-8'); ?></td>
              <td>
                <div>Req: <?php echo number_format((int)$c['sats_requested']); ?> sats</div>
                <div>Sent: <?php echo number_format((int)$c['sats_sent']); ?> sats</div>
              </td>
              <td>
                <form method="post" style="margin:0; display:flex; flex-direction:column; gap:3px;">
                  <input type="hidden" name="id" value="<?php echo (int)$c['id']; ?>" />
                  <input type="hidden" name="filter_status" value="<?php echo htmlspecialchars($filterStatus, ENT_QUOTES, 'UTF-8'); ?>" />
                  <input type="hidden" name="filter_last24" value="<?php echo $filterLast24 ? '1' : '0'; ?>" />

                  <select name="status" class="status-select">
                    <?php foreach ($allowedStatuses as $st): ?>
                      <option value="<?php echo $st; ?>" <?php if ($c['status']===$st) echo 'selected'; ?>>
                        <?php echo ucfirst($st); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>

                  <label class="tiny">Sats sent:</label>
                  <input type="number"
                         name="sats_sent"
                         class="sats-input"
                         min="0"
                         step="1"
                         value="<?php echo ($c['sats_sent'] > 0) ? (int)$c['sats_sent'] : 100; ?>" />

                  <label class="tiny">TX ref:</label>
                  <input type="text"
                         name="tx_reference"
                         class="tx-input"
                         value="<?php echo htmlspecialchars($c['tx_reference'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" />
                  <span class="reason"><strong>Reason:</strong> <?php echo $c['reason']?></span>
                  <button type="submit" name="update_status" value="1" class="update-btn">Update</button>
                </form>
              </td>
              <td><?php echo htmlspecialchars($c['tx_reference'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
              <td><?php echo htmlspecialchars($c['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
              <td><?php echo htmlspecialchars($c['updated_at'], ENT_QUOTES, 'UTF-8'); ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
      <div class="tiny" style="margin-top:6px;">
        Showing latest <?php echo (int)$limit; ?> records with current filters.
      </div>
    </div>
  </div>
  <script src="../scripts/faucet.js" async defer></script>
</body>
</html>
