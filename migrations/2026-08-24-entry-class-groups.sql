-- Add presentation groups for the two-stage ride class selector.
-- The existing pricing row remains the booked and reported class.

ALTER TABLE pricing_scheme_rows
    ADD COLUMN IF NOT EXISTS class_group VARCHAR(32) NULL DEFAULT NULL AFTER class_code;

ALTER TABLE event_pricing_rows
    ADD COLUMN IF NOT EXISTS class_group VARCHAR(32) NULL DEFAULT NULL AFTER class_code;

UPDATE pricing_scheme_rows
SET class_group = CASE
    WHEN UPPER(TRIM(COALESCE(class_code, class_name, ''))) LIKE 'VPR%' THEN 'VPR'
    WHEN UPPER(TRIM(COALESCE(class_code, class_name, ''))) LIKE 'CTR%' THEN 'CTR'
    WHEN UPPER(TRIM(COALESCE(class_code, class_name, ''))) LIKE 'ER%' THEN 'ER'
    WHEN UPPER(TRIM(COALESCE(class_code, class_name, ''))) LIKE 'PR%' THEN 'PR'
    ELSE 'OTHER'
END
WHERE class_group IS NULL OR TRIM(class_group) = '';

UPDATE event_pricing_rows
SET class_group = CASE
    WHEN UPPER(TRIM(COALESCE(class_code, class_name, ''))) LIKE 'VPR%' THEN 'VPR'
    WHEN UPPER(TRIM(COALESCE(class_code, class_name, ''))) LIKE 'CTR%' THEN 'CTR'
    WHEN UPPER(TRIM(COALESCE(class_code, class_name, ''))) LIKE 'ER%' THEN 'ER'
    WHEN UPPER(TRIM(COALESCE(class_code, class_name, ''))) LIKE 'PR%' THEN 'PR'
    ELSE 'OTHER'
END
WHERE class_group IS NULL OR TRIM(class_group) = '';

SELECT 'pricing_scheme_rows' AS source, class_group, COUNT(*) AS row_count
FROM pricing_scheme_rows
GROUP BY class_group
UNION ALL
SELECT 'event_pricing_rows' AS source, class_group, COUNT(*) AS row_count
FROM event_pricing_rows
GROUP BY class_group
ORDER BY source, class_group;

-- Rollback after reverting the related source commit:
-- ALTER TABLE event_pricing_rows DROP COLUMN class_group;
-- ALTER TABLE pricing_scheme_rows DROP COLUMN class_group;
