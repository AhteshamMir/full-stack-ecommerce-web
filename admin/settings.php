<?php
/**
 * ShopSphere - Platform Global Settings & CMS Configuration
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/helpers.php';

require_role('admin');
$db = get_db();

// Setting group mapping
$settingGroups = [
    // General
    'site_name'               => 'general',
    'site_tagline'            => 'general',
    'announcement_text'       => 'general',
    'announcement_link'       => 'general',
    'announcement_active'     => 'general',

    // Contact
    'contact_phone'           => 'contact',
    'contact_email'           => 'contact',
    'contact_address'         => 'contact',
    'support_hours'           => 'contact',
    'whatsapp_number'         => 'contact',

    // Social Links
    'facebook_url'            => 'social',
    'twitter_url'             => 'social',
    'instagram_url'           => 'social',
    'youtube_url'             => 'social',
    'linkedin_url'            => 'social',

    // Marketplace & Shipping
    'currency'                => 'marketplace',
    'currency_symbol'         => 'marketplace',
    'default_commission_rate' => 'marketplace',
    'default_shipping_fee'    => 'marketplace',
    'free_shipping_threshold' => 'marketplace',
    'min_order_amount'        => 'marketplace',
];

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    $submitted = $_POST['settings'] ?? [];
    $updatedCount = 0;

    $stmt = $db->prepare("
        INSERT INTO website_settings (setting_key, setting_value, setting_group, created_at, updated_at)
        VALUES (:key, :val, :grp, NOW(), NOW())
        ON DUPLICATE KEY UPDATE setting_value = :val2, setting_group = :grp2, updated_at = NOW()
    ");

    foreach ($settingGroups as $key => $group) {
        $val = trim((string)($submitted[$key] ?? ''));
        $stmt->execute([
            'key'  => $key,
            'val'  => $val,
            'grp'  => $group,
            'val2' => $val,
            'grp2' => $group,
        ]);
        $updatedCount++;
    }

    log_audit($db, 'update_site_settings', 'website_settings', null, null, $submitted);

    flash('success', 'Marketplace settings and configurations saved successfully.');
    redirect(url('/admin/settings.php'));
}

// Fetch all current settings from database
$allSettings = [];
try {
    $rows = $db->query("SELECT setting_key, setting_value FROM website_settings")->fetchAll();
    foreach ($rows as $r) {
        $allSettings[$r['setting_key']] = $r['setting_value'];
    }
} catch (Exception $e) {}

// Helper to get loaded setting with fallback
$s = function(string $key, string $default = '') use ($allSettings): string {
    return $allSettings[$key] ?? $default;
};

$page_title = 'Site & Marketplace Settings';
$current_page = 'settings';
require_once __DIR__ . '/header.php';
?>

<form method="POST" action="<?php echo url('/admin/settings.php'); ?>">
    <?php echo csrf_field(); ?>

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
        <div>
            <h2 class="h4 mb-1">Global Configuration Engine</h2>
            <p class="text-ss-muted small mb-0">Manage platform identity, announcements, customer support, and financial parameters.</p>
        </div>
        <div>
            <button type="submit" class="btn btn-ss-primary px-4">
                <i class="bi bi-check2-circle me-1"></i> Save All Settings
            </button>
        </div>
    </div>

    <div class="row g-4">
        <!-- Tab Navigation Navigation on the left or top -->
        <div class="col-md-3">
            <div class="ss-card">
                <div class="list-group list-group-flush rounded-3" id="settingsTabs" role="tablist">
                    <a class="list-group-item list-group-item-action active p-3" id="general-tab" data-bs-toggle="list" href="#tab-general" role="tab">
                        <i class="bi bi-globe me-2 text-primary"></i> General & Branding
                    </a>
                    <a class="list-group-item list-group-item-action p-3" id="contact-tab" data-bs-toggle="list" href="#tab-contact" role="tab">
                        <i class="bi bi-telephone me-2 text-success"></i> Contact & Helpdesk
                    </a>
                    <a class="list-group-item list-group-item-action p-3" id="marketplace-tab" data-bs-toggle="list" href="#tab-marketplace" role="tab">
                        <i class="bi bi-wallet2 me-2 text-warning"></i> Finance & Shipping
                    </a>
                    <a class="list-group-item list-group-item-action p-3" id="social-tab" data-bs-toggle="list" href="#tab-social" role="tab">
                        <i class="bi bi-share me-2 text-info"></i> Social Network Links
                    </a>
                </div>
            </div>
        </div>

        <!-- Tab Content Panes -->
        <div class="col-md-9">
            <div class="tab-content" id="settingsTabContent">
                <!-- 1. General & Branding -->
                <div class="tab-pane fade show active" id="tab-general" role="tabpanel">
                    <div class="ss-card mb-4">
                        <div class="ss-card-header">
                            <h5><i class="bi bi-globe text-primary me-2"></i> Marketplace Branding</h5>
                        </div>
                        <div class="ss-card-body">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Marketplace Name</label>
                                <input type="text" name="settings[site_name]" class="form-control" value="<?php echo e($s('site_name', 'ShopSphere')); ?>" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-bold">Site Tagline / Headline</label>
                                <input type="text" name="settings[site_tagline]" class="form-control" value="<?php echo e($s('site_tagline', 'Pakistan\'s Premier Multi-Vendor Online Marketplace')); ?>">
                            </div>

                            <hr class="my-4">

                            <h6 class="fw-bold mb-3"><i class="bi bi-megaphone text-warning me-2"></i> Top Announcement Banner</h6>

                            <div class="mb-3">
                                <label class="form-label fw-bold">Announcement Text</label>
                                <input type="text" name="settings[announcement_text]" class="form-control" value="<?php echo e($s('announcement_text', 'Free nationwide shipping on orders over Rs. 2,500! Shop top verified stores now.')); ?>" placeholder="Text displayed in the top bar across storefront...">
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-bold">Announcement Target Link</label>
                                <input type="text" name="settings[announcement_link]" class="form-control" value="<?php echo e($s('announcement_link', '/shop.php')); ?>" placeholder="/shop.php or /deals.php">
                            </div>

                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="settings[announcement_active]" id="annActive" value="1" <?php echo $s('announcement_active', '1') === '1' ? 'checked' : ''; ?>>
                                <label class="form-check-label fw-semibold" for="annActive">Display Announcement Bar on Storefront</label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 2. Contact & Helpdesk -->
                <div class="tab-pane fade" id="tab-contact" role="tabpanel">
                    <div class="ss-card mb-4">
                        <div class="ss-card-header">
                            <h5><i class="bi bi-telephone text-success me-2"></i> Support & Contact Info</h5>
                        </div>
                        <div class="ss-card-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Official Support Phone</label>
                                    <input type="text" name="settings[contact_phone]" class="form-control" value="<?php echo e($s('contact_phone', '+92 300 1234567')); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Official Inquiries Email</label>
                                    <input type="email" name="settings[contact_email]" class="form-control" value="<?php echo e($s('contact_email', 'support@shopsphere.pk')); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">WhatsApp Hotline Number</label>
                                    <input type="text" name="settings[whatsapp_number]" class="form-control" value="<?php echo e($s('whatsapp_number', '+923001234567')); ?>" placeholder="+923001234567">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Operating / Support Hours</label>
                                    <input type="text" name="settings[support_hours]" class="form-control" value="<?php echo e($s('support_hours', 'Mon - Sat: 9:00 AM - 9:00 PM PKT')); ?>">
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-bold">Headquarters Physical Address</label>
                                    <textarea name="settings[contact_address]" class="form-control" rows="3"><?php echo e($s('contact_address', 'Level 4, Tech Plaza, Main Boulevard, Gulberg III, Lahore, Pakistan')); ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 3. Finance & Shipping -->
                <div class="tab-pane fade" id="tab-marketplace" role="tabpanel">
                    <div class="ss-card mb-4">
                        <div class="ss-card-header">
                            <h5><i class="bi bi-wallet2 text-warning me-2"></i> Financial & Shipping Rules</h5>
                        </div>
                        <div class="ss-card-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Currency Code</label>
                                    <input type="text" name="settings[currency]" class="form-control text-uppercase" value="<?php echo e($s('currency', 'PKR')); ?>" readonly>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Currency Display Symbol</label>
                                    <input type="text" name="settings[currency_symbol]" class="form-control" value="<?php echo e($s('currency_symbol', 'Rs.')); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Baseline Platform Commission (%)</label>
                                    <div class="input-group">
                                        <input type="number" step="0.01" min="0" max="100" name="settings[default_commission_rate]" class="form-control" value="<?php echo e($s('default_commission_rate', '10.00')); ?>" required>
                                        <span class="input-group-text">%</span>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Default Flat Shipping Fee (PKR)</label>
                                    <div class="input-group">
                                        <span class="input-group-text">Rs.</span>
                                        <input type="number" step="0.01" min="0" name="settings[default_shipping_fee]" class="form-control" value="<?php echo e($s('default_shipping_fee', '200.00')); ?>" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Free Shipping Minimum Threshold (PKR)</label>
                                    <div class="input-group">
                                        <span class="input-group-text">Rs.</span>
                                        <input type="number" step="0.01" min="0" name="settings[free_shipping_threshold]" class="form-control" value="<?php echo e($s('free_shipping_threshold', '2500.00')); ?>" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Minimum Cart Checkout Amount (PKR)</label>
                                    <div class="input-group">
                                        <span class="input-group-text">Rs.</span>
                                        <input type="number" step="0.01" min="0" name="settings[min_order_amount]" class="form-control" value="<?php echo e($s('min_order_amount', '500.00')); ?>" required>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 4. Social Links -->
                <div class="tab-pane fade" id="tab-social" role="tabpanel">
                    <div class="ss-card mb-4">
                        <div class="ss-card-header">
                            <h5><i class="bi bi-share text-info me-2"></i> Official Social Media Channels</h5>
                        </div>
                        <div class="ss-card-body">
                            <div class="mb-3">
                                <label class="form-label fw-bold"><i class="bi bi-facebook text-primary me-1"></i> Facebook Page URL</label>
                                <input type="url" name="settings[facebook_url]" class="form-control" value="<?php echo e($s('facebook_url', 'https://facebook.com/shopsphere.pk')); ?>" placeholder="https://facebook.com/...">
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold"><i class="bi bi-instagram text-danger me-1"></i> Instagram Profile URL</label>
                                <input type="url" name="settings[instagram_url]" class="form-control" value="<?php echo e($s('instagram_url', 'https://instagram.com/shopsphere.pk')); ?>" placeholder="https://instagram.com/...">
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold"><i class="bi bi-twitter-x text-dark me-1"></i> X / Twitter Handle URL</label>
                                <input type="url" name="settings[twitter_url]" class="form-control" value="<?php echo e($s('twitter_url', 'https://x.com/shopspherepk')); ?>" placeholder="https://x.com/...">
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold"><i class="bi bi-youtube text-danger me-1"></i> YouTube Channel URL</label>
                                <input type="url" name="settings[youtube_url]" class="form-control" value="<?php echo e($s('youtube_url', 'https://youtube.com/@shopspherepk')); ?>" placeholder="https://youtube.com/...">
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold"><i class="bi bi-linkedin text-primary me-1"></i> LinkedIn Page URL</label>
                                <input type="url" name="settings[linkedin_url]" class="form-control" value="<?php echo e($s('linkedin_url', 'https://linkedin.com/company/shopsphere-pk')); ?>" placeholder="https://linkedin.com/...">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="d-flex justify-content-end">
                <button type="submit" class="btn btn-ss-primary btn-lg px-5">
                    <i class="bi bi-check2-circle me-1"></i> Save All Settings
                </button>
            </div>
        </div>
    </div>
</form>

<?php require_once __DIR__ . '/footer.php'; ?>
