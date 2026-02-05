-- Inventory Management System Migration
-- Run this SQL to add inventory tracking tables

-- 1. Product Categories
CREATE TABLE IF NOT EXISTS product_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    sort_order INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 2. Products
CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    sku VARCHAR(50) DEFAULT NULL,
    size VARCHAR(20) DEFAULT NULL,
    color VARCHAR(50) DEFAULT NULL,
    variant_type ENUM('mesh', 'regular', 'standard') DEFAULT 'standard',
    low_stock_threshold INT DEFAULT 8,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT DEFAULT NULL,
    FOREIGN KEY (category_id) REFERENCES product_categories(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

-- 3. Inventory Counts (weekly snapshots per location)
CREATE TABLE IF NOT EXISTS inventory_counts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    location_id INT NOT NULL,
    count_date DATE NOT NULL,
    quantity INT NOT NULL DEFAULT 0,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT DEFAULT NULL,
    UNIQUE KEY unique_product_location_date (product_id, location_id, count_date),
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

-- 4. Order Requests (member requests for products)
CREATE TABLE IF NOT EXISTS order_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT DEFAULT NULL,
    location_id INT NOT NULL,
    member_name VARCHAR(255) NOT NULL,
    product_description VARCHAR(255) DEFAULT NULL,
    size_requested VARCHAR(20) DEFAULT NULL,
    color_requested VARCHAR(50) DEFAULT NULL,
    quantity INT DEFAULT 1,
    status ENUM('pending', 'ordered', 'received', 'completed', 'cancelled') DEFAULT 'pending',
    notes TEXT DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT DEFAULT NULL,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
    FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

-- =====================
-- SEED DATA: Categories
-- =====================
INSERT INTO product_categories (name, sort_order) VALUES
('Kids Gi', 1),
('Adults Gi', 2),
('Rashguards', 3),
('Shorts', 4),
('Belts', 5),
('Boxing Gloves', 6),
('Shinguards', 7),
('Handwraps', 8),
('Mouthguards', 9),
('Streetwear', 10),
('Misc', 11);

-- =====================
-- SEED DATA: Products
-- =====================

-- Kids Gi (Category 1)
INSERT INTO products (category_id, name, size, color, variant_type) VALUES
-- White Kids Gi
(1, 'Kids Gi White', 'Y1', 'White', 'standard'),
(1, 'Kids Gi White', 'Y2', 'White', 'standard'),
(1, 'Kids Gi White', 'Y3', 'White', 'standard'),
(1, 'Kids Gi White', 'Y4', 'White', 'standard'),
(1, 'Kids Gi White', 'Y5', 'White', 'standard'),
(1, 'Kids Gi White', 'Y6', 'White', 'standard'),
-- Navy Kids Gi
(1, 'Kids Gi Navy', 'Y1', 'Navy', 'standard'),
(1, 'Kids Gi Navy', 'Y2', 'Navy', 'standard'),
(1, 'Kids Gi Navy', 'Y3', 'Navy', 'standard'),
(1, 'Kids Gi Navy', 'Y4', 'Navy', 'standard'),
(1, 'Kids Gi Navy', 'Y5', 'Navy', 'standard'),
(1, 'Kids Gi Navy', 'Y6', 'Navy', 'standard');

-- Adults Gi (Category 2)
INSERT INTO products (category_id, name, size, color, variant_type) VALUES
-- White Adults Gi Regular
(2, 'Adults Gi White', 'A0', 'White', 'regular'),
(2, 'Adults Gi White', 'A1', 'White', 'regular'),
(2, 'Adults Gi White', 'A2', 'White', 'regular'),
(2, 'Adults Gi White', 'A3', 'White', 'regular'),
(2, 'Adults Gi White', 'A4', 'White', 'regular'),
(2, 'Adults Gi White', 'A5', 'White', 'regular'),
(2, 'Adults Gi White', 'A6', 'White', 'regular'),
-- White Adults Gi Mesh
(2, 'Adults Gi White Mesh', 'A0', 'White', 'mesh'),
(2, 'Adults Gi White Mesh', 'A1', 'White', 'mesh'),
(2, 'Adults Gi White Mesh', 'A2', 'White', 'mesh'),
(2, 'Adults Gi White Mesh', 'A3', 'White', 'mesh'),
(2, 'Adults Gi White Mesh', 'A4', 'White', 'mesh'),
(2, 'Adults Gi White Mesh', 'A5', 'White', 'mesh'),
(2, 'Adults Gi White Mesh', 'A6', 'White', 'mesh'),
-- Navy Adults Gi Regular
(2, 'Adults Gi Navy', 'A0', 'Navy', 'regular'),
(2, 'Adults Gi Navy', 'A1', 'Navy', 'regular'),
(2, 'Adults Gi Navy', 'A2', 'Navy', 'regular'),
(2, 'Adults Gi Navy', 'A3', 'Navy', 'regular'),
(2, 'Adults Gi Navy', 'A4', 'Navy', 'regular'),
(2, 'Adults Gi Navy', 'A5', 'Navy', 'regular'),
(2, 'Adults Gi Navy', 'A6', 'Navy', 'regular'),
-- Navy Adults Gi Mesh
(2, 'Adults Gi Navy Mesh', 'A0', 'Navy', 'mesh'),
(2, 'Adults Gi Navy Mesh', 'A1', 'Navy', 'mesh'),
(2, 'Adults Gi Navy Mesh', 'A2', 'Navy', 'mesh'),
(2, 'Adults Gi Navy Mesh', 'A3', 'Navy', 'mesh'),
(2, 'Adults Gi Navy Mesh', 'A4', 'Navy', 'mesh'),
(2, 'Adults Gi Navy Mesh', 'A5', 'Navy', 'mesh'),
(2, 'Adults Gi Navy Mesh', 'A6', 'Navy', 'mesh');

-- Rashguards (Category 3)
INSERT INTO products (category_id, name, size, color) VALUES
-- Kids Rashguards
(3, 'Kids Rashguard', 'YS', 'Navy'),
(3, 'Kids Rashguard', 'YM', 'Navy'),
(3, 'Kids Rashguard', 'YL', 'Navy'),
(3, 'Kids Rashguard', 'YXL', 'Navy'),
-- Adults Rashguards
(3, 'Adults Rashguard', 'S', 'Navy'),
(3, 'Adults Rashguard', 'M', 'Navy'),
(3, 'Adults Rashguard', 'L', 'Navy'),
(3, 'Adults Rashguard', 'XL', 'Navy'),
(3, 'Adults Rashguard', 'XXL', 'Navy');

-- Shorts (Category 4)
INSERT INTO products (category_id, name, size, color) VALUES
-- Kids Shorts
(4, 'Kids Fight Shorts', 'YS', 'Navy'),
(4, 'Kids Fight Shorts', 'YM', 'Navy'),
(4, 'Kids Fight Shorts', 'YL', 'Navy'),
(4, 'Kids Fight Shorts', 'YXL', 'Navy'),
-- Adults Shorts
(4, 'Adults Fight Shorts', 'S', 'Navy'),
(4, 'Adults Fight Shorts', 'M', 'Navy'),
(4, 'Adults Fight Shorts', 'L', 'Navy'),
(4, 'Adults Fight Shorts', 'XL', 'Navy'),
(4, 'Adults Fight Shorts', 'XXL', 'Navy');

-- Belts (Category 5)
INSERT INTO products (category_id, name, size, color) VALUES
(5, 'White Belt', 'A1', 'White'),
(5, 'White Belt', 'A2', 'White'),
(5, 'White Belt', 'A3', 'White'),
(5, 'White Belt', 'A4', 'White'),
(5, 'Gray/White Belt (Kids)', 'K0', 'Gray/White'),
(5, 'Gray/White Belt (Kids)', 'K1', 'Gray/White'),
(5, 'Gray/White Belt (Kids)', 'K2', 'Gray/White'),
(5, 'Gray/White Belt (Kids)', 'K3', 'Gray/White'),
(5, 'Gray Belt (Kids)', 'K0', 'Gray'),
(5, 'Gray Belt (Kids)', 'K1', 'Gray'),
(5, 'Gray Belt (Kids)', 'K2', 'Gray'),
(5, 'Gray Belt (Kids)', 'K3', 'Gray'),
(5, 'Gray/Black Belt (Kids)', 'K0', 'Gray/Black'),
(5, 'Gray/Black Belt (Kids)', 'K1', 'Gray/Black'),
(5, 'Gray/Black Belt (Kids)', 'K2', 'Gray/Black'),
(5, 'Gray/Black Belt (Kids)', 'K3', 'Gray/Black'),
(5, 'Yellow/White Belt (Kids)', 'K0', 'Yellow/White'),
(5, 'Yellow/White Belt (Kids)', 'K1', 'Yellow/White'),
(5, 'Yellow/White Belt (Kids)', 'K2', 'Yellow/White'),
(5, 'Yellow/White Belt (Kids)', 'K3', 'Yellow/White'),
(5, 'Yellow Belt (Kids)', 'K0', 'Yellow'),
(5, 'Yellow Belt (Kids)', 'K1', 'Yellow'),
(5, 'Yellow Belt (Kids)', 'K2', 'Yellow'),
(5, 'Yellow Belt (Kids)', 'K3', 'Yellow'),
(5, 'Yellow/Black Belt (Kids)', 'K0', 'Yellow/Black'),
(5, 'Yellow/Black Belt (Kids)', 'K1', 'Yellow/Black'),
(5, 'Yellow/Black Belt (Kids)', 'K2', 'Yellow/Black'),
(5, 'Yellow/Black Belt (Kids)', 'K3', 'Yellow/Black'),
(5, 'Orange/White Belt (Kids)', 'K0', 'Orange/White'),
(5, 'Orange/White Belt (Kids)', 'K1', 'Orange/White'),
(5, 'Orange/White Belt (Kids)', 'K2', 'Orange/White'),
(5, 'Orange/White Belt (Kids)', 'K3', 'Orange/White'),
(5, 'Orange Belt (Kids)', 'K0', 'Orange'),
(5, 'Orange Belt (Kids)', 'K1', 'Orange'),
(5, 'Orange Belt (Kids)', 'K2', 'Orange'),
(5, 'Orange Belt (Kids)', 'K3', 'Orange'),
(5, 'Orange/Black Belt (Kids)', 'K0', 'Orange/Black'),
(5, 'Orange/Black Belt (Kids)', 'K1', 'Orange/Black'),
(5, 'Orange/Black Belt (Kids)', 'K2', 'Orange/Black'),
(5, 'Orange/Black Belt (Kids)', 'K3', 'Orange/Black'),
(5, 'Green/White Belt (Kids)', 'K0', 'Green/White'),
(5, 'Green/White Belt (Kids)', 'K1', 'Green/White'),
(5, 'Green/White Belt (Kids)', 'K2', 'Green/White'),
(5, 'Green/White Belt (Kids)', 'K3', 'Green/White'),
(5, 'Green Belt (Kids)', 'K0', 'Green'),
(5, 'Green Belt (Kids)', 'K1', 'Green'),
(5, 'Green Belt (Kids)', 'K2', 'Green'),
(5, 'Green Belt (Kids)', 'K3', 'Green'),
(5, 'Green/Black Belt (Kids)', 'K0', 'Green/Black'),
(5, 'Green/Black Belt (Kids)', 'K1', 'Green/Black'),
(5, 'Green/Black Belt (Kids)', 'K2', 'Green/Black'),
(5, 'Green/Black Belt (Kids)', 'K3', 'Green/Black');

-- Boxing Gloves (Category 6)
INSERT INTO products (category_id, name, size, color) VALUES
(6, 'Boxing Gloves', '8oz', 'Black'),
(6, 'Boxing Gloves', '10oz', 'Black'),
(6, 'Boxing Gloves', '12oz', 'Black'),
(6, 'Boxing Gloves', '14oz', 'Black'),
(6, 'Boxing Gloves', '16oz', 'Black'),
(6, 'Kids Boxing Gloves', '4oz', 'Black'),
(6, 'Kids Boxing Gloves', '6oz', 'Black');

-- Shinguards (Category 7)
INSERT INTO products (category_id, name, size, color) VALUES
(7, 'Shinguards', 'S', 'Black'),
(7, 'Shinguards', 'M', 'Black'),
(7, 'Shinguards', 'L', 'Black'),
(7, 'Shinguards', 'XL', 'Black'),
(7, 'Kids Shinguards', 'YS', 'Black'),
(7, 'Kids Shinguards', 'YM', 'Black'),
(7, 'Kids Shinguards', 'YL', 'Black');

-- Handwraps (Category 8)
INSERT INTO products (category_id, name, size, color) VALUES
(8, 'Handwraps', '108"', 'Black'),
(8, 'Handwraps', '180"', 'Black'),
(8, 'Kids Handwraps', '108"', 'Black');

-- Mouthguards (Category 9)
INSERT INTO products (category_id, name, size, color) VALUES
(9, 'Mouthguard', 'Adult', 'Clear'),
(9, 'Mouthguard', 'Youth', 'Clear');

-- Streetwear (Category 10)
INSERT INTO products (category_id, name, size, color) VALUES
(10, 'GB T-Shirt', 'S', 'Navy'),
(10, 'GB T-Shirt', 'M', 'Navy'),
(10, 'GB T-Shirt', 'L', 'Navy'),
(10, 'GB T-Shirt', 'XL', 'Navy'),
(10, 'GB T-Shirt', 'XXL', 'Navy'),
(10, 'GB Hoodie', 'S', 'Navy'),
(10, 'GB Hoodie', 'M', 'Navy'),
(10, 'GB Hoodie', 'L', 'Navy'),
(10, 'GB Hoodie', 'XL', 'Navy'),
(10, 'GB Hoodie', 'XXL', 'Navy'),
(10, 'Kids GB T-Shirt', 'YS', 'Navy'),
(10, 'Kids GB T-Shirt', 'YM', 'Navy'),
(10, 'Kids GB T-Shirt', 'YL', 'Navy'),
(10, 'Kids GB T-Shirt', 'YXL', 'Navy');

-- Misc (Category 11)
INSERT INTO products (category_id, name, size, color) VALUES
(11, 'GB Patch', 'One Size', 'Navy'),
(11, 'GB Bag', 'One Size', 'Navy'),
(11, 'GB Water Bottle', 'One Size', 'Navy');
