# Changelog

Every change to The Satoshi Faucet, newest first. For each one: what changed, why, which files, anything to run
on the server, and how it was tested. Deployment is manual (cPanel), so "Deploy" lists exactly what to upload.

---

## 2026-11-18 — Anti-abuse: block scripts, damaged/wrong LNURLs, and one-wallet-many-LNURLs

### Why
Claims 4710–4721 came from a script, not a browser:

- **Browser ID:** `curl/8.14.1`.
- **IPs:** a different Brazil / Latin America IP each time.
- **Wallet:** always the same coinos wallet, `probeagent032926`. It's the same farmer as claim 4625, which was blocked earlier.
- **The trick:** it added `?c=<number>` to the end of the LNURL, so each claim looked like a new wallet and slipped past the 7-day limit.
- **CAPTCHA:** it passed every time, so it's probably using a CAPTCHA-solving service.

Other bad claims in the same period:

| Claims | Problem |
|---|---|
| 4685, 4679 (both from 5.187.52.154) | LNURLs edited by hand (damaged text) |
| 4715, 4718 | A **LNURL-auth login link** (`tag=login`) for another site, which can never be paid |

### What changed
1. **Scripts are rejected.** Requests whose browser ID is empty, starts with a script tool's name, or contains `HeadlessChrome` are refused:
   - Tool names: `curl`, `wget`, `python-requests`, `python-urllib`, `httpx`, `aiohttp`, `Go-http-client`, `Java/`, `okhttp`, `libwww-perl`, `PHP/`, `GuzzleHttp`, `axios`, `node-fetch`, `undici`, `PostmanRuntime`, `Insomnia`, `HTTPie`, `Scrapy`
   - Message shown: "Please claim from a normal web browser." In Block Race it says "play" instead of "claim".
   - This runs **before** the CAPTCHA check, so bots don't use up hCaptcha verifications.
2. **The LNURL must decode and be a receive link.**
   - The bech32 checksum must be valid, so typos and hand-edited LNURLs are refused.
   - The decoded link must be `https://`. Plain `http://` is allowed only for `.onion` addresses.
   - It must **not** be a login, withdraw or channel link: no `tag=` other than `payRequest`, and no `k1=`.
3. **Limits now count wallets, not LNURL text.**
   - Each LNURL is decoded to a **wallet key**: host + path, with no `?query` and lowercase host. For example, `coinos.io/.well-known/lnurlp/probeagent032926`. So `…?c=1` and `…?c=2` count as the same wallet.
   - **Faucet 7-day rule:** a claim is refused when the same wallet has a paid, processing, pending **or blocked** claim in the last 7 days. Blocked claims count too, so a wallet blocked for farming can't come straight back.
     - The response to the user does not reveal the block.
     - It never shows another visitor's IP.
     - The existing IP and exact-LNURL check is unchanged.
   - **Block Race:** the daily limit (2 per IP or wallet) and the one-entry-per-wallet-per-block rule use the wallet key too.

### Files
| File | Change |
|---|---|
| `lnurl-guard.php` | **New.** Shared helpers: `lg_script_ua()`, `lg_bech32_decode_url()`, `lg_check_lnurl()`, `lg_url_key()`, `lg_lnurl_key()` |
| `claim.php` | Loads `lnurl-guard.php`; browser-ID check; LNURL checksum/type check; 7-day wallet-key check |
| `block-race-lib.php` | Loads `lnurl-guard.php`; new `br_check_lnurl()`; `br_daily_count()` compares wallet keys |
| `block-race.php` | Browser-ID check on lock/attach/status; uses `br_check_lnurl()`; `br_lnurl_taken()` compares wallet keys |

No database changes. No new `config.local.php` settings.

### Deploy
Upload `lnurl-guard.php`, `claim.php`, `block-race.php` and `block-race-lib.php` together, because the other three need `lnurl-guard.php`.

Then block the open claims that came in before this change:

```sql
UPDATE faucet_claims SET status='blocked', sats_sent=0, reason='Farming: curl script, coinos probeagent wallet, rotating proxies' WHERE id IN (4697,4710,4711,4712,4713,4714,4716,4717,4719,4720,4721) AND status IN ('pending','processing');
UPDATE faucet_claims SET status='blocked', sats_sent=0, reason='LNURL-auth login link, not a payable LNURL' WHERE id IN (4715,4718) AND status IN ('pending','processing');
UPDATE faucet_claims SET status='blocked', sats_sent=0, reason='Farming: curl script' WHERE user_agent LIKE 'curl/%' AND status IN ('pending','processing');
```

### Tested (local)
- **No false positives on real data.** All 318 LNURLs in the claims CSVs from 2026-11-14 to 2026-11-18 pass. The only ones refused are the 4 bad ones: 4679 and 4685 (damaged), and 4715 and 4718 (login link).
  - Wallets checked include WoS (lowercase and UPPERCASE), Zebedee and coinos.
- **The two `?c=` coinos variants** decode to the same wallet key.
- **`claim.php` over HTTP:**
  - `curl/8.14.1` is refused.
  - The login link and the damaged LNURL are refused with clear messages.
  - A good LNURL from a browser reaches the CAPTCHA step as before.
- **`block-race.php`:** a curl lock is refused, and a lock with the login link is refused.
- **Against the local database:**
  - Block Race daily count: an entry with `?c=20058` counts against `?c=20091` from a different IP.
  - Faucet 7-day query: a blocked claim with one `?c=` variant matches the other variant.
  - The test rows were deleted afterwards.

### Limits / not done
- **A script can fake a browser ID.** Check 1 only stops lazy scripts. Checks 2–3 can't be dodged by changing headers.
- **Wallets with a fixed name are not blocked.** For example, `probeagent*`. This was option 4 and was skipped for now.

---

## 2026-11-15 — Block Race leaderboard and nickname (not yet committed)

- **Public 🏆 leaderboard:**
  - Today's top 10, plus the all-time best.
  - "Today" is a UTC day.
  - Blocked claims are left out.
  - The server caches it for 20 s.
- **Optional nickname** (max 15 characters: letters, numbers, space, `_`, `-`). Names that pretend to be staff ("admin", "support", …) are refused. The name shown is: nickname → wallet username from the LNURL → "Anonymous".
- **Files:** `block-race-lib.php`, `block-race.php`, `game/block-race/index.html`, `block-race.css`, `block-race.js` (cache-bust `?v=202611151200`).
- **DB:** run `add-block-race-nickname.sql`, which adds the `nickname` column and the `idx_score` index. The code works without the column, but nicknames aren't saved until it's added.

## 2026-11-14 — Admin fixes

- **Admin panel: "⬇ CSV" button.** It downloads the claims that match the current filters with every column.
  - Times are in UTC.
  - Cells are protected against formula injection.
  - File: `admin-panel/admin-7f39c2b5s.php`.
- **"▶ Run Scheduler" returned 403** after the admin session change. `admin-panel/run_scheduler.php` and `scheduler_process.php` now use `admin_session_start()`, and `config.local.php` is loaded with `require_once`.
