-- =================================================================
-- ShopSphere - Seed Data
-- Demo categories, sellers, customers, products, and sample orders
-- =================================================================
-- Run AFTER importing shopsphere.sql (the schema)
-- =================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------------
-- Website Settings
-- -----------------------------------------------------------------
INSERT INTO website_settings (setting_key, setting_value, setting_group) VALUES
('site_name', 'ShopSphere', 'general'),
('site_tagline', 'Your Premier Multi-Vendor Marketplace', 'general'),
('announcement_text', 'Free shipping on orders over Rs. 2,000 — Use code FREESHIP', 'general'),
('contact_email', 'support@shopsphere.local', 'contact'),
('contact_phone', '+92 300 1234567', 'contact'),
('contact_address', '123 Commerce Street, Lahore, Pakistan', 'contact'),
('social_facebook', 'https://facebook.com/shopsphere', 'social'),
('social_instagram', 'https://instagram.com/shopsphere', 'social'),
('social_twitter', 'https://twitter.com/shopsphere', 'social'),
('currency', 'PKR', 'finance'),
('currency_symbol', 'Rs.', 'finance'),
('default_commission_rate', '10.00', 'finance'),
('min_payout_amount', '500.00', 'finance'),
('meta_title', 'ShopSphere - Shop Smarter, Live Better', 'seo'),
('meta_description', 'Pakistan''s leading multi-vendor online marketplace. Shop electronics, fashion, home & more from verified sellers.', 'seo')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

-- -----------------------------------------------------------------
-- Admin Account
-- Password: Admin@123
-- -----------------------------------------------------------------
INSERT INTO users (id, email, password_hash, first_name, last_name, phone, role, status, email_verified_at, created_at, updated_at) VALUES
(1, 'admin@shopsphere.local', '$2y$10$xMwnBI60sHuMAmQ07HlE9ufJnDOvaj2dmDHYPJyqz28MWIn1gF9fa', 'Super', 'Admin', '+923001234567', 'admin', 'active', NOW(), NOW(), NOW());

-- -----------------------------------------------------------------
-- Demo Sellers (2 sellers)
-- Password: Seller@123
-- -----------------------------------------------------------------
INSERT INTO users (id, email, password_hash, first_name, last_name, phone, role, status, email_verified_at, created_at, updated_at) VALUES
(2, 'techworld@shopsphere.local', '$2y$10$xMwnBI60sHuMAmQ07HlE9ufJnDOvaj2dmDHYPJyqz28MWIn1gF9fa', 'Ahmed', 'Khan', '+923011234567', 'seller', 'active', NOW(), NOW(), NOW()),
(3, 'fashionhub@shopsphere.local', '$2y$10$xMwnBI60sHuMAmQ07HlE9ufJnDOvaj2dmDHYPJyqz28MWIn1gF9fa', 'Sara', 'Ali', '+923021234567', 'seller', 'active', NOW(), NOW(), NOW());

INSERT INTO seller_profiles (user_id, store_name, store_slug, store_description, business_name, business_address, status, approved_at, rating_avg, total_sales, verified, created_at, updated_at) VALUES
(2, 'TechWorld Electronics', 'techworld-electronics', 'Your one-stop destination for the latest electronics, smartphones, laptops, and tech accessories. Quality products at competitive prices with fast delivery across Pakistan.', 'TechWorld Trading Co.', '45-B Gulberg III, Lahore', 'approved', NOW(), 4.50, 142, 1, NOW(), NOW()),
(3, 'FashionHub Pakistan', 'fashionhub-pakistan', 'Premium fashion store offering trendy clothing, shoes, bags, and accessories for men and women. Curated collections with the latest styles at affordable prices.', 'FashionHub Pvt. Ltd.', '12 Zamzama Commercial, Karachi', 'approved', NOW(), 4.30, 89, 1, NOW(), NOW());

