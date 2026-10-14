<?php
/**
 * ShopSphere - FAQ Page
 */
$page_title = 'Frequently Asked Questions';
require_once __DIR__ . '/includes/header.php';

$faqs = [];
try {
    $db = get_db();
    $stmt = $db->query("SELECT question, answer, category FROM faqs WHERE is_active = 1 ORDER BY sort_order ASC, id ASC");
    $faqs = $stmt->fetchAll();
} catch (Exception $e) {}

if (empty($faqs)) {
    $faqs = [
        ['category' => 'Orders & Shipping', 'question' => 'How long does delivery take?', 'answer' => 'Orders are typically delivered within 2–5 business days depending on your location and seller fulfillment speed.'],
        ['category' => 'Orders & Shipping', 'question' => 'Can I track my order in real-time?', 'answer' => 'Yes! Go to the "Track Order" link in the header or your account dashboard and enter your unique Order Tracking Number.'],
        ['category' => 'Payments & Coupons', 'question' => 'What payment methods do you accept?', 'answer' => 'We accept Cash on Delivery (COD) nationwide and simulated card payments for sandbox/testing.'],
        ['category' => 'Payments & Coupons', 'question' => 'How do I apply a discount coupon?', 'answer' => 'In your shopping cart or checkout screen, enter your promo code in the coupon field and click Apply.'],
        ['category' => 'Returns & Refunds', 'question' => 'What is the return policy window?', 'answer' => 'You can request a return within 7 calendar days of delivery if the product is defective, damaged, or incorrect.'],
        ['category' => 'Sellers', 'question' => 'How do I become a seller on ShopSphere?', 'answer' => 'Click "Sell on ShopSphere" in the header, complete the seller registration form, and wait for administrator verification.'],
    ];
}

// Group FAQs by category
$grouped_faqs = [];
foreach ($faqs as $f) {
    $cat = $f['category'] ?: 'General';
    $grouped_faqs[$cat][] = $f;
}
?>

<div class="container py-4">
    <nav class="ss-breadcrumb">
        <ol>
            <li><a href="<?php echo e(url('/')); ?>"><i class="bi bi-house"></i> Home</a></li>
            <li>FAQ</li>
        </ol>
    </nav>

    <div class="text-center mb-5">
        <h1 class="h2 mb-2">Frequently Asked Questions</h1>
        <p class="text-ss-muted">Find quick answers to common questions about shopping, shipping, and selling on ShopSphere.</p>
    </div>

    <div class="row justify-content-center">
        <div class="col-lg-9">
            <?php $accordion_index = 0; ?>
            <?php foreach ($grouped_faqs as $category => $items): ?>
                <h3 class="h5 mt-4 mb-3 text-ss-primary border-bottom pb-2">
                    <i class="bi bi-folder2-open text-ss-accent me-2"></i><?php echo e($category); ?>
                </h3>
                <div class="accordion mb-4" id="faqAccordion_<?php echo md5($category); ?>">
                    <?php foreach ($items as $item): $accordion_index++; ?>
                        <div class="accordion-item mb-2 border rounded">
                            <h2 class="accordion-header" id="heading_<?php echo $accordion_index; ?>">
                                <button class="accordion-button <?php echo $accordion_index === 1 ? '' : 'collapsed'; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#collapse_<?php echo $accordion_index; ?>">
                                    <?php echo e($item['question']); ?>
                                </button>
                            </h2>
                            <div id="collapse_<?php echo $accordion_index; ?>" class="accordion-collapse collapse <?php echo $accordion_index === 1 ? 'show' : ''; ?>" data-bs-parent="#faqAccordion_<?php echo md5($category); ?>">
                                <div class="accordion-body text-ss-muted">
                                    <?php echo nl2br(e($item['answer'])); ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>

            <div class="ss-card mt-5 text-center p-4 bg-ss-accent-light border-0">
                <h4 class="h5 mb-2">Still have questions?</h4>
                <p class="text-ss-muted mb-3">Our friendly support team is always ready to assist you.</p>
                <a href="<?php echo e(url('/contact.php')); ?>" class="btn btn-ss-primary px-4 py-2">
                    <i class="bi bi-chat-dots"></i> Contact Customer Support
                </a>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
