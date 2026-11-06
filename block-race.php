<?php
// block-race.php
// API for Block Race "play for sats" (game/block-race/). All responses are JSON.
//
//   GET  ?action=info                         -> next block, paid spots left, whether paid entries are open
//   POST action=lock   (target,time,tx,fee, captcha token, optional lnurl) -> reserves a paid spot, returns a ticket token
//   POST action=attach (token, lnurl)         -> adds the LNURL later (before or after the block is found)
//   POST action=status (token)                -> grades the entry if its block is out, returns the result
//
// Guesses are stored at lock-in and graded here against the real block; the browser never sends a score.
header('Content-Type: application/json');
header('Cache-Control: no-store');

$configFile = __DIR__ . '/config.local.php';
if (!file_exists($configFile)) {
    echo json_encode(['ok' => false, 'error' => 'config', 'message' => 'Server config missing.']);
    exit;
}
require $configFile;
require __DIR__ . '/block-race-lib.php';

function br_out(array $data): void {
    echo json_encode($data);
    exit;
}

function br_fail(string $code, string $message, array $extra = []): void {
    br_out(['ok' => false, 'error' => $code, 'message' => $message] + $extra);
}

function br_find_entry(PDO $pdo, string $token): ?array {
    if (!preg_match('/^[0-9a-f]{32}$/', $token)) return null;
    $stmt = $pdo->prepare("SELECT * FROM block_race_entries WHERE token_hash = :h");
    $stmt->execute([':h' => hash('sha256', $token)]);
    return $stmt->fetch() ?: null;
}

// Is this LNURL already on another entry for the same block?
function br_lnurl_taken(PDO $pdo, int $height, string $lnurl, int $exceptId = 0): bool {
    $stmt = $pdo->prepare("SELECT 1 FROM block_race_entries WHERE target_height = :h AND lnurl = :l AND id <> :id LIMIT 1");
    $stmt->execute([':h' => $height, ':l' => $lnurl, ':id' => $exceptId]);
    return (bool)$stmt->fetchColumn();
}

$s = br_settings();
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

if (!$s['enabled'] || $s['winners'] === 0) {
    br_fail('disabled', 'Playing for sats is switched off right now. You can still play for fun!', ['enabled' => false]);
}

