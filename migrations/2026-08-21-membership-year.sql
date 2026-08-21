-- Replace redundant calendar-year membership period dates with membership_year.
--
-- IMPORTANT:
--   1. Back up membership_types and membership_purchases before running this file.
--   2. Run this migration before deploying code that expects membership_year.
--   3. This does not alter sale_starts, sale_ends or purchased_at.
--   4. Horse logbook tables are intentionally outside this migration.

ALTER TABLE membership_types
    ADD COLUMN IF NOT EXISTS membership_year SMALLINT UNSIGNED NULL AFTER sale_ends;

UPDATE membership_types
SET membership_year = COALESCE(
    membership_year,
    YEAR(membership_ends),
    YEAR(membership_starts),
    YEAR(sale_ends),
    CAST(NULLIF(REGEXP_SUBSTR(name, '(19|20)[0-9]{2}'), '') AS UNSIGNED)
)
WHERE membership_year IS NULL;

ALTER TABLE membership_purchases
    ADD COLUMN IF NOT EXISTS membership_year SMALLINT UNSIGNED NULL AFTER membership_type_id;

UPDATE membership_purchases mp
LEFT JOIN membership_types mt ON mt.id = mp.membership_type_id
SET mp.membership_year = COALESCE(
    mp.membership_year,
    YEAR(mp.ends_at),
    YEAR(mp.starts_at),
    mt.membership_year,
    YEAR(mp.purchased_at)
)
WHERE mp.membership_year IS NULL;

-- Abort before destructive column removal if any row could not be classified.
DROP PROCEDURE IF EXISTS assert_membership_year_migration;
DELIMITER //
CREATE PROCEDURE assert_membership_year_migration()
BEGIN
    DECLARE unresolved_types INT DEFAULT 0;
    DECLARE unresolved_purchases INT DEFAULT 0;

    SELECT COUNT(*) INTO unresolved_types
    FROM membership_types
    WHERE membership_year IS NULL OR membership_year < 2000 OR membership_year > 2100;

    SELECT COUNT(*) INTO unresolved_purchases
    FROM membership_purchases
    WHERE membership_year IS NULL OR membership_year < 2000 OR membership_year > 2100;

    IF unresolved_types > 0 OR unresolved_purchases > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Membership-year migration stopped: unresolved or invalid years remain';
    END IF;
END//
DELIMITER ;

CALL assert_membership_year_migration();
DROP PROCEDURE assert_membership_year_migration;

ALTER TABLE membership_types
    MODIFY COLUMN membership_year SMALLINT UNSIGNED NOT NULL,
    DROP COLUMN IF EXISTS membership_starts,
    DROP COLUMN IF EXISTS membership_ends;

ALTER TABLE membership_purchases
    MODIFY COLUMN membership_year SMALLINT UNSIGNED NOT NULL,
    DROP COLUMN IF EXISTS starts_at,
    DROP COLUMN IF EXISTS ends_at;

-- Verification output retained in SQL-client logs.
SELECT id, name, membership_year, sale_starts, sale_ends, status
FROM membership_types
ORDER BY membership_year DESC, id;

SELECT
    mp.id,
    mp.purchased_by_user_id,
    mp.member_id,
    mp.membership_type_id,
    mp.membership_year,
    mp.status,
    mp.purchased_at,
    mp.amount
FROM membership_purchases mp
ORDER BY mp.membership_year DESC, mp.purchased_at DESC, mp.id;
