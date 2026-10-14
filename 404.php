<?php
/**
 * ShopSphere - 404 Not Found Page
 */
http_response_code(404);
$page_title = 'Page Not Found — 404';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container py-5 text-center">
    <div class="row justify-content-center">
        <div class="col-md-7 col-lg-6">
            <div class="py-5">
                <div class="display-1 fw-bold text-ss-accent mb-3" style="font-size: 6rem; letter-spacing: -2px;">404</div>
                <h1 class="h3 fw-bold mb-3 text-ss-primary">Oops! Page Not Found</h1>
                <p class="text-ss-muted mb-4 lead" style="font-size: 1.05rem;">
                    The page you are looking for might have been removed, had its name changed, or is temporarily unavailable.
                </p>
                <div class="d-flex justify-content-center gap-3">
                    <a href="<?php echo e(url('/')); ?>" class="btn btn-ss-primary px-4 py-2">
                        <i class="bi bi-house me-1"></i> Return to Homepage
                    </a>
                    <a href="<?php echo e(url('/shop.php')); ?>" class="btn btn-ss-outline px-4 py-2">
                        <i class="bi bi-grid me-1"></i> Browse Products
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