-- -----------------------------------------------------------------
-- Demo Customers (2 customers)
-- Password: Customer@123
-- -----------------------------------------------------------------
INSERT INTO users (id, email, password_hash, first_name, last_name, phone, role, status, email_verified_at, created_at, updated_at) VALUES
(4, 'ali.customer@gmail.com', '$2y$10$xMwnBI60sHuMAmQ07HlE9ufJnDOvaj2dmDHYPJyqz28MWIn1gF9fa', 'Ali', 'Raza', '+923031234567', 'customer', 'active', NOW(), NOW(), NOW()),
(5, 'fatima.buyer@gmail.com', '$2y$10$xMwnBI60sHuMAmQ07HlE9ufJnDOvaj2dmDHYPJyqz28MWIn1gF9fa', 'Fatima', 'Hassan', '+923041234567', 'customer', 'active', NOW(), NOW(), NOW());

INSERT INTO customer_profiles (user_id, gender) VALUES
(4, 'male'),
(5, 'female');

INSERT INTO addresses (id, user_id, label, full_name, phone, address_line1, city, state, postal_code, country, is_default, created_at, updated_at) VALUES
(1, 4, 'Home', 'Ali Raza', '+923031234567', 'House 15, Street 4, DHA Phase 5', 'Lahore', 'Punjab', '54000', 'Pakistan', 1, NOW(), NOW()),
(2, 4, 'Office', 'Ali Raza', '+923031234567', 'Office 301, Arfa Tower, Ferozepur Road', 'Lahore', 'Punjab', '54000', 'Pakistan', 0, NOW(), NOW()),
(3, 5, 'Home', 'Fatima Hassan', '+923041234567', 'Flat 7B, Sea View Apartments, Clifton', 'Karachi', 'Sindh', '75600', 'Pakistan', 1, NOW(), NOW());

-- -----------------------------------------------------------------
-- Categories
-- -----------------------------------------------------------------
INSERT INTO categories (id, parent_id, name, slug, description, icon, commission_rate, sort_order, is_active, created_at, updated_at) VALUES
(1, NULL, 'Electronics', 'electronics', 'Smartphones, laptops, tablets, and electronic gadgets', 'bi-phone', 8.00, 1, 1, NOW(), NOW()),
(2, NULL, 'Fashion', 'fashion', 'Clothing, shoes, and accessories for men and women', 'bi-bag', 12.00, 2, 1, NOW(), NOW()),
(3, NULL, 'Home & Living', 'home-living', 'Furniture, home decor, and kitchen appliances', 'bi-house', 10.00, 3, 1, NOW(), NOW()),
(4, NULL, 'Beauty & Health', 'beauty-health', 'Skincare, makeup, personal care, and health products', 'bi-heart-pulse', 15.00, 4, 1, NOW(), NOW()),
(5, NULL, 'Sports & Outdoors', 'sports-outdoors', 'Sports equipment, fitness gear, and outdoor accessories', 'bi-bicycle', 10.00, 5, 1, NOW(), NOW()),
(6, NULL, 'Watches & Accessories', 'watches-accessories', 'Premium watches, jewelry, and fashion accessories', 'bi-watch', 12.00, 6, 1, NOW(), NOW()),
(7, NULL, 'Grocery & Essentials', 'grocery-essentials', 'Daily essentials, groceries, and household supplies', 'bi-basket', 5.00, 7, 1, NOW(), NOW()),
(8, NULL, 'Books & Stationery', 'books-stationery', 'Books, office supplies, and creative materials', 'bi-book', 8.00, 8, 1, NOW(), NOW()),
-- Sub-categories
(9, 1, 'Smartphones', 'smartphones', 'Latest smartphones from all brands', 'bi-phone', NULL, 1, 1, NOW(), NOW()),
(10, 1, 'Laptops', 'laptops', 'Laptops and notebooks', 'bi-laptop', NULL, 2, 1, NOW(), NOW()),
(11, 1, 'Audio & Headphones', 'audio-headphones', 'Earbuds, headphones, and speakers', 'bi-headphones', NULL, 3, 1, NOW(), NOW()),
(12, 2, 'Men''s Clothing', 'mens-clothing', 'Shirts, trousers, and casual wear', 'bi-person', NULL, 1, 1, NOW(), NOW()),
(13, 2, 'Women''s Clothing', 'womens-clothing', 'Dresses, tops, and traditional wear', 'bi-person-dress', NULL, 2, 1, NOW(), NOW()),
(14, 2, 'Shoes', 'shoes', 'Footwear for all occasions', 'bi-shop', NULL, 3, 1, NOW(), NOW()),
(15, 3, 'Furniture', 'furniture', 'Tables, chairs, and home furniture', 'bi-lamp', NULL, 1, 1, NOW(), NOW()),
(16, 3, 'Kitchen Appliances', 'kitchen-appliances', 'Blenders, toasters, and kitchen tools', 'bi-cup-hot', NULL, 2, 1, NOW(), NOW());

