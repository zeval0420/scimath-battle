-- 012_add_clincher_flag_to_contestants.sql
--
-- Marks the teams that the operator manually selects to enter the clincher
-- round. Teams with is_clincher = 0 do not participate in that round.

ALTER TABLE scimath_contestants
    ADD COLUMN is_clincher BOOLEAN NOT NULL DEFAULT 0 AFTER is_active,
    ADD KEY idx_scimath_contestants_event_clincher (event_id, is_clincher);
