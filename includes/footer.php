<?php
/**
 * ShopSphere - Main Site Footer Include
 *
 * Provides the global site footer, navigation links, social links,
 * newsletter subscription UI, payment badges, CDN scripts, and closes the HTML document.
 *
 * @package ShopSphere\Includes
 */

declare(strict_types=1);

// Safe retrieval of site configuration and social media settings with try-catch
$site_name = defined('APP_NAME') ? APP_NAME : 'ShopSphere';
$site_description = 'ShopSphere is Pakistan\'s premier multi-vendor e-commerce marketplace connecting trusted sellers with thousands of buyers across the country.';
$social_facebook = 'https://facebook.com';
$social_twitter = 'https://twitter.com';
$social_instagram = 'https://instagram.com';
$social_linkedin = 'https://linkedin.com';
$social_youtube = 'https://youtube.com';

try {
    $settings = get_settings_batch([
        'site_name',
        'site_description',
        'social_facebook',
        'social_twitter',
        'social_instagram',
        'social_linkedin',
        'social_youtube'
    ]);

    if (!empty($settings['site_name'])) {
        $site_name = (string) $settings['site_name'];
    }
    if (!empty($settings['site_description'])) {
        $site_description = (string) $settings['site_description'];
    }
    if (!empty($settings['social_facebook'])) {
        $social_facebook = (string) $settings['social_facebook'];
    }
    if (!empty($settings['social_twitter'])) {
        $social_twitter = (string) $settings['social_twitter'];
    }
    if (!empty($settings['social_instagram'])) {
        $social_instagram = (string) $settings['social_instagram'];
    }
    if (!empty($settings['social_linkedin'])) {
        $social_linkedin = (string) $settings['social_linkedin'];
    }
    if (!empty($settings['social_youtube'])) {
        $social_youtube = (string) $settings['social_youtube'];
    }
} catch (Throwable $e) {
    error_log('[ShopSphere Footer] Settings retrieval error: ' . $e->getMessage());
}

// Default semantic navigation links
$quick_links = [
    ['label' => 'Shop All Products', 'url' => url('products.php')],
    ['label' => 'Hot Deals & Offers', 'url' => url('deals.php')],
    ['label' => 'Browse Categories', 'url' => url('categories.php')],
    ['label' => 'Track Your Order', 'url' => url('track-order.php')],
    ['label' => 'About Us', 'url' => url('about.php')],
    ['label' => 'Contact Us', 'url' => url('contact.php')],
];

$customer_service_links = [
    ['label' => 'Help Center & FAQ', 'url' => url('help.php')],
    ['label' => 'Shipping & Delivery', 'url' => url('shipping.php')],
    ['label' => 'Returns & Refunds', 'url' => url('returns.php')],
    ['label' => 'Payment Methods', 'url' => url('payments.php')],
    ['label' => 'Privacy Policy', 'url' => url('privacy.php')],
    ['label' => 'Terms & Conditions', 'url' => url('terms.php')],
];

$account_links = [
    ['label' => is_logged_in() ? 'My Profile' : 'Sign In / Register', 'url' => url(is_logged_in() ? 'account/index.php' : 'login.php')],
    ['label' => 'Order History', 'url' => url(is_logged_in() ? 'account/orders.php' : 'login.php')],
    ['label' => 'My Wishlist', 'url' => url(is_logged_in() ? 'account/wishlist.php' : 'login.php')],
    ['label' => 'Shopping Cart', 'url' => url('cart.php')],
    ['label' => (is_logged_in() && is_seller()) ? 'Seller Dashboard' : 'Become a Seller', 'url' => url((is_logged_in() && is_seller()) ? 'seller/dashboard.php' : 'become-seller.php')],
];

