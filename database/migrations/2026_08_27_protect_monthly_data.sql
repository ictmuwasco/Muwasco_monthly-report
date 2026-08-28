-- ============================================================
-- Migration: 2026_08_27_protect_monthly_data.sql
-- Purpose:   Protect historical reporting data from accidental
--            deletion caused by ON DELETE CASCADE foreign keys.
--
-- BEFORE:    monthly_data FKs cascade-delete historical values when a
--            month or parameter row is deleted.
-- AFTER:     Deletion attempts are rejected (RESTRICT), forcing an
--            explicit, deliberate decision first.
--
-- Data safety:
--   * Orphan check performed before applying: 0 orphans found
--     (monthly_data↔months, monthly_data↔parameters) — safe to tighten.
--   * No rows are added, modified or deleted. DDL only changes FK action.
--
-- Rollback strategy:
--   Restore the original constraints with:
--     ALTER TABLE monthly_data
--       DROP FOREIGN KEY fk_monthly_data_month,
--       DROP FOREIGN KEY fk_monthly_data_parameter,
--       ADD CONSTRAINT fk_monthly_data_month
--         FOREIGN KEY (month_id) REFERENCES months(id) ON DELETE CASCADE,
--       ADD CONSTRAINT fk_monthly_data_parameter
--         FOREIGN KEY (parameter_id) REFERENCES parameters(id)
--         ON DELETE CASCADE;
--   (Names reverted accordingly.)
--
-- Validation queries after apply:
--   SELECT COUNT(*) FROM monthly_data;                 -- must equal 1080
--   SHOW CREATE TABLE monthly_data\G                   -- inspect FK actions
-- ============================================================

ALTER TABLE monthly_data
  DROP FOREIGN KEY monthly_data_ibfk_1,
  DROP FOREIGN KEY monthly_data_ibfk_2;

ALTER TABLE monthly_data
  ADD CONSTRAINT fk_monthly_data_month
    FOREIGN KEY (month_id) REFERENCES months(id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT fk_monthly_data_parameter
    FOREIGN KEY (parameter_id) REFERENCES parameters(id)
    ON DELETE RESTRICT ON UPDATE CASCADE;
