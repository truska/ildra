-- ILDRA upgrade: add loyalty card support
-- Date: 2026-02-02

CREATE TABLE IF NOT EXISTS loyalty_cards (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    card_number VARCHAR(24) NOT NULL,
    points_balance INT UNSIGNED NOT NULL DEFAULT 0,
    tier VARCHAR(20) NOT NULL DEFAULT 'bronze',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_loyalty_user (user_id),
    UNIQUE KEY uniq_loyalty_card_number (card_number),
    INDEX idx_loyalty_points (points_balance)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Backfill one card per existing user.
INSERT INTO loyalty_cards (user_id, card_number, points_balance, tier, created_at, updated_at)
SELECT u.id,
       CONCAT('LC-', LPAD(u.id, 6, '0')),
       0,
       'bronze',
       NOW(),
       NOW()
FROM users u
LEFT JOIN loyalty_cards lc ON lc.user_id = u.id
WHERE lc.user_id IS NULL;
