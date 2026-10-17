-- P3_002 — Idempotency keys (Patch 6a: safe retries on weak internet)
--
-- One row per client-generated request key. It is inserted inside the SAME database transaction as the
-- work it protects (sale, receive stock, transfer, transfer receipt, stock adjustment) and stores the
-- response, so a retry with the same key returns the original result and never creates a second
-- record or moves stock twice. Requests without a key keep working as before.
--
--   client_key     UUID sent by the browser (unique index = the guarantee)
--   action         sale | stock_in | transfer_forward | transfer_receive | stock_adjust
--   user_id        who made the request (a key can't be replayed by someone else)
--   request_hash   sha256 of the submitted content (a different payload under the same key is refused)
--   reference_no   e.g. SALE-…, STK-…, TRF-… of the resulting record (for support lookups)
--   result_json    the original response body returned on replay
--
-- Safe to run more than once (CREATE TABLE IF NOT EXISTS). Old rows are only useful for a short time;
-- they may be deleted after ~30 days:  DELETE FROM idempotency_keys WHERE created_at < NOW() - INTERVAL 30 DAY;

CREATE TABLE IF NOT EXISTS idempotency_keys (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_key    CHAR(36)        NOT NULL,
  action        VARCHAR(40)     NOT NULL,
  user_id       INT UNSIGNED    NOT NULL,
  request_hash  CHAR(64)        NOT NULL,
  reference_no  VARCHAR(100)    NULL,
  result_json   MEDIUMTEXT      NULL,
  created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_idempotency_client_key (client_key),
  KEY idx_idempotency_created_at (created_at),
  KEY idx_idempotency_reference (reference_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
