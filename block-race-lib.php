<?php
// block-race-lib.php
// Server side of Block Race "play for sats": settings, mempool.space lookups, grading and claim creation.
// Used by block-race.php (the game's API) and admin-panel/block_race_cron.php (grades in the background).
//
// The browser only ever sends GUESSES. The server stores them at lock-in, fetches the real block itself,
// scores it with the same formulas the game shows, and turns the score into a normal faucet_claims row
// (claim_source = 'block-race'). Payouts then follow the usual scheduler -> manual payout flow.
//
// Expects config.local.php to be loaded already. Every setting below has a default, so nothing new has
// to be added to config.local.php unless you want to change it.

const BR_SOURCE = 'block-race';

function br_settings(): array {
    static $s = null;
    if ($s !== null) return $s;
    $g = $GLOBALS;
    $s = [
        'enabled'        => isset($g['BLOCK_RACE_ENABLED']) ? (bool)$g['BLOCK_RACE_ENABLED'] : true,
        'winners'        => max(0, (int)($g['BLOCK_RACE_WINNERS_PER_BLOCK'] ?? 10)),   // paid spots per block, first come first served
        'lock_window'    => max(0, (int)($g['BLOCK_RACE_LOCK_WINDOW_SECONDS'] ?? 480)), // paid lock-ins close 8 min after the last block
        'max_sats'       => max(1, (int)($g['BLOCK_RACE_MAX_SATS'] ?? 100)),           // 100% score = this many sats (1% = 1 sat at 100)
        'min_sats'       => max(1, (int)($g['BLOCK_RACE_MIN_SATS'] ?? 1)),             // below this, nothing is paid
        'daily_budget'   => max(0, (int)($g['BLOCK_RACE_DAILY_BUDGET_SATS'] ?? 0)),    // 0 = no daily cap (faucet balance still applies)
        'claim_window'   => max(60, (int)($g['BLOCK_RACE_CLAIM_WINDOW_SECONDS'] ?? 3600)), // time to add an LNURL after grading
        'api_base'       => rtrim((string)($g['BLOCK_RACE_MEMPOOL_API'] ?? 'https://mempool.space/api'), '/'),
    ];
    // Never promise more than the scheduler will accept.
    if (isset($g['MAX_REWARD_SATS']) && (int)$g['MAX_REWARD_SATS'] > 0) {
        $s['max_sats'] = min($s['max_sats'], (int)$g['MAX_REWARD_SATS']);
    }
    $s['min_sats'] = min($s['min_sats'], $s['max_sats']);
    return $s;
}

