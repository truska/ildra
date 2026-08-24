-- Replace redundant calendar-year horse logbook period dates with valid_year.
--
-- IMPORTANT:
--   1. Back up horse_logbook_types and horse_logbook_purchases before running.
--   2. Run this migration before deploying code that expects purchase valid_year.
--   3. This does not alter type sale_starts/sale_ends or purchase purchased_at.

ALTER TABLE horse_logbook_purchases
    ADD COLUMN IF NOT EXISTS valid_year SMALLINT UNSIGNED NULL AFTER logbook_type_id;

-- Some environments have already removed one or both legacy period columns.
-- Build the backfill statement without referencing columns that do not exist.
SET @horse_logbook_has_ends_at = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'horse_logbook_purchases'
      AND column_name = 'ends_at'
);

SET @horse_logbook_has_starts_at = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'horse_logbook_purchases'
      AND column_name = 'starts_at'
);

SET @horse_logbook_backfill_sql = CONCAT(
    'UPDATE horse_logbook_purchases hlp ',
    'LEFT JOIN horse_logbook_types hlt ON hlt.id = hlp.logbook_type_id ',
    'SET hlp.valid_year = COALESCE(hlp.valid_year',
    IF(@horse_logbook_has_ends_at > 0, ', YEAR(hlp.ends_at)', ''),
    IF(@horse_logbook_has_starts_at > 0, ', YEAR(hlp.starts_at)', ''),
    ', hlt.valid_year, YEAR(hlp.purchased_at)) ',
    'WHERE hlp.valid_year IS NULL'
);

PREPARE horse_logbook_backfill FROM @horse_logbook_backfill_sql;
EXECUTE horse_logbook_backfill;
DEALLOCATE PREPARE horse_logbook_backfill;

-- Abort before destructive column removal if a row could not be classified.
DROP PROCEDURE IF EXISTS assert_horse_logbook_year_migration;
DELIMITER //
CREATE PROCEDURE assert_horse_logbook_year_migration()
BEGIN
    DECLARE unresolved_purchases INT DEFAULT 0;
    DECLARE duplicate_years INT DEFAULT 0;

    SELECT COUNT(*) INTO unresolved_purchases
    FROM horse_logbook_purchases
    WHERE valid_year IS NULL OR valid_year < 2000 OR valid_year > 2100;

    SELECT COUNT(*) INTO duplicate_years
    FROM (
        SELECT horse_id, valid_year
        FROM horse_logbook_purchases
        WHERE valid_year IS NOT NULL
        GROUP BY horse_id, valid_year
        HAVING COUNT(*) > 1
    ) duplicates;

    IF unresolved_purchases > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Horse-logbook migration stopped: unresolved or invalid years remain';
    END IF;

    IF duplicate_years > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Horse-logbook migration stopped: duplicate horse/year records require review';
    END IF;
END//
DELIMITER ;

CALL assert_horse_logbook_year_migration();
DROP PROCEDURE assert_horse_logbook_year_migration;

ALTER TABLE horse_logbook_purchases
    MODIFY COLUMN valid_year SMALLINT UNSIGNED NOT NULL,
    DROP COLUMN IF EXISTS starts_at,
    DROP COLUMN IF EXISTS ends_at;

ALTER TABLE horse_logbook_purchases
    ADD UNIQUE INDEX IF NOT EXISTS uniq_horse_logbook_year (horse_id, valid_year);

-- Verification output retained in SQL-client logs.
SELECT id, name, valid_year, sale_starts, sale_ends, status
FROM horse_logbook_types
ORDER BY valid_year DESC, id;

SELECT
    id,
    purchased_by_user_id,
    horse_id,
    logbook_type_id,
    valid_year,
    status,
    purchased_at,
    amount
FROM horse_logbook_purchases
ORDER BY valid_year DESC, purchased_at DESC, id;