-- -----------------------------------------------------------------
-- Products (20 demo products)
-- -----------------------------------------------------------------
INSERT INTO products (id, seller_id, category_id, name, slug, description, short_description, price, compare_price, cost_price, sku, stock_quantity, low_stock_threshold, status, is_featured, is_trending, views_count, sales_count, rating_avg, rating_count, created_at, updated_at) VALUES
-- TechWorld Electronics products
(1, 2, 9, 'Samsung Galaxy S24 Ultra 256GB', 'samsung-galaxy-s24-ultra-256gb', 'Experience the pinnacle of mobile technology with the Samsung Galaxy S24 Ultra. Featuring a stunning 6.8" Dynamic AMOLED 2X display, powerful Snapdragon 8 Gen 3 processor, and an advanced quad camera system with 200MP main sensor. Built with titanium frame for premium durability.', 'Flagship smartphone with AI features, S Pen, and titanium build', 349999.00, 399999.00, 280000.00, 'TW-S24U-256', 25, 5, 'approved', 1, 1, 1240, 35, 4.70, 28, NOW(), NOW()),
(2, 2, 9, 'iPhone 15 Pro Max 256GB', 'iphone-15-pro-max-256gb', 'The most powerful iPhone ever. Featuring the A17 Pro chip, a customizable Action button, and a 48MP camera system with 5x optical zoom. Titanium design with USB-C connectivity and all-day battery life.', 'Apple''s premium flagship with titanium design and A17 Pro chip', 459999.00, 499999.00, 380000.00, 'TW-IP15PM-256', 18, 5, 'approved', 1, 1, 980, 22, 4.80, 18, NOW(), NOW()),
(3, 2, 10, 'MacBook Air M3 15" 256GB', 'macbook-air-m3-15-256gb', 'Impossibly thin and incredibly powerful. The 15-inch MacBook Air with M3 chip delivers up to 18 hours of battery life, a stunning Liquid Retina display, and seamless performance for work and creativity.', 'Ultra-thin laptop with Apple M3 chip and 18hr battery', 289999.00, 329999.00, 230000.00, 'TW-MBA-M3-15', 12, 3, 'approved', 1, 0, 650, 15, 4.60, 12, NOW(), NOW()),
(4, 2, 10, 'Dell XPS 15 Core i7 RTX 4060', 'dell-xps-15-i7-rtx4060', 'Premium Windows laptop with 15.6" OLED display, Intel Core i7-13700H, NVIDIA RTX 4060 graphics, 16GB RAM, and 512GB SSD. Perfect for professionals and creators.', 'Premium laptop with OLED display and dedicated GPU', 359999.00, 399999.00, 290000.00, 'TW-XPS15-I7', 8, 3, 'approved', 0, 1, 420, 8, 4.40, 6, NOW(), NOW()),
(5, 2, 11, 'Sony WH-1000XM5 Headphones', 'sony-wh-1000xm5-headphones', 'Industry-leading noise cancellation meets exceptional sound quality. Features 30 hours of battery life, multipoint Bluetooth, speak-to-chat, and a lightweight, comfortable design for all-day wearing.', 'Premium wireless noise-cancelling headphones', 79999.00, 89999.00, 55000.00, 'TW-SONY-XM5', 40, 10, 'approved', 1, 1, 890, 42, 4.80, 35, NOW(), NOW()),
(6, 2, 11, 'Apple AirPods Pro 2nd Gen', 'apple-airpods-pro-2nd-gen', 'Rebuilt from the sound up with the H2 chip. Features Adaptive Audio, Personalized Spatial Audio, and up to 2x more Active Noise Cancellation. MagSafe Charging Case with speaker and lanyard loop.', 'Premium wireless earbuds with adaptive noise cancellation', 54999.00, 62999.00, 40000.00, 'TW-APP2', 55, 10, 'approved', 0, 1, 1100, 58, 4.70, 45, NOW(), NOW()),
(7, 2, 1, 'Samsung 55" Crystal 4K UHD Smart TV', 'samsung-55-crystal-4k-tv', 'Immerse yourself in crystal-clear 4K resolution with Samsung''s Crystal Processor. Features HDR support, built-in streaming apps, voice control, and a sleek design.', 'Crystal clear 4K entertainment for your living room', 149999.00, 179999.00, 110000.00, 'TW-SAM55-4K', 15, 5, 'approved', 1, 0, 320, 12, 4.30, 8, NOW(), NOW()),
(8, 2, 1, 'JBL Charge 5 Bluetooth Speaker', 'jbl-charge-5-bluetooth-speaker', 'Powerful JBL Original Pro Sound with deep bass. Features IP67 waterproof and dustproof rating, 20 hours of playtime, built-in powerbank, and JBL PartyBoost for pairing multiple speakers.', 'Portable waterproof speaker with powerful bass', 29999.00, 35999.00, 20000.00, 'TW-JBL-CH5', 30, 5, 'approved', 0, 0, 280, 20, 4.50, 15, NOW(), NOW()),
(9, 2, 16, 'Ninja Professional Blender 1100W', 'ninja-professional-blender-1100w', 'Professional-grade blending power with Total Crushing Technology. Features a 72oz pitcher, 6 blade assembly, and multiple speed settings for smoothies, ice crushing, and food processing.', 'High-power blender for smoothies and food prep', 18999.00, 24999.00, 12000.00, 'TW-NINJA-1100', 20, 5, 'approved', 0, 0, 180, 14, 4.20, 9, NOW(), NOW()),
(10, 2, 1, 'Logitech MX Master 3S Wireless Mouse', 'logitech-mx-master-3s-mouse', 'The most advanced wireless mouse. Features MagSpeed scroll, 8K DPI tracking on any surface, USB-C charging, and Bolt connectivity. Perfect for productivity across multiple devices.', 'Premium wireless mouse for productivity professionals', 17999.00, 21999.00, 12000.00, 'TW-LG-MX3S', 45, 10, 'approved', 0, 1, 350, 25, 4.60, 18, NOW(), NOW()),

