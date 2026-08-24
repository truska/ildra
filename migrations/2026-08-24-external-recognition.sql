-- External rider and horse recognition. These records grant ride eligibility
-- only and do not create ILDRA membership or horse logbook purchases.

CREATE TABLE IF NOT EXISTS recognised_organisations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) NOT NULL,
    name VARCHAR(190) NOT NULL,
    country_code CHAR(2) NOT NULL,
    verification_email VARCHAR(190) NULL,
    is_approved TINYINT(1) NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_recognised_org_code (code)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS external_recognition_applications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    subject_type ENUM('rider','horse') NOT NULL,
    person_id INT UNSIGNED NULL,
    horse_id INT UNSIGNED NULL,
    organisation_id INT UNSIGNED NULL,
    other_organisation_name VARCHAR(190) NULL,
    organisation_country_code CHAR(2) NOT NULL,
    credential_number VARCHAR(100) NOT NULL,
    valid_until DATE NOT NULL,
    status ENUM('pending','awaiting_verification','verified','rejected','expired','withdrawn') NOT NULL DEFAULT 'pending',
    applicant_notes TEXT NULL,
    admin_notes TEXT NULL,
    verification_token_hash CHAR(64) NULL,
    verification_requested_at DATETIME NULL,
    verified_at DATETIME NULL,
    reviewed_by_user_id INT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_recognition_user (user_id),
    INDEX idx_recognition_person (person_id,status,valid_until),
    INDEX idx_recognition_horse (horse_id,status,valid_until),
    INDEX idx_recognition_status (status),
    UNIQUE KEY uniq_recognition_token (verification_token_hash)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

INSERT INTO recognised_organisations (code,name,country_code,is_approved,is_active)
VALUES ('EI','Endurance Ireland','IE',1,1),('EGB','Endurance England and Wales','GB',1,1),('SERC','Endurance Scotland','GB',1,1)
ON DUPLICATE KEY UPDATE name=VALUES(name),country_code=VALUES(country_code),is_approved=VALUES(is_approved),is_active=VALUES(is_active);

INSERT INTO admin_menu_items (menu_key,label,href,icon_class,parent_id,display_order,is_active,required_roles,is_system)
VALUES ('external_recognition','External Recognition','external_recognition.php','fa-solid fa-id-card-clip',NULL,95,1,'superadmin,admin,manager',1)
ON DUPLICATE KEY UPDATE label=VALUES(label),href=VALUES(href),icon_class=VALUES(icon_class),display_order=VALUES(display_order),is_active=VALUES(is_active),required_roles=VALUES(required_roles),is_system=VALUES(is_system);

SELECT code,name,country_code,verification_email,is_approved,is_active FROM recognised_organisations ORDER BY name;

-- Rollback after reverting the related source commit:
-- DELETE FROM admin_menu_items WHERE menu_key='external_recognition';
-- DROP TABLE external_recognition_applications;
-- DROP TABLE recognised_organisations;
