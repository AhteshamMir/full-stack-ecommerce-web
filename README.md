# 🛒 ShopSphere — Multi-Vendor E-Commerce Marketplace

A complete, production-oriented multi-vendor e-commerce marketplace built with PHP 8.2, MySQL 8, Bootstrap 5, and jQuery. Designed as a fully functional platform where customers browse and purchase, sellers manage stores and inventory, and administrators oversee the entire marketplace.

---

## ✨ Features

### For Customers
- Account registration and email verification
- Product browsing with search, filters, and sorting
- Product detail pages with image galleries, variants, and reviews
- Persistent shopping cart with quantity controls
- Coupon code application at checkout
- Multi-vendor checkout with address management
- Order history, tracking, and status timeline
- Wishlist management
- Review and rating system (verified purchases)
- In-app notification center

### For Sellers
- Seller application and approval workflow
- Store branding and profile management
- Product listing with image upload and inventory tracking
- Order management with status transitions and tracking
- Earnings dashboard with commission breakdown
- Payout withdrawal requests
- Promotional coupon creation
- Sales analytics and performance reports
- Low stock alerts and notifications

### For Administrators
- Executive dashboard with GMV and revenue metrics
- Seller moderation (approve/reject/suspend)
- Customer management
- Category hierarchy management with custom commissions
- Product approval/rejection workflow
- Platform-wide order management
- Coupon engine management
- Commission rules configuration (default, category, seller)
- Payout settlement processing
- Review moderation
- Banner CMS
- Site-wide settings editor
- Contact message inbox
- Audit log viewer
- Financial reports with export

---

## 🛠 Technology Stack

| Layer | Technology |
|-------|-----------|
| **Frontend** | HTML5, CSS3, JavaScript, jQuery 3.7, Bootstrap 5.3 |
| **Icons** | Bootstrap Icons |
| **Fonts** | Google Fonts (Inter, Plus Jakarta Sans) |
| **Backend** | PHP 8.2+ (vanilla, no framework) |
| **Database** | MySQL 8.0 / MariaDB 10.5+ |
| **Sessions** | PHP native sessions with security hardening |
| **Security** | PDO prepared statements, CSRF tokens, password hashing, input validation |

---

## 📁 Project Structure

```
shopsphere/
├── .env                          # Environment configuration
├── .env.example                  # Template environment file
├── .gitignore
├── database.sql                  # Database schema (root copy)
├── index.php                     # Homepage
├── shop.php                      # Product catalog
├── product.php                   # Product detail
├── cart.php                      # Shopping cart
├── checkout.php                  # Multi-vendor checkout
├── order-success.php             # Order confirmation
├── login.php / register.php      # Customer authentication
├── account.php / profile.php     # Customer account pages
├── orders.php / order-details.php
├── wishlist.php / reviews.php
├── track-order.php / notifications.php
├── config/
│   ├── app.php                   # Application constants
│   ├── database.php              # PDO connection singleton
│   └── security.php              # Session & security headers
├── includes/
│   ├── header.php                # Main storefront header
│   ├── footer.php                # Main storefront footer
│   ├── auth.php                  # Authentication functions
│   ├── csrf.php                  # CSRF protection
│   ├── functions.php             # Utility functions
│   └── validation.php            # Input validation
├── classes/                      # 12 service classes
│   ├── AuthService.php
│   ├── ProductService.php
│   ├── CartService.php
│   ├── OrderService.php
│   ├── InventoryService.php
│   ├── CouponService.php
│   ├── CommissionService.php
│   ├── PaymentService.php
│   ├── NotificationService.php
│   ├── ReviewService.php
│   ├── PayoutService.php
│   └── Database.php
├── actions/
│   ├── checkout-actions.php      # Transactional order processor
│   └── review-actions.php        # Review submission handler
├── api/
│   ├── cart.php                  # AJAX cart operations
│   ├── wishlist.php              # AJAX wishlist toggle
│   ├── search.php                # Search autocomplete
│   └── notifications.php         # Notification actions
├── seller/                       # Seller dashboard (18 pages)
│   ├── dashboard.php
│   ├── products.php / add-product.php / edit-product.php
│   ├── orders.php / order-details.php
│   ├── inventory.php / earnings.php / payouts.php
│   ├── coupons.php / analytics.php / reviews.php / returns.php
│   ├── store-settings.php / profile.php / notifications.php
│   └── login.php / register.php
├── admin/                        # Admin control panel (20+ pages)
│   ├── dashboard.php
│   ├── sellers.php / seller-details.php / customers.php
│   ├── categories.php / products.php
│   ├── orders.php / order-details.php
│   ├── coupons.php / commissions.php / payouts.php
│   ├── reviews.php / banners.php / settings.php
│   ├── contact-messages.php / activity-logs.php
│   ├── inventory.php / reports.php
│   └── login.php
├── database/
│   ├── shopsphere.sql            # Full database schema (37 tables)
│   └── seed.sql                  # Demo data
└── assets/
    ├── css/style.css             # Custom stylesheet (42KB)
    ├── js/main.js                # Custom JavaScript (25KB)
    └── images/misc/placeholder.svg
```

