<?php
/**
 * ShopSphere - 500 Internal Server Error Page
 */
http_response_code(500);
$page_title = 'Internal Server Error — 500';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container py-5 text-center">
    <div class="row justify-content-center">
        <div class="col-md-7 col-lg-6">
            <div class="py-5">
                <div class="display-1 fw-bold text-ss-danger mb-3" style="font-size: 6rem; letter-spacing: -2px;">500</div>
                <h1 class="h3 fw-bold mb-3 text-ss-primary">Server Error Encountered</h1>
                <p class="text-ss-muted mb-4 lead" style="font-size: 1.05rem;">
                    Something went wrong on our end. Our technical operations team has been logged of this event and is actively addressing it.
                </p>
                <div class="d-flex justify-content-center gap-3">
                    <a href="<?php echo e(url('/')); ?>" class="btn btn-ss-primary px-4 py-2">
                        <i class="bi bi-house me-1"></i> Back to Home
                    </a>
                    <a href="<?php echo e(url('/contact.php')); ?>" class="btn btn-ss-outline px-4 py-2">
                        <i class="bi bi-envelope me-1"></i> Report an Issue
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
