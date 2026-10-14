-- ============================================================================
-- ShopSphere Multi-Vendor E-Commerce Marketplace
-- Database Schema Definition
-- Engine: InnoDB | Charset: utf8mb4 | Collation: utf8mb4_unicode_ci
-- Currency: PKR (Pakistani Rupee) | Timestamps: UTC
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

START TRANSACTION;

-- ============================================================================
-- DROP EXISTING TABLES (Reverse Dependency Order)
-- ============================================================================

DROP TABLE IF EXISTS `email_verification_tokens`;
DROP TABLE IF EXISTS `password_reset_tokens`;
DROP TABLE IF EXISTS `audit_logs`;
DROP TABLE IF EXISTS `faqs`;
DROP TABLE IF EXISTS `contact_messages`;
DROP TABLE IF EXISTS `menus`;
DROP TABLE IF EXISTS `content_pages`;
DROP TABLE IF EXISTS `website_settings`;
DROP TABLE IF EXISTS `banners`;
DROP TABLE IF EXISTS `homepage_sections`;
DROP TABLE IF EXISTS `notifications`;
DROP TABLE IF EXISTS `payout_items`;
DROP TABLE IF EXISTS `payouts`;
DROP TABLE IF EXISTS `shipments`;
DROP TABLE IF EXISTS `refunds`;
DROP TABLE IF EXISTS `return_requests`;
DROP TABLE IF EXISTS `reviews`;
DROP TABLE IF EXISTS `commission_rules`;
DROP TABLE IF EXISTS `coupon_redemptions`;
DROP TABLE IF EXISTS `payments`;
DROP TABLE IF EXISTS `order_status_history`;
DROP TABLE IF EXISTS `order_items`;
DROP TABLE IF EXISTS `seller_orders`;
DROP TABLE IF EXISTS `orders`;
DROP TABLE IF EXISTS `wishlists`;
DROP TABLE IF EXISTS `cart_items`;
DROP TABLE IF EXISTS `carts`;
DROP TABLE IF EXISTS `coupons`;
DROP TABLE IF EXISTS `inventory_movements`;
DROP TABLE IF EXISTS `product_variants`;
DROP TABLE IF EXISTS `product_images`;
DROP TABLE IF EXISTS `products`;
DROP TABLE IF EXISTS `categories`;
DROP TABLE IF EXISTS `addresses`;
DROP TABLE IF EXISTS `seller_profiles`;
DROP TABLE IF EXISTS `customer_profiles`;
DROP TABLE IF EXISTS `users`;

-- ============================================================================
-- 1. USERS
-- Purpose: Core authentication and user account identity for customers, sellers, and administrators.
-- ============================================================================
CREATE TABLE `users` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `email` VARCHAR(255) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `first_name` VARCHAR(100) NOT NULL,
    `last_name` VARCHAR(100) NOT NULL,
    `phone` VARCHAR(30) NULL,
    `role` ENUM('customer', 'seller', 'admin') NOT NULL DEFAULT 'customer',
    `status` ENUM('active', 'suspended', 'pending_verification') NOT NULL DEFAULT 'active',
    `email_verified_at` DATETIME NULL,
    `avatar` VARCHAR(255) NULL,
    `last_login_at` DATETIME NULL,
    `failed_login_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `locked_until` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_users_email` (`email`),
    KEY `idx_users_role` (`role`),
    KEY `idx_users_status` (`status`),
    KEY `idx_users_phone` (`phone`),
    KEY `idx_users_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 2. CUSTOMER PROFILES
-- Purpose: Extended profile metadata, demographic data, and shopping preferences for customers.
-- ============================================================================
CREATE TABLE `customer_profiles` (
    `user_id` INT UNSIGNED NOT NULL PRIMARY KEY,
    `date_of_birth` DATE NULL,
    `gender` ENUM('male', 'female', 'other', 'prefer_not_to_say') NULL,
    `preferences` JSON NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_customer_profiles_user` FOREIGN KEY (`user_id`) 
        REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 3. SELLER PROFILES
