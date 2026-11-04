# The Satoshi Faucet — Project Handoff

> Purpose: bring a fresh Claude Code account fully up to speed on thesatoshifaucet.com so work can continue without losing context.
> Written 2026-10-23 from the repo state at commit `93ee8f3 "Updated games"` (branch `main`, in sync with `origin/main`, working tree clean).
> Everything below was derived from the code, git history and the previous account's saved memory notes. **Verify against the code before relying on any line-level detail — files change between sessions.**

---

## 1. What this is

**The Satoshi Faucet** — a small Bitcoin **Lightning faucet**. Visitors paste an **LNURL** (`lnurl1...`), solve a captcha, and receive a small random reward (currently random ~10–100 sats). It runs on cheap **cPanel shared hosting** (PHP + MySQL), with a plain static front end.

- Repo: `https://github.com/shahzadafzal/thesatoshifaucet.com.git` (branch `main`)
- Local path (previous machine): `D:\SHAHZAD\OneDrive\Documents\Projects\thesatoshifaucet.com\SourceCode`
- Stack: vanilla PHP (PDO/MySQL), vanilla HTML/CSS/JS (no build step, no framework, no npm), Apache `.htaccess` rewrites. LNbits (free `demo.lnbits.com`) is the Lightning wallet backend.
- Analytics: Umami (`cloud.umami.is`). Captcha: hCaptcha (reCAPTCHA is supported as an alternative via `$CAPTCHA_PROVIDER`).
- Workflow: the user tests locally on **XAMPP** (Apache on **port 81**, i.e. `http://localhost:81/faucet/...`; MySQL client at `C:\xampp\mysql\bin\mysql.exe`), then **deploys to cPanel manually**. There is no staging environment and no CI.

---

## 2. Repository map

| Path | What it is |
|---|---|
| `index.html` | Home page: claim form, captcha, cooldown timer, recent-activity sidebar, balance display, **"Play while you wait" game tiles + popup**. Contains all inline JS for claim flow. |
| `claim.php` | The claim endpoint (POST) plus two GET helpers: `?captcha_config=1` (provider + site key) and `?cooldown_status=1` (global cooldown seconds remaining). |
| `admin-panel/scheduler_process.php` | CLI/background worker: picks `pending` claims, decodes LNURL, fetches pay data, requests a BOLT11 invoice, stores it, emails admin. **Auto-pay is deliberately commented out.** |
| `admin-panel/run_scheduler.php` | Admin-only POST endpoint that runs the scheduler synchronously (used from the admin page via iframe/AJAX). |
| `admin-panel/admin-7f39c2b5s.php` | **Main admin panel** (~1500 lines): login, filterable claims table, search/stats, manual status/tx_reference edit. |
| `admin-panel/xadmin-7f39c2b5.php` | Older admin panel (kept, superseded). |
| `admin-panel/manual-payments/` | **Gitignored.** `manual-payout.php` (LNbits "Pay Now" admin screen), plus PHP test scripts and `testing-quries.sql`. Not version-controlled — back it up separately. |
| `balance.php` | JSON: faucet balance (`faucet_balance` row id=1). |
| `recent-activity.php` | JSON: last 10 claims (anonymised) for the home sidebar. |
| `status.json.php`, `status.php`, `faucet-status.php` | Public status page + JSON totals (served as pretty URL `/faucet-status`). |
| `donate_invoice.php` | Resolves `$DONATION_LNURL` and returns a BOLT11 invoice for a chosen donation amount (used by `support.html`). |
| `support.html` / `support.php` | Donation page; `support.php` string-replaces the LNURL/BTC addresses in `support.html` from config. |
| `helper.php` | `decodeBolt11Invoice()` helper. |
| `how-to-*.html`, `contact.html`, `dear-hackers.html` | Static help/info pages. `xhow-to-get-lnurl.html`, `test.html`, `claim - Copy.php`, `index.html.bak` are leftovers/backups. |
| `scripts/faucet.js` | Small front-end helpers (copy invoice, refresh balance). |
| `assets/style.css`, `assets/innerstyle.css` | Site styles (game popup classes `.game-popup-*` live in `style.css`). |
| `game/` | The browser games — see §6. |
| `images/` | Favicons, logos. |
| `.htaccess` | No-cache for `.html`; extensionless URL rewrites (`/faucet/` base — local dev path); `/faucet-status` pretty URL. |
| `config.local.php` | **Gitignored, contains secrets.** Defines DB creds, LNbits URL/key, captcha keys, admin password, SMTP creds, reward/cooldown settings. Never commit it; never paste its values into chat/docs. |
| `SECURITY.md` | cPanel hardening checklist (2FA, SFTP/SSH keys, `chmod 444 support.html`, backups, integrity checks). |
| `EMAIL_*.md`, `IMPLEMENTATION_SUMMARY.md` | Docs for the admin email-notification system (SMTP via cPanel mail, port 465). |
| `add-faucet-claim-source-column.sql` | Migration that added `faucet_claims.claim_source`. |