-- FashionHub products
(11, 3, 12, 'Premium Cotton Oxford Shirt - Navy Blue', 'premium-cotton-oxford-shirt-navy', 'Crafted from 100% Egyptian cotton, this classic Oxford shirt features a button-down collar, single chest pocket, and a tailored fit. Perfect for both office and casual occasions.', 'Classic tailored Oxford shirt in premium Egyptian cotton', 4999.00, 6999.00, 2500.00, 'FH-OXF-NVY', 60, 10, 'approved', 1, 0, 420, 38, 4.40, 22, NOW(), NOW()),
(12, 3, 13, 'Embroidered Lawn Suit - 3 Piece', 'embroidered-lawn-suit-3piece', 'Elegant three-piece lawn suit featuring intricate machine embroidery. Includes embroidered shirt front and back, printed chiffon dupatta, and dyed cambric trouser. Perfect for summer occasions.', 'Elegant embroidered lawn suit for summer', 5499.00, 7999.00, 2800.00, 'FH-LAWN-EMB', 80, 15, 'approved', 1, 1, 680, 52, 4.50, 35, NOW(), NOW()),
(13, 3, 14, 'Classic Leather Derby Shoes - Brown', 'classic-leather-derby-shoes-brown', 'Handcrafted genuine leather Derby shoes with Goodyear welt construction. Features a cushioned insole, rubber outsole for grip, and a timeless design suitable for formal and smart-casual wear.', 'Handcrafted genuine leather formal shoes', 12999.00, 16999.00, 7000.00, 'FH-DRB-BRN', 25, 5, 'approved', 0, 1, 310, 18, 4.30, 12, NOW(), NOW()),
(14, 3, 14, 'Running Performance Sneakers - Black', 'running-performance-sneakers-black', 'Lightweight performance running shoes with responsive cushioning, breathable mesh upper, and durable rubber outsole. Designed for comfort during long runs and everyday training.', 'Lightweight running shoes with responsive cushioning', 8999.00, 11999.00, 4500.00, 'FH-RUN-BLK', 35, 10, 'approved', 0, 0, 240, 22, 4.40, 15, NOW(), NOW()),
(15, 3, 2, 'Leather Crossbody Sling Bag', 'leather-crossbody-sling-bag', 'Versatile genuine leather crossbody bag with adjustable strap, multiple compartments, and RFID-blocking pocket. Available in classic tan color. Perfect for everyday carry.', 'Versatile genuine leather everyday bag', 6999.00, 9499.00, 3500.00, 'FH-BAG-TAN', 40, 10, 'approved', 0, 0, 290, 28, 4.20, 18, NOW(), NOW()),
(16, 3, 6, 'Casio Edifice Chronograph Watch', 'casio-edifice-chronograph-watch', 'Sleek chronograph watch with stainless steel case and band. Features date display, 100m water resistance, 1-second stopwatch, and a sophisticated blue dial. Solar-powered movement.', 'Solar-powered chronograph with stainless steel build', 24999.00, 32999.00, 15000.00, 'FH-CSIO-EDI', 20, 5, 'approved', 1, 0, 370, 16, 4.60, 11, NOW(), NOW()),
(17, 3, 4, 'The Ordinary Skincare Starter Set', 'the-ordinary-skincare-starter-set', 'Complete skincare routine set including Niacinamide 10% + Zinc 1%, Hyaluronic Acid 2% + B5, and Natural Moisturizing Factors. Suitable for all skin types.', 'Complete daily skincare essentials kit', 4499.00, 5999.00, 2200.00, 'FH-ORD-SET', 50, 10, 'approved', 0, 1, 520, 45, 4.70, 32, NOW(), NOW()),
(18, 3, 5, 'Premium Yoga Mat with Carry Strap', 'premium-yoga-mat-carry-strap', 'Extra thick 6mm yoga mat made from eco-friendly TPE material. Features double-sided non-slip texture, alignment marks, and includes a carry strap. Perfect for yoga, Pilates, and home workouts.', 'Eco-friendly non-slip yoga mat with alignment guides', 3499.00, 4999.00, 1500.00, 'FH-YOGA-MAT', 45, 10, 'approved', 0, 0, 180, 20, 4.30, 14, NOW(), NOW()),
(19, 3, 15, 'Modern Minimalist Office Desk', 'modern-minimalist-office-desk', 'Clean-lined modern office desk with spacious 120x60cm desktop. Features cable management holes, scratch-resistant melamine surface, and sturdy powder-coated steel frame. Easy assembly.', 'Sleek modern desk with cable management', 22999.00, 29999.00, 13000.00, 'FH-DESK-MIN', 10, 3, 'approved', 0, 0, 150, 6, 4.10, 4, NOW(), NOW()),
(20, 3, 7, 'Organic Green Tea Collection - 50 Bags', 'organic-green-tea-collection-50', 'Premium organic green tea sourced from high-altitude tea gardens. Collection includes Classic Green, Jasmine Green, and Matcha Green varieties. USDA Organic certified.', 'Premium organic green tea sampler pack', 1299.00, 1799.00, 600.00, 'FH-TEA-ORG', 100, 20, 'approved', 0, 0, 120, 35, 4.50, 22, NOW(), NOW());

