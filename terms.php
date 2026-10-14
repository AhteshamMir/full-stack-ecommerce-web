<?php
/**
 * ShopSphere - Terms and Conditions Page
 */
$page_title = 'Terms & Conditions';
require_once __DIR__ . '/includes/header.php';

$content = '';
$title = 'Terms & Conditions';
try {
    $db = get_db();
    $stmt = $db->prepare("SELECT title, content FROM content_pages WHERE slug = 'terms-and-conditions' AND is_active = 1 LIMIT 1");
    $stmt->execute();
    if ($row = $stmt->fetch()) {
        $title = $row['title'];
        $content = $row['content'];
    }
} catch (Exception $e) {}

if (empty($content)) {
    $content = '
    <h3>1. Acceptance of Terms</h3>
    <p>By creating an account, browsing products, or submitting transactions on ShopSphere, you agree to comply with and be bound by these legal terms and conditions. If you do not accept these terms, you must refrain from using the marketplace.</p>

    <h3>2. Customer Obligations</h3>
    <p>Customers must provide accurate, current, and complete details during checkout and registration. Fraudulent orders, spoofed identities, payment manipulation, and deliberate exploitation of pricing mistakes will result in immediate account suspension and order cancellation.</p>

    <h3>3. Multi-Vendor Model & Vendor Responsibility</h3>
    <p>ShopSphere operates as a marketplace platform. Each vendor is an independent merchant responsible for product specifications, accurate inventory, genuine product sourcing, and fulfillment. ShopSphere actively moderates vendors and retains dispute arbitration authority.</p>

    <h3>4. Pricing, Stock & Order Acceptance</h3>
    <p>Prices are denominated in Pakistani Rupees (PKR) and are inclusive of standard local calculations unless specified. An order placement represents an offer to purchase; order confirmation occurs once seller allocation and inventory reservation pass system validation.</p>

    <h3>5. Platform Commissions & Seller Settlements</h3>
    <p>Vendors agree to marketplace commission deductions taken at order placement based on the effective commission rule. Payout disbursements are subject to successful delivery confirmation and administrative audit verification.</p>

    <h3>6. Governing Law</h3>
    <p>These terms shall be governed by and interpreted in accordance with the laws of Pakistan. Any disputes arising shall be resolved within competent local jurisdictions.</p>';
}
?>

<div class="container py-4">
    <nav class="ss-breadcrumb">
        <ol>
            <li><a href="<?php echo e(url('/')); ?>"><i class="bi bi-house"></i> Home</a></li>
            <li>Terms & Conditions</li>
        </ol>
    </nav>

    <div class="row justify-content-center">
        <div class="col-lg-9">
            <div class="ss-card">
                <div class="ss-card-body p-4 p-md-5">
                    <h1 class="h2 mb-4"><?php echo e($title); ?></h1>
                    <div class="ss-page-content text-ss-muted" style="line-height: 1.8;">
                        <?php echo $content; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
