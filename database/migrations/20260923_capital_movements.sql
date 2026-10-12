-- Add capital_movements for investor payment / funding ledger
-- Run once on existing Hostinger MySQL databases.

CREATE TABLE IF NOT EXISTS capital_movements (
  id CHAR(36) NOT NULL PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  account_id CHAR(36) NULL,
  type ENUM('deposit_to_trader','return_from_trader','broker_deposit','broker_withdrawal','fee','other') NOT NULL,
  amount DECIMAL(18,2) NOT NULL,
  currency VARCHAR(16) NOT NULL DEFAULT 'INR',
  fx_rate DECIMAL(18,6) NULL,
  amount_usd DECIMAL(18,2) NULL,
  paid_at DATETIME(3) NOT NULL,
  method ENUM('upi','bank','cash','other') NOT NULL DEFAULT 'upi',
  reference VARCHAR(255) NULL,
  counterparty VARCHAR(255) NULL,
  note TEXT NULL,
  receipt_url TEXT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  KEY capital_movements_user_idx (user_id, paid_at),
  KEY capital_movements_account_idx (account_id),
  CONSTRAINT capital_movements_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT capital_movements_account_fk FOREIGN KEY (account_id) REFERENCES trading_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