-- Product images (primary only)
INSERT INTO product_images (product_id, image_path, alt_text, sort_order, is_primary, created_at) VALUES
(1, 'uploads/products/placeholder_phone.jpg', 'Samsung Galaxy S24 Ultra', 0, 1, NOW()),
(2, 'uploads/products/placeholder_phone.jpg', 'iPhone 15 Pro Max', 0, 1, NOW()),
(3, 'uploads/products/placeholder_laptop.jpg', 'MacBook Air M3', 0, 1, NOW()),
(4, 'uploads/products/placeholder_laptop.jpg', 'Dell XPS 15', 0, 1, NOW()),
(5, 'uploads/products/placeholder_headphones.jpg', 'Sony WH-1000XM5', 0, 1, NOW()),
(6, 'uploads/products/placeholder_earbuds.jpg', 'AirPods Pro 2', 0, 1, NOW()),
(7, 'uploads/products/placeholder_tv.jpg', 'Samsung 4K TV', 0, 1, NOW()),
(8, 'uploads/products/placeholder_speaker.jpg', 'JBL Charge 5', 0, 1, NOW()),
(9, 'uploads/products/placeholder_appliance.jpg', 'Ninja Blender', 0, 1, NOW()),
(10, 'uploads/products/placeholder_mouse.jpg', 'Logitech MX Master 3S', 0, 1, NOW()),
(11, 'uploads/products/placeholder_shirt.jpg', 'Oxford Shirt Navy', 0, 1, NOW()),
(12, 'uploads/products/placeholder_dress.jpg', 'Embroidered Lawn Suit', 0, 1, NOW()),
(13, 'uploads/products/placeholder_shoes.jpg', 'Derby Shoes Brown', 0, 1, NOW()),
(14, 'uploads/products/placeholder_sneakers.jpg', 'Running Sneakers', 0, 1, NOW()),
(15, 'uploads/products/placeholder_bag.jpg', 'Crossbody Sling Bag', 0, 1, NOW()),
(16, 'uploads/products/placeholder_watch.jpg', 'Casio Edifice Watch', 0, 1, NOW()),
(17, 'uploads/products/placeholder_skincare.jpg', 'The Ordinary Set', 0, 1, NOW()),
(18, 'uploads/products/placeholder_fitness.jpg', 'Yoga Mat', 0, 1, NOW()),
(19, 'uploads/products/placeholder_furniture.jpg', 'Office Desk', 0, 1, NOW()),
(20, 'uploads/products/placeholder_grocery.jpg', 'Organic Green Tea', 0, 1, NOW());

