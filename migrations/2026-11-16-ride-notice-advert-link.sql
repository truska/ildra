-- Apply after deploying the matching account.php change and backing up the
-- destination database. Preview the target first; no consent data is changed.
SELECT id, name, title, url, link_target FROM advertising
WHERE LOWER(TRIM(name)) = 'rider email notifications'
   OR LOWER(TRIM(title)) = 'rider email notifications';

UPDATE advertising
SET url = '/account?view=people&email_preferences=ride_notice',
    link_target = '_self', updated_at = NOW()
WHERE LOWER(TRIM(name)) = 'rider email notifications'
   OR LOWER(TRIM(title)) = 'rider email notifications';
