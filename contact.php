<?php
/**
 * ShopSphere - Contact Us Page
 */
$page_title = 'Contact Us';
require_once __DIR__ . '/includes/header.php';

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');

    // Honeypot field check - bots typically fill hidden fields
    if (!empty($_POST['website'])) {
        // Silent success to confuse bots
        $success = true;
    } else {
        if (empty($name)) $errors[] = 'Please enter your name.';
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';
        if (empty($subject)) $errors[] = 'Please enter a subject.';
        if (empty($message)) $errors[] = 'Please enter your message.';

        // Message length validation to prevent spam
        if (strlen($message) < 10) $errors[] = 'Message is too short.';
        if (strlen($message) > 2000) $errors[] = 'Message is too long (max 2000 characters).';

        // Rate limiting: check if same IP submitted too recently
        if (empty($errors)) {
            try {
                $db = get_db();
                $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
                $stmt = $db->prepare("SELECT COUNT(*) FROM contact_messages WHERE ip_address = ? AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)");
                $stmt->execute([$ip]);
                $recentCount = (int)$stmt->fetchColumn();

                if ($recentCount >= 3) {
                    $errors[] = 'Too many messages submitted recently. Please wait a few minutes before trying again.';
                } else {
                    $stmt = $db->prepare("INSERT INTO contact_messages (name, email, subject, message, status, ip_address, created_at) VALUES (?, ?, ?, ?, 'new', ?, NOW())");
                    $stmt->execute([$name, $email, $subject, $message, $ip]);
                    $success = true;
                }
            } catch (Exception $e) {
                $errors[] = 'Could not save your message. Please try again later.';
            }
        }
    }
}

$site_email = get_setting('contact_email', 'support@shopsphere.local');
$site_phone = get_setting('contact_phone', '+92 300 1234567');
$site_address = get_setting('contact_address', 'Suite 401, Tech Tower, Blue Area, Islamabad, Pakistan');
?>

<div class="container py-4">
    <nav class="ss-breadcrumb">
        <ol>
            <li><a href="<?php echo e(url('/')); ?>"><i class="bi bi-house"></i> Home</a></li>
            <li>Contact Us</li>
        </ol>
    </nav>

    <div class="row g-4 justify-content-center">
        <div class="col-lg-5">
            <div class="ss-card h-100">
                <div class="ss-card-body p-4">
                    <h2 class="h4 mb-3">Get in Touch</h2>
                    <p class="text-ss-muted mb-4">Have questions about an order, seller registration, or platform policies? Reach out to us anytime.</p>

                    <div class="d-flex align-items-start gap-3 mb-3">
                        <div class="ss-stat-icon blue flex-shrink-0"><i class="bi bi-geo-alt"></i></div>
                        <div>
                            <h6 class="mb-1">Headquarters</h6>
                            <p class="text-ss-muted small mb-0"><?php echo e($site_address); ?></p>
                        </div>
                    </div>

                    <div class="d-flex align-items-start gap-3 mb-3">
                        <div class="ss-stat-icon green flex-shrink-0"><i class="bi bi-envelope"></i></div>
                        <div>
                            <h6 class="mb-1">Email Support</h6>
                            <p class="text-ss-muted small mb-0"><a href="mailto:<?php echo e($site_email); ?>"><?php echo e($site_email); ?></a></p>
                        </div>
                    </div>

                    <div class="d-flex align-items-start gap-3 mb-3">
                        <div class="ss-stat-icon yellow flex-shrink-0"><i class="bi bi-telephone"></i></div>
                        <div>
                            <h6 class="mb-1">Phone Line</h6>
                            <p class="text-ss-muted small mb-0"><?php echo e($site_phone); ?></p>
                        </div>
                    </div>

                    <div class="d-flex align-items-start gap-3">
                        <div class="ss-stat-icon red flex-shrink-0"><i class="bi bi-clock"></i></div>
                        <div>
                            <h6 class="mb-1">Working Hours</h6>
                            <p class="text-ss-muted small mb-0">Monday – Saturday: 9:00 AM – 7:00 PM (PKT)</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="ss-card">
                <div class="ss-card-body p-4">
                    <h2 class="h4 mb-3">Send Us a Message</h2>
                    <?php if ($success): ?>
                        <div class="ss-alert ss-alert-success">
                            <i class="bi bi-check-circle"></i>
                            <div>Thank you! Your message has been sent successfully. Our support team will review and contact you shortly.</div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($errors)): ?>
                        <div class="ss-alert ss-alert-danger">
                            <i class="bi bi-exclamation-triangle"></i>
                            <div>
                                <ul class="mb-0 ps-3">
                                    <?php foreach ($errors as $err): ?>
                                        <li><?php echo e($err); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    <?php endif; ?>

                    <form action="<?php echo e(url('/contact.php')); ?>" method="POST" class="ss-prevent-double-submit">
                        <?php echo csrf_field(); ?>
                        <!-- Honeypot field for spam protection -->
                        <input type="text" name="website" style="display:none;" tabindex="-1" autocomplete="off">
                        <div class="row g-3">
                            <div class="col-md-6 ss-form-group">
                                <label for="name">Your Name <span class="required">*</span></label>
                                <input type="text" id="name" name="name" class="ss-form-control" required value="<?php echo e($_POST['name'] ?? ''); ?>" placeholder="John Doe">
                            </div>
                            <div class="col-md-6 ss-form-group">
                                <label for="email">Your Email <span class="required">*</span></label>
                                <input type="email" id="email" name="email" class="ss-form-control" required value="<?php echo e($_POST['email'] ?? ''); ?>" placeholder="john@example.com">
                            </div>
                            <div class="col-12 ss-form-group">
                                <label for="subject">Subject <span class="required">*</span></label>
                                <input type="text" id="subject" name="subject" class="ss-form-control" required value="<?php echo e($_POST['subject'] ?? ''); ?>" placeholder="Order issue / Seller inquiry">
                            </div>
                            <div class="col-12 ss-form-group">
                                <label for="message">Message <span class="required">*</span></label>
                                <textarea id="message" name="message" rows="5" class="ss-form-control" required placeholder="Type your detailed message here..."><?php echo e($_POST['message'] ?? ''); ?></textarea>
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-ss-primary px-4 py-2">
                                    <i class="bi bi-send"></i> Send Message
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
