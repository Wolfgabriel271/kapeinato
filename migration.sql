-- ============================================================
-- Kape Inato — Database Migrations
-- Run in phpMyAdmin → SQL tab (safe to re-run where noted)
-- ============================================================

-- ── Fix #2 — Payment System ──────────────────────────────────
ALTER TABLE online_orders 
    ADD COLUMN IF NOT EXISTS payment_status ENUM('unpaid','proof_submitted','confirmed') DEFAULT 'unpaid',
    ADD COLUMN IF NOT EXISTS payment_proof VARCHAR(255) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS payment_method VARCHAR(50) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS payment_confirmed_at DATETIME DEFAULT NULL;

-- ── Fix #10 — DB Normalization ───────────────────────────────
-- Line items in online_order_items; remove legacy TEXT `items` column.

CREATE TABLE IF NOT EXISTS online_order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    online_order_id INT NOT NULL,
    menu_item_id INT NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    price_at_time DECIMAL(10,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (online_order_id) REFERENCES online_orders(id) ON DELETE CASCADE,
    FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE RESTRICT,
    INDEX idx_online_order (online_order_id),
    INDEX idx_menu_item (menu_item_id)
);

-- Drop denormalized column (MariaDB 10.0.2+ / MySQL 8.0.29+)
-- If this fails on older MySQL, run manually: ALTER TABLE online_orders DROP COLUMN items;
ALTER TABLE online_orders DROP COLUMN IF EXISTS items;

-- Point sample/default menu rows at category placeholders (Fix #7)
UPDATE menu_items SET image_path = 'default_pizza.jpg'
    WHERE category = 'Pizza' AND (image_path = 'default.jpg' OR image_path = '' OR image_path IS NULL);
UPDATE menu_items SET image_path = 'default_pasta.jpg'
    WHERE category = 'Pasta' AND (image_path = 'default.jpg' OR image_path = '' OR image_path IS NULL);
UPDATE menu_items SET image_path = 'default_drinks.jpg'
    WHERE category = 'Drinks' AND (image_path = 'default.jpg' OR image_path = '' OR image_path IS NULL);
UPDATE menu_items SET image_path = 'default_appetizers.jpg'
    WHERE category = 'Appetizers' AND (image_path = 'default.jpg' OR image_path = '' OR image_path IS NULL);
