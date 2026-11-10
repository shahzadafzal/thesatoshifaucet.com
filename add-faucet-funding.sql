-- add-faucet-funding.sql
-- Funding ledger for the faucet: every sat that goes INTO the faucet (first load, donations, top-ups).
-- The balance is then always worked out the same way instead of being patched claim by claim:
--
--   balance = SUM(faucet_funding.amount_sats)
--           - SUM(sats_sent)      of claims with status 'paid'
--           - SUM(sats_requested) of claims with status 'pending' or 'processing'  (reserved, not sent yet)
--
-- faucet_balance.balance_sats stays as the stored figure everything already reads; the code recalculates it
-- (faucet-balance-lib.php) after any status change, payout or refund, and after "Add funds" in the admin panel.
--
-- Run once in phpMyAdmin. Safe to run on the live database: it only adds a table and two rows,
-- then sets the balance from the formula above.

CREATE TABLE IF NOT EXISTS faucet_funding (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  amount_sats  BIGINT NOT NULL,                       -- positive = money in; negative = correction / money taken out
  kind         ENUM('initial','donation','topup','adjustment') NOT NULL DEFAULT 'topup',
  note         VARCHAR(255) NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- What has gone into the faucet so far (only added if the table is still empty).
INSERT INTO faucet_funding (amount_sats, kind, note, created_at)
SELECT * FROM (
  SELECT 700000 AS amount_sats, 'initial'  AS kind, 'First faucet load'  AS note, '2025-12-01 00:00:00' AS created_at
  UNION ALL
  SELECT 10000,                 'donation',         'Donation received', NOW()
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM faucet_funding);

-- Clean up old rows: failed/blocked claims sent nothing, but the old admin form filled in sats_sent on them.
-- (Doesn't change the balance, which only counts paid claims; it fixes the per-status "sent" figures.)
UPDATE faucet_claims SET sats_sent = 0 WHERE status IN ('failed','blocked') AND sats_sent <> 0;

-- Set the balance from the ledger.
UPDATE faucet_balance
SET balance_sats = GREATEST(0,
      (SELECT COALESCE(SUM(amount_sats), 0) FROM faucet_funding)
    - (SELECT COALESCE(SUM(sats_sent), 0)      FROM faucet_claims WHERE status = 'paid')
    - (SELECT COALESCE(SUM(sats_requested), 0) FROM faucet_claims WHERE status IN ('pending','processing')))
WHERE id = 1;

SELECT balance_sats FROM faucet_balance WHERE id = 1;