`config.local.php` variable *names* (values omitted on purpose): `DB_HOST/NAME/USER/PASS`, `LNBITS_URL`, `LNBITS_KEY`, `ADMIN_PASSWORD`, `CAPTCHA_PROVIDER`, `HCAPTCHA_SITE_KEY/SECRET_KEY/ALLOWED_HOSTS/MAX_TOKEN_AGE_SECONDS/DEBUG_LOG`, `RECAPTCHA_SITE_KEY/SECRET_KEY`, `REWARD_SATS` (computed randomly in config from `REWARD_WEIGHTS`), `MAX_REWARD_SATS`, `GLOBAL_CLAIM_COOLDOWN_MIN_SECONDS/MAX_SECONDS`, `MAX_PROCESSING_CLAIMS`, `ENABLE_DUBPLICATE_IP_CHECK` (sic), `SCHEDULER_TRIGGER_ENABLED`, `SCHEDULER_FILE`, `PHP_CLI_PATH`, `DONATION_LNURL`, `DONATION_BTC`, `EMAIL_ENABLED/ADMIN/FROM/SMTP_HOST/SMTP_PORT/SMTP_USER/SMTP_PASS/SMTP_SECURE`.

---

## 3. Database (inferred from queries — no schema dump is in the repo)

**`faucet_claims`** — one row per claim. Columns seen in code: `id`, `invoice` (holds the user's LNURL), `ip_address`, `user_agent`, `sats_requested`, `sats_sent`, `status`, `admin_status`, `reason`, `payment_type` (`'lnurl'`), `claim_source` (`paste|scan|upload`), `cooldown_seconds`, `receiver_domain`, `lnurl_full_url`, `lnurl_host`, `lnurl_username`, `pay_bolt11`, `tx_reference`, `created_at`, `updated_at`.
- `status`: `pending` → `processing` → `paid` | `failed`.
- `admin_status`: e.g. `ready_to_pay` (set by scheduler once a BOLT11 is stored), `failed_validation`, plus values the admin sets by hand.

**`faucet_balance`** — single row `id=1`, `balance_sats`, `updated_at`. Sats are **reserved (deducted) at queue time** inside a transaction with `SELECT … FOR UPDATE`; refunded by `mark_failed()` when a claim fails.

**`faucet_settings`** — `setting_name`/`setting_value`; DB overrides for `global_cooldown_min_seconds` / `global_cooldown_max_seconds`.

---

## 4. Core flow (claim → payout)

1. **Browser** (`index.html`): user pastes / scans (html5-qrcode) / uploads a QR of an LNURL, solves hCaptcha, POSTs to `claim.php` (`address`, `claim_source`, captcha token).
2. **`claim.php`** validates in this order: LNURL-only format (bech32 charset, length 30–2048) → captcha token present → server-side captcha verify (success, hostname in allowed list, token age) → queue-size limit (`MAX_PROCESSING_CLAIMS`, default 50) → **global cooldown** (random 9–11 min by default, stored per claim in `cooldown_seconds` so bots can't rely on exactly 10 min; the cooldown is global across all users, not per user) → **7-day duplicate check by LNURL OR IP** → balance check → insert `pending` row + deduct balance (one transaction).
3. It sends the JSON "queued" response with `Content-Length` + `Connection: close` (+ `fastcgi_finish_request`) so the user gets an instant reply, **then** fires `scheduler_process.php --batch=1` in the background (Windows `cmd /c start /B` or Linux `&`), if `SCHEDULER_TRIGGER_ENABLED`.
4. **`scheduler_process.php`**: locks one `pending` row → `processing` → decodes the LNURL (own bech32 implementation) → stores `receiver_domain / lnurl_host / lnurl_username / lnurl_full_url` → fetches LNURL-pay data → requests a BOLT11 for `sats_requested` → stores `pay_bolt11`, sets `admin_status='ready_to_pay'` → emails the admin. Any exception → `mark_failed()` (+ optional refund).
5. **Admin pays manually** (see §5), marking the claim `paid` with `sats_sent` and `tx_reference`.

---

## 5. Key decisions & conventions (read before proposing changes)

### Payout is deliberately MANUAL
Confirmed with the user on 2026-07-28: they want a human in the loop for every payout. The scheduler's auto-pay block (`pay_bolt11_invoice`, "Step C/D") is commented out **on purpose**. They run LNbits on the free `demo.lnbits.com`, topping it up in small batches (~50 transactions' worth) and watching it drain through the admin panel.
- `admin-panel/manual-payments/manual-payout.php` = LNbits-assisted "Pay Now" screen. `admin-7f39c2b5s.php` = broader filterable table + manual status/audit tool. **They keep distinct roles — do not propose merging them, and do not enable scheduler auto-pay, without asking first.**
- Frame any stats/"how much is left" features around the batch-top-up-and-drain workflow.

### Admin panel search/stats (`admin-7f39c2b5s.php`) — conventions to reuse
- Wildcard char is `*` (→ SQL `%` via `parse_wildcard_search()`); no `*` = exact match; literal `% _ \` are escaped. A leading `~` **negates** (e.g. `~112.110.*` excludes).
- Searchable: `search_ip`, `search_lnurl_user`, `search_domain` (matches `receiver_domain` OR `lnurl_host`), ID search (single id or range `3800-3810`), amount range `search_amount` (`10-50`, `10-`, `-50`, bare `50`; reversed bounds are silently swapped; matches `sats_requested` OR `sats_sent` on the main panel, `sats_requested` only on manual-payout).
- `date_from`/`date_to` (`datetime-local` on `created_at`) take precedence over the older `filter_last24`; reversed ranges silently ignored.
- Always-visible stat cards: sats paid in last 24h; sats paid over the last N paid txs (`stat_n`, default 50 = the user's LNbits top-up batch size); plus filtered-view total (sum across ALL matching rows, not capped by page size).
- All filter GET params are passed through as hidden inputs in the per-row Update form so filters survive an edit + reload.
- Panel is responsive (commit `3e4b6fc`), has a "Clear filters" link, and runs the scheduler via `run_scheduler.php`.
- **Lesson learned:** this file (and the gitignored `manual-payout.php`, which has no git safety net) has changed between sessions without the assistant touching it. **Always re-read it immediately before editing**, and confirm with the user if anything looks unfamiliar.

### Known issues noted but NOT actioned (revisit only if asked)
- `config.local.php` and the whole `admin-panel/manual-payments/` folder are gitignored → production values aren't visible here and `manual-payout.php` isn't version-controlled.
- The scheduler's "ready to pay" email uses the stale global `$REWARD_SATS` instead of the row's actual `sats_requested` (mismatch bug when rewards differ / batch > 1).
- No email is sent when a payout completes via either admin screen.
- Admin can mark a claim `paid` with zero proof-of-payment verification.
- `claim.php` still writes `hcaptcha-debug.log` on every captcha response (PII: IP + UA) and returns `'debug' => $e->getMessage()` in its generic error response (marked "enable if you need to see errors locally") — worth tightening before/if exposing publicly.
- Leftover files in the repo root: `claim - Copy.php`, `xhow-to-get-lnurl.html`, `index.html.bak`, `test.html`, `xscheduler.log`.

---

## 6. "Play while you wait" browser games (the most recent body of work)

Home page (`index.html`) shows a row of 3 tiles (`.game-tile` with `data-game-url`, `data-game-title`, `data-game-hash`) that open a game in a shared popup iframe (`.game-popup-*` classes in `assets/style.css`). The old FIFA **Penalty Shootout** is archived (`game/penalty-shootout.*` still present, button removed).

**Design constraints:** everything runs **client-side** (cPanel host sees no extra load); vanilla JS + Canvas; light, mobile-first; keep the popup's top-right clear for the close button; **no sats rewards for scores** (would give bots a reason to farm games — see §7).

| Game | Files | Tile URL / hash | Notes |
|---|---|---|---|
| **Mempool Aquarium** | `game/mempool-aquarium.{html,css,js}`, `game/aquarium-creatures.js` | `game/mempool-aquarium` / `#Mempool_Aquarium` | Each fish = a live unconfirmed tx from mempool.space `/api/mempool/recent` (polled ~4 s, de-duped by txid). Size = log(sats), colour = fee rate; tiers shrimp/fish/shark/whale (`CONFIG.whaleSats` default 1 BTC; shark 0.1 BTC; fish 0.001 BTC). Score = total value of caught txs (sats + BTC). Simulated fish after 3 failed polls. In-game `?` help (auto-opens once per device), synthesized Web Audio sounds (mute persisted in localStorage), fullscreen button. Creatures drawn by `aquarium-creatures.js`; whale banner and caught card are drawn on the canvas under the animals (DOM copies visually hidden for screen readers). |
| **Block Race** | `game/block-race/{index.html,block-race.css,block-race.js}` | `game/block-race/` / `#Block-Race` | Live data over mempool.space WebSocket with REST fallback. Next block drawn as a 20×20 fee-coloured grid. Round = 3 guesses (arrival time, tx count, median fee), graded when the real block lands. Scoring = accuracy % per guess → play-money sats (max 1,000/guess, 3,000/round). Arrival measured from the **previous block's timestamp** (negative gap → 0); hints/slider defaults from the last block. Reads faucet cooldown from `../../claim.php?cooldown_status=1` (strip hidden locally because local claim.php returns `ok:false`). Keep playing past cooldown end until the block lands; free play if the faucet is already ready. |
| **PoW Mini Miner** | `game/pow-miner/{index.html,pow.css,pow.js,pow-worker.js,pow-core.js}` | `game/pow-miner/` / `#PoW-Miner` | Double-SHA-256 with midstate in a Web Worker (verified against the genesis block and Node crypto; ~0.7–1.2 MH/s/thread). Sats = for each new leading-zero level L reached, L×10 play-money sats; headline metric = leading zero bits; record in localStorage. Power selector Eco/Balanced/Turbo; pauses when tab hidden; 20-min auto-break. Files deliberately avoid the word "miner" (ad-block/NoCoin heuristics). Transparent that it cannot earn Bitcoin. |

**Cross-game systems**
- **Deep links + share:** tiles carry `data-game-hash` (spellings exactly as above; matching is case-insensitive and treats `_`/`-` alike). `syncFromHash()` runs on load/`hashchange`/`popstate`; tile click does `history.pushState`; closing does `history.back()` (if we pushed) else `replaceState`. Share button (`.share-btn`, 4th in the button column) is wired by shared `game/shared/share.{js,css}` via `data-share-hash/title/text/home`: Web Share API where available, else a menu (WhatsApp/X/Telegram/Facebook/Copy link). Shared link = parent URL without query/hash + `#Hash`.
- **To add a game:** add a tile with the 3 `data-game-*` attributes + a share button with matching `data-share-*`; create its own folder under `game/` (user's preference: **a separate folder per new game**).
- **Fullscreen:** real Fullscreen API where available; otherwise (and always on iPhone/iPad — all WebKit, including iPadOS desktop-class UA — to avoid the unsuppressable "typing in full screen" warning) the game posts `{type:'game-maximize'}` to the parent, which toggles `.is-max`. Parent trusts only its own iframe (source + origin check).
- **Sound:** all synthesized with Web Audio (no audio files). `sfx`, help panel and fullscreen code are currently **copied per game**; only share is a shared module.
- **Cache-busting:** `index.html` loads `assets/style.css?v=...` — **bump the query when editing `assets/style.css`**.

**Testing notes**
- Extensionless URLs (`game/mempool-aquarium`) rely on the Apache `.htaccess` rewrite, so PHP's built-in server 404s them (use the `.html` path, or add a tiny router).
- The in-app browser pane can't reach `localhost:81`; start a throwaway server via `.claude/launch.json` (`php -S localhost:8099 -t .`) and delete the file afterwards. Local Apache (port 81) isn't always running.
- When the browser pane is hidden, `document.hidden` is true and games correctly pause (no polls/animation frames) — override `document.hidden` and drive frames by hand (temporary exposed `step()`) to test.
- `node --check <file.js>` is the quick syntax check used for the game scripts; real-fullscreen was verified with a mocked API.
- The Aquarium is live on production (user confirmed). Other games' production status: assume deployed when the user says so, otherwise ask.

---

## 7. Roadmap / what to do next

1. **Bot / AI-bot claim-abuse mitigation (next planned phase — NOT started).** Existing defences: hCaptcha, IP-based 7-day duplicate check, unique-LNURL check, randomized global cooldown. The user suspects **residential-proxy IP rotation** bypasses the IP check. Before building anything, run a fresh round of clarifying questions on which anti-abuse signals are acceptable (e.g. tighter LNURL/IP correlation, device fingerprinting, rate-limiting by `lnurl_host`/domain, behavioural signals). Use the admin search tools (wildcard domain/user, negate `~`) and the stats cards to investigate patterns.
2. **Optional game follow-ups** (all discussed, none urgent): lift `sfx`/help/fullscreen into a shared `game/shared/` module; direct-access protection for game pages (user deferred it).
3. **Known-issue clean-ups** from §5 only if the user asks.

---

## 8. Working agreements with this user (what the previous sessions learned)

- Ask before changing the **payout model** or merging admin screens (§5).
- Prefer the user's **existing conventions** when extending the admin panel (ID-search style for ranges, `*` wildcards, hidden-input param passthrough).
- Small, mobile-first, dependency-free front-end code; one folder per game; no external audio/asset files.
- Verify end-to-end where possible (e.g. curl the admin panel with a session cookie and cross-check counts against direct SQL on local XAMPP) and say plainly what was and wasn't tested.
- **Never print, commit or paste secrets** from `config.local.php`.
- Re-read a file right before editing it; the user also edits files outside Claude sessions.
- The user's commit style is short messages like "Updated games" — only commit/push when they ask.

---

## 9. Recent git history (newest first)

```
93ee8f3 Updated games
8ed1577 Updated games
6447fe1 Updated Games
330d85d Added new games
6aeac91 Added new game
2c46a04 Update home page
60cd745 Update home page
22de926 Updated admin panel
fb63f2d Updated the search filters
3e4b6fc Updated admin panel to be responsive
1d6d4d5 Updated admin panel search
373bcfe Update
85de022 Added new support and status update on faucet
9719126 Added support faucet details
4568c62 Updated processing of claims to save username and URL
2b1c573 Updated cool down timer logic
54ead62 Update the captcha logs
314b497 Cool down timer set to 10 minutes (Bitcoin avg block time)
cf5d5b9 Updated support page: weighted average of sent sats
be7a2cd Reward logic: random 10-100 sats instead of fixed 50
58281f4 Option to run scheduler from iframe
...      (earlier: manual payments, email notifications, IP check, support/contact pages, Umami, LNURL scheduler)
```

---

## 10. Files NOT in the repo that the new account must get separately

Copy these over manually (they are gitignored or live outside the repo):
- `config.local.php` (secrets — transfer securely, not via chat)
- `admin-panel/manual-payments/` (whole folder, incl. `manual-payout.php`)
- The previous account's memory notes (4 files under `~/.claude/projects/<project-slug>/memory/`: `MEMORY.md`, `project_payout_flow.md`, `project_admin_panel_search.md`, `project_games.md`) — optional, since this document consolidates them.
- `.claude/settings.local.json` is machine-local permission allow-list (node --check etc.); recreate as needed.
