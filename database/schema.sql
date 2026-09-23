-- TradeView API schema for Hostinger MySQL
-- Import via phpMyAdmin into u524154866_trade

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS users (
  id CHAR(36) NOT NULL PRIMARY KEY,
  email VARCHAR(255) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY users_email_uq (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS profiles (
  id CHAR(36) NOT NULL PRIMARY KEY,
  full_name VARCHAR(255) NULL,
  email VARCHAR(255) NULL,
  avatar_url TEXT NULL,
  timezone VARCHAR(64) NOT NULL DEFAULT 'UTC',
  demo_mode TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  CONSTRAINT profiles_user_fk FOREIGN KEY (id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS trading_accounts (
  id CHAR(36) NOT NULL PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  label VARCHAR(255) NOT NULL,
  platform ENUM('mt4','mt5','tradingview','ctrader','ibkr','binance') NOT NULL,
  broker VARCHAR(255) NULL,
  account_identifier VARCHAR(255) NULL,
  currency VARCHAR(16) NOT NULL DEFAULT 'USD',
  balance DECIMAL(18,2) NOT NULL DEFAULT 0,
  equity DECIMAL(18,2) NOT NULL DEFAULT 0,
  margin DECIMAL(18,2) NOT NULL DEFAULT 0,
  free_margin DECIMAL(18,2) NOT NULL DEFAULT 0,
  margin_level DECIMAL(18,2) NULL,
  server_time DATETIME(3) NULL,
  connection_status ENUM('live','delayed','disconnected','pending') NOT NULL DEFAULT 'pending',
  last_seen_at DATETIME(3) NULL,
  is_demo TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  KEY trading_accounts_user_idx (user_id),
  CONSTRAINT trading_accounts_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS connector_tokens (
  id CHAR(36) NOT NULL PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  account_id CHAR(36) NULL,
  token_hash CHAR(64) NOT NULL,
  token_prefix VARCHAR(32) NOT NULL,
  platform ENUM('mt4','mt5','tradingview','ctrader','ibkr','binance') NOT NULL,
  status ENUM('active','revoked','expired') NOT NULL DEFAULT 'active',
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  expires_at DATETIME(3) NULL,
  last_used_at DATETIME(3) NULL,
  revoked_at DATETIME(3) NULL,
  UNIQUE KEY connector_tokens_hash_uq (token_hash),
  KEY connector_tokens_user_idx (user_id),
  CONSTRAINT connector_tokens_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT connector_tokens_account_fk FOREIGN KEY (account_id) REFERENCES trading_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS positions (
  id CHAR(36) NOT NULL PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  account_id CHAR(36) NOT NULL,
  ticket VARCHAR(64) NOT NULL,
  symbol VARCHAR(64) NOT NULL,
  direction ENUM('buy','sell') NOT NULL,
  volume DECIMAL(18,4) NOT NULL,
  entry_price DECIMAL(18,5) NOT NULL,
  current_price DECIMAL(18,5) NULL,
  stop_loss DECIMAL(18,5) NULL,
  take_profit DECIMAL(18,5) NULL,
  profit DECIMAL(18,2) NOT NULL DEFAULT 0,
  swap DECIMAL(18,2) NOT NULL DEFAULT 0,
  commission DECIMAL(18,2) NOT NULL DEFAULT 0,
  open_time DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  status ENUM('open','closed','pending') NOT NULL DEFAULT 'open',
  metadata JSON NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY positions_account_ticket_uq (account_id, ticket),
  KEY positions_account_idx (account_id),
  KEY positions_symbol_idx (symbol),
  KEY positions_status_idx (status),
  KEY positions_updated_idx (updated_at),
  CONSTRAINT positions_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT positions_account_fk FOREIGN KEY (account_id) REFERENCES trading_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pending_orders (
  id CHAR(36) NOT NULL PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  account_id CHAR(36) NOT NULL,
  ticket VARCHAR(64) NOT NULL,
  symbol VARCHAR(64) NOT NULL,
  direction ENUM('buy','sell') NOT NULL,
  volume DECIMAL(18,4) NOT NULL,
  requested_price DECIMAL(18,5) NULL,
  stop_loss DECIMAL(18,5) NULL,
  take_profit DECIMAL(18,5) NULL,
  status ENUM('open','closed','pending') NOT NULL DEFAULT 'pending',
  placed_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  metadata JSON NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY pending_orders_account_ticket_uq (account_id, ticket),
  KEY pending_orders_account_idx (account_id),
  CONSTRAINT pending_orders_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT pending_orders_account_fk FOREIGN KEY (account_id) REFERENCES trading_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS trades (
  id CHAR(36) NOT NULL PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  account_id CHAR(36) NOT NULL,
  ticket VARCHAR(64) NOT NULL,
  symbol VARCHAR(64) NOT NULL,
  direction ENUM('buy','sell') NOT NULL,
  volume DECIMAL(18,4) NOT NULL,
  entry_price DECIMAL(18,5) NOT NULL,
  exit_price DECIMAL(18,5) NULL,
  profit DECIMAL(18,2) NOT NULL DEFAULT 0,
  commission DECIMAL(18,2) NOT NULL DEFAULT 0,
  swap DECIMAL(18,2) NOT NULL DEFAULT 0,
  open_time DATETIME(3) NOT NULL,
  close_time DATETIME(3) NULL,
  status ENUM('open','closed','pending') NOT NULL DEFAULT 'closed',
  metadata JSON NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY trades_account_ticket_uq (account_id, ticket),
  KEY trades_account_idx (account_id),
  KEY trades_symbol_idx (symbol),
  KEY trades_close_time_idx (close_time),
  CONSTRAINT trades_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT trades_account_fk FOREIGN KEY (account_id) REFERENCES trading_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tradingview_signals (
  id CHAR(36) NOT NULL PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  symbol VARCHAR(64) NOT NULL,
  direction ENUM('buy','sell') NOT NULL,
  entry DECIMAL(18,5) NULL,
  stop_loss DECIMAL(18,5) NULL,
  take_profit DECIMAL(18,5) NULL,
  alert_time DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  source VARCHAR(128) NOT NULL DEFAULT 'TradingView',
  payload JSON NOT NULL,
  event_id VARCHAR(128) NULL,
  status ENUM('received','matched','unmatched','expired') NOT NULL DEFAULT 'received',
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY signals_user_idx (user_id, alert_time),
  UNIQUE KEY signals_event_uq (user_id, event_id),
  CONSTRAINT signals_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS share_links (
  id CHAR(36) NOT NULL PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  label VARCHAR(255) NOT NULL DEFAULT 'Live monitoring',
  token_hash CHAR(64) NOT NULL,
  token_prefix VARCHAR(32) NOT NULL,
  visibility VARCHAR(32) NOT NULL DEFAULT 'full',
  permissions JSON NOT NULL,
  account_ids JSON NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'active',
  view_count INT NOT NULL DEFAULT 0,
  last_viewed_at DATETIME(3) NULL,
  expires_at DATETIME(3) NULL,
  revoked_at DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY share_links_hash_uq (token_hash),
  KEY share_links_user_idx (user_id),
  CONSTRAINT share_links_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
  id CHAR(36) NOT NULL PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  account_id CHAR(36) NULL,
  type ENUM('TRADE_OPENED','TRADE_CLOSED','SL_HIT','TP_HIT','CONNECTION_LOST','CONNECTION_RESTORED','DRAWDOWN_WARNING') NOT NULL,
  title VARCHAR(255) NOT NULL,
  message TEXT NULL,
  read_at DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY notifications_user_idx (user_id, created_at),
  CONSTRAINT notifications_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT notifications_account_fk FOREIGN KEY (account_id) REFERENCES trading_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
  id CHAR(36) NOT NULL PRIMARY KEY,
  user_id CHAR(36) NULL,
  action VARCHAR(128) NOT NULL,
  target VARCHAR(255) NULL,
  metadata JSON NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY audit_logs_user_idx (user_id, created_at),
  CONSTRAINT audit_logs_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
