<?php
// admin-7f39c2b5.php
//
// Simple admin panel for The Satoshi Faucet.
// - Login with password stored in config.local.php
// - View recent transactions
// - Change status of a transaction

session_start();

$configFile = __DIR__ . '/../config.local.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    echo "Server config missing. {$configFile}";
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

// --- If not logged in: show login form and exit ---
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

// --- At this point: admin is logged in ---

// Handle status update
$updateMessage = '';
if (isset($_POST['update_status'])) {
    $id     = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    $status = $_POST['status'] ?? '';
    $allowedStatuses = ['pending','processing','paid','failed','blocked'];

    if ($id > 0 && in_array($status, $allowedStatuses, true)) {
        $stmt = $pdo->prepare("UPDATE faucet_claims SET status = :status WHERE id = :id");
        $stmt->execute([':status' => $status, ':id' => $id]);
        $updateMessage = "Updated transaction #{$id} to status '{$status}'.";
    }
}

// Fetch recent claims (you can adjust the LIMIT)
$limit = 50;
$claimsStmt = $pdo->prepare("
    SELECT id, invoice, ip_address, sats_requested, sats_sent, status, tx_reference, created_at, updated_at
    FROM faucet_claims
    ORDER BY created_at DESC
    LIMIT :lim
");
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

    .status-select {
      font-size: 0.8rem;
      padding: 2px 4px;
    }

    .update-btn {
      font-size: 0.8rem;
      padding: 3px 6px;
      border-radius: 4px;
      border: none;
      cursor: pointer;
      background: var(--accent);
      color: #fff;
    }

    .update-btn:hover {
      background: #a72824;
    }

    .tiny {
      font-size: 0.8rem;
      color: #666;
    }

    textarea.invoice-copy {
      width: 100%;
      font-family: "SFMono-Regular", Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
      font-size: 0.78rem;
      height: 70px;
    }

    @media (max-width: 720px) {
      table {
        font-size: 0.8rem;
      }
    }
  </style>
</head>
<body>
  <div class="page">
    <header>
      <div>
        <h1>Faucet Admin</h1>
        <div class="subtitle">Manage transaction statuses &amp; copy invoices.</div>
      </div>
      <form method="post" class="logout-form">
        <button type="submit" name="logout" value="1">Logout</button>
      </form>
    </header>

    <?php if ($updateMessage): ?>
      <div class="message"><?php echo htmlspecialchars($updateMessage, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <div class="panel">
      <table>
        <thead>
          <tr>
            <th>ID</th>
            <th>Invoice (full)</th>
            <th>IP</th>
            <th>Sats req / sent</th>
            <th>Status</th>
            <th>TX ref</th>
            <th>Created</th>
            <th>Updated</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$claims): ?>
          <tr><td colspan="8">No transactions found.</td></tr>
        <?php else: ?>
          <?php foreach ($claims as $c): ?>
            <tr>
              <td><?php echo (int)$c['id']; ?></td>
              <td class="invoice-full">
                <!-- textarea for easy copy-paste -->
                <textarea class="invoice-copy" readonly><?php echo htmlspecialchars($c['invoice'], ENT_QUOTES, 'UTF-8'); ?></textarea>
              </td>
              <td><?php echo htmlspecialchars($c['ip_address'], ENT_QUOTES, 'UTF-8'); ?></td>
              <td>
                <div>Req: <?php echo number_format((int)$c['sats_requested']); ?> sats</div>
                <div>Sent: <?php echo number_format((int)$c['sats_sent']); ?> sats</div>
              </td>
              <td>
                <form method="post" style="margin:0; display:flex; flex-direction:column; gap:4px;">
                  <input type="hidden" name="id" value="<?php echo (int)$c['id']; ?>" />
                  <select name="status" class="status-select">
                    <?php
                      $statuses = ['pending','processing','paid','failed','blocked'];
                      foreach ($statuses as $st):
                    ?>
                      <option value="<?php echo $st; ?>" <?php if ($c['status']===$st) echo 'selected'; ?>>
                        <?php echo ucfirst($st); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
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
        Showing latest <?php echo (int)$limit; ?> transactions ordered by created time. Use phpMyAdmin for deeper history if needed.
      </div>
    </div>
  </div>
</body>
</html>
