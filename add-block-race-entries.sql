-- Block Race: entries that play for real sats.
-- Run this once against your MySQL database (local XAMPP and production).
--
-- One row per paid lock-in. Guesses are stored by the server at lock-in time and graded by the
-- server against the real block from mempool.space, so the browser can't send its own score.
-- When a graded entry has an LNURL, a normal faucet_claims row is created with
-- claim_source = 'block-race' and follows the usual scheduler -> manual payout flow.

CREATE TABLE IF NOT EXISTS block_race_entries (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  token_hash      CHAR(64)     NOT NULL,              -- sha256 of the secret ticket token kept by the browser
  target_height   INT UNSIGNED NOT NULL,              -- the block being guessed
  base_timestamp  INT UNSIGNED NOT NULL,              -- previous block's timestamp (arrival is counted from it)
  spot_no         TINYINT UNSIGNED NOT NULL,          -- 1..N, first come first served
  guess_time_min  DECIMAL(5,1) NOT NULL,
  guess_tx        INT UNSIGNED NOT NULL,
  guess_fee       DECIMAL(8,2) NOT NULL,
  lnurl           VARCHAR(1024) DEFAULT NULL,         -- may be added after lock-in, even after the block is found
  ip_address      VARCHAR(45)  NOT NULL,
  user_agent      VARCHAR(255) DEFAULT NULL,
  status          ENUM('waiting','graded','claimed','no_reward','expired','failed') NOT NULL DEFAULT 'waiting',
  actual_seconds  INT UNSIGNED DEFAULT NULL,
  actual_tx       INT UNSIGNED DEFAULT NULL,
  actual_fee      DECIMAL(8,2) DEFAULT NULL,
  acc_time        TINYINT UNSIGNED DEFAULT NULL,      -- accuracy % per guess
  acc_tx          TINYINT UNSIGNED DEFAULT NULL,
  acc_fee         TINYINT UNSIGNED DEFAULT NULL,
  score_pct       TINYINT UNSIGNED DEFAULT NULL,      -- average of the three
  sats            INT UNSIGNED DEFAULT NULL,          -- reward (1% = 1 sat, max 100)
  claim_id        INT UNSIGNED DEFAULT NULL,          -- faucet_claims.id once claimed
  reason          VARCHAR(255) DEFAULT NULL,
  graded_at       TIMESTAMP NULL DEFAULT NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_token (token_hash),
  KEY idx_target_status (target_height, status),
  KEY idx_target_ip (target_height, ip_address),
  KEY idx_status_graded (status, graded_at),
  KEY idx_claim (claim_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
