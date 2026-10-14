<?php
/**
 * ShopSphere - Privacy Policy Page
 */
$page_title = 'Privacy Policy';
require_once __DIR__ . '/includes/header.php';

$content = '';
$title = 'Privacy Policy';
try {
    $db = get_db();
    $stmt = $db->prepare("SELECT title, content FROM content_pages WHERE slug = 'privacy-policy' AND is_active = 1 LIMIT 1");
    $stmt->execute();
    if ($row = $stmt->fetch()) {
        $title = $row['title'];
        $content = $row['content'];
    }
} catch (Exception $e) {}

if (empty($content)) {
    $content = '
    <h3>1. Information We Collect</h3>
    <p>We collect information you provide directly to us when creating an account, updating your profile, placing orders, requesting seller access, or contacting customer support. This may include your name, email address, phone number, physical delivery address, and order transaction details.</p>

    <h3>2. How We Use Your Information</h3>
    <p>Your personal information is used exclusively to facilitate marketplace operations: order processing, logistics delivery, fraud prevention, transactional notifications, vendor settlements, and improving platform usability.</p>

    <h3>3. Data Protection and Passwords</h3>
    <p>We implement defense-in-depth security standards. All passwords are encrypted using state-of-the-art cryptographic hashing algorithms (BCRYPT / Argon2). We never store or log plaintext passwords, credit card numbers, or full financial credentials.</p>

    <h3>4. Multi-Vendor Information Sharing</h3>
    <p>When you purchase an item from an independent seller on ShopSphere, we share only the essential delivery details (recipient name, address, phone number) with that specific seller to enable packaging and shipment. Sellers are legally bound not to misuse your customer details.</p>

    <h3>5. Cookies & Session Storage</h3>
    <p>We use strict HTTP-only session cookies and CSRF security tokens to maintain your login session and secure checkout interactions. We do not sell your personal browsing habits to third-party ad networks.</p>

    <h3>6. Contacting Privacy Compliance</h3>
    <p>If you have any questions or data deletion requests, contact our compliance officer at <a href="mailto:privacy@shopsphere.local">privacy@shopsphere.local</a>.</p>';
}
?>

<div class="container py-4">
    <nav class="ss-breadcrumb">
        <ol>
            <li><a href="<?php echo e(url('/')); ?>"><i class="bi bi-house"></i> Home</a></li>
            <li>Privacy Policy</li>
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
