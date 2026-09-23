-- Optional demo seed
-- Email: trader@tradeview.local
-- Password: demo
-- Generate hash: php -r "echo password_hash('demo', PASSWORD_BCRYPT), PHP_EOL;"

INSERT INTO users (id, email, password_hash)
VALUES (
  'a0000000-0000-4000-8000-000000000001',
  'trader@tradeview.local',
  '$2y$12$gURSgtZcEF0ShfYT.D1l9uDUfZV5Wrvs3RunAuS/17AB5sBO055U6'
)
ON DUPLICATE KEY UPDATE email = VALUES(email);

INSERT INTO profiles (id, full_name, email, timezone, demo_mode)
VALUES (
  'a0000000-0000-4000-8000-000000000001',
  'Demo Trader',
  'trader@tradeview.local',
  'UTC',
  1
)
ON DUPLICATE KEY UPDATE full_name = VALUES(full_name);
