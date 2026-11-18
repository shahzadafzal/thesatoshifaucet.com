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

require_once __DIR__ . '/lnurl-guard.php';   // script-UA, LNURL checksum/type and wallet-key checks

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
        'daily_per_user' => max(0, (int)($g['BLOCK_RACE_MAX_CLAIMS_PER_DAY'] ?? 2)),    // sats rewards per IP or LNURL per day (0 = no limit)
        'reject_same_guess' => isset($g['BLOCK_RACE_REJECT_SAME_GUESSES']) ? (bool)$g['BLOCK_RACE_REJECT_SAME_GUESSES'] : true, // one paid spot per exact guess set per block
        'flag_same_device'  => max(0, (int)($g['BLOCK_RACE_FLAG_SAME_DEVICE'] ?? 3)),  // N+ winners in one block with the same browser string -> claims created as 'blocked' for review (0 = off)
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

// br_clean_lnurl() plus the anti-abuse checks (valid checksum, receive link, not login/withdraw).
// Returns [lnurl or null, error message or null].
function br_check_lnurl(string $value): array {
    $lnurl = br_clean_lnurl($value);
    if ($lnurl === null) return [null, null];
    $c = lg_check_lnurl($lnurl);
    return $c['ok'] ? [$lnurl, null] : [null, $c['message']];
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

/* ================= Daily limit per player ================= */

// How many of today's entries belong to this IP OR this LNURL (either one matching counts).
// "Today" is the database server's calendar day, so the limit resets at its midnight.
//   $statuses: which entries count, e.g. ['waiting','graded','claimed'] = still able to pay, or ['claimed'] = paid out
//   $exceptId: leave this entry out (the one being checked)
//   LNURLs are compared by wallet key (decoded host + path, without ?query), so the same wallet with a
//   different "?c=…" or letter case still counts as one player (anti-abuse, 2026-11-18).
function br_daily_count(PDO $pdo, string $ip, ?string $lnurl, array $statuses, int $exceptId = 0): int {
    $in = implode(',', array_map(fn($st) => $pdo->quote($st), $statuses));
    $key = lg_lnurl_key($lnurl);
    $stmt = $pdo->prepare("SELECT ip_address, lnurl FROM block_race_entries
                           WHERE created_at >= CURDATE() AND status IN ($in) AND id <> :id
                             AND (ip_address = :ip" . ($key !== null ? " OR lnurl IS NOT NULL" : "") . ")");
    $stmt->execute([':id' => $exceptId, ':ip' => $ip]);
    $n = 0;
    foreach ($stmt as $r) {
        if ($r['ip_address'] === $ip || ($key !== null && lg_lnurl_key($r['lnurl']) === $key)) $n++;
    }
    return $n;
}

// Entries that can still turn into a payout, plus the ones that already did.
const BR_LIVE_STATUSES = ['waiting', 'graded', 'claimed'];

function br_daily_limit_message(): string {
    $n = br_settings()['daily_per_user'];
    return "You've reached today's limit of {$n} Block Race sats " . ($n === 1 ? 'reward' : 'rewards')
         . ' (per player and per LNURL). Come back tomorrow — you can still play for fun!';
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

        // Final guard for the per-player daily limit (also catches entries made before the limit existed).
        if ($s['daily_per_user'] > 0 &&
            br_daily_count($pdo, (string)$e['ip_address'], $e['lnurl'], ['claimed'], $entryId) >= $s['daily_per_user']) {
            return $fail('Daily Block Race limit reached for this player or LNURL');
        }

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

        // Looks like one player farming several spots? Create the claim as 'blocked' with the reason, for the admin to review.
        // (Unblocking it to 'pending' in the admin panel puts it back in the normal flow and takes the sats off then.)
        $flag = br_farming_reason($pdo, $e, $s);

        // cooldown_seconds = 0: game claims never hold up the normal faucet's global cooldown.
        $pdo->prepare("
            INSERT INTO faucet_claims (invoice, ip_address, sats_requested, status, reason, user_agent, payment_type, claim_source, cooldown_seconds)
            VALUES (:inv, :ip, :sats, :st, :rs, :ua, 'lnurl', :src, 0)
        ")->execute([
            ':inv'  => $e['lnurl'],
            ':ip'   => $e['ip_address'],
            ':sats' => $sats,
            ':st'   => $flag === null ? 'pending' : 'blocked',
            ':rs'   => $flag === null ? null : 'Flagged for review: ' . $flag,
            ':ua'   => $e['user_agent'],
            ':src'  => BR_SOURCE,
        ]);
        $claimId = (int)$pdo->lastInsertId();

        if ($flag === null) {
            $pdo->prepare("UPDATE faucet_balance SET balance_sats = balance_sats - :amt WHERE id = 1")->execute([':amt' => $sats]);
        }
        $pdo->prepare("UPDATE block_race_entries SET status = 'claimed', claim_id = :c, reason = NULL WHERE id = :id")
            ->execute([':c' => $claimId, ':id' => $entryId]);
        $pdo->commit();
        return ['ok' => true, 'claim_id' => $claimId];
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

// Signs that one player is taking several paid spots in the same block from rotating IPs and wallets:
//  - N or more entries in the block share this entry's exact browser string, or
//  - another entry in the block has the same browser string and the same time + tx guesses (fee nudged to dodge the duplicate check).
// Returns a short reason, or null when the entry looks fine.
function br_farming_reason(PDO $pdo, array $e, array $s): ?string {
    $n = $s['flag_same_device'];
    $ua = (string)($e['user_agent'] ?? '');
    if ($n <= 0 || $ua === '') return null;
    $q = $pdo->prepare("
        SELECT COUNT(*) AS same_ua,
               SUM(id <> :id AND guess_time_min = :gt AND guess_tx = :gx) AS near_copy
        FROM block_race_entries WHERE target_height = :h AND user_agent = :ua
    ");
    $q->execute([':id' => $e['id'], ':gt' => $e['guess_time_min'], ':gx' => $e['guess_tx'], ':h' => $e['target_height'], ':ua' => $ua]);
    $r = $q->fetch();
    if ((int)$r['same_ua'] >= $n) return (int)$r['same_ua'] . ' entries in block ' . $e['target_height'] . ' from the same browser';
    if ((int)$r['near_copy'] > 0) return 'near-identical guesses from the same browser in block ' . $e['target_height'];
    return null;
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

/* ================= Leaderboard ================= */

const BR_NICK_MAX = 15;
const BR_NICK_HELP = 'Nicknames can be up to 15 letters, numbers, spaces, _ or - (no links), and can\'t look like the site\'s staff. Change it, or leave it empty.';

// Has add-block-race-nickname.sql been run? (So uploading the PHP before running the SQL doesn't break lock-ins.)
function br_has_nickname(PDO $pdo): bool {
    static $has = null;
    if ($has === null) {
        $has = (bool)$pdo->query("SHOW COLUMNS FROM block_race_entries LIKE 'nickname'")->fetch();
    }
    return $has;
}

// Nickname shown on the public leaderboard. Returns '' for "none", null when it isn't allowed.
// Letters (any language), numbers, spaces, _ and - only: no links, no HTML, max 15 characters.
function br_clean_nickname(string $value): ?string {
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    if ($value === '') return '';
    if (mb_strlen($value, 'UTF-8') > BR_NICK_MAX) return null;
    if (!preg_match('/^[\p{L}\p{N}\p{M} _\-]+$/u', $value)) return null;
    // Don't let players pose as the site.
    $flat = preg_replace('/[^a-z0-9]/', '', strtolower($value));
    foreach (['admin', 'moderator', 'satoshifaucet', 'faucetteam', 'support', 'official'] as $word) {
        if (strpos($flat, $word) !== false) return null;
    }
    return $value;
}

// The wallet username inside an LNURL-pay link (e.g. lnurl1... -> https://walletofsatoshi.com/.well-known/lnurlp/NAME -> NAME).
// Display only, so the checksum isn't verified. Returns null when there is no username.
function br_lnurl_username(?string $lnurl): ?string {
    if ($lnurl === null || $lnurl === '') return null;
    $s = strtolower(trim($lnurl));
    $pos = strrpos($s, '1');
    if (strpos($s, 'lnurl1') !== 0 || $pos === false || strlen($s) - $pos < 8) return null;
    $charset = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
    $data = substr($s, $pos + 1, -6);   // drop the 6-character checksum
    $acc = 0; $bits = 0; $url = '';
    for ($i = 0, $n = strlen($data); $i < $n; $i++) {
        $v = strpos($charset, $data[$i]);
        if ($v === false) return null;
        $acc = ($acc << 5) | $v;
        $bits += 5;
        if ($bits >= 8) {
            $bits -= 8;
            $url .= chr(($acc >> $bits) & 0xff);
        }
        $acc &= (1 << $bits) - 1;   // keep only the bits not written yet
    }
    if (!preg_match('#/\.well-known/lnurlp/([A-Za-z0-9._\-]{1,40})(?:[/?\#]|$)#', $url, $m)) return null;
    return $m[1];
}

function br_display_name(array $row): string {
    $nick = trim((string)($row['nickname'] ?? ''));
    if ($nick !== '') return $nick;
    return br_lnurl_username($row['lnurl'] ?? null) ?? 'Anonymous';
}

// Today's top N scores (UTC day) and the best score ever. Entries whose sats claim was blocked as farming are left out.
function br_leaderboard(PDO $pdo, int $limit = 10): array {
    $pdo->exec("SET time_zone = '+00:00'");
    $nick = br_has_nickname($pdo) ? 'e.nickname' : 'NULL AS nickname';
    $base = "SELECT e.target_height, e.spot_no, e.score_pct, e.sats, e.lnurl, $nick, e.graded_at
             FROM block_race_entries e
             LEFT JOIN faucet_claims c ON c.id = e.claim_id
             WHERE e.score_pct IS NOT NULL AND (c.id IS NULL OR c.status <> 'blocked')";
    $order = " ORDER BY e.score_pct DESC, e.graded_at ASC, e.id ASC";

    $row = function (array $r): array {
        return [
            'name'   => br_display_name($r),
            'score'  => (int)$r['score_pct'],
            'sats'   => (int)$r['sats'],
            'block'  => (int)$r['target_height'],
            'spot'   => (int)$r['spot_no'],
            'at'     => $r['graded_at'] ? gmdate('Y-m-d\TH:i:s\Z', strtotime($r['graded_at'] . ' UTC')) : null,
        ];
    };

    $today = $pdo->prepare($base . " AND e.created_at >= UTC_DATE()" . $order . " LIMIT :n");
    $today->bindValue(':n', max(1, min(50, $limit)), PDO::PARAM_INT);
    $today->execute();
    $best = $pdo->query($base . $order . " LIMIT 1")->fetch();

    return [
        'today'   => array_map($row, $today->fetchAll()),
        'allTime' => $best ? $row($best) : null,
        'day'     => gmdate('Y-m-d'),
    ];
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