-- -----------------------------------------------------------------
-- Sample Commission Rules
-- -----------------------------------------------------------------
INSERT INTO commission_rules (type, category_id, seller_id, rate, effective_from, created_by, created_at, updated_at) VALUES
('default', NULL, NULL, 10.00, '2024-01-01', 1, NOW(), NOW()),
('category', 1, NULL, 8.00, '2024-01-01', 1, NOW(), NOW()),
('category', 2, NULL, 12.00, '2024-01-01', 1, NOW(), NOW()),
('category', 4, NULL, 15.00, '2024-01-01', 1, NOW(), NOW()),
('category', 7, NULL, 5.00, '2024-01-01', 1, NOW(), NOW());

-- -----------------------------------------------------------------
-- Sample Coupons
-- -----------------------------------------------------------------
INSERT INTO coupons (code, type, value, min_order_amount, max_discount, usage_limit, per_user_limit, seller_id, first_order_only, is_active, starts_at, expires_at, created_by, created_at, updated_at) VALUES
('WELCOME10', 'percentage', 10.00, 2000.00, 5000.00, 1000, 1, NULL, 1, 1, DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_ADD(NOW(), INTERVAL 6 MONTH), 1, NOW(), NOW()),
('FREESHIP', 'fixed', 200.00, 2000.00, 200.00, NULL, 3, NULL, 0, 1, DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_ADD(NOW(), INTERVAL 3 MONTH), 1, NOW(), NOW()),
('TECH15', 'percentage', 15.00, 10000.00, 15000.00, 500, 2, 2, 0, 1, DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_ADD(NOW(), INTERVAL 2 MONTH), 2, NOW(), NOW()),
('FASHION20', 'percentage', 20.00, 3000.00, 4000.00, 300, 1, 3, 0, 1, DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_ADD(NOW(), INTERVAL 1 MONTH), 3, NOW(), NOW());

