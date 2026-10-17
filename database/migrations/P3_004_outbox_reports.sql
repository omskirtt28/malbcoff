-- P3_004 — Outbox "Needs action" reports (Patch 6c-1: offline outbox for stock actions)
--
-- When a queued stock action (Receive Stock, Branch Transfer create, Transfer receive) is finally refused by the
-- server (serial already received, not enough stock, transfer already received…), the phone reports it here so the
-- OWNER can see it. The report holds NO stock data of its own and never changes stock; it is only a to-do list.
--
--   client_key       the outbox item id = the Patch 6a idempotency key (UNIQUE → reporting twice is harmless)
--   user_id          who queued it (taken from the session, never from the browser)
--   branch_id        that user's branch (from the session)
--   action_type      stock_in | transfer_forward | transfer_receive
--   summary          one plain line, e.g. "Receive 2 × Samsung Galaxy A15 • 128GB"
--   details          optional extra lines (identifiers, destination…) for the owner
--   reason           why the server refused it, in plain English
--   queued_at        when the staff member tapped the button (phone clock, informational)
--   staff_discarded  1 = the staff member discarded the item on the device
--   status           open | resolved
--
-- Safe to run more than once (CREATE TABLE IF NOT EXISTS).

CREATE TABLE IF NOT EXISTS outbox_reports (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_key       CHAR(36)        NOT NULL,
  user_id          INT UNSIGNED    NOT NULL,
  branch_id        INT UNSIGNED    NULL,
  action_type      VARCHAR(40)     NOT NULL,
  summary          VARCHAR(255)    NOT NULL,
  details          VARCHAR(600)    NULL,
  reason           VARCHAR(500)    NOT NULL,
  queued_at        DATETIME        NULL,
  staff_discarded  TINYINT(1)      NOT NULL DEFAULT 0,
  status           VARCHAR(10)     NOT NULL DEFAULT 'open',
  resolved_by      INT UNSIGNED    NULL,
  resolved_at      DATETIME        NULL,
  resolved_note    VARCHAR(255)    NULL,
  created_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_outbox_reports_client_key (client_key),
  KEY idx_outbox_reports_status (status, created_at),
  KEY idx_outbox_reports_branch (branch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