function br_pdo(): PDO {
    global $DB_HOST, $DB_NAME, $DB_USER, $DB_PASS;
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO("mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4", $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
}

/* ================= mempool.space ================= */

// Tiny file cache so a busy round doesn't hammer mempool.space (and our host) with identical requests.
function br_cache_file(string $key): string {
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tsf-block-race-' . md5($key) . '.json';
}

function br_http_json(string $path, int $cacheSeconds) {
    $url = br_settings()['api_base'] . $path;
    $file = br_cache_file($url);
    if ($cacheSeconds > 0 && is_file($file) && time() - filemtime($file) < $cacheSeconds) {
        $hit = json_decode((string)@file_get_contents($file), true);
        if (is_array($hit) && array_key_exists('v', $hit)) return $hit['v'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 6,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_USERAGENT      => 'TheSatoshiFaucet/1.0',
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $code === 0 || $code >= 500) {
        throw new RuntimeException('mempool.space unreachable');
    }
    $value = $code === 200 ? json_decode($body, true) : null;   // 4xx = "no such block yet"
    if ($cacheSeconds > 0) @file_put_contents($file, json_encode(['v' => $value]));
    return $value;
}

function br_norm_block(array $b): array {
    $ex = $b['extras'] ?? [];
    return [
        'height'     => (int)$b['height'],
        'timestamp'  => (int)($b['timestamp'] ?? 0),
        'tx_count'   => (int)($b['tx_count'] ?? 0),
        'median_fee' => isset($ex['medianFee']) && is_numeric($ex['medianFee']) ? (float)$ex['medianFee'] : null,
    ];
}

// The current chain tip.
function br_tip(): array {
    $list = br_http_json('/v1/blocks', 15);
    if (!is_array($list) || !$list) throw new RuntimeException('no blocks');
    $tip = null;
    foreach ($list as $b) {
        if (isset($b['height']) && ($tip === null || $b['height'] > $tip['height'])) $tip = $b;
    }
    return br_norm_block($tip);
}

// A block by height, or null if it hasn't been found yet.
function br_block(int $height): ?array {
    // A found block never changes, but its median fee can arrive a little later, so re-check briefly.
    $list = br_http_json('/v1/blocks/' . $height, 20);
    if (!is_array($list)) return null;
    foreach ($list as $b) {
        if (isset($b['height']) && (int)$b['height'] === $height) return br_norm_block($b);
    }
    return null;
}

/* ================= Validation ================= */

// Same rules as claim.php: LNURL only (lnurl1...), sane length, bech32 charset.
function br_clean_lnurl(string $value): ?string {
    $value = trim($value);
    $lower = strtolower($value);
    if (strpos($lower, 'lnurl1') !== 0) return null;
    $len = strlen($lower);
    if ($len < 30 || $len > 2048) return null;
    if (!preg_match('/^lnurl1[02-9ac-hj-np-z]+$/', $lower)) return null;
    return $value;
}

// Same checks as claim.php: success, allowed hostname, token age.
function br_verify_captcha(string $token, string $ip): bool {
    global $CAPTCHA_PROVIDER, $RECAPTCHA_SECRET_KEY, $HCAPTCHA_SECRET_KEY, $HCAPTCHA_ALLOWED_HOSTS, $HCAPTCHA_MAX_TOKEN_AGE_SECONDS;
    if ($token === '') return false;

    if (strtolower($CAPTCHA_PROVIDER ?? 'hcaptcha') === 'google') {
        $verifyUrl = 'https://www.google.com/recaptcha/api/siteverify';
        $secret = $RECAPTCHA_SECRET_KEY ?? '';
    } else {
        $verifyUrl = 'https://hcaptcha.com/siteverify';
        $secret = $HCAPTCHA_SECRET_KEY ?? '';
    }

    $ch = curl_init($verifyUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['secret' => $secret, 'response' => $token, 'remoteip' => $ip]),
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'TheSatoshiFaucet/1.0',
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = $response ? json_decode($response, true) : null;
    if (empty($data['success'])) return false;

    $host = strtolower($data['hostname'] ?? '');
    if (!in_array($host, (array)($HCAPTCHA_ALLOWED_HOSTS ?? []), true)) return false;

    $ts = isset($data['challenge_ts']) ? strtotime($data['challenge_ts']) : false;
    if ($ts === false) return false;
    $age = time() - $ts;
    return $age >= 0 && $age <= (int)($HCAPTCHA_MAX_TOKEN_AGE_SECONDS ?? 120);
}

/* ================= Scoring (mirrors block-race.js) ================= */

function br_acc_linear(float $g, float $a, float $floor): float {
    return max(0.0, min(1.0, 1 - abs($g - $a) / max($g, $a, $floor, 1e-9)));
}

function br_acc_fee(float $g, float $a): float {
    return max(0.0, min(1.0, 1 - abs(log(max($g, 0.05) / max($a, 0.05))) / log(4)));
}

// Returns the per-guess accuracy (whole %), the average score and the reward in sats.
function br_score(array $entry, array $block): array {
    $actualSeconds = max(0, $block['timestamp'] - (int)$entry['base_timestamp']);
    $accTime = (int)round(100 * br_acc_linear((float)$entry['guess_time_min'], $actualSeconds / 60, 2));
    $accTx   = (int)round(100 * br_acc_linear((float)$entry['guess_tx'], (float)$block['tx_count'], 0));
    $accFee  = $block['median_fee'] === null ? null : (int)round(100 * br_acc_fee((float)$entry['guess_fee'], $block['median_fee']));

    $parts = array_values(array_filter([$accTime, $accTx, $accFee], fn($v) => $v !== null));
    $score = (int)round(array_sum($parts) / count($parts));
    $s = br_settings();
    $sats = (int)min($s['max_sats'], round($score * $s['max_sats'] / 100));

    return [
        'actual_seconds' => $actualSeconds,
        'acc_time'       => $accTime,
        'acc_tx'         => $accTx,
        'acc_fee'        => $accFee,
        'score_pct'      => $score,
        'sats'           => $sats,
    ];
}

/* ================= Grading & claims ================= */

// Grade every waiting entry for one block height. Returns the number of claims created (0 if the block isn't out yet).
function br_grade_height(PDO $pdo, int $height): int {
    $block = br_block($height);
    if (!$block) return 0;

    // The median fee normally comes with the block; give mempool.space up to 20 minutes before scoring without it.
    if ($block['median_fee'] === null && time() - $block['timestamp'] < 1200) return 0;

    $stmt = $pdo->prepare("SELECT * FROM block_race_entries WHERE target_height = :h AND status = 'waiting'");
    $stmt->execute([':h' => $height]);
    $minSats = br_settings()['min_sats'];
    $created = 0;

    foreach ($stmt->fetchAll() as $e) {
        $r = br_score($e, $block);
        $status = $r['sats'] >= $minSats ? 'graded' : 'no_reward';
        $upd = $pdo->prepare("
            UPDATE block_race_entries
            SET status = :st, actual_seconds = :as, actual_tx = :atx, actual_fee = :af,
                acc_time = :at, acc_tx = :ax, acc_fee = :afe, score_pct = :sc, sats = :sats,
                reason = :reason, graded_at = NOW()
            WHERE id = :id AND status = 'waiting'
        ");
        $upd->execute([
            ':st'     => $status,
            ':as'     => $r['actual_seconds'],
            ':atx'    => $block['tx_count'],
            ':af'     => $block['median_fee'],
            ':at'     => $r['acc_time'],
            ':ax'     => $r['acc_tx'],
            ':afe'    => $r['acc_fee'],
            ':sc'     => $r['score_pct'],
            ':sats'   => $status === 'graded' ? $r['sats'] : 0,
            ':reason' => $status === 'no_reward' ? 'Score too low for a reward' : null,
            ':id'     => $e['id'],
        ]);
        if ($upd->rowCount() && $status === 'graded' && !empty($e['lnurl'])) {
            if (br_create_claim($pdo, (int)$e['id'])['ok']) $created++;
        }
    }
    return $created;
}

// Turn a graded entry that has an LNURL into a pending faucet claim (reserving the sats, like claim.php).
function br_create_claim(PDO $pdo, int $entryId): array {
    $s = br_settings();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT * FROM block_race_entries WHERE id = :id FOR UPDATE");
        $stmt->execute([':id' => $entryId]);
        $e = $stmt->fetch();
        if (!$e || $e['status'] !== 'graded' || empty($e['lnurl']) || $e['claim_id']) {
            $pdo->rollBack();
            return ['ok' => false, 'reason' => 'not claimable'];
        }
        $sats = (int)$e['sats'];

        $fail = function (string $reason) use ($pdo, $entryId) {
            $pdo->prepare("UPDATE block_race_entries SET status = 'failed', reason = :r WHERE id = :id")
                ->execute([':r' => $reason, ':id' => $entryId]);
            $pdo->commit();
            return ['ok' => false, 'reason' => $reason];
        };

        if ($s['daily_budget'] > 0) {
            $used = (int)$pdo->query("
                SELECT COALESCE(SUM(sats_requested), 0) FROM faucet_claims
                WHERE claim_source = '" . BR_SOURCE . "' AND status IN ('pending','processing','paid')
                  AND created_at >= CURDATE()
            ")->fetchColumn();
            if ($used + $sats > $s['daily_budget']) return $fail("Today's Block Race budget is used up");
        }

        $bal = (int)$pdo->query("SELECT balance_sats FROM faucet_balance WHERE id = 1 FOR UPDATE")->fetchColumn();
        if ($bal < $sats) return $fail('Faucet is empty right now');

        // cooldown_seconds = 0: game claims never hold up the normal faucet's global cooldown.
        $pdo->prepare("
            INSERT INTO faucet_claims (invoice, ip_address, sats_requested, status, user_agent, payment_type, claim_source, cooldown_seconds)
            VALUES (:inv, :ip, :sats, 'pending', :ua, 'lnurl', :src, 0)
        ")->execute([
            ':inv'  => $e['lnurl'],
            ':ip'   => $e['ip_address'],
            ':sats' => $sats,
            ':ua'   => $e['user_agent'],
            ':src'  => BR_SOURCE,
        ]);
        $claimId = (int)$pdo->lastInsertId();

        $pdo->prepare("UPDATE faucet_balance SET balance_sats = balance_sats - :amt WHERE id = 1")->execute([':amt' => $sats]);
        $pdo->prepare("UPDATE block_race_entries SET status = 'claimed', claim_id = :c, reason = NULL WHERE id = :id")
            ->execute([':c' => $claimId, ':id' => $entryId]);
        $pdo->commit();
        return ['ok' => true, 'claim_id' => $claimId];
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

// Close entries nobody will finish: graded with no LNURL past the claim window, or waiting on a block for over a day.
function br_expire(PDO $pdo): void {
    $pdo->prepare("
        UPDATE block_race_entries SET status = 'expired', reason = 'No LNURL added in time'
        WHERE status = 'graded' AND lnurl IS NULL AND graded_at < (NOW() - INTERVAL :w SECOND)
    ")->execute([':w' => br_settings()['claim_window']]);
    $pdo->exec("
        UPDATE block_race_entries SET status = 'expired', reason = 'Block could not be graded'
        WHERE status = 'waiting' AND created_at < (NOW() - INTERVAL 1 DAY)
    ");
}

// Grade everything that can be graded. Returns the number of claims created.
function br_process_all(PDO $pdo): int {
    $heights = $pdo->query("
        SELECT DISTINCT target_height FROM block_race_entries WHERE status = 'waiting' ORDER BY target_height LIMIT 20
    ")->fetchAll(PDO::FETCH_COLUMN);
    $created = 0;
    foreach ($heights as $h) {
        try {
            $created += br_grade_height($pdo, (int)$h);
        } catch (RuntimeException $e) {
            break;   // mempool.space is unreachable: try again on the next run
        }
    }
    br_expire($pdo);
    return $created;
}

// Fire the existing scheduler in the background (same command claim.php uses) so new claims get their BOLT11 invoice.
function br_trigger_scheduler(int $batch): void {
    global $SCHEDULER_TRIGGER_ENABLED, $SCHEDULER_FILE, $PHP_CLI_PATH;
    if ($batch <= 0 || empty($SCHEDULER_TRIGGER_ENABLED) || empty($SCHEDULER_FILE)) return;
    $php = escapeshellarg($PHP_CLI_PATH ?? 'php');
    $file = escapeshellarg($SCHEDULER_FILE);
    $log = escapeshellarg(__DIR__ . '/scheduler.log');
    if (stripos(PHP_OS, 'WIN') === 0) {
        @pclose(@popen('cmd /c start "" /B ' . $php . ' ' . $file . ' --batch=' . $batch . ' >> ' . $log . ' 2>&1', 'r'));
    } else {
        @exec($php . ' ' . $file . ' --batch=' . $batch . ' >> ' . $log . ' 2>&1 &');
    }
}

// What the browser is allowed to see about its own entry.
function br_entry_public(array $e): array {
    $claim = null;
    if (!empty($e['claim_id'])) {
        $st = br_pdo()->prepare("SELECT status, sats_sent FROM faucet_claims WHERE id = :id");
        $st->execute([':id' => $e['claim_id']]);
        $claim = $st->fetch() ?: null;
    }
    $graded = $e['graded_at'] !== null;
    $claimLeft = null;
    if ($graded && $e['status'] === 'graded') {
        // Ask MySQL: graded_at is in the DB's time zone, which can differ from PHP's.
        $st = br_pdo()->prepare("SELECT TIMESTAMPDIFF(SECOND, graded_at, NOW()) FROM block_race_entries WHERE id = :id");
        $st->execute([':id' => $e['id']]);
        $claimLeft = max(0, br_settings()['claim_window'] - (int)$st->fetchColumn());
    }
    return [
        'target'        => (int)$e['target_height'],
        'spot'          => (int)$e['spot_no'],
        'status'        => $e['status'],
        'hasLnurl'      => !empty($e['lnurl']),
        'guesses'       => ['time' => (float)$e['guess_time_min'], 'tx' => (int)$e['guess_tx'], 'fee' => (float)$e['guess_fee']],
        'acc'           => $graded ? ['time' => $e['acc_time'] === null ? null : (int)$e['acc_time'],
                                      'tx'   => $e['acc_tx'] === null ? null : (int)$e['acc_tx'],
                                      'fee'  => $e['acc_fee'] === null ? null : (int)$e['acc_fee']] : null,
        'score'         => $graded ? (int)$e['score_pct'] : null,
        'sats'          => $graded ? (int)$e['sats'] : null,
        'claimId'       => $e['claim_id'] ? (int)$e['claim_id'] : null,
        'claimStatus'   => $claim ? $claim['status'] : null,
        'claimSecondsLeft' => $claimLeft,
        'reason'        => $e['reason'],
    ];
}