-- Purpose: Store branding, business verification, payout settings, and performance metrics for marketplace vendors.
-- ============================================================================
CREATE TABLE `seller_profiles` (
    `user_id` INT UNSIGNED NOT NULL PRIMARY KEY,
    `store_name` VARCHAR(150) NOT NULL,
    `store_slug` VARCHAR(150) NOT NULL,
    `store_description` TEXT NULL,
    `store_logo` VARCHAR(255) NULL,
    `store_banner` VARCHAR(255) NULL,
    `business_name` VARCHAR(200) NOT NULL,
    `business_address` TEXT NOT NULL,
    `tax_id` VARCHAR(100) NULL,
    `commission_rate` DECIMAL(5,2) NULL COMMENT 'Vendor specific commission override percentage',
    `status` ENUM('pending', 'approved', 'rejected', 'suspended') NOT NULL DEFAULT 'pending',
    `approved_at` DATETIME NULL,
    `rejection_reason` TEXT NULL,
    `suspension_reason` TEXT NULL,
    `rating_avg` DECIMAL(3,2) NOT NULL DEFAULT 0.00,
    `total_sales` INT UNSIGNED NOT NULL DEFAULT 0,
    `verified` BOOLEAN NOT NULL DEFAULT FALSE,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `chk_seller_commission` CHECK (`commission_rate` IS NULL OR (`commission_rate` >= 0.00 AND `commission_rate` <= 100.00)),
    CONSTRAINT `chk_seller_rating` CHECK (`rating_avg` >= 0.00 AND `rating_avg` <= 5.00),
    UNIQUE KEY `uq_seller_store_slug` (`store_slug`),
    KEY `idx_seller_status` (`status`),
    KEY `idx_seller_verified` (`verified`),
    KEY `idx_seller_rating` (`rating_avg`),
    CONSTRAINT `fk_seller_profiles_user` FOREIGN KEY (`user_id`) 
        REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 4. ADDRESSES
-- Purpose: User billing and shipping physical addresses for order fulfillment.
-- ============================================================================
CREATE TABLE `addresses` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `label` VARCHAR(50) NOT NULL DEFAULT 'Home',
    `full_name` VARCHAR(150) NOT NULL,
    `phone` VARCHAR(30) NOT NULL,
    `address_line1` VARCHAR(255) NOT NULL,
    `address_line2` VARCHAR(255) NULL,
    `city` VARCHAR(100) NOT NULL,
    `state` VARCHAR(100) NOT NULL,
    `postal_code` VARCHAR(20) NOT NULL,
    `country` VARCHAR(100) NOT NULL DEFAULT 'Pakistan',
    `is_default` BOOLEAN NOT NULL DEFAULT FALSE,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_addresses_user_id` (`user_id`),
    KEY `idx_addresses_is_default` (`is_default`),
    KEY `idx_addresses_user_default` (`user_id`, `is_default`),
    CONSTRAINT `fk_addresses_user` FOREIGN KEY (`user_id`) 
        REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 5. CATEGORIES
-- Purpose: Hierarchical product classification tree with commission rate support.
-- ============================================================================
CREATE TABLE `categories` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `parent_id` INT UNSIGNED NULL,
    `name` VARCHAR(150) NOT NULL,
    `slug` VARCHAR(150) NOT NULL,
    `description` TEXT NULL,
    `image` VARCHAR(255) NULL,
    `icon` VARCHAR(100) NULL,
    `commission_rate` DECIMAL(5,2) NULL COMMENT 'Category default commission percentage',
    `sort_order` INT NOT NULL DEFAULT 0,
    `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `chk_category_commission` CHECK (`commission_rate` IS NULL OR (`commission_rate` >= 0.00 AND `commission_rate` <= 100.00)),
    UNIQUE KEY `uq_categories_slug` (`slug`),
    KEY `idx_categories_parent_id` (`parent_id`),
    KEY `idx_categories_is_active` (`is_active`),
    KEY `idx_categories_sort_order` (`sort_order`),
    CONSTRAINT `fk_categories_parent` FOREIGN KEY (`parent_id`) 
        REFERENCES `categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 6. PRODUCTS
-- Purpose: Main product catalog entries offered by marketplace sellers.
-- ============================================================================
CREATE TABLE `products` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `seller_id` INT UNSIGNED NOT NULL,
    `category_id` INT UNSIGNED NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `slug` VARCHAR(255) NOT NULL,
    `description` LONGTEXT NULL,
    `short_description` TEXT NULL,
    `price` DECIMAL(12,2) NOT NULL,
    `compare_price` DECIMAL(12,2) NULL,
    `cost_price` DECIMAL(12,2) NULL,
    `sku` VARCHAR(100) NULL,
    `barcode` VARCHAR(100) NULL,
    `stock_quantity` INT NOT NULL DEFAULT 0,
    `low_stock_threshold` INT NOT NULL DEFAULT 5,
    `weight` DECIMAL(8,2) NULL COMMENT 'Weight in kg',
    `dimensions` VARCHAR(100) NULL COMMENT 'L x W x H in cm',
    `status` ENUM('draft', 'pending', 'approved', 'rejected', 'archived') NOT NULL DEFAULT 'draft',
    `is_featured` BOOLEAN NOT NULL DEFAULT FALSE,
    `is_trending` BOOLEAN NOT NULL DEFAULT FALSE,
    `rejection_reason` TEXT NULL,
    `views_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `sales_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `rating_avg` DECIMAL(3,2) NOT NULL DEFAULT 0.00,
    `rating_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `chk_product_price` CHECK (`price` >= 0.00),
    CONSTRAINT `chk_product_compare_price` CHECK (`compare_price` IS NULL OR `compare_price` >= 0.00),
    CONSTRAINT `chk_product_cost_price` CHECK (`cost_price` IS NULL OR `cost_price` >= 0.00),
    CONSTRAINT `chk_product_stock` CHECK (`stock_quantity` >= 0),
    CONSTRAINT `chk_product_low_stock` CHECK (`low_stock_threshold` >= 0),
    CONSTRAINT `chk_product_rating` CHECK (`rating_avg` >= 0.00 AND `rating_avg` <= 5.00),
    UNIQUE KEY `uq_products_slug` (`slug`),
    UNIQUE KEY `uq_products_sku` (`sku`),
    KEY `idx_products_seller_id` (`seller_id`),
    KEY `idx_products_category_id` (`category_id`),
    KEY `idx_products_status` (`status`),
    KEY `idx_products_featured` (`is_featured`),
    KEY `idx_products_trending` (`is_trending`),
    KEY `idx_products_price` (`price`),
    KEY `idx_products_rating` (`rating_avg`),
    KEY `idx_products_created_at` (`created_at`),
    KEY `idx_products_seller_status` (`seller_id`, `status`),
    KEY `idx_products_category_status` (`category_id`, `status`),
    CONSTRAINT `fk_products_seller` FOREIGN KEY (`seller_id`) 
        REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_products_category` FOREIGN KEY (`category_id`) 
        REFERENCES `categories` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 7. PRODUCT IMAGES
-- Purpose: Gallery images and primary thumbnails associated with products.
-- ============================================================================
CREATE TABLE `product_images` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `product_id` INT UNSIGNED NOT NULL,
    `image_path` VARCHAR(255) NOT NULL,
    `alt_text` VARCHAR(255) NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `is_primary` BOOLEAN NOT NULL DEFAULT FALSE,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_product_images_product_id` (`product_id`),
    KEY `idx_product_images_primary` (`is_primary`),
    KEY `idx_product_images_product_primary` (`product_id`, `is_primary`),
    CONSTRAINT `fk_product_images_product` FOREIGN KEY (`product_id`) 
        REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 8. PRODUCT VARIANTS
-- Purpose: SKU-level variants for products (size, color, material, etc.).
-- ============================================================================
CREATE TABLE `product_variants` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `product_id` INT UNSIGNED NOT NULL,
    `name` VARCHAR(100) NOT NULL COMMENT 'Attribute name e.g. Color, Size',
    `value` VARCHAR(100) NOT NULL COMMENT 'Attribute value e.g. Red, XL',
    `sku_suffix` VARCHAR(50) NULL,
    `price_adjustment` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `stock_quantity` INT NOT NULL DEFAULT 0,
    `sort_order` INT NOT NULL DEFAULT 0,
    `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `chk_variant_stock` CHECK (`stock_quantity` >= 0),
    KEY `idx_variants_product_id` (`product_id`),
    KEY `idx_variants_is_active` (`is_active`),
    KEY `idx_variants_product_active` (`product_id`, `is_active`),
    CONSTRAINT `fk_product_variants_product` FOREIGN KEY (`product_id`) 
        REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 9. INVENTORY MOVEMENTS
-- Purpose: Complete audit trail and double-entry style tracking for stock mutations.
-- ============================================================================
CREATE TABLE `inventory_movements` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `product_id` INT UNSIGNED NOT NULL,
    `variant_id` INT UNSIGNED NULL,
    `quantity_change` INT NOT NULL COMMENT 'Positive for stock increase, negative for decrease',
    `type` ENUM('purchase', 'sale', 'return', 'adjustment', 'reservation', 'reservation_release') NOT NULL,
    `reference_type` VARCHAR(100) NULL COMMENT 'e.g. order, return_request, manual_adjustment',
    `reference_id` INT UNSIGNED NULL,
    `reason` TEXT NULL,
    `created_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_inv_movements_product` (`product_id`),
    KEY `idx_inv_movements_variant` (`variant_id`),
    KEY `idx_inv_movements_type` (`type`),
    KEY `idx_inv_movements_ref` (`reference_type`, `reference_id`),
    KEY `idx_inv_movements_created_by` (`created_by`),
    KEY `idx_inv_movements_created_at` (`created_at`),
    CONSTRAINT `fk_inv_movements_product` FOREIGN KEY (`product_id`) 
        REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_inv_movements_variant` FOREIGN KEY (`variant_id`) 
        REFERENCES `product_variants` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_inv_movements_user` FOREIGN KEY (`created_by`) 
        REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 10. COUPONS
-- Purpose: Discount vouchers and promotional promo codes (platform or vendor level).
-- ============================================================================
CREATE TABLE `coupons` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(50) NOT NULL,
    `type` ENUM('percentage', 'fixed') NOT NULL DEFAULT 'percentage',
    `value` DECIMAL(12,2) NOT NULL,
    `min_order_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `max_discount` DECIMAL(12,2) NULL,
    `usage_limit` INT UNSIGNED NULL,
    `per_user_limit` INT UNSIGNED NOT NULL DEFAULT 1,
    `used_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `seller_id` INT UNSIGNED NULL COMMENT 'NULL for platform-wide coupon, vendor ID for seller-specific coupon',
    `category_id` INT UNSIGNED NULL COMMENT 'Optional category restriction',
    `first_order_only` BOOLEAN NOT NULL DEFAULT FALSE,
    `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
    `starts_at` DATETIME NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `created_by` INT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `chk_coupon_value` CHECK (`value` >= 0.00),
    CONSTRAINT `chk_coupon_min_order` CHECK (`min_order_amount` >= 0.00),
    CONSTRAINT `chk_coupon_max_discount` CHECK (`max_discount` IS NULL OR `max_discount` >= 0.00),
    UNIQUE KEY `uq_coupons_code` (`code`),
    KEY `idx_coupons_seller_id` (`seller_id`),
    KEY `idx_coupons_category_id` (`category_id`),
    KEY `idx_coupons_is_active` (`is_active`),
    KEY `idx_coupons_validity` (`starts_at`, `expires_at`),
    CONSTRAINT `fk_coupons_seller` FOREIGN KEY (`seller_id`) 
        REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_coupons_category` FOREIGN KEY (`category_id`) 
        REFERENCES `categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_coupons_creator` FOREIGN KEY (`created_by`) 
        REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 11. CARTS
-- Purpose: Active user or guest shopping sessions with coupon preservation.
-- ============================================================================
CREATE TABLE `carts` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NULL,
    `session_id` VARCHAR(100) NULL,
    `coupon_code` VARCHAR(50) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_carts_user_id` (`user_id`),
    KEY `idx_carts_session_id` (`session_id`),
    CONSTRAINT `fk_carts_user` FOREIGN KEY (`user_id`) 
        REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 12. CART ITEMS
-- Purpose: Individual line items and quantities within a shopping cart.
-- ============================================================================
CREATE TABLE `cart_items` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `cart_id` INT UNSIGNED NOT NULL,
    `product_id` INT UNSIGNED NOT NULL,
    `variant_id` INT UNSIGNED NULL,
    `quantity` INT NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `chk_cart_item_quantity` CHECK (`quantity` > 0),
    KEY `idx_cart_items_cart_id` (`cart_id`),
    KEY `idx_cart_items_product_id` (`product_id`),
    KEY `idx_cart_items_variant_id` (`variant_id`),
    UNIQUE KEY `uq_cart_product_variant` (`cart_id`, `product_id`, `variant_id`),
    CONSTRAINT `fk_cart_items_cart` FOREIGN KEY (`cart_id`) 
        REFERENCES `carts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_cart_items_product` FOREIGN KEY (`product_id`) 
        REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_cart_items_variant` FOREIGN KEY (`variant_id`) 
        REFERENCES `product_variants` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 13. WISHLISTS
-- Purpose: Saved favorite products for registered customers.
-- ============================================================================
CREATE TABLE `wishlists` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `product_id` INT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_wishlists_user_product` (`user_id`, `product_id`),
    KEY `idx_wishlists_user_id` (`user_id`),
    KEY `idx_wishlists_product_id` (`product_id`),
    CONSTRAINT `fk_wishlists_user` FOREIGN KEY (`user_id`) 
        REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_wishlists_product` FOREIGN KEY (`product_id`) 
        REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 14. ORDERS
-- Purpose: Master customer order records across multiple sellers with totals and addresses.
-- ============================================================================
CREATE TABLE `orders` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `order_number` VARCHAR(50) NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `address_id` INT UNSIGNED NULL,
    `shipping_address` JSON NOT NULL COMMENT 'Snapshotted address at time of checkout',
    `billing_address` JSON NOT NULL COMMENT 'Snapshotted address at time of checkout',
    `subtotal` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `discount_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `shipping_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `tax_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `total_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `coupon_id` INT UNSIGNED NULL,
    `coupon_code` VARCHAR(50) NULL,
    `coupon_discount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `payment_method` VARCHAR(50) NOT NULL,
    `payment_status` ENUM('pending', 'paid', 'failed', 'refunded', 'partially_refunded') NOT NULL DEFAULT 'pending',
    `order_status` ENUM('pending', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled', 'returned') NOT NULL DEFAULT 'pending',
    `notes` TEXT NULL,
    `ip_address` VARCHAR(45) NULL,
    `idempotency_key` VARCHAR(100) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `chk_orders_subtotal` CHECK (`subtotal` >= 0.00),
    CONSTRAINT `chk_orders_discount` CHECK (`discount_amount` >= 0.00),
    CONSTRAINT `chk_orders_shipping` CHECK (`shipping_amount` >= 0.00),
    CONSTRAINT `chk_orders_tax` CHECK (`tax_amount` >= 0.00),
    CONSTRAINT `chk_orders_total` CHECK (`total_amount` >= 0.00),
    UNIQUE KEY `uq_orders_order_number` (`order_number`),
    UNIQUE KEY `uq_orders_idempotency_key` (`idempotency_key`),
    KEY `idx_orders_user_id` (`user_id`),
    KEY `idx_orders_address_id` (`address_id`),
    KEY `idx_orders_coupon_id` (`coupon_id`),
    KEY `idx_orders_payment_status` (`payment_status`),
    KEY `idx_orders_order_status` (`order_status`),
    KEY `idx_orders_created_at` (`created_at`),
    KEY `idx_orders_user_status` (`user_id`, `order_status`),
    CONSTRAINT `fk_orders_user` FOREIGN KEY (`user_id`) 
        REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_orders_address` FOREIGN KEY (`address_id`) 
        REFERENCES `addresses` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_orders_coupon` FOREIGN KEY (`coupon_id`) 
        REFERENCES `coupons` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 15. SELLER ORDERS
-- Purpose: Sub-order breakdown per vendor for fulfillment, earnings, and commissions.
-- ============================================================================
CREATE TABLE `seller_orders` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `order_id` INT UNSIGNED NOT NULL,
    `seller_id` INT UNSIGNED NOT NULL,
    `subtotal` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `discount_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `shipping_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `commission_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `seller_earning` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `status` ENUM('pending', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled', 'returned') NOT NULL DEFAULT 'pending',
    `tracking_number` VARCHAR(100) NULL,
    `shipping_carrier` VARCHAR(100) NULL,
    `shipped_at` DATETIME NULL,
    `delivered_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `chk_seller_orders_subtotal` CHECK (`subtotal` >= 0.00),
    CONSTRAINT `chk_seller_orders_discount` CHECK (`discount_amount` >= 0.00),
    CONSTRAINT `chk_seller_orders_shipping` CHECK (`shipping_amount` >= 0.00),
    CONSTRAINT `chk_seller_orders_commission` CHECK (`commission_amount` >= 0.00),
    CONSTRAINT `chk_seller_orders_earning` CHECK (`seller_earning` >= 0.00),
    KEY `idx_seller_orders_order_id` (`order_id`),
    KEY `idx_seller_orders_seller_id` (`seller_id`),
    KEY `idx_seller_orders_status` (`status`),
    KEY `idx_seller_orders_tracking` (`tracking_number`),
    KEY `idx_seller_orders_created_at` (`created_at`),
    KEY `idx_seller_orders_seller_status` (`seller_id`, `status`),
    CONSTRAINT `fk_seller_orders_order` FOREIGN KEY (`order_id`) 
        REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_seller_orders_seller` FOREIGN KEY (`seller_id`) 
        REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 16. ORDER ITEMS
-- Purpose: Itemized products within an order, capturing immutable price and commission snapshots.
-- ============================================================================
CREATE TABLE `order_items` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `order_id` INT UNSIGNED NOT NULL,
    `seller_order_id` INT UNSIGNED NOT NULL,
    `product_id` INT UNSIGNED NOT NULL,
    `variant_id` INT UNSIGNED NULL,
    `seller_id` INT UNSIGNED NOT NULL,
    `product_name` VARCHAR(255) NOT NULL,
    `variant_info` VARCHAR(255) NULL,
    `sku` VARCHAR(100) NULL,
    `quantity` INT NOT NULL DEFAULT 1,
    `unit_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `total_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `discount_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `commission_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    `commission_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `seller_earning` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `status` ENUM('pending', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled', 'returned') NOT NULL DEFAULT 'pending',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `chk_order_items_qty` CHECK (`quantity` > 0),
    CONSTRAINT `chk_order_items_unit_price` CHECK (`unit_price` >= 0.00),
    CONSTRAINT `chk_order_items_total_price` CHECK (`total_price` >= 0.00),
    CONSTRAINT `chk_order_items_discount` CHECK (`discount_amount` >= 0.00),
    CONSTRAINT `chk_order_items_comm_amount` CHECK (`commission_amount` >= 0.00),
    CONSTRAINT `chk_order_items_seller_earning` CHECK (`seller_earning` >= 0.00),
    KEY `idx_order_items_order_id` (`order_id`),
    KEY `idx_order_items_seller_order_id` (`seller_order_id`),
    KEY `idx_order_items_product_id` (`product_id`),
    KEY `idx_order_items_variant_id` (`variant_id`),
    KEY `idx_order_items_seller_id` (`seller_id`),
    KEY `idx_order_items_status` (`status`),
    CONSTRAINT `fk_order_items_order` FOREIGN KEY (`order_id`) 
        REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_order_items_seller_order` FOREIGN KEY (`seller_order_id`) 
        REFERENCES `seller_orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_order_items_product` FOREIGN KEY (`product_id`) 
        REFERENCES `products` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_order_items_variant` FOREIGN KEY (`variant_id`) 
        REFERENCES `product_variants` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_order_items_seller` FOREIGN KEY (`seller_id`) 
        REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 17. ORDER STATUS HISTORY
-- Purpose: Complete chronological lifecycle transition history and audit comments for orders.
-- ============================================================================
CREATE TABLE `order_status_history` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `order_id` INT UNSIGNED NOT NULL,
    `seller_order_id` INT UNSIGNED NULL,
    `old_status` VARCHAR(50) NULL,
    `new_status` VARCHAR(50) NOT NULL,
    `comment` TEXT NULL,
    `created_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_order_history_order_id` (`order_id`),
    KEY `idx_order_history_seller_order_id` (`seller_order_id`),
    KEY `idx_order_history_created_by` (`created_by`),
    KEY `idx_order_history_created_at` (`created_at`),
    CONSTRAINT `fk_order_history_order` FOREIGN KEY (`order_id`) 
        REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_order_history_seller_order` FOREIGN KEY (`seller_order_id`) 
        REFERENCES `seller_orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_order_history_user` FOREIGN KEY (`created_by`) 
        REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 18. PAYMENTS
-- Purpose: Payment transactions, gateway responses, and reconciliation records in PKR.
-- ============================================================================
CREATE TABLE `payments` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `order_id` INT UNSIGNED NOT NULL,
    `method` VARCHAR(50) NOT NULL COMMENT 'card, jazzcash, easypaisa, bank_transfer, cod',
    `transaction_id` VARCHAR(150) NULL,
    `amount` DECIMAL(12,2) NOT NULL,
    `currency` VARCHAR(10) NOT NULL DEFAULT 'PKR',
    `status` ENUM('pending', 'completed', 'failed', 'refunded') NOT NULL DEFAULT 'pending',
    `gateway_response` JSON NULL,
    `is_simulated` BOOLEAN NOT NULL DEFAULT FALSE,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `chk_payments_amount` CHECK (`amount` >= 0.00),
    KEY `idx_payments_order_id` (`order_id`),
    KEY `idx_payments_transaction_id` (`transaction_id`),
    KEY `idx_payments_status` (`status`),
    KEY `idx_payments_method` (`method`),
    KEY `idx_payments_created_at` (`created_at`),
    CONSTRAINT `fk_payments_order` FOREIGN KEY (`order_id`) 
        REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 19. COUPON REDEMPTIONS
-- Purpose: Usage tracking of coupons per user and order to enforce usage limits.
-- ============================================================================
CREATE TABLE `coupon_redemptions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `coupon_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `order_id` INT UNSIGNED NOT NULL,
    `discount_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `chk_coupon_redemptions_discount` CHECK (`discount_amount` >= 0.00),
    KEY `idx_coupon_redemptions_coupon_id` (`coupon_id`),
    KEY `idx_coupon_redemptions_user_id` (`user_id`),
    KEY `idx_coupon_redemptions_order_id` (`order_id`),
    KEY `idx_coupon_redemptions_user_coupon` (`user_id`, `coupon_id`),
    CONSTRAINT `fk_coupon_redemptions_coupon` FOREIGN KEY (`coupon_id`) 
        REFERENCES `coupons` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_coupon_redemptions_user` FOREIGN KEY (`user_id`) 
        REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_coupon_redemptions_order` FOREIGN KEY (`order_id`) 
        REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 20. COMMISSION RULES
-- Purpose: Marketplace commission rules configurable at global, category, or seller level.
-- ============================================================================
CREATE TABLE `commission_rules` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `type` ENUM('default', 'category', 'seller') NOT NULL DEFAULT 'default',
    `category_id` INT UNSIGNED NULL,
    `seller_id` INT UNSIGNED NULL,
    `rate` DECIMAL(5,2) NOT NULL COMMENT 'Commission percentage',
    `effective_from` DATE NOT NULL,
    `effective_to` DATE NULL,
    `created_by` INT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `chk_commission_rules_rate` CHECK (`rate` >= 0.00 AND `rate` <= 100.00),
    KEY `idx_comm_rules_type` (`type`),
    KEY `idx_comm_rules_category` (`category_id`),
    KEY `idx_comm_rules_seller` (`seller_id`),
    KEY `idx_comm_rules_dates` (`effective_from`, `effective_to`),
    CONSTRAINT `fk_comm_rules_category` FOREIGN KEY (`category_id`) 
        REFERENCES `categories` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_comm_rules_seller` FOREIGN KEY (`seller_id`) 
        REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_comm_rules_creator` FOREIGN KEY (`created_by`) 
        REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 21. REVIEWS
-- Purpose: Product ratings, customer reviews, verified purchase flags, and seller/admin replies.
-- ============================================================================
CREATE TABLE `reviews` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `product_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `order_item_id` INT UNSIGNED NULL,
    `rating` TINYINT UNSIGNED NOT NULL,
    `title` VARCHAR(200) NULL,
    `comment` TEXT NULL,
    `status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    `is_verified_purchase` BOOLEAN NOT NULL DEFAULT FALSE,
    `admin_response` TEXT NULL,
    `reported` BOOLEAN NOT NULL DEFAULT FALSE,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `chk_reviews_rating` CHECK (`rating` >= 1 AND `rating` <= 5),
    KEY `idx_reviews_product_id` (`product_id`),
    KEY `idx_reviews_user_id` (`user_id`),
    KEY `idx_reviews_order_item_id` (`order_item_id`),
    KEY `idx_reviews_status` (`status`),
    KEY `idx_reviews_rating` (`rating`),
    KEY `idx_reviews_product_status` (`product_id`, `status`),
    CONSTRAINT `fk_reviews_product` FOREIGN KEY (`product_id`) 
        REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_reviews_user` FOREIGN KEY (`user_id`) 
        REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_reviews_order_item` FOREIGN KEY (`order_item_id`) 
        REFERENCES `order_items` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 22. RETURN REQUESTS
-- Purpose: Customer return requests for specific order items with workflow statuses.
-- ============================================================================
CREATE TABLE `return_requests` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `order_id` INT UNSIGNED NOT NULL,
    `order_item_id` INT UNSIGNED NOT NULL,
    `seller_order_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `reason` TEXT NOT NULL,
    `status` ENUM('pending', 'approved', 'rejected', 'completed') NOT NULL DEFAULT 'pending',
    `admin_notes` TEXT NULL,
    `refund_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `chk_return_requests_refund` CHECK (`refund_amount` >= 0.00),
    KEY `idx_return_requests_order_id` (`order_id`),
    KEY `idx_return_requests_order_item_id` (`order_item_id`),
    KEY `idx_return_requests_seller_order_id` (`seller_order_id`),
    KEY `idx_return_requests_user_id` (`user_id`),
    KEY `idx_return_requests_status` (`status`),
    KEY `idx_return_requests_created_at` (`created_at`),
    CONSTRAINT `fk_return_requests_order` FOREIGN KEY (`order_id`) 
        REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_return_requests_order_item` FOREIGN KEY (`order_item_id`) 
        REFERENCES `order_items` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_return_requests_seller_order` FOREIGN KEY (`seller_order_id`) 
        REFERENCES `seller_orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_return_requests_user` FOREIGN KEY (`user_id`) 
        REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 23. REFUNDS
-- Purpose: Financial refunds processed back to customers against cancelled or returned items.
-- ============================================================================
CREATE TABLE `refunds` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `order_id` INT UNSIGNED NOT NULL,
    `order_item_id` INT UNSIGNED NULL,
    `return_request_id` INT UNSIGNED NULL,
    `amount` DECIMAL(12,2) NOT NULL,
    `reason` TEXT NOT NULL,
    `status` ENUM('pending', 'approved', 'processed', 'rejected') NOT NULL DEFAULT 'pending',
    `processed_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `chk_refunds_amount` CHECK (`amount` >= 0.00),
    KEY `idx_refunds_order_id` (`order_id`),
    KEY `idx_refunds_order_item_id` (`order_item_id`),
    KEY `idx_refunds_return_request_id` (`return_request_id`),
    KEY `idx_refunds_status` (`status`),
    KEY `idx_refunds_processed_by` (`processed_by`),
    KEY `idx_refunds_created_at` (`created_at`),
    CONSTRAINT `fk_refunds_order` FOREIGN KEY (`order_id`) 
        REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_refunds_order_item` FOREIGN KEY (`order_item_id`) 
        REFERENCES `order_items` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_refunds_return_request` FOREIGN KEY (`return_request_id`) 
        REFERENCES `return_requests` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_refunds_processor` FOREIGN KEY (`processed_by`) 
        REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 24. SHIPMENTS
-- Purpose: Shipping logistics, carrier tracking numbers, and delivery milestones per vendor order.
-- ============================================================================
CREATE TABLE `shipments` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `seller_order_id` INT UNSIGNED NOT NULL,
    `tracking_number` VARCHAR(100) NOT NULL,
    `carrier` VARCHAR(100) NOT NULL,
    `status` ENUM('pending', 'picked_up', 'in_transit', 'delivered', 'failed') NOT NULL DEFAULT 'pending',
    `estimated_delivery` DATE NULL,
    `shipped_at` DATETIME NULL,
    `delivered_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_shipments_seller_order_id` (`seller_order_id`),
    KEY `idx_shipments_tracking_number` (`tracking_number`),
    KEY `idx_shipments_status` (`status`),
    KEY `idx_shipments_carrier` (`carrier`),
    CONSTRAINT `fk_shipments_seller_order` FOREIGN KEY (`seller_order_id`) 
        REFERENCES `seller_orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 25. PAYOUTS
-- Purpose: Vendor settlement disbursements and balance withdrawals from escrow.
-- ============================================================================
CREATE TABLE `payouts` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `seller_id` INT UNSIGNED NOT NULL,
    `amount` DECIMAL(12,2) NOT NULL,
    `method` VARCHAR(50) NOT NULL COMMENT 'bank_transfer, jazzcash, easypaisa',
    `reference` VARCHAR(100) NULL,
    `status` ENUM('pending', 'approved', 'processing', 'completed', 'rejected') NOT NULL DEFAULT 'pending',
    `requested_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `approved_at` DATETIME NULL,
    `completed_at` DATETIME NULL,
    `rejected_reason` TEXT NULL,
    `processed_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `chk_payouts_amount` CHECK (`amount` > 0.00),
    KEY `idx_payouts_seller_id` (`seller_id`),
    KEY `idx_payouts_status` (`status`),
    KEY `idx_payouts_requested_at` (`requested_at`),
    KEY `idx_payouts_created_at` (`created_at`),
    CONSTRAINT `fk_payouts_seller` FOREIGN KEY (`seller_id`) 
        REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_payouts_processor` FOREIGN KEY (`processed_by`) 
        REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 26. PAYOUT ITEMS
-- Purpose: Line-item linkage connecting individual vendor orders to a specific payout disbursement.
-- ============================================================================
CREATE TABLE `payout_items` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `payout_id` INT UNSIGNED NOT NULL,
    `seller_order_id` INT UNSIGNED NOT NULL,
    `amount` DECIMAL(12,2) NOT NULL,
    `commission_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    CONSTRAINT `chk_payout_items_amount` CHECK (`amount` >= 0.00),
    CONSTRAINT `chk_payout_items_commission` CHECK (`commission_amount` >= 0.00),
    KEY `idx_payout_items_payout_id` (`payout_id`),
    KEY `idx_payout_items_seller_order_id` (`seller_order_id`),
    CONSTRAINT `fk_payout_items_payout` FOREIGN KEY (`payout_id`) 
        REFERENCES `payouts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_payout_items_seller_order` FOREIGN KEY (`seller_order_id`) 
        REFERENCES `seller_orders` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 27. NOTIFICATIONS
-- Purpose: In-app system and transactional alerts for customers, sellers, and administrators.
-- ============================================================================
CREATE TABLE `notifications` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `type` VARCHAR(50) NOT NULL COMMENT 'order, payout, account, system, stock',
    `title` VARCHAR(200) NOT NULL,
    `message` TEXT NOT NULL,
    `link` VARCHAR(255) NULL,
    `is_read` BOOLEAN NOT NULL DEFAULT FALSE,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_notifications_user_id` (`user_id`),
    KEY `idx_notifications_is_read` (`is_read`),
    KEY `idx_notifications_created_at` (`created_at`),
    KEY `idx_notifications_user_read` (`user_id`, `is_read`),
    CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) 
        REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 28. HOMEPAGE SECTIONS
-- Purpose: Dynamic CMS homepage block builder and layout order configuration.
-- ============================================================================
CREATE TABLE `homepage_sections` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `section_type` ENUM('hero', 'banner_slider', 'featured_categories', 'featured_products', 'trending_products', 'deals', 'seller_highlights', 'promotional') NOT NULL,
    `title` VARCHAR(200) NOT NULL,
    `subtitle` VARCHAR(255) NULL,
    `content` JSON NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_homepage_sections_type` (`section_type`),
    KEY `idx_homepage_sections_active` (`is_active`),
    KEY `idx_homepage_sections_sort` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 29. BANNERS
-- Purpose: Marketing image banners and carousel slides displayed across the marketplace.
-- ============================================================================
CREATE TABLE `banners` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(200) NOT NULL,
    `image_path` VARCHAR(255) NOT NULL,
    `link_url` VARCHAR(255) NULL,
    `position` ENUM('hero', 'sidebar', 'promotional', 'category') NOT NULL DEFAULT 'hero',
    `sort_order` INT NOT NULL DEFAULT 0,
    `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
    `starts_at` DATETIME NULL,
    `expires_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_banners_position` (`position`),
    KEY `idx_banners_active` (`is_active`),
    KEY `idx_banners_sort` (`sort_order`),
    KEY `idx_banners_dates` (`starts_at`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 30. WEBSITE SETTINGS
-- Purpose: Key-value configuration store for marketplace operational settings, currency, branding.
-- ============================================================================
CREATE TABLE `website_settings` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `setting_key` VARCHAR(100) NOT NULL,
    `setting_value` TEXT NULL,
    `setting_group` VARCHAR(50) NOT NULL DEFAULT 'general',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_settings_key` (`setting_key`),
    KEY `idx_settings_group` (`setting_group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 31. CONTENT PAGES
-- Purpose: Static content and policy pages (Terms of Service, Privacy Policy, About Us, etc.).
-- ============================================================================
CREATE TABLE `content_pages` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `slug` VARCHAR(150) NOT NULL,
    `title` VARCHAR(200) NOT NULL,
    `content` LONGTEXT NOT NULL,
    `meta_title` VARCHAR(255) NULL,
    `meta_description` TEXT NULL,
    `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_content_pages_slug` (`slug`),
    KEY `idx_content_pages_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 32. MENUS
-- Purpose: Navigation menus and footer link columns with nested sub-item support.
-- ============================================================================
CREATE TABLE `menus` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `location` ENUM('header', 'footer_col1', 'footer_col2', 'footer_col3', 'footer_col4') NOT NULL DEFAULT 'header',
    `label` VARCHAR(100) NOT NULL,
    `url` VARCHAR(255) NOT NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
    `parent_id` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_menus_location` (`location`),
    KEY `idx_menus_active` (`is_active`),
    KEY `idx_menus_sort` (`sort_order`),
    KEY `idx_menus_parent_id` (`parent_id`),
    CONSTRAINT `fk_menus_parent` FOREIGN KEY (`parent_id`) 
        REFERENCES `menus` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 33. CONTACT MESSAGES
-- Purpose: Inquiries submitted through customer contact forms with support response state.
-- ============================================================================
CREATE TABLE `contact_messages` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(150) NOT NULL,
    `email` VARCHAR(255) NOT NULL,
    `subject` VARCHAR(200) NOT NULL,
    `message` TEXT NOT NULL,
    `status` ENUM('new', 'read', 'replied', 'archived') NOT NULL DEFAULT 'new',
    `admin_notes` TEXT NULL,
    `replied_at` DATETIME NULL,
    `ip_address` VARCHAR(45) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_contact_email` (`email`),
    KEY `idx_contact_status` (`status`),
    KEY `idx_contact_created_at` (`created_at`),
    KEY `idx_contact_ip` (`ip_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 34. FAQS
-- Purpose: Frequently Asked Questions knowledge base categorized by topic.
-- ============================================================================
CREATE TABLE `faqs` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `question` TEXT NOT NULL,
    `answer` TEXT NOT NULL,
    `category` VARCHAR(100) NOT NULL DEFAULT 'General',
    `sort_order` INT NOT NULL DEFAULT 0,
    `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_faqs_category` (`category`),
    KEY `idx_faqs_active` (`is_active`),
    KEY `idx_faqs_sort` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 35. AUDIT LOGS
-- Purpose: Security and operational audit log capturing entity changes with diff snapshots.
-- ============================================================================
CREATE TABLE `audit_logs` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NULL,
    `action` VARCHAR(100) NOT NULL,
    `entity_type` VARCHAR(100) NOT NULL,
    `entity_id` INT UNSIGNED NULL,
    `old_values` JSON NULL,
    `new_values` JSON NULL,
    `ip_address` VARCHAR(45) NULL,
    `user_agent` VARCHAR(255) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_audit_user_id` (`user_id`),
    KEY `idx_audit_action` (`action`),
    KEY `idx_audit_entity` (`entity_type`, `entity_id`),
    KEY `idx_audit_created_at` (`created_at`),
    CONSTRAINT `fk_audit_logs_user` FOREIGN KEY (`user_id`) 
        REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 36. PASSWORD RESET TOKENS
-- Purpose: Time-sensitive cryptographic tokens for secure password recovery workflows.
-- ============================================================================
CREATE TABLE `password_reset_tokens` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `token` VARCHAR(255) NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `used_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_pwd_reset_user_id` (`user_id`),
    KEY `idx_pwd_reset_token` (`token`),
    KEY `idx_pwd_reset_expires_at` (`expires_at`),
    CONSTRAINT `fk_pwd_reset_user` FOREIGN KEY (`user_id`) 
        REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 37. EMAIL VERIFICATION TOKENS
-- Purpose: Tokens for validating customer and vendor email addresses upon registration.
-- ============================================================================
CREATE TABLE `email_verification_tokens` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `token` VARCHAR(255) NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_email_verify_user_id` (`user_id`),
    KEY `idx_email_verify_token` (`token`),
    KEY `idx_email_verify_expires_at` (`expires_at`),
    CONSTRAINT `fk_email_verify_user` FOREIGN KEY (`user_id`) 
        REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;

SET FOREIGN_KEY_CHECKS = 1;