-- -----------------------------------------------------------------
-- Sample Reviews
-- -----------------------------------------------------------------
INSERT INTO reviews (product_id, user_id, rating, title, comment, status, is_verified_purchase, created_at, updated_at) VALUES
(1, 4, 5, 'Best phone I ever owned!', 'The S24 Ultra is absolutely incredible. The camera quality is mind-blowing and the S Pen integration is seamless. Battery lasts all day. Highly recommended for anyone looking for a premium smartphone.', 'approved', 1, NOW(), NOW()),
(5, 4, 5, 'Perfect noise cancellation', 'These headphones are worth every rupee. The noise cancellation is the best I have ever experienced. Sound quality is superb and they are so comfortable for long listening sessions.', 'approved', 1, NOW(), NOW()),
(12, 5, 4, 'Beautiful lawn suit', 'The embroidery work is really nice and the fabric quality is great. Colors are exactly as shown. Only giving 4 stars because delivery took a bit longer than expected. Otherwise, lovely product.', 'approved', 1, NOW(), NOW()),
(17, 5, 5, 'Amazing skincare results', 'Been using The Ordinary set for 3 weeks now and can already see a noticeable difference in my skin. The niacinamide serum is a game changer. Great value for the price.', 'approved', 1, NOW(), NOW()),
(6, 4, 4, 'Great earbuds, slightly pricey', 'Sound quality and ANC are top-notch. The adaptive audio feature is really cool. Wish the price was a bit lower but overall a solid purchase.', 'approved', 1, NOW(), NOW()),
(11, 5, 4, 'Excellent fabric quality', 'The cotton quality is genuinely premium. Fits well and the navy color is rich. Good for office wear and semi-formal events.', 'approved', 1, NOW(), NOW());

-- -----------------------------------------------------------------
-- Sample Order (Customer Ali Raza ordering from TechWorld)
-- -----------------------------------------------------------------
INSERT INTO orders (id, order_number, user_id, address_id, shipping_address, billing_address, subtotal, discount_amount, shipping_amount, tax_amount, total_amount, payment_method, payment_status, order_status, created_at, updated_at) VALUES
(1, 'SS-20261001-0001', 4, 1, '{"full_name":"Ali Raza","phone":"+923031234567","address_line1":"House 15, Street 4, DHA Phase 5","city":"Lahore","state":"Punjab","postal_code":"54000","country":"Pakistan"}', '{"full_name":"Ali Raza","phone":"+923031234567","address_line1":"House 15, Street 4, DHA Phase 5","city":"Lahore","state":"Punjab","postal_code":"54000","country":"Pakistan"}', 134998.00, 0.00, 0.00, 0.00, 134998.00, 'cod', 'pending', 'delivered', DATE_SUB(NOW(), INTERVAL 5 DAY), NOW());

