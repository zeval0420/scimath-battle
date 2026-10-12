-- 011_add_round_to_questions.sql
--
-- Groups questions into contest rounds by difficulty. 'clincher' is the final
-- tie-break round, in which only manually selected teams take part.

ALTER TABLE scimath_questions
    ADD COLUMN `round` ENUM('easy', 'medium', 'hard', 'clincher')
        NOT NULL DEFAULT 'easy' AFTER category_id,
    ADD KEY idx_scimath_questions_event_round (event_id, `round`);
