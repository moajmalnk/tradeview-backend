-- Multi screenshot/receipt attachments for capital ledger
-- Run once on existing Hostinger MySQL databases.

ALTER TABLE capital_movements
  ADD COLUMN attachments JSON NULL AFTER receipt_url;

UPDATE capital_movements
SET attachments = JSON_ARRAY()
WHERE attachments IS NULL;

ALTER TABLE capital_movements
  MODIFY attachments JSON NOT NULL;