try {
    $pdo = br_pdo();

    /* ---------- info ---------- */
    if ($action === 'info') {
        try {
            $tip = br_tip();
        } catch (RuntimeException $e) {
            br_fail('mempool', "Can't reach mempool.space right now.");
        }
        $target = $tip['height'] + 1;
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM block_race_entries WHERE target_height = :h");
        $cnt->execute([':h' => $target]);
        $taken = (int)$cnt->fetchColumn();
        $elapsed = max(0, time() - $tip['timestamp']);
        br_out([
            'ok'          => true,
            'enabled'     => true,
            'target'      => $target,
            'tipTime'     => $tip['timestamp'],
            'closesIn'    => max(0, $s['lock_window'] - $elapsed),
            'lockWindow'  => $s['lock_window'],
            'spotsTotal'  => $s['winners'],
            'spotsLeft'   => max(0, $s['winners'] - $taken),
            'maxSats'     => $s['max_sats'],
            'claimWindow' => $s['claim_window'],
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') br_fail('method', 'Bad request.');

    /* ---------- lock ---------- */
    if ($action === 'lock') {
        $time = (float)($_POST['time'] ?? 0);
        $tx = (int)($_POST['tx'] ?? 0);
        $fee = (float)($_POST['fee'] ?? 0);
        $target = (int)($_POST['target'] ?? 0);
        if ($time < 0.5 || $time > 30 || $tx < 1 || $tx > 20000 || $fee < 0.1 || $fee > 200) {
            br_fail('guess', 'Those guesses are out of range.');
        }
        $time = round($time * 2) / 2;   // slider steps are 0.5 min

        $lnurl = null;
        $rawLnurl = trim((string)($_POST['lnurl'] ?? ''));
        if ($rawLnurl !== '') {
            $lnurl = br_clean_lnurl($rawLnurl);
            if ($lnurl === null) br_fail('lnurl', 'That doesn\'t look like an LNURL (it starts with lnurl1…). Leave it empty and add it later if you like.');
        }

        $captcha = (string)($_POST['h-captcha-response'] ?? $_POST['g-recaptcha-response'] ?? '');
        if (!br_verify_captcha($captcha, $ip)) br_fail('captcha', 'CAPTCHA check failed. Please try again.');

        try {
            $tip = br_tip();
        } catch (RuntimeException $e) {
            br_fail('mempool', "Can't check the latest block right now. Try again in a moment.");
        }
        if ($target !== $tip['height'] + 1) {
            br_fail('stale', 'A new block was just found — the round has moved on to block #' . number_format($tip['height'] + 1) . '.', ['target' => $tip['height'] + 1]);
        }
        if (time() - $tip['timestamp'] > $s['lock_window']) {
            br_fail('closed', 'Paid entries for this block closed ' . round($s['lock_window'] / 60) . ' minutes after the last block. You can still play for fun!');
        }

        // First come, first served: one named lock so two lock-ins can't take the same last spot.
        $got = (int)$pdo->query("SELECT GET_LOCK('tsf_block_race_lock', 5)")->fetchColumn();
        if ($got !== 1) br_fail('busy', 'Busy — please try again.');
        try {
            $q = $pdo->prepare("SELECT COUNT(*) AS n, SUM(ip_address = :ip) AS mine FROM block_race_entries WHERE target_height = :h");
            $q->execute([':h' => $target, ':ip' => $ip]);
            $row = $q->fetch();
            if ((int)$row['mine'] > 0) br_fail('duplicate', 'You already have a sats entry for this block.');
            if ((int)$row['n'] >= $s['winners']) br_fail('full', 'All ' . $s['winners'] . ' paid spots for this block are taken. You can still play for fun!', ['spotsLeft' => 0]);
            if ($lnurl !== null && br_lnurl_taken($pdo, $target, $lnurl)) br_fail('lnurl_taken', 'That LNURL already has an entry for this block.');

            $token = bin2hex(random_bytes(16));
            $spot = (int)$row['n'] + 1;
            $pdo->prepare("
                INSERT INTO block_race_entries
                    (token_hash, target_height, base_timestamp, spot_no, guess_time_min, guess_tx, guess_fee, lnurl, ip_address, user_agent)
                VALUES (:th, :h, :bt, :spot, :gt, :gx, :gf, :l, :ip, :ua)
            ")->execute([
                ':th'   => hash('sha256', $token),
                ':h'    => $target,
                ':bt'   => $tip['timestamp'],
                ':spot' => $spot,
                ':gt'   => $time,
                ':gx'   => $tx,
                ':gf'   => round($fee, 2),
                ':l'    => $lnurl,
                ':ip'   => $ip,
                ':ua'   => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);
        } finally {
            $pdo->query("SELECT RELEASE_LOCK('tsf_block_race_lock')");
        }

        br_out([
            'ok'         => true,
            'token'      => $token,
            'spot'       => $spot,
            'spotsTotal' => $s['winners'],
            'entry'      => br_entry_public(br_find_entry($pdo, $token)),
        ]);
    }

    /* ---------- attach / status ---------- */
    $entry = br_find_entry($pdo, (string)($_POST['token'] ?? ''));
    if (!$entry) br_fail('ticket', 'This entry was not found.');

    if ($action === 'attach') {
        $lnurl = br_clean_lnurl((string)($_POST['lnurl'] ?? ''));
        if ($lnurl === null) br_fail('lnurl', 'That doesn\'t look like an LNURL (it starts with lnurl1…).');
        if (!in_array($entry['status'], ['waiting', 'graded'], true) || !empty($entry['lnurl'])) {
            br_fail('state', 'This entry can no longer take an LNURL.', ['entry' => br_entry_public($entry)]);
        }
        if (br_lnurl_taken($pdo, (int)$entry['target_height'], $lnurl, (int)$entry['id'])) {
            br_fail('lnurl_taken', 'That LNURL already has an entry for this block.');
        }
        $pdo->prepare("UPDATE block_race_entries SET lnurl = :l WHERE id = :id AND lnurl IS NULL")
            ->execute([':l' => $lnurl, ':id' => $entry['id']]);
        if ($entry['status'] === 'graded') {
            $r = br_create_claim($pdo, (int)$entry['id']);
            if ($r['ok']) br_trigger_scheduler(1);
        }
        $entry = br_find_entry($pdo, (string)$_POST['token']);
        br_out(['ok' => true, 'entry' => br_entry_public($entry)]);
    }

    if ($action === 'status') {
        if ($entry['status'] === 'waiting') {
            try {
                $created = br_grade_height($pdo, (int)$entry['target_height']);
                br_trigger_scheduler($created);
            } catch (RuntimeException $e) {
                // mempool.space unreachable: the cron job (or the next status check) will grade it
            }
            $entry = br_find_entry($pdo, (string)$_POST['token']);
        } elseif ($entry['status'] === 'graded') {
            br_expire($pdo);
            $entry = br_find_entry($pdo, (string)$_POST['token']);
        }
        br_out(['ok' => true, 'entry' => br_entry_public($entry)]);
    }

    br_fail('action', 'Unknown action.');
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('block-race.php: ' . $e->getMessage());
    br_fail('server', 'Server error. Please try again.');
}
