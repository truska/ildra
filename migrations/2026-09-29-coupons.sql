CREATE TABLE IF NOT EXISTS coupons (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  coupon_type ENUM('manual','ride_helper','marketing') NOT NULL DEFAULT 'manual',
  code VARCHAR(32) NOT NULL UNIQUE,
  title VARCHAR(160) NOT NULL,
  message_html MEDIUMTEXT NULL,
  terms_html MEDIUMTEXT NULL,
  amount DECIMAL(12,2) NOT NULL,
  valid_from DATETIME NOT NULL,
  valid_until DATETIME NOT NULL,
  event_id INT UNSIGNED NULL,
  usage_limit INT UNSIGNED NOT NULL DEFAULT 1,
  use_count INT UNSIGNED NOT NULL DEFAULT 0,
  per_person_limit INT UNSIGNED NOT NULL DEFAULT 0,
  can_convert_to_credit TINYINT(1) NOT NULL DEFAULT 1,
  recipient_email VARCHAR(190) NULL,
  issued_to_member_id INT UNSIGNED NULL,
  issued_event_id INT UNSIGNED NULL,
  status ENUM('active','cancelled','expired') NOT NULL DEFAULT 'active',
  created_by_user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX idx_coupons_active (status,valid_from,valid_until),
  INDEX idx_coupons_event (event_id)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS coupon_redemptions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  coupon_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  member_id INT UNSIGNED NULL,
  booking_ref VARCHAR(80) NULL,
  applied_amount DECIMAL(12,2) NOT NULL,
  credit_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  status ENUM('reserved','redeemed','released') NOT NULL DEFAULT 'reserved',
  reserved_until DATETIME NULL,
  created_at DATETIME NOT NULL,
  redeemed_at DATETIME NULL,
  INDEX idx_coupon_redemptions_coupon (coupon_id,status),
  INDEX idx_coupon_redemptions_person (coupon_id,member_id,status)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS coupon_audit (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  coupon_id INT UNSIGNED NOT NULL,
  actor_user_id INT UNSIGNED NULL,
  action VARCHAR(80) NOT NULL,
  notes TEXT NULL,
  metadata MEDIUMTEXT NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_coupon_audit_coupon (coupon_id,created_at)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
