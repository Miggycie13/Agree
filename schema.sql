-- AGREE: web marketplace for agricultural cooperatives in Ibaan, Batangas
-- Local XAMPP:  c:\xampp\mysql\bin\mysql.exe -u root < schema.sql
-- InfinityFree: create the database in the control panel, open phpMyAdmin,
-- select that database, and import this file. If CREATE DATABASE is refused,
-- delete the CREATE DATABASE and USE lines, then import again.
-- Re-importing wipes demo data and restores the accounts below.
-- Demo password for every seeded account: Agree@2026

CREATE DATABASE IF NOT EXISTS agree_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE agree_db;

SET NAMES utf8mb4;

DROP TABLE IF EXISTS reviews;
DROP TABLE IF EXISTS payments;
DROP TABLE IF EXISTS messages;
DROP TABLE IF EXISTS orders;
DROP TABLE IF EXISTS quotes;
DROP TABLE IF EXISTS rfqs;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS price_snapshots;
DROP TABLE IF EXISTS users;

CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role ENUM('admin', 'cooperative', 'buyer') NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(150) NOT NULL,
    phone VARCHAR(30) NOT NULL,
    organization VARCHAR(190) NOT NULL,
    business_type VARCHAR(80) DEFAULT NULL,
    barangay VARCHAR(80) NOT NULL,
    municipality VARCHAR(80) NOT NULL DEFAULT 'Ibaan',
    province VARCHAR(80) NOT NULL DEFAULT 'Batangas',
    address_line VARCHAR(255) NOT NULL,
    latitude DECIMAL(10,7) DEFAULT NULL,
    longitude DECIMAL(10,7) DEFAULT NULL,
    permit_path VARCHAR(255) DEFAULT NULL,
    verification_status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    verification_note VARCHAR(500) DEFAULT NULL,
    verified_at DATETIME DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE products (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    coop_id INT UNSIGNED NOT NULL,
    category ENUM('live_chicken', 'dressed_chicken', 'table_eggs', 'agri_supply') NOT NULL,
    name VARCHAR(150) NOT NULL,
    description TEXT,
    unit VARCHAR(40) NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    stock DECIMAL(10,2) NOT NULL DEFAULT 0,
    moq DECIMAL(10,2) NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_products_coop FOREIGN KEY (coop_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_products_market (category, is_active, price)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE price_snapshots (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category ENUM('live_chicken', 'dressed_chicken', 'table_eggs', 'agri_supply') NOT NULL,
    avg_price DECIMAL(10,2) NOT NULL,
    sample_size INT UNSIGNED NOT NULL DEFAULT 0,
    recorded_on DATE NOT NULL,
    UNIQUE KEY uniq_cat_day (category, recorded_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rfqs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    buyer_id INT UNSIGNED NOT NULL,
    coop_id INT UNSIGNED DEFAULT NULL,
    product_id INT UNSIGNED DEFAULT NULL,
    category ENUM('live_chicken', 'dressed_chicken', 'table_eggs', 'agri_supply') NOT NULL,
    item_name VARCHAR(150) NOT NULL,
    quantity DECIMAL(10,2) NOT NULL,
    unit VARCHAR(40) NOT NULL,
    needed_by DATE NOT NULL,
    delivery_address VARCHAR(255) NOT NULL,
    notes TEXT,
    status ENUM('open', 'quoted', 'accepted', 'closed', 'cancelled') NOT NULL DEFAULT 'open',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_rfqs_buyer FOREIGN KEY (buyer_id) REFERENCES users(id),
    CONSTRAINT fk_rfqs_coop FOREIGN KEY (coop_id) REFERENCES users(id),
    CONSTRAINT fk_rfqs_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE quotes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rfq_id INT UNSIGNED NOT NULL,
    coop_id INT UNSIGNED NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    quantity DECIMAL(10,2) NOT NULL,
    total_amount DECIMAL(12,2) NOT NULL,
    deposit_percent TINYINT UNSIGNED NOT NULL DEFAULT 50,
    delivery_date DATE NOT NULL,
    valid_until DATE NOT NULL,
    notes TEXT,
    status ENUM('pending', 'accepted', 'declined', 'withdrawn') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_quotes_rfq FOREIGN KEY (rfq_id) REFERENCES rfqs(id) ON DELETE CASCADE,
    CONSTRAINT fk_quotes_coop FOREIGN KEY (coop_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE orders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_code VARCHAR(24) NOT NULL UNIQUE,
    quote_id INT UNSIGNED NOT NULL,
    rfq_id INT UNSIGNED NOT NULL,
    buyer_id INT UNSIGNED NOT NULL,
    coop_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED DEFAULT NULL,
    item_summary VARCHAR(190) NOT NULL,
    quantity DECIMAL(10,2) NOT NULL,
    unit VARCHAR(40) NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    total_amount DECIMAL(12,2) NOT NULL,
    deposit_amount DECIMAL(12,2) NOT NULL,
    balance_due DECIMAL(12,2) NOT NULL,
    delivery_date DATE NOT NULL,
    delivery_address VARCHAR(255) NOT NULL,
    order_status ENUM('pending_payment', 'processing', 'in_transit', 'fulfilled', 'cancelled') NOT NULL DEFAULT 'pending_payment',
    payment_status ENUM('awaiting_deposit', 'deposit_review', 'deposit_verified', 'cod_balance', 'settled') NOT NULL DEFAULT 'awaiting_deposit',
    stock_deducted TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_orders_quote FOREIGN KEY (quote_id) REFERENCES quotes(id),
    CONSTRAINT fk_orders_rfq FOREIGN KEY (rfq_id) REFERENCES rfqs(id),
    CONSTRAINT fk_orders_buyer FOREIGN KEY (buyer_id) REFERENCES users(id),
    CONSTRAINT fk_orders_coop FOREIGN KEY (coop_id) REFERENCES users(id),
    CONSTRAINT fk_orders_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
    INDEX idx_orders_coop_delivery (coop_id, delivery_date),
    INDEX idx_orders_buyer (buyer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    payer_id INT UNSIGNED NOT NULL,
    kind ENUM('deposit', 'balance') NOT NULL DEFAULT 'deposit',
    amount DECIMAL(12,2) NOT NULL,
    receipt_path VARCHAR(255) NOT NULL,
    reference_no VARCHAR(80) DEFAULT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    reviewer_id INT UNSIGNED DEFAULT NULL,
    reviewer_note VARCHAR(500) DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_payments_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_payments_payer FOREIGN KEY (payer_id) REFERENCES users(id),
    CONSTRAINT fk_payments_reviewer FOREIGN KEY (reviewer_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sender_id INT UNSIGNED NOT NULL,
    receiver_id INT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_messages_sender FOREIGN KEY (sender_id) REFERENCES users(id),
    CONSTRAINT fk_messages_receiver FOREIGN KEY (receiver_id) REFERENCES users(id),
    INDEX idx_messages_pair (sender_id, receiver_id, id),
    INDEX idx_messages_unread (receiver_id, is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reviews (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    buyer_id INT UNSIGNED NOT NULL,
    coop_id INT UNSIGNED NOT NULL,
    rating TINYINT UNSIGNED NOT NULL,
    comment VARCHAR(500) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_review_order (order_id),
    CONSTRAINT fk_reviews_order FOREIGN KEY (order_id) REFERENCES orders(id),
    CONSTRAINT fk_reviews_buyer FOREIGN KEY (buyer_id) REFERENCES users(id),
    CONSTRAINT fk_reviews_coop FOREIGN KEY (coop_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Password hash is bcrypt for Agree@2026
INSERT INTO users
    (id, role, email, password_hash, full_name, phone, organization, business_type, barangay, municipality, province, address_line, latitude, longitude, permit_path, verification_status, verified_at)
VALUES
    (1, 'admin', 'admin@agree.ph', '$2y$10$l3M98z3laQGWp.qJVlics.UnKUdzZX0eDqz6LJVbcpKaSfVz7jIhS', 'Maricel Hernandez', '09175550101', 'AGREE Municipal Registry', 'municipal_registry', 'Poblacion', 'Ibaan', 'Batangas', 'Municipal Agriculture Office, Poblacion, Ibaan, Batangas', 13.8192000, 121.1328000, NULL, 'approved', NOW()),
    (2, 'cooperative', 'coop.ibaan@agree.ph', '$2y$10$l3M98z3laQGWp.qJVlics.UnKUdzZX0eDqz6LJVbcpKaSfVz7jIhS', 'Elena Reyes', '09175550102', 'Ibaan Integrated Farmers Cooperative', 'agricultural_cooperative', 'Poblacion', 'Ibaan', 'Batangas', '123 J.P. Rizal Street, Poblacion, Ibaan, Batangas', 13.8192000, 121.1328000, 'cda-ibaan.png', 'approved', NOW()),
    (3, 'cooperative', 'coop.sanagustin@agree.ph', '$2y$10$l3M98z3laQGWp.qJVlics.UnKUdzZX0eDqz6LJVbcpKaSfVz7jIhS', 'Marco Dimaano', '09175550103', 'San Agustin Agricultural Cooperative', 'agricultural_cooperative', 'San Agustin', 'Ibaan', 'Batangas', 'San Agustin Road, Ibaan, Batangas', 13.8070000, 121.1705000, 'cda-sanagustin.png', 'approved', NOW()),
    (4, 'cooperative', 'coop.palindan@agree.ph', '$2y$10$l3M98z3laQGWp.qJVlics.UnKUdzZX0eDqz6LJVbcpKaSfVz7jIhS', 'Liza Catapang', '09175550104', 'Palindan Poultry Growers Cooperative', 'agricultural_cooperative', 'Palindan', 'Ibaan', 'Batangas', 'Sitio Maligaya, Palindan, Ibaan, Batangas', 13.7860000, 121.1215000, 'cda-palindan.png', 'approved', NOW()),
    (5, 'cooperative', 'coop.mabalor@agree.ph', '$2y$10$l3M98z3laQGWp.qJVlics.UnKUdzZX0eDqz6LJVbcpKaSfVz7jIhS', 'Roberto Magpantay', '09175550105', 'Mabalor Farmers Multi-Purpose Cooperative', 'agricultural_cooperative', 'Mabalor', 'Ibaan', 'Batangas', 'Barangay Hall Compound, Mabalor, Ibaan, Batangas', 13.8510000, 121.1395000, 'cda-mabalor.png', 'pending', NULL),
    (6, 'buyer', 'buyer.bahaykubo@agree.ph', '$2y$10$l3M98z3laQGWp.qJVlics.UnKUdzZX0eDqz6LJVbcpKaSfVz7jIhS', 'Andrea Villena', '09175550106', 'Bahay Kubo Kitchen', 'restaurant', 'Poblacion', 'Ibaan', 'Batangas', '45 Burgos Street, Poblacion, Ibaan, Batangas', 13.8214000, 121.1365000, NULL, 'approved', NOW()),
    (7, 'buyer', 'buyer.rosario@agree.ph', '$2y$10$l3M98z3laQGWp.qJVlics.UnKUdzZX0eDqz6LJVbcpKaSfVz7jIhS', 'Paolo Garcia', '09175550107', 'Rosario Institutional Foods', 'institutional', 'Poblacion', 'Rosario', 'Batangas', 'National Road, Rosario, Batangas', 13.8460000, 121.2060000, NULL, 'approved', NOW()),
    (8, 'buyer', 'buyer.freshmart@agree.ph', '$2y$10$l3M98z3laQGWp.qJVlics.UnKUdzZX0eDqz6LJVbcpKaSfVz7jIhS', 'Camille Salazar', '09175550108', 'Ibaan Fresh Mart', 'commercial', 'Quilo', 'Ibaan', 'Batangas', 'Quilo Public Market, Ibaan, Batangas', 13.8125000, 121.1080000, NULL, 'approved', NOW());

INSERT INTO products
    (id, coop_id, category, name, description, unit, price, stock, moq)
VALUES
    (1, 2, 'live_chicken', 'Live broiler chicken', 'Day-old to market-age broilers raised by Ibaan Integrated members. Picked up live or scheduled for slaughter after the deposit is verified.', 'head', 195.00, 400, 20),
    (2, 2, 'dressed_chicken', 'Dressed whole chicken', 'Chilled dressed broilers, packed for restaurant and institutional kitchens.', 'kg', 220.00, 180, 10),
    (3, 2, 'table_eggs', 'Table eggs, tray of 30', 'Fresh table eggs collected from member layers. Sold by tray.', 'tray', 235.00, 300, 5),
    (4, 2, 'agri_supply', 'Rice bran feed', 'Cooperative feed supply for member and buyer farm use. Not part of the poultry price index.', 'sack', 850.00, 40, 2),
    (5, 3, 'live_chicken', 'Live native and broiler mix', 'San Agustin growers. State the preferred size in the RFQ notes.', 'head', 188.00, 250, 15),
    (6, 3, 'dressed_chicken', 'Dressed chicken, kitchen cut', 'Whole dressed birds. Cutting instructions can be added on the RFQ.', 'kg', 215.00, 120, 8),
    (7, 3, 'table_eggs', 'Table eggs, tray of 30', 'Medium-large table eggs from San Agustin layers.', 'tray', 228.00, 200, 4),
    (8, 4, 'live_chicken', 'Live chicken, premium size', 'Heavier live birds from Palindan growers. Higher unit price, tighter supply.', 'head', 205.00, 150, 10),
    (9, 4, 'dressed_chicken', 'Dressed chicken', 'Dressed birds chilled the morning of delivery.', 'kg', 230.00, 90, 5),
    (10, 4, 'table_eggs', 'Table eggs, tray of 30', 'Palindan table eggs packed in trays of 30.', 'tray', 242.00, 160, 5);

INSERT INTO price_snapshots (category, avg_price, sample_size, recorded_on) VALUES
    ('live_chicken', 188.00, 3, DATE_SUB(CURDATE(), INTERVAL 13 DAY)),
    ('live_chicken', 189.50, 3, DATE_SUB(CURDATE(), INTERVAL 12 DAY)),
    ('live_chicken', 188.75, 3, DATE_SUB(CURDATE(), INTERVAL 11 DAY)),
    ('live_chicken', 190.00, 3, DATE_SUB(CURDATE(), INTERVAL 10 DAY)),
    ('live_chicken', 191.25, 3, DATE_SUB(CURDATE(), INTERVAL 9 DAY)),
    ('live_chicken', 190.50, 3, DATE_SUB(CURDATE(), INTERVAL 8 DAY)),
    ('live_chicken', 192.00, 3, DATE_SUB(CURDATE(), INTERVAL 7 DAY)),
    ('live_chicken', 193.00, 3, DATE_SUB(CURDATE(), INTERVAL 6 DAY)),
    ('live_chicken', 192.50, 3, DATE_SUB(CURDATE(), INTERVAL 5 DAY)),
    ('live_chicken', 194.00, 3, DATE_SUB(CURDATE(), INTERVAL 4 DAY)),
    ('live_chicken', 195.25, 3, DATE_SUB(CURDATE(), INTERVAL 3 DAY)),
    ('live_chicken', 194.75, 3, DATE_SUB(CURDATE(), INTERVAL 2 DAY)),
    ('live_chicken', 196.00, 3, DATE_SUB(CURDATE(), INTERVAL 1 DAY)),
    ('dressed_chicken', 210.00, 3, DATE_SUB(CURDATE(), INTERVAL 13 DAY)),
    ('dressed_chicken', 212.00, 3, DATE_SUB(CURDATE(), INTERVAL 12 DAY)),
    ('dressed_chicken', 211.50, 3, DATE_SUB(CURDATE(), INTERVAL 11 DAY)),
    ('dressed_chicken', 214.00, 3, DATE_SUB(CURDATE(), INTERVAL 10 DAY)),
    ('dressed_chicken', 213.00, 3, DATE_SUB(CURDATE(), INTERVAL 9 DAY)),
    ('dressed_chicken', 215.50, 3, DATE_SUB(CURDATE(), INTERVAL 8 DAY)),
    ('dressed_chicken', 216.00, 3, DATE_SUB(CURDATE(), INTERVAL 7 DAY)),
    ('dressed_chicken', 217.25, 3, DATE_SUB(CURDATE(), INTERVAL 6 DAY)),
    ('dressed_chicken', 216.50, 3, DATE_SUB(CURDATE(), INTERVAL 5 DAY)),
    ('dressed_chicken', 218.00, 3, DATE_SUB(CURDATE(), INTERVAL 4 DAY)),
    ('dressed_chicken', 219.50, 3, DATE_SUB(CURDATE(), INTERVAL 3 DAY)),
    ('dressed_chicken', 220.00, 3, DATE_SUB(CURDATE(), INTERVAL 2 DAY)),
    ('dressed_chicken', 221.00, 3, DATE_SUB(CURDATE(), INTERVAL 1 DAY)),
    ('table_eggs', 221.00, 3, DATE_SUB(CURDATE(), INTERVAL 13 DAY)),
    ('table_eggs', 223.00, 3, DATE_SUB(CURDATE(), INTERVAL 12 DAY)),
    ('table_eggs', 222.50, 3, DATE_SUB(CURDATE(), INTERVAL 11 DAY)),
    ('table_eggs', 225.00, 3, DATE_SUB(CURDATE(), INTERVAL 10 DAY)),
    ('table_eggs', 226.00, 3, DATE_SUB(CURDATE(), INTERVAL 9 DAY)),
    ('table_eggs', 224.75, 3, DATE_SUB(CURDATE(), INTERVAL 8 DAY)),
    ('table_eggs', 227.00, 3, DATE_SUB(CURDATE(), INTERVAL 7 DAY)),
    ('table_eggs', 229.00, 3, DATE_SUB(CURDATE(), INTERVAL 6 DAY)),
    ('table_eggs', 228.50, 3, DATE_SUB(CURDATE(), INTERVAL 5 DAY)),
    ('table_eggs', 231.00, 3, DATE_SUB(CURDATE(), INTERVAL 4 DAY)),
    ('table_eggs', 232.50, 3, DATE_SUB(CURDATE(), INTERVAL 3 DAY)),
    ('table_eggs', 233.00, 3, DATE_SUB(CURDATE(), INTERVAL 2 DAY)),
    ('table_eggs', 234.25, 3, DATE_SUB(CURDATE(), INTERVAL 1 DAY));

INSERT INTO rfqs
    (id, buyer_id, coop_id, product_id, category, item_name, quantity, unit, needed_by, delivery_address, notes, status, created_at)
VALUES
    (1, 6, 2, 1, 'live_chicken', 'Live broiler chicken', 50, 'head', DATE_SUB(CURDATE(), INTERVAL 12 DAY), '45 Burgos Street, Poblacion, Ibaan, Batangas', 'Weekend family-style service. Average market weight is fine.', 'accepted', DATE_SUB(NOW(), INTERVAL 16 DAY)),
    (2, 7, 3, 6, 'dressed_chicken', 'Dressed chicken, kitchen cut', 80, 'kg', DATE_ADD(CURDATE(), INTERVAL 2 DAY), 'National Road, Rosario, Batangas', 'Institutional kitchen. Deliver chilled, whole dressed.', 'accepted', DATE_SUB(NOW(), INTERVAL 3 DAY)),
    (3, 8, 4, 10, 'table_eggs', 'Table eggs, tray of 30', 30, 'tray', DATE_ADD(CURDATE(), INTERVAL 4 DAY), 'Quilo Public Market, Ibaan, Batangas', 'Retail resale at Ibaan Fresh Mart. No cracked trays.', 'accepted', DATE_SUB(NOW(), INTERVAL 1 DAY)),
    (4, 6, NULL, NULL, 'dressed_chicken', 'Dressed whole chicken', 40, 'kg', DATE_ADD(CURDATE(), INTERVAL 6 DAY), '45 Burgos Street, Poblacion, Ibaan, Batangas', 'Open to any verified Ibaan cooperative. Need a firm quote before Friday.', 'quoted', DATE_SUB(NOW(), INTERVAL 1 DAY)),
    (5, 7, NULL, NULL, 'table_eggs', 'Table eggs', 20, 'tray', DATE_ADD(CURDATE(), INTERVAL 8 DAY), 'National Road, Rosario, Batangas', 'Standing breakfast supply for the canteen week.', 'open', DATE_SUB(NOW(), INTERVAL 2 HOUR)),
    (6, 8, 3, 7, 'table_eggs', 'Table eggs, tray of 30', 15, 'tray', DATE_SUB(CURDATE(), INTERVAL 20 DAY), 'Quilo Public Market, Ibaan, Batangas', 'First trial order for the mart.', 'accepted', DATE_SUB(NOW(), INTERVAL 24 DAY));

INSERT INTO quotes
    (id, rfq_id, coop_id, unit_price, quantity, total_amount, deposit_percent, delivery_date, valid_until, notes, status, created_at)
VALUES
    (1, 1, 2, 195.00, 50, 9750.00, 50, DATE_SUB(CURDATE(), INTERVAL 10 DAY), DATE_SUB(CURDATE(), INTERVAL 13 DAY), 'Deposit 50 percent. Balance cash on delivery at the kitchen door.', 'accepted', DATE_SUB(NOW(), INTERVAL 15 DAY)),
    (2, 2, 3, 215.00, 80, 17200.00, 50, DATE_ADD(CURDATE(), INTERVAL 2 DAY), DATE_ADD(CURDATE(), INTERVAL 1 DAY), 'Birds are released for dressing only after the deposit receipt is approved.', 'accepted', DATE_SUB(NOW(), INTERVAL 2 DAY)),
    (3, 3, 4, 242.00, 30, 7260.00, 50, DATE_ADD(CURDATE(), INTERVAL 4 DAY), DATE_ADD(CURDATE(), INTERVAL 2 DAY), 'Trays packed the morning of delivery.', 'accepted', DATE_SUB(NOW(), INTERVAL 20 HOUR)),
    (4, 4, 4, 228.00, 40, 9120.00, 40, DATE_ADD(CURDATE(), INTERVAL 6 DAY), DATE_ADD(CURDATE(), INTERVAL 3 DAY), 'Palindan can cover 40 kg. Deposit 40 percent, balance COD in Poblacion.', 'pending', DATE_SUB(NOW(), INTERVAL 5 HOUR)),
    (5, 6, 3, 228.00, 15, 3420.00, 50, DATE_SUB(CURDATE(), INTERVAL 18 DAY), DATE_SUB(CURDATE(), INTERVAL 21 DAY), 'Trial tray price held for this first order.', 'accepted', DATE_SUB(NOW(), INTERVAL 23 DAY));

INSERT INTO orders
    (id, order_code, quote_id, rfq_id, buyer_id, coop_id, product_id, item_summary, quantity, unit, unit_price, total_amount, deposit_amount, balance_due, delivery_date, delivery_address, order_status, payment_status, stock_deducted, created_at)
VALUES
    (1, 'AGR-260910-KUBO1', 1, 1, 6, 2, 1, 'Live broiler chicken', 50, 'head', 195.00, 9750.00, 4875.00, 4875.00, DATE_SUB(CURDATE(), INTERVAL 10 DAY), '45 Burgos Street, Poblacion, Ibaan, Batangas', 'fulfilled', 'settled', 1, DATE_SUB(NOW(), INTERVAL 15 DAY)),
    (2, 'AGR-261003-ROSA1', 2, 2, 7, 3, 6, 'Dressed chicken, kitchen cut', 80, 'kg', 215.00, 17200.00, 8600.00, 8600.00, DATE_ADD(CURDATE(), INTERVAL 2 DAY), 'National Road, Rosario, Batangas', 'processing', 'deposit_verified', 1, DATE_SUB(NOW(), INTERVAL 2 DAY)),
    (3, 'AGR-261005-MART1', 3, 3, 8, 4, 10, 'Table eggs, tray of 30', 30, 'tray', 242.00, 7260.00, 3630.00, 3630.00, DATE_ADD(CURDATE(), INTERVAL 4 DAY), 'Quilo Public Market, Ibaan, Batangas', 'pending_payment', 'deposit_review', 0, DATE_SUB(NOW(), INTERVAL 18 HOUR)),
    (4, 'AGR-260912-MART2', 5, 6, 8, 3, 7, 'Table eggs, tray of 30', 15, 'tray', 228.00, 3420.00, 1710.00, 1710.00, DATE_SUB(CURDATE(), INTERVAL 18 DAY), 'Quilo Public Market, Ibaan, Batangas', 'fulfilled', 'settled', 1, DATE_SUB(NOW(), INTERVAL 23 DAY));

INSERT INTO payments
    (id, order_id, payer_id, kind, amount, receipt_path, reference_no, status, reviewer_id, reviewer_note, reviewed_at, created_at)
VALUES
    (1, 1, 6, 'deposit', 4875.00, 'sample-deposit.png', 'DEP-44110', 'approved', 2, 'Deposit matched the quoted 50 percent. Released for slaughter.', DATE_SUB(NOW(), INTERVAL 14 DAY), DATE_SUB(NOW(), INTERVAL 14 DAY)),
    (2, 2, 7, 'deposit', 8600.00, 'sample-deposit.png', 'DEP-55218', 'approved', 3, 'Bank receipt verified. Dressing scheduled.', DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY)),
    (3, 3, 8, 'deposit', 3630.00, 'sample-deposit.png', 'DEP-88421', 'pending', NULL, NULL, NULL, DATE_SUB(NOW(), INTERVAL 2 HOUR)),
    (4, 4, 8, 'deposit', 1710.00, 'sample-deposit.png', 'DEP-33002', 'approved', 3, 'Trial order deposit verified.', DATE_SUB(NOW(), INTERVAL 22 DAY), DATE_SUB(NOW(), INTERVAL 22 DAY));

INSERT INTO messages (id, sender_id, receiver_id, body, is_read, created_at) VALUES
    (1, 6, 2, 'Good morning. We need about 50 live broilers for the weekend service at Bahay Kubo. Can you hold a quote through Thursday?', 1, DATE_SUB(NOW(), INTERVAL 16 DAY)),
    (2, 2, 6, 'Yes. I posted the binding quote at 195 per head, 50 percent deposit, balance on delivery at Burgos Street.', 1, DATE_SUB(NOW(), INTERVAL 15 DAY)),
    (3, 6, 2, 'Deposit is in. The birds arrived on weight and the kitchen was happy. We will send another RFQ for dressed chicken.', 1, DATE_SUB(NOW(), INTERVAL 9 DAY)),
    (4, 7, 3, 'Can the 80 kg dressed order leave San Agustin early so it reaches Rosario before the lunch service?', 0, DATE_SUB(NOW(), INTERVAL 3 HOUR)),
    (5, 4, 8, 'We saw the egg RFQ for 30 trays. Quote is in. We will pack only after the deposit receipt is approved.', 1, DATE_SUB(NOW(), INTERVAL 18 HOUR)),
    (6, 8, 4, 'Receipt reference DEP-88421 is uploaded. Please check it before you set aside the trays.', 1, DATE_SUB(NOW(), INTERVAL 2 HOUR)),
    (7, 1, 5, 'Mabalor registration is in the queue. The CDA certificate scan needs a clearer registration number before AGREE can approve selling under RA 11967.', 0, DATE_SUB(NOW(), INTERVAL 1 DAY));

INSERT INTO reviews (id, order_id, buyer_id, coop_id, rating, comment, created_at) VALUES
    (1, 1, 6, 2, 5, 'On-time delivery and the birds matched the quoted weight. We will reorder for weekend service.', DATE_SUB(NOW(), INTERVAL 9 DAY)),
    (2, 4, 8, 3, 4, 'Trays were complete and the price matched the Ibaan index. One tray had two cracked eggs.', DATE_SUB(NOW(), INTERVAL 17 DAY));
