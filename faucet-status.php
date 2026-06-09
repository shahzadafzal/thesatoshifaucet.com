<?php
// faucet-status.php
//
// Public read-only status page for The Satoshi Faucet.
// Shows aggregate stats + recent requests (anonymised invoices).

$configFile = __DIR__ . '/config.local.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    echo "Server config missing.";
    exit;
}
require $configFile;

// Set a timezone for display (change if you prefer)
date_default_timezone_set('UTC');

// --- Connect to DB ---
try {
    $dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4";
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo "Could not connect to database.";
    exit;
}

// --- Summary stats: counts per status ---
$summaryStmt = $pdo->query("
    SELECT status,
           COUNT(*)             AS cnt,
           COALESCE(SUM(sats_requested), 0) AS total_requested,
           COALESCE(SUM(sats_sent), 0)      AS total_sent
    FROM faucet_claims
    GROUP BY status
");
$statusRows = $summaryStmt->fetchAll();

// Build easy lookup
$summary = [
    'pending'    => ['cnt' => 0, 'req' => 0, 'sent' => 0],
    'processing' => ['cnt' => 0, 'req' => 0, 'sent' => 0],
    'paid'       => ['cnt' => 0, 'req' => 0, 'sent' => 0],
    'failed'     => ['cnt' => 0, 'req' => 0, 'sent' => 0],
    'blocked'    => ['cnt' => 0, 'req' => 0, 'sent' => 0],
];

$totalRequests = 0;
$totalRequested = 0;
$totalSent = 0;

foreach ($statusRows as $row) {
    $st = $row['status'];
    $cnt = (int) $row['cnt'];
    $req = (int) $row['total_requested'];
    $sent = (int) $row['total_sent'];

    if (isset($summary[$st])) {
        $summary[$st]['cnt']  = $cnt;
        $summary[$st]['req']  = $req;
        $summary[$st]['sent'] = $sent;
    }

    $totalRequests  += $cnt;
    $totalRequested += $req;
    $totalSent      += $sent;
}

// Last updated time
$lastUpdatedRow = $pdo->query("SELECT MAX(updated_at) AS last_updated FROM faucet_claims")->fetch();
$lastUpdated = $lastUpdatedRow && $lastUpdatedRow['last_updated']
    ? (new DateTime($lastUpdatedRow['last_updated']))->format('Y-m-d H:i:s')
    : 'N/A';

// --- Recent transactions (anonymised) ---
$limit = 50;
$recentStmt = $pdo->prepare("
    SELECT id, invoice, sats_requested, sats_sent, status, tx_reference, created_at, updated_at, reason, receiver_domain
    FROM faucet_claims
    ORDER BY created_at DESC
    LIMIT :lim
");
$recentStmt->bindValue(':lim', $limit, PDO::PARAM_INT);
$recentStmt->execute();
$recentClaims = $recentStmt->fetchAll();

// Helper to shorten invoices for display
function short_invoice(string $inv): string {
    $inv = trim($inv);
    $len = strlen($inv);
    if ($len <= 24) return htmlspecialchars($inv, ENT_QUOTES, 'UTF-8');
    $start = htmlspecialchars(substr($inv, 0, 12), ENT_QUOTES, 'UTF-8');
    $end   = htmlspecialchars(substr($inv, -8), ENT_QUOTES, 'UTF-8');
    return $start . '…' . $end;
}

// Helper to style status
function status_label(string $status): string {
    $status = strtolower($status);
    switch ($status) {
        case 'pending':    return 'Queued';
        case 'processing': return 'Processing';
        case 'paid':       return 'Paid';
        case 'failed':     return 'Failed';
        case 'blocked':    return 'Blocked';
        default:           return htmlspecialchars($status, ENT_QUOTES, 'UTF-8');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Satoshi Faucet Status – Recent Free Bitcoin (sats) Transactions</title>

<meta name="description" 
  content="Live status of The Satoshi Faucet showing recent free bitcoin and satoshi payouts via Lightning LNURL.">

<meta name="keywords" content="
bitcoin faucet status,
free bitcoin transactions,
free satoshi payouts,
lightning faucet status
">

  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <script defer src="https://cloud.umami.is/script.js" data-website-id="a95ca758-29c2-4a8c-ae3c-3e2d35505735"></script>
  <!-- Favicon -->
  <link rel="icon" type="image/png" sizes="32x32" href="images/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="images//favicon-16x16.png">
  <link rel="apple-touch-icon" href="images/apple-touch-icon.png">
  <style>
    :root {
      --accent: #c9302c;
      --accent-soft: #fce7e6;
      --border-color: #ddd;
      --bg-light: #fafafa;
      --font-main: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    }

    * {
      box-sizing: border-box;
    }

    body {
      margin: 0;
      padding: 0;
      font-family: var(--font-main);
      background: #ffffff;
      color: #222;
      line-height: 1.6;
    }

    .page {
      max-width: 1100px;
      margin: 0 auto;
      padding: 24px 16px 40px;
    }

    /* ── Shared site header ─────────────────────────────── */
    .site-header {
      background: linear-gradient(135deg, #1a1a2e 0%, #16213e 60%, #0d1b2a 100%);
      border-radius: 8px;
      padding: 18px 22px;
      margin-bottom: 22px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      flex-wrap: wrap;
    }
    .site-header-brand {
      display: flex;
      align-items: center;
      gap: 14px;
      text-decoration: none;
    }
    .site-header-brand img {
      width: 54px;
      height: auto;
      filter: drop-shadow(0 2px 4px rgba(0,0,0,0.4));
    }
    .site-header-brand h1 {
      font-size: 1.3rem;
      margin: 0 0 2px;
      color: #fff;
      font-weight: 700;
    }
    .site-header-brand .subtitle {
      font-size: 0.8rem;
      color: rgba(255,255,255,0.6);
      margin: 0;
    }
    .back-link {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      font-size: 0.84rem;
      color: rgba(255,255,255,0.8);
      text-decoration: none;
      background: rgba(255,255,255,0.1);
      border: 1px solid rgba(255,255,255,0.2);
      border-radius: 20px;
      padding: 5px 12px;
      transition: background 0.15s;
      white-space: nowrap;
    }
    .back-link:hover {
      background: rgba(255,255,255,0.2);
      text-decoration: none;
    }
    /* ── Page hero (title below header) ─────────────────── */
    .page-hero {
      margin-bottom: 20px;
    }
    .page-hero h2 {
      font-size: 1.6rem;
      margin: 0 0 4px;
    }

    h1 {
      font-size: 1.8rem;
      margin: 0 0 4px;
    }

    .subtitle {
      font-size: 0.95rem;
      color: #555;
    }

    .meta {
      font-size: 0.85rem;
      color: #777;
      margin-top: 6px;
    }

    .summary-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: 12px;
      margin-bottom: 24px;
    }

    .card {
      border: 1px solid var(--border-color);
      background: var(--bg-light);
      border-radius: 4px;
      padding: 12px 14px;
      font-size: 0.9rem;
    }

    .card h2 {
      margin: 0 0 6px;
      font-size: 1rem;
    }

    .big-number {
      font-size: 1.4rem;
      font-weight: 600;
    }

    .muted {
      color: #777;
      font-size: 0.8rem;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      font-size: 0.9rem;
      margin-top: 4px;
    }

    th, td {
      border: 1px solid var(--border-color);
      padding: 6px 8px;
      text-align: left;
      vertical-align: top;
    }

    th {
      background: #f4f4f4;
      font-weight: 600;
      white-space: nowrap;
    }

    tr:nth-child(even) td {
      background: #fcfcfc;
    }

    .status-pill {
      display: inline-block;
      padding: 2px 8px;
      border-radius: 999px;
      font-size: 0.8rem;
      font-weight: 600;
    }

    .status-pending {
      background: #fff7e6;
      color: #8a5a00;
    }
    .status-processing {
      background: #e6f3ff;
      color: #004785;
    }
    .status-paid {
      background: #e6f7e6;
      color: #0a7f00;
    }
    .status-failed {
      background: #ffe6e6;
      color: #a30000;
    }
    .status-blocked {
      background: #f2f2f2;
      color: #555;
    }

    .invoice-cell {
      font-family: "SFMono-Regular", Menlo, Monaco, Consolas, "Liberation Mono", monospace;
      font-size: 0.8rem;
      max-width: 280px;
      word-break: break-all;
    }

    .tiny {
      font-size: 0.8rem;
      color: #777;
    }

    .tiny-reason {
      display: block;
    }

    footer {
      margin-top: 26px;
      font-size: 0.8rem;
      color: #777;
      border-top: 1px solid var(--border-color);
      padding-top: 8px;
    }

    @media (max-width: 720px) {
      th:nth-child(1),
      td:nth-child(1) {
        display: none; /* hide ID on very small screens */
      }
    }
  </style>
</head>
<body>
  <div class="page">
    <!-- ===== HEADER ===== -->
    <header class="site-header">
      <a href="./" class="site-header-brand">
        <img src="images/tap.png" alt="The Satoshi Faucet" />
        <div>
          <h1>The Satoshi Faucet</h1>
          <div class="subtitle">A tribute to the original Bitcoin Faucet</div>
        </div>
      </a>
      <a href="./" class="back-link">&larr; Back to Home</a>
    </header>

    <!-- Page title -->
    <div class="page-hero">
      <h2>Faucet Status</h2>
      <div class="subtitle" style="color:#555;">
        A public peek at what the faucet is doing: queued, processing, paid, and failed requests.
      </div>
      <div class="meta">
        Last updated: <strong><?php echo htmlspecialchars($lastUpdated, ENT_QUOTES, 'UTF-8'); ?> UTC</strong>
      </div>
    </div>

    <!-- Summary cards -->
    <div class="summary-grid">
      <div class="card">
        <h2>Total Requests</h2>
        <div class="big-number"><?php echo number_format($totalRequests); ?></div>
        <div class="muted">All time requests recorded by this faucet.</div>
      </div>

      <div class="card">
        <h2>Queued</h2>
        <div class="big-number"><?php echo number_format($summary['pending']['cnt']); ?></div>
        <div class="muted">Waiting to be processed by the offline sender.</div>
      </div>

      <div class="card">
        <h2>Processing</h2>
        <div class="big-number"><?php echo number_format($summary['processing']['cnt']); ?></div>
        <div class="muted">Currently being handled by the scheduler.</div>
      </div>

      <div class="card">
        <h2>Paid</h2>
        <div class="big-number"><?php echo number_format($summary['paid']['cnt']); ?></div>
        <div class="muted">
          Total sats sent: <strong><?php echo number_format($totalSent); ?></strong>
        </div>
      </div>
    </div>

    <!-- Status breakdown -->
    <div class="card">
      <h2>Status Breakdown</h2>
      <table>
        <thead>
          <tr>
            <th>Status</th>
            <th>Count</th>
            <th>Sats Requested</th>
            <th>Sats Sent</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach (['pending','processing','paid','failed','blocked'] as $st): ?>
            <tr>
              <td><?php echo ucfirst($st); ?></td>
              <td><?php echo number_format($summary[$st]['cnt']); ?></td>
              <td><?php echo number_format($summary[$st]['req']); ?></td>
              <td><?php echo number_format($summary[$st]['sent']); ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <div class="tiny" style="margin-top:6px;">
        Note: amounts here are based on what the faucet recorded in its logs, not live wallet balances.
      </div>
    </div>

    <!-- Recent activity -->
    <div class="card">
      <h2>Recent Activity (last <?php echo (int)$limit; ?> requests)</h2>
      <?php if (!$recentClaims): ?>
        <p class="tiny">No requests have been recorded yet.</p>
      <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>ID</th>
              <th>Invoice (truncated)</th>
              <th>Status</th>
              <th>Sats requested / sent</th>
              <th>Created (UTC)</th>
              <th>Updated (UTC)</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($recentClaims as $c): ?>
            <?php
              $statusClass = 'status-' . strtolower($c['status']);
              $statusText  = status_label($c['status']);
            ?>
            <tr>
              <td><?php echo (int)$c['id']; ?></td>
              <td class="invoice-cell">
                <span><?php echo short_invoice($c['invoice']); ?></span>
                <span class="tiny tiny-reason">
                    <?php echo htmlspecialchars($c['receiver_domain'], ENT_QUOTES, 'UTF-8'); ?>
                </span>
            </td>
              <td>
                <span class="status-pill <?php echo htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8'); ?>">
                  <?php echo htmlspecialchars($statusText, ENT_QUOTES, 'UTF-8'); ?>
                </span>
                <span class="tiny tiny-reason">
                    <?php echo htmlspecialchars($c['reason'], ENT_QUOTES, 'UTF-8'); ?>
                </span>
              </td>
              <td>
                <div>Requested: <?php echo number_format((int)$c['sats_requested']); ?> sats</div>
                <div>Sent: <?php echo number_format((int)$c['sats_sent']); ?> sats</div>
              </td>
              <td><?php echo htmlspecialchars($c['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
              <td><?php echo htmlspecialchars($c['updated_at'], ENT_QUOTES, 'UTF-8'); ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
      <div class="tiny" style="margin-top:6px;">
        Invoices are truncated on this public page for privacy. Your full invoice is never shown here.
      </div>
    </div>

    <footer>
      This status page is informational only. The faucet’s sending logic runs offline and independently of this web server.
      <br />
          <a href="faucet-status" style="font-size:0.85rem;">View faucet status &amp; recent activity</a>
          | <a href="dear-hackers" style="font-size:0.8rem;">A friendly message to hackers</a>
          | <a href="contact" style="font-size:0.8rem;">Contact us</a>
          | <a href="support" style="font-size:0.8rem;">☕ Support the faucet</a>
    </footer>
  </div>
</body>
</html>