---

## 🚀 Installation Guide

### Prerequisites
- **XAMPP** (or WAMP/MAMP) with PHP 8.2+ and MySQL 8.0+
- **Web browser** (Chrome, Firefox, Edge)

### Step-by-Step Setup

1. **Install XAMPP** from [apachefriends.org](https://www.apachefriends.org/) and start **Apache** and **MySQL** services.

2. **Copy the project** folder to your XAMPP htdocs directory:
   ```
   C:\xampp\htdocs\shopsphere\
   ```

3. **Create the database** via phpMyAdmin (`http://localhost/phpmyadmin`):
   - Click **"New"** in the sidebar
   - Database name: `shopsphere`
   - Collation: `utf8mb4_unicode_ci`
   - Click **Create**

4. **Import the schema**: In phpMyAdmin, select the `shopsphere` database, go to **Import**, and upload:
   ```
   database/shopsphere.sql
   ```

5. **Import seed data**: Import the demo data file:
   ```
   database/seed.sql
   ```

6. **Configure environment**: Copy `.env.example` to `.env` (already done) and update if needed:
   ```
   DB_HOST=localhost
   DB_NAME=shopsphere
   DB_USER=root
   DB_PASS=
   APP_URL=http://localhost/shopsphere
   ```

7. **Create upload directories**:
   ```
   mkdir uploads\products
   mkdir uploads\avatars
   mkdir uploads\stores
   mkdir uploads\banners
   mkdir uploads\categories
   ```

8. **Open in browser**: Navigate to `http://localhost/shopsphere/`

---

## 🔐 Demo Login Credentials

| Role | Email | Password |
|------|-------|----------|
| **Admin** | admin@shopsphere.local | Admin@123 |
| **Seller 1** (TechWorld) | techworld@shopsphere.local | Seller@123 |
| **Seller 2** (FashionHub) | fashionhub@shopsphere.local | Seller@123 |
| **Customer 1** | ali.customer@gmail.com | Customer@123 |
| **Customer 2** | fatima.buyer@gmail.com | Customer@123 |

> **Note:** All demo accounts use the same bcrypt hash. In production, each user would have a unique password.

---

## 💰 Currency

ShopSphere is configured for **Pakistani Rupee (PKR)** with the symbol **Rs.**. This can be changed in the `.env` file.

---

## 🔒 Security Features

- **PDO Prepared Statements** — All database queries use parameterized queries
- **CSRF Protection** — Token-based protection on all forms
- **Password Hashing** — bcrypt via `password_hash()` / `password_verify()`
- **Session Security** — HttpOnly cookies, SameSite=Strict, regeneration on login
- **Input Validation** — Server-side validation on all form submissions
- **Output Escaping** — All user data escaped with `htmlspecialchars()`
- **Brute-Force Protection** — Account lockout after 5 failed login attempts
- **Role-Based Access** — Strict role enforcement (customer/seller/admin)
- **SQL Injection Prevention** — No raw user input in queries
- **XSS Prevention** — Security headers and output encoding

---

## 📝 License

This project was created for educational and demonstration purposes. Feel free to use, modify, and build upon it.

---

## 🤝 Contributing

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/new-feature`)
3. Commit your changes
4. Push to the branch
5. Open a Pull Request

---

Built with ❤️ using PHP, MySQL, Bootstrap, and jQuery.
