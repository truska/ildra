-- Reusable choices for entry-form components, such as event menu selections.
CREATE TABLE IF NOT EXISTS entry_component_options (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    component_id INT UNSIGNED NOT NULL,
    label VARCHAR(255) NOT NULL,
    note TEXT DEFAULT NULL,
    price_adjustment DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    display_order INT UNSIGNED NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_component_options (component_id, is_active, display_order)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
