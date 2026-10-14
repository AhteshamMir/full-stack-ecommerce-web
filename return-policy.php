<?php
/**
 * ShopSphere - Return & Refund Policy Page
 */
$page_title = 'Return & Refund Policy';
require_once __DIR__ . '/includes/header.php';

$content = '';
$title = 'Return & Refund Policy';
try {
    $db = get_db();
    $stmt = $db->prepare("SELECT title, content FROM content_pages WHERE slug = 'return-policy' AND is_active = 1 LIMIT 1");
    $stmt->execute();
    if ($row = $stmt->fetch()) {
        $title = $row['title'];
        $content = $row['content'];
    }
} catch (Exception $e) {}

if (empty($content)) {
    $content = '
    <h3>1. 7-Day Hassle-Free Returns</h3>
    <p>We want you to be completely delighted with your purchase. Customers can file a return request within <strong>7 calendar days</strong> of receiving their parcel.</p>

    <h3>2. Eligible Return Reasons</h3>
    <ul>
        <li>Product received was defective, broken, or damaged in transit.</li>
        <li>Delivered item does not match website specifications or description.</li>
        <li>Incorrect size, color, or model received.</li>
        <li>Missing accessories or parts from the manufacturer box.</li>
    </ul>

    <h3>3. Return Eligibility Requirements</h3>
    <p>Items must be returned in their original packaging, with all vendor tags, barcodes, warranty cards, manuals, and free gifts included. Products showing signs of wear or intentional damage are not eligible for return.</p>

    <h3>4. Refund Processing</h3>
    <p>Once the vendor receives and inspects the returned merchandise, the return status is updated. Approved refunds are credited back to customer account balance or issued via electronic bank transfer within 3 to 7 business days.</p>

    <h3>5. How to File a Return</h3>
    <p>Log into your ShopSphere account, visit your <a href="orders.php">Order History</a>, select the order item you wish to return, and click <strong>"Request Return"</strong> with an explanation and photo proof.</p>';
}
?>

<div class="container py-4">
    <nav class="ss-breadcrumb">
        <ol>
            <li><a href="<?php echo e(url('/')); ?>"><i class="bi bi-house"></i> Home</a></li>
            <li>Return Policy</li>
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