INSERT INTO seller_orders (id, order_id, seller_id, subtotal, discount_amount, shipping_amount, commission_amount, seller_earning, status, tracking_number, shipping_carrier, shipped_at, delivered_at, created_at, updated_at) VALUES
(1, 1, 2, 134998.00, 0.00, 0.00, 10799.84, 124198.16, 'delivered', 'TCS-1234567890', 'TCS', DATE_SUB(NOW(), INTERVAL 3 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_SUB(NOW(), INTERVAL 5 DAY), NOW());

INSERT INTO order_items (order_id, seller_order_id, product_id, seller_id, product_name, sku, quantity, unit_price, total_price, discount_amount, commission_rate, commission_amount, seller_earning, status, created_at, updated_at) VALUES
(1, 1, 5, 2, 'Sony WH-1000XM5 Headphones', 'TW-SONY-XM5', 1, 79999.00, 79999.00, 0.00, 8.00, 6399.92, 73599.08, 'delivered', DATE_SUB(NOW(), INTERVAL 5 DAY), NOW()),
(1, 1, 6, 2, 'Apple AirPods Pro 2nd Gen', 'TW-APP2', 1, 54999.00, 54999.00, 0.00, 8.00, 4399.92, 50599.08, 'delivered', DATE_SUB(NOW(), INTERVAL 5 DAY), NOW());

INSERT INTO order_status_history (order_id, seller_order_id, old_status, new_status, comment, created_at) VALUES
(1, NULL, NULL, 'pending', 'Order placed by customer', DATE_SUB(NOW(), INTERVAL 5 DAY)),
(1, 1, 'pending', 'confirmed', 'Order confirmed by seller', DATE_SUB(NOW(), INTERVAL 4 DAY)),
(1, 1, 'confirmed', 'shipped', 'Package shipped via TCS', DATE_SUB(NOW(), INTERVAL 3 DAY)),
(1, 1, 'shipped', 'delivered', 'Package delivered', DATE_SUB(NOW(), INTERVAL 1 DAY));

INSERT INTO payments (order_id, method, amount, currency, status, is_simulated, created_at, updated_at) VALUES
(1, 'cod', 134998.00, 'PKR', 'completed', 0, DATE_SUB(NOW(), INTERVAL 5 DAY), NOW());

-- -----------------------------------------------------------------
-- Homepage Sections
-- -----------------------------------------------------------------
INSERT INTO homepage_sections (section_type, title, subtitle, sort_order, is_active, created_at, updated_at) VALUES
('hero', 'Shop Smarter, Live Better', 'Discover thousands of products from verified sellers across Pakistan', 1, 1, NOW(), NOW()),
('featured_categories', 'Shop by Category', 'Explore our most popular product categories', 2, 1, NOW(), NOW()),
('deals', 'Flash Deals', 'Limited time offers you don''t want to miss', 3, 1, NOW(), NOW()),
('featured_products', 'Featured Products', 'Handpicked selections from our best sellers', 4, 1, NOW(), NOW()),
('trending_products', 'Trending Now', 'See what everyone is buying', 5, 1, NOW(), NOW()),
('seller_highlights', 'Top Sellers', 'Trusted sellers with proven track records', 6, 1, NOW(), NOW());

-- -----------------------------------------------------------------
-- FAQs
-- -----------------------------------------------------------------
INSERT INTO faqs (question, answer, category, sort_order, is_active, created_at, updated_at) VALUES
('How do I create an account?', 'Click on the "Register" button in the top navigation bar. Fill in your personal details including name, email, and password. Verify your email address and you are ready to start shopping!', 'Account', 1, 1, NOW(), NOW()),
('How do I track my order?', 'Go to "My Orders" in your account dashboard. Click on the order you want to track. You will see the current status and tracking information once your package has been shipped.', 'Orders', 2, 1, NOW(), NOW()),
('What payment methods are accepted?', 'We currently accept Cash on Delivery (COD) and simulated card payments. More payment options including JazzCash and EasyPaisa are coming soon.', 'Payments', 3, 1, NOW(), NOW()),
('How do returns work?', 'You can request a return within 7 days of delivery. Go to your order details and click "Request Return". Our team will review your request and process the refund if approved.', 'Returns', 4, 1, NOW(), NOW()),
('How do I become a seller?', 'Click on "Sell on ShopSphere" in the footer or navigation. Complete the seller registration form with your business details. Our team will review your application and notify you once approved.', 'Selling', 5, 1, NOW(), NOW()),
('What is ShopSphere''s commission rate?', 'Commission rates vary by category, typically ranging from 5% to 15%. The default platform commission is 10%. You can view the detailed rate structure in your seller dashboard.', 'Selling', 6, 1, NOW(), NOW());

-- -----------------------------------------------------------------
-- Sample Notifications
-- -----------------------------------------------------------------
INSERT INTO notifications (user_id, type, title, message, link, is_read, created_at) VALUES
(4, 'order', 'Order Delivered!', 'Your order SS-20261001-0001 has been delivered. We hope you enjoy your purchase!', '/order-details.php?order_number=SS-20261001-0001', 0, NOW()),
(2, 'order', 'New Order Received', 'You have received a new order SS-20261001-0001 worth Rs. 134,998.00', '/seller/order-details.php?id=1', 0, NOW());

SET FOREIGN_KEY_CHECKS = 1;

-- =================================================================
-- Seed Data Complete!
-- Login Credentials:
-- Admin:    admin@shopsphere.local / Admin@123
-- Seller 1: techworld@shopsphere.local / Seller@123
-- Seller 2: fashionhub@shopsphere.local / Seller@123
-- Customer: ali.customer@gmail.com / Customer@123
-- Customer: fatima.buyer@gmail.com / Customer@123
-- =================================================================
