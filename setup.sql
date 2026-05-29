-- ============================================================
-- Kape En Ato Database Setup
-- Run this in phpMyAdmin or MySQL CLI
-- ============================================================



-- Users Table (admin accounts)
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Menu Items Table
CREATE TABLE IF NOT EXISTS menu_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    description TEXT,
    category ENUM('Pizza','Pasta','Drinks','Appetizers') NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    is_special TINYINT(1) DEFAULT 0,
    image_path VARCHAR(255) DEFAULT 'default.jpg',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Online Orders Table (for customer online orders with email)
CREATE TABLE IF NOT EXISTS online_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL,
    phone VARCHAR(20),
    items TEXT NOT NULL,
    total_amount DECIMAL(10,2) DEFAULT 0,
    status ENUM('pending','confirmed','preparing','ready','completed','cancelled') DEFAULT 'pending',
    pickup_time DATETIME,
    special_instructions TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Add inventory tracking to menu_items
ALTER TABLE menu_items ADD COLUMN IF NOT EXISTS stock_quantity INT DEFAULT 100;
ALTER TABLE menu_items ADD COLUMN IF NOT EXISTS is_available TINYINT(1) DEFAULT 1;

-- Customer Feedback/Ratings Table
CREATE TABLE IF NOT EXISTS feedback (
    id INT AUTO_INCREMENT PRIMARY KEY,
    menu_item_id INT NOT NULL,
    customer_name VARCHAR(100),
    rating INT CHECK (rating >= 1 AND rating <= 5),
    comment TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE CASCADE
);

-- Insert default admin (password: admin123)
INSERT INTO users (username, password) VALUES 
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi')
ON DUPLICATE KEY UPDATE username=username;

-- Insert sample menu items
INSERT INTO menu_items (name, description, category, price, is_special, image_path, stock_quantity, is_available) VALUES
('Signature Margherita', 'Fresh tomato, mozzarella, basil on crispy thin crust', 'Pizza', 185.00, 1, 'default.jpg', 50, 1),
('Kapeinato Overload', 'Extra cheese, pepperoni, mushrooms, bell peppers', 'Pizza', 220.00, 1, 'default.jpg', 40, 1),
('Sunset Pasta Carbonara', 'Creamy sauce, bacon bits, parmesan, fresh herbs', 'Pasta', 165.00, 0, 'default.jpg', 30, 1),
('Pesto Pasta Primavera', 'Homemade basil pesto, seasonal vegetables', 'Pasta', 155.00, 0, 'default.jpg', 25, 1),
('Cold Brew Signature', 'Slow-steeped 18hrs, served over ice', 'Drinks', 95.00, 1, 'default.jpg', 100, 1),
('Bohol Sunset Frappe', 'Caramel, espresso, cream, golden drizzle', 'Drinks', 110.00, 1, 'default.jpg', 80, 1),
('Garlic Parmesan Wings', 'Crispy wings tossed in garlic parmesan butter', 'Appetizers', 145.00, 0, 'default.jpg', 35, 1),
('Truffle Fries', 'Thin cut fries, truffle oil, parmesan, herbs', 'Appetizers', 120.00, 0, 'default.jpg', 40, 1);