<?php
/**
 * ShopSphere - About Page
 * Displays company information from content_pages table
 */

$page_title = 'About Us';
require_once __DIR__ . '/includes/header.php';

// Get about page content from database
$about_content = '';
$about_title = 'About ShopSphere';
try {
    $db = get_db();
    $stmt = $db->prepare("SELECT title, content FROM content_pages WHERE slug = 'about' AND is_active = 1 LIMIT 1");
    $stmt->execute();
    $page = $stmt->fetch();
    if ($page) {
        $about_title = $page['title'];
        $about_content = $page['content'];
    }
} catch (Exception $e) {
    // Use default content
}

if (empty($about_content)) {
    $about_content = '<p>ShopSphere is a premier multi-vendor e-commerce marketplace connecting buyers with trusted sellers across Pakistan. Our platform offers a wide range of products from electronics and fashion to home essentials and beauty products.</p>
    <h3>Our Mission</h3>
    <p>To create a trustworthy, accessible, and vibrant online marketplace where sellers can grow their businesses and customers can find quality products at competitive prices.</p>
    <h3>Why Choose ShopSphere?</h3>
    <ul>
        <li><strong>Verified Sellers:</strong> Every seller on our platform is vetted and approved to ensure quality and reliability.</li>
        <li><strong>Competitive Prices:</strong> Multiple sellers offering the same products means you always get the best deal.</li>
        <li><strong>Secure Payments:</strong> Your transactions are protected with industry-standard security measures.</li>
        <li><strong>Easy Returns:</strong> Not satisfied? Our hassle-free return policy has you covered.</li>
        <li><strong>24/7 Support:</strong> Our customer service team is always ready to help.</li>
    </ul>
    <h3>Our Story</h3>
    <p>Founded with the vision of democratizing e-commerce in Pakistan, ShopSphere brings together thousands of sellers and millions of products on a single platform. We believe that every business, big or small, deserves the opportunity to reach customers nationwide.</p>
    <h3>Join Our Community</h3>
    <p>Whether you are a customer looking for great deals or a seller wanting to grow your business, ShopSphere welcomes you. Join our growing community today and experience the future of online shopping.</p>';
}
?>

<div class="container py-4">
    <nav class="ss-breadcrumb">
        <ol>
            <li><a href="<?php echo e(url('/')); ?>"><i class="bi bi-house"></i> Home</a></li>
            <li><?php echo e($about_title); ?></li>
        </ol>
    </nav>

    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="ss-card">
                <div class="ss-card-body" style="padding: 2.5rem;">
                    <h1 class="mb-4" style="font-size: 2rem;"><?php echo e($about_title); ?></h1>
                    <div class="ss-page-content" style="font-size: 0.9375rem; line-height: 1.8;">
                        <?php echo $about_content; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