// Optional retrieval from database menus table with try-catch
try {
    $pdo = get_db_connection();
    $stmtMenu = $pdo->query("SELECT title, url, menu_group FROM menus WHERE is_active = 1 ORDER BY sort_order ASC");
    $dbMenus = $stmtMenu->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($dbMenus)) {
        $customQuick = [];
        $customService = [];
        $customAccount = [];

        foreach ($dbMenus as $m) {
            $group = strtolower((string) ($m['menu_group'] ?? ''));
            $item = [
                'label' => (string) ($m['title'] ?? ''),
                'url'   => url((string) ($m['url'] ?? '')),
            ];

            if (in_array($group, ['quick', 'quick_links', 'quick-links'], true)) {
                $customQuick[] = $item;
            } elseif (in_array($group, ['service', 'customer_service', 'customer-service', 'support'], true)) {
                $customService[] = $item;
            } elseif (in_array($group, ['account', 'user', 'my_account'], true)) {
                $customAccount[] = $item;
            }
        }

        if (!empty($customQuick)) {
            $quick_links = $customQuick;
        }
        if (!empty($customService)) {
            $customer_service_links = $customService;
        }
        if (!empty($customAccount)) {
            $account_links = $customAccount;
        }
    }
} catch (Throwable $e) {
    // Database menus table does not exist or is unavailable; fallback defaults are preserved
}
?>

    </main>
    <!-- Main Content Container End -->

    <!-- ShopSphere Main Footer -->
    <footer class="ss-footer" role="contentinfo">
        <div class="container">
            <div class="row g-4">
                <!-- Column 1: About ShopSphere -->
                <div class="col-12 col-md-6 col-lg-3">
                    <a href="<?= url('/') ?>" class="ss-logo mb-3 d-inline-flex align-items-center">
                        <span class="ss-logo-icon me-2">
                            <i class="bi bi-bag-check-fill"></i>
                        </span>
                        <span><?= e($site_name) ?></span>
                    </a>
                    <p class="text-white-50 small mb-3">
                        <?= e($site_description) ?>
                    </p>
                    <div class="ss-footer-social">
                        <?php if (!empty($social_facebook)): ?>
                            <a href="<?= e($social_facebook) ?>" target="_blank" rel="noopener noreferrer" title="Facebook" aria-label="ShopSphere on Facebook">
                                <i class="bi bi-facebook"></i>
                            </a>
                        <?php endif; ?>
                        <?php if (!empty($social_twitter)): ?>
                            <a href="<?= e($social_twitter) ?>" target="_blank" rel="noopener noreferrer" title="Twitter / X" aria-label="ShopSphere on Twitter">
                                <i class="bi bi-twitter-x"></i>
                            </a>
                        <?php endif; ?>
                        <?php if (!empty($social_instagram)): ?>
                            <a href="<?= e($social_instagram) ?>" target="_blank" rel="noopener noreferrer" title="Instagram" aria-label="ShopSphere on Instagram">
                                <i class="bi bi-instagram"></i>
                            </a>
                        <?php endif; ?>
                        <?php if (!empty($social_linkedin)): ?>
                            <a href="<?= e($social_linkedin) ?>" target="_blank" rel="noopener noreferrer" title="LinkedIn" aria-label="ShopSphere on LinkedIn">
                                <i class="bi bi-linkedin"></i>
                            </a>
                        <?php endif; ?>
                        <?php if (!empty($social_youtube)): ?>
                            <a href="<?= e($social_youtube) ?>" target="_blank" rel="noopener noreferrer" title="YouTube" aria-label="ShopSphere on YouTube">
                                <i class="bi bi-youtube"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Column 2: Quick Links -->
                <div class="col-6 col-md-6 col-lg-3">
                    <h5>Quick Links</h5>
                    <ul class="ss-footer-links">
                        <?php foreach ($quick_links as $link): ?>
                            <li>
                                <a href="<?= e($link['url']) ?>">
                                    <?= e($link['label']) ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <!-- Column 3: Customer Service -->
                <div class="col-6 col-md-6 col-lg-3">
                    <h5>Customer Service</h5>
                    <ul class="ss-footer-links">
                        <?php foreach ($customer_service_links as $link): ?>
                            <li>
                                <a href="<?= e($link['url']) ?>">
                                    <?= e($link['label']) ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <!-- Column 4: My Account & Newsletter -->
                <div class="col-12 col-md-6 col-lg-3">
                    <h5>My Account</h5>
                    <ul class="ss-footer-links mb-4">
                        <?php foreach ($account_links as $link): ?>
                            <li>
                                <a href="<?= e($link['url']) ?>">
                                    <?= e($link['label']) ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <!-- Newsletter Signup Form (UI) -->
                    <div class="ss-footer-newsletter">
                        <h5>Stay Connected</h5>
                        <p class="text-white-50">Subscribe for exclusive deals, flash vouchers, and updates.</p>
                        <form class="ss-newsletter-form" onsubmit="event.preventDefault(); alert('Thank you for subscribing to ShopSphere updates!');">
                            <div class="input-group">
                                <input type="email" class="form-control" placeholder="Enter your email" required aria-label="Enter your email address">
                                <button class="btn btn-ss-primary" type="submit" aria-label="Subscribe">
                                    <i class="bi bi-send-fill"></i>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Footer Bottom -->
            <div class="ss-footer-bottom">
                <div class="text-white-50">
                    &copy; <?= date('Y') ?> <strong><?= e($site_name) ?></strong>. All rights reserved.
                </div>

                <div class="d-flex align-items-center gap-3">
                    <a href="<?= url('privacy.php') ?>">Privacy Policy</a>
                    <span class="text-white-50">&bull;</span>
                    <a href="<?= url('terms.php') ?>">Terms of Service</a>
                    <span class="text-white-50">&bull;</span>
                    <a href="<?= url('shipping.php') ?>">Shipping Policy</a>
                </div>

                <!-- Payment Methods -->
                <div class="d-flex align-items-center gap-2 text-white-50">
                    <span class="small me-1">Secure Payments:</span>
                    <span class="badge bg-light text-dark py-1 px-2 border" title="Cash on Delivery"><i class="bi bi-cash me-1"></i>COD</span>
                    <span class="badge bg-light text-dark py-1 px-2 border" title="JazzCash"><i class="bi bi-wallet2 me-1"></i>JazzCash</span>
                    <span class="badge bg-light text-dark py-1 px-2 border" title="EasyPaisa"><i class="bi bi-phone me-1"></i>EasyPaisa</span>
                    <span class="badge bg-light text-dark py-1 px-2 border" title="Credit / Debit Cards"><i class="bi bi-credit-card-2-front me-1"></i>Cards</span>
                </div>
            </div>
        </div>
    </footer>

    <!-- jQuery 3.7 CDN -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>

    <!-- Bootstrap 5 Bundle JS CDN (includes Popper) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>

    <!-- Custom ShopSphere JavaScript -->
    <script src="<?= asset('js/main.js') ?>"></script>
</body>
</html>
