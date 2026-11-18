-- Block Race leaderboard: optional nickname per paid entry (max 15 characters), plus an index for the top-scores query.
-- Run once against the faucet database (phpMyAdmin > SQL). Safe to upload the PHP first: without this column
-- the game simply shows wallet usernames / "Anonymous" and ignores nicknames.

ALTER TABLE block_race_entries
  ADD COLUMN nickname VARCHAR(15) NULL DEFAULT NULL AFTER lnurl,
  ADD KEY idx_score (score_pct, graded_at);

-- To remove a rude nickname later:
-- UPDATE block_race_entries SET nickname = NULL WHERE nickname = 'the nickname';
