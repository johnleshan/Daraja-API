-- Daraja Ledger schema (MySQL 5.7+/8.x, utf8mb4)
CREATE DATABASE IF NOT EXISTS daraja_ledger CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE daraja_ledger;

CREATE TABLE IF NOT EXISTS transactions (
  id          VARCHAR(64)  NOT NULL,
  system      VARCHAR(20)  NOT NULL DEFAULT 'ledger',
  type        VARCHAR(20)  NOT NULL DEFAULT 'c2b',          -- c2b | stk_push
  receipt     VARCHAR(40)  DEFAULT NULL,
  trans_time  DATETIME     NOT NULL,
  amount      DECIMAL(10,2) NOT NULL DEFAULT 0,
  phone       VARCHAR(20)  NOT NULL DEFAULT '',
  payer_name  VARCHAR(120) DEFAULT '',
  account_ref VARCHAR(120) DEFAULT '',
  status      VARCHAR(16)  NOT NULL DEFAULT 'pending',      -- pending | completed | failed | unknown
  result_desc VARCHAR(255) DEFAULT '',
  raw         JSON         NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id, system),
  KEY idx_time (trans_time),
  KEY idx_status (status),
  KEY idx_phone (phone),
  KEY idx_receipt (receipt)
) ENGINE=InnoDB;

-- per-day rollup for dashboards (optional but handy)
CREATE OR REPLACE VIEW daily_summary AS
SELECT DATE(trans_time) AS day,
       COUNT(*) AS tx_count,
       SUM(CASE WHEN status IN ('completed') THEN amount ELSE 0 END) AS received,
       SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
       SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed
FROM transactions
GROUP BY DATE(trans_time);

-- user the API logs in as (change password!)
CREATE USER IF NOT EXISTS 'daraja_api'@'localhost' IDENTIFIED BY 'CHANGE_ME_strong_password';
GRANT SELECT, INSERT, UPDATE, DELETE ON daraja_ledger.transactions TO 'daraja_api'@'localhost';
GRANT SELECT ON daraja_ledger.* TO 'daraja_api'@'localhost';
FLUSH PRIVILEGES;
