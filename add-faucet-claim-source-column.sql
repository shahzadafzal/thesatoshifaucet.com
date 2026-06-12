-- Add a new submission source column to faucet_claims.
-- Run this against your MySQL database to support tracking how claims were submitted.

ALTER TABLE faucet_claims
  ADD COLUMN claim_source VARCHAR(16) NOT NULL DEFAULT 'paste';

-- Optional: if you want to classify existing rows explicitly later, update them as needed.
-- UPDATE faucet_claims SET claim_source = 'paste' WHERE claim_source = '' OR claim_source IS NULL;
