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
$filterLimit = 20;
$filterSort = 'DESC';

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
$where = [];
$params = [];

if ($filterStatuses !== [] && count($filterStatuses) !== count($allowedStatuses)) {
    $placeholders = [];
    foreach ($filterStatuses as $idx => $status) {
        $key = ":fstatus{$idx}";
        $placeholders[] = $key;
        $params[$key] = $status;
    }
    $where[] = "status IN (" . implode(", ", $placeholders) . ")";
}

if ($filterLast24) {
    $where[] = "created_at >= (NOW() - INTERVAL 1 DAY)";
}

$sql = "
    SELECT id, invoice, ip_address, sats_requested, sats_sent, status, tx_reference, created_at, 
    updated_at, reason, receiver_domain, admin_status, pay_bolt11, claim_source
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




$processingCount = 0;
$stmt = $pdo->query(
    "SELECT COUNT(*) FROM faucet_claims WHERE status = 'processing'"
);
$processingCount  = (int)$stmt->fetchColumn();

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

    .filter-count {
      width: 70px;
      padding: 4px 6px;
      border-radius: 4px;
      border: 1px solid #ccc;
      font-size: 0.85rem;
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
      <div style="display:flex; gap:10px; align-items:center;">        
        <form method="post" class="logout-form">
          <button type="submit" name="logout" value="1">Logout</button>
        </form>
      </div>
    </header>

    <?php if ($updateMessage): ?>
      <div class="message"><?php echo htmlspecialchars($updateMessage, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

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
        <div style="display:flex; flex-wrap:wrap; gap:6px; align-items:center;">
          <span style="font-size:0.85rem; font-weight:600;">Status:</span>
          <?php foreach ($allowedStatuses as $st): ?>
            <label style="display:inline-flex; align-items:center; gap:4px; white-space:nowrap;">
              <input type="checkbox"
                     name="filter_status[]"
                     value="<?php echo $st; ?>"
                     class="filter-checkbox"
                     <?php if (in_array($st, $filterStatuses, true)) echo 'checked'; ?> />
              <?php echo ucfirst($st); ?>
            </label>
          <?php endforeach; ?>
        </div>
        <label>
          Show
          <input type="number" name="filter_limit" class="filter-count" min="1" max="500" step="1"
                 value="<?php echo (int)$filterLimit; ?>" />
          records
        </label>
        <label>
          Sort:
          <select name="filter_sort" class="filter-select">
            <option value="DESC" <?php if ($filterSort==='DESC') echo 'selected'; ?>>Newest first (DESC)</option>
            <option value="ASC"  <?php if ($filterSort==='ASC')  echo 'selected'; ?>>Oldest first (ASC)</option>
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
                  <div style="display:flex; flex-direction:column; gap:6px;">
                    <textarea class="lnbc-copy" readonly><?php echo htmlspecialchars($c['pay_bolt11'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                    <button type="button" class="copy-btn" onclick="copyInvoiceToClipboard(this,'lnbc-copy')">Copy pay invoice</button>
                    <div class="tiny">
                      Paste into your wallet to pay <?php echo (int)$c['sats_requested']; ?> sats.
                    </div>
                    <!-- QR Code: unique div id per claim row -->
                    <div class="qr-box">
                      <div id="qr-<?php echo (int)$c['id']; ?>"
                           data-invoice="<?php echo htmlspecialchars($c['pay_bolt11'], ENT_QUOTES, 'UTF-8'); ?>"></div>
                      <div class="qr-label">⚡ Scan to pay <?php echo (int)$c['sats_requested']; ?> sats</div>
                    </div>
                  </div>
                <?php else: ?>
                  <span class="tiny">—</span>
                <?php endif; ?>
              </td>
              <td>
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
                <span class="tiny"><?php echo $c['receiver_domain']; ?></span>
              </td>
              <td>
                <div>Req: <?php echo number_format((int)$c['sats_requested']); ?> sats</div>
                <div>Sent: <?php echo number_format((int)$c['sats_sent']); ?> sats</div>
              </td>
              <td>
                <form method="post" style="margin:0; display:flex; flex-direction:column; gap:3px;">
                  <input type="hidden" name="id" value="<?php echo (int)$c['id']; ?>" />
                  <input type="hidden" name="filter_status" value="<?php echo htmlspecialchars(implode(',', $filterStatuses), ENT_QUOTES, 'UTF-8'); ?>" />
                  <input type="hidden" name="filter_last24" value="<?php echo $filterLast24 ? '1' : '0'; ?>" />
                  <input type="hidden" name="filter_limit" value="<?php echo (int)$filterLimit; ?>" />
                  <input type="hidden" name="filter_sort" value="<?php echo htmlspecialchars($filterSort, ENT_QUOTES, 'UTF-8'); ?>" />

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

                  
                  <button type="submit" name="update_status" value="1" class="update-btn">Update</button>
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
        Showing latest <?php echo (int)$limit; ?> records with current filters.
      </div>
    </div>
  </div>
  <script src="../scripts/faucet.js" async defer></script>
  <script>
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
