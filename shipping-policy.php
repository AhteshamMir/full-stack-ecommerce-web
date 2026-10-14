<?php
/**
 * ShopSphere - Shipping Policy Page
 */
$page_title = 'Shipping Policy';
require_once __DIR__ . '/includes/header.php';

$content = '';
$title = 'Shipping Policy';
try {
    $db = get_db();
    $stmt = $db->prepare("SELECT title, content FROM content_pages WHERE slug = 'shipping-policy' AND is_active = 1 LIMIT 1");
    $stmt->execute();
    if ($row = $stmt->fetch()) {
        $title = $row['title'];
        $content = $row['content'];
    }
} catch (Exception $e) {}

if (empty($content)) {
    $content = '
    <h3>1. Shipping Coverage</h3>
    <p>ShopSphere delivers across all major metropolitan and suburban areas in Pakistan. Courier coverage includes Karachi, Lahore, Islamabad, Rawalpindi, Faisalabad, Multan, Peshawar, Quetta, and nationwide partner courier networks.</p>

    <h3>2. Delivery Timelines</h3>
    <ul>
        <li><strong>Standard Metro Delivery:</strong> 2 to 4 business days.</li>
        <li><strong>Regional & Non-Metro Delivery:</strong> 4 to 7 business days.</li>
        <li><strong>Express Dispatch:</strong> Orders placed before 1:00 PM are processed same-day by participating verified sellers.</li>
    </ul>

    <h3>3. Multi-Vendor Deliveries</h3>
    <p>Because ShopSphere is a multi-vendor marketplace, items ordered from different sellers may ship separately and arrive on different dates depending on vendor location. You will receive dedicated consignment tracking numbers for each seller shipment.</p>

    <h3>4. Shipping Rates</h3>
    <p>Standard delivery is charged at Rs. 200 per order or per seller group as configured. Orders that meet promotional free shipping thresholds (e.g. over Rs. 3,000) qualify for complimentary delivery.</p>

    <h3>5. Order Inspection & Cash on Delivery (COD)</h3>
    <p>For Cash on Delivery orders, please ensure exact cash is available upon arrival of the courier rider. Couriers may require digital or physical signature proof upon package handover.</p>';
}
?>

<div class="container py-4">
    <nav class="ss-breadcrumb">
        <ol>
            <li><a href="<?php echo e(url('/')); ?>"><i class="bi bi-house"></i> Home</a></li>
            <li>Shipping Policy</li>
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
