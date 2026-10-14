<?php
/**
 * ShopSphere - Authentication & User Account Service
 *
 * Handles customer, vendor, and administrator authentication, registration,
 * credential management, password reset lifecycles, and email verification.
 *
 * @package ShopSphere\Classes
 */

declare(strict_types=1);

require_once __DIR__ . '/Database.php';

class AuthService
{
    /**
     * Database connection instance.
     *
     * @var PDO
     */
    protected PDO $pdo;

    /**
     * Maximum failed login attempts allowed before lockout.
     */
    public const MAX_FAILED_ATTEMPTS = 5;

    /**
     * Lockout duration in minutes.
     */
    public const LOCKOUT_DURATION_MINUTES = 15;

    /**
     * Constructor accepts optional PDO instance, defaulting to Database singleton.
     *
     * @param PDO|null $pdo Optional PDO instance.
     */
    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance();
    }

    /**
     * Register a new user account (customer or seller).
     *
     * @param array<string, mixed> $data Registration form data.
     * @return array{success: bool, user_id?: int, errors?: array<string, string>} Result with ID or error list.
     */
    public function register(array $data): array
    {
        $errors = [];

        // Sanitize and extract basic fields
        $email     = trim((string) ($data['email'] ?? ''));
        $password  = (string) ($data['password'] ?? '');
        $firstName = trim((string) ($data['first_name'] ?? ''));
        $lastName  = trim((string) ($data['last_name'] ?? ''));
        $phone     = trim((string) ($data['phone'] ?? ''));
        $role      = (string) ($data['role'] ?? 'customer');

        // Validation
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'A valid email address is required.';
        } else {
            // Check email uniqueness
            $stmt = $this->pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
            $stmt->execute([':email' => $email]);
            if ($stmt->fetch()) {
                $errors['email'] = 'This email address is already registered.';
            }
        }

        if (strlen($password) < 8 || strlen($password) > 72) {
            $errors['password'] = 'Password must be between 8 and 72 characters long.';
        }

        if ($firstName === '') {
            $errors['first_name'] = 'First name is required.';
        }

        if ($lastName === '') {
            $errors['last_name'] = 'Last name is required.';
        }

        if (!in_array($role, ['customer', 'seller'], true)) {
            $role = 'customer';
        }

        // Additional validations for sellers
        $storeName       = '';
        $storeSlug       = '';
        $businessName    = '';
        $businessAddress = '';
        $taxId           = null;

        if ($role === 'seller') {
            $storeName       = trim((string) ($data['store_name'] ?? ''));
            $businessName    = trim((string) ($data['business_name'] ?? ''));
            $businessAddress = trim((string) ($data['business_address'] ?? ''));
            $taxId           = !empty($data['tax_id']) ? trim((string) $data['tax_id']) : null;

            if ($storeName === '') {
                $errors['store_name'] = 'Store name is required for vendor accounts.';
            }

            if ($businessName === '') {
                $errors['business_name'] = 'Business/Company name is required.';
            }

            if ($businessAddress === '') {
                $errors['business_address'] = 'Registered business address is required.';
            }

            // Generate store slug and ensure uniqueness
            $storeSlug = $this->generateSlug($storeName);
            if ($storeSlug === '') {
                $storeSlug = 'store-' . bin2hex(random_bytes(4));
            }

            $stmtSlug = $this->pdo->prepare('SELECT user_id FROM seller_profiles WHERE store_slug = :slug LIMIT 1');
            $stmtSlug->execute([':slug' => $storeSlug]);
            if ($stmtSlug->fetch()) {
                $storeSlug .= '-' . substr(bin2hex(random_bytes(4)), 0, 4);
            }
        }

        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }

        // Begin transaction for multi-table creation
        $this->pdo->beginTransaction();

        try {
            $passwordHash = password_hash($password, PASSWORD_BCRYPT);
            $userStatus   = 'active';

            $insertUserSql = 'INSERT INTO users (
                email, password_hash, first_name, last_name, phone, role, status, created_at, updated_at
            ) VALUES (
                :email, :password_hash, :first_name, :last_name, :phone, :role, :status, UTC_TIMESTAMP(), UTC_TIMESTAMP()
            )';

            $stmtUser = $this->pdo->prepare($insertUserSql);
            $stmtUser->execute([
                ':email'         => $email,
                ':password_hash' => $passwordHash,
                ':first_name'    => $firstName,
                ':last_name'     => $lastName,
                ':phone'         => $phone !== '' ? $phone : null,
                ':role'          => $role,
                ':status'        => $userStatus,
            ]);

            $userId = (int) $this->pdo->lastInsertId();

            if ($role === 'customer') {
                $insertCustomerSql = 'INSERT INTO customer_profiles (user_id, created_at, updated_at) VALUES (:user_id, UTC_TIMESTAMP(), UTC_TIMESTAMP())';
                $stmtCust = $this->pdo->prepare($insertCustomerSql);
                $stmtCust->execute([':user_id' => $userId]);
            } elseif ($role === 'seller') {
                $storeDescription = !empty($data['store_description']) ? trim((string) $data['store_description']) : null;
                $insertSellerSql = 'INSERT INTO seller_profiles (
                    user_id, store_name, store_slug, store_description, business_name, business_address, tax_id, status, rating_avg, total_sales, verified, created_at, updated_at
                ) VALUES (
                    :user_id, :store_name, :store_slug, :store_description, :business_name, :business_address, :tax_id, :status, 0.00, 0, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP()
                )';
                $stmtSeller = $this->pdo->prepare($insertSellerSql);
                $stmtSeller->execute([
                    ':user_id'           => $userId,
                    ':store_name'        => $storeName,
                    ':store_slug'        => $storeSlug,
                    ':store_description' => $storeDescription,
                    ':business_name'     => $businessName,
                    ':business_address'  => $businessAddress,
                    ':tax_id'            => $taxId,
                    ':status'            => 'pending', // Vendor requires admin approval
                ]);
            }

            $this->pdo->commit();

            return ['success' => true, 'user_id' => $userId];
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            error_log('[AuthService::register Error] ' . $e->getMessage());
            return [
                'success' => false,
                'errors'  => ['general' => 'Registration failed due to a system error. Please try again.']
            ];
        }
    }

    /**
     * Authenticate user credentials and return user profile array or false.
     *
     * @param string $email User email.
     * @param string $password User plaintext password.
     * @return array<string, mixed>|false User data on success, false on failure.
     */
    public function login(string $email, string $password): array|false
    {
        $email = trim($email);
        if ($email === '' || $password === '' || strlen($password) > 72) {
            return false;
        }

        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return false;
        }

        $userId = (int) $user['id'];

        // Check if user is suspended or inactive
        if (in_array($user['status'], ['suspended', 'banned'], true)) {
            return false;
        }

        // Check for active lockout
        if (!empty($user['locked_until'])) {
            $lockedUntilTime = strtotime($user['locked_until'] . ' UTC');
            if ($lockedUntilTime !== false && $lockedUntilTime > time()) {
                return false;
            }
        }

        // Verify password
        if (!password_verify($password, $user['password_hash'])) {
            $this->recordFailedLogin($userId);
            return false;
        }

        // Password valid - reset lockouts and failed attempts
        $this->resetFailedLogins($userId);

        // Update last login timestamp
        $updateStmt = $this->pdo->prepare('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = :id');
        $updateStmt->execute([':id' => $userId]);

        // Regenerate session ID if session is active to prevent session fixation
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        // Unset password hash for security
        unset($user['password_hash']);

        // Attach and validate seller details if role is seller
        if ($user['role'] === 'seller') {
            $sellerStmt = $this->pdo->prepare('SELECT * FROM seller_profiles WHERE user_id = :user_id LIMIT 1');
            $sellerStmt->execute([':user_id' => $userId]);
            $sellerProfile = $sellerStmt->fetch(PDO::FETCH_ASSOC) ?: null;

            // Reject login if seller profile is suspended or rejected
            if ($sellerProfile && in_array($sellerProfile['status'], ['suspended', 'rejected'], true)) {
                return false;
            }

            $user['seller_profile'] = $sellerProfile;
        }

        return $user;
    }

    /**
     * Retrieve user record by email address.
     *
     * @param string $email User email.
     * @return array<string, mixed>|null User row or null.
     */
    public function getUserByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => trim($email)]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        return $user !== false ? $user : null;
    }

    /**
     * Retrieve user record by ID.
     *
     * @param int $id User ID.
     * @return array<string, mixed>|null User row or null.
     */
    public function getUserById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        return $user !== false ? $user : null;
    }

    /**
     * Record a failed login attempt and apply lockout if threshold exceeded.
     *
     * @param int $userId Target user ID.
     * @return void
     */
    public function recordFailedLogin(int $userId): void
    {
        $lockoutMinutes = (int) self::LOCKOUT_DURATION_MINUTES;
        $maxAttempts = (int) self::MAX_FAILED_ATTEMPTS;

        $stmt = $this->pdo->prepare("UPDATE users SET 
            failed_login_attempts = failed_login_attempts + 1,
            locked_until = CASE 
                WHEN failed_login_attempts + 1 >= :max_attempts 
                THEN DATE_ADD(UTC_TIMESTAMP(), INTERVAL {$lockoutMinutes} MINUTE) 
                ELSE locked_until 
            END
            WHERE id = :id");
        $stmt->execute([
            ':max_attempts' => $maxAttempts,
            ':id'           => $userId,
        ]);
    }

    /**
     * Reset failed login counter and clear lockout for a user.
     *
     * @param int $userId Target user ID.
     * @return void
     */
    public function resetFailedLogins(int $userId): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE id = :id');
        $stmt->execute([':id' => $userId]);
    }

    /**
     * Generate a cryptographic password reset token valid for 1 hour.
     * Stores a SHA-256 hash in the database to prevent token leakage from database dumps.
     *
     * @param int $userId Target user ID.
     * @return string Crypto-safe plaintext reset token to send to user.
     */
    public function createPasswordResetToken(int $userId): string
    {
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);

        // Invalidate prior unused tokens
        $delStmt = $this->pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = :user_id AND used_at IS NULL');
        $delStmt->execute([':user_id' => $userId]);

        // Insert new token hash with 1 hour expiration
        $insertStmt = $this->pdo->prepare('INSERT INTO password_reset_tokens (
            user_id, token, expires_at, created_at
        ) VALUES (
            :user_id, :token, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR), UTC_TIMESTAMP()
        )');
        $insertStmt->execute([
            ':user_id' => $userId,
            ':token'   => $tokenHash,
        ]);

        return $token;
    }

    /**
     * Validate password reset token existence and expiry.
     * Supports both SHA-256 hashed tokens and legacy plaintext tokens.
     *
     * @param string $token Reset token string.
     * @return array<string, mixed>|false Token record array or false if invalid.
     */
    public function validateResetToken(string $token): array|false
    {
        $trimmed = trim($token);
        if ($trimmed === '') {
            return false;
        }

        $tokenHash = hash('sha256', $trimmed);

        $stmt = $this->pdo->prepare('SELECT * FROM password_reset_tokens 
            WHERE (token = :token_hash OR token = :plain_token) 
              AND used_at IS NULL 
              AND expires_at > UTC_TIMESTAMP() 
            LIMIT 1');
        $stmt->execute([
            ':token_hash'  => $tokenHash,
            ':plain_token' => $trimmed,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : false;
    }

    /**
     * Reset user password using a valid reset token.
     *
     * @param string $token Valid reset token.
     * @param string $newPassword New plaintext password.
     * @return bool True on success, false on failure.
     */
    public function resetPassword(string $token, string $newPassword): bool
    {
        if (strlen($newPassword) < 8 || strlen($newPassword) > 72) {
            return false;
        }

        $tokenData = $this->validateResetToken($token);
        if (!$tokenData) {
            return false;
        }

        $userId = (int) $tokenData['user_id'];
        $newHash = password_hash($newPassword, PASSWORD_BCRYPT);

        $this->pdo->beginTransaction();
        try {
            // Update password and reset login locks
            $userStmt = $this->pdo->prepare('UPDATE users SET 
                password_hash = :hash, 
                failed_login_attempts = 0, 
                locked_until = NULL, 
                updated_at = UTC_TIMESTAMP() 
                WHERE id = :id');
            $userStmt->execute([
                ':hash' => $newHash,
                ':id'   => $userId,
            ]);

            // Mark token as used
            $tokenStmt = $this->pdo->prepare('UPDATE password_reset_tokens SET used_at = UTC_TIMESTAMP() WHERE id = :id');
            $tokenStmt->execute([':id' => $tokenData['id']]);

            $this->pdo->commit();
            return true;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            error_log('[AuthService::resetPassword Error] ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Change password for an authenticated user with current password validation.
     *
     * @param int $userId Authenticated user ID.
     * @param string $currentPassword Current plaintext password.
     * @param string $newPassword New plaintext password.
     * @return array{success: bool, error?: string} Result state.
     */
    public function changePassword(int $userId, string $currentPassword, string $newPassword): array
    {
        if (strlen($newPassword) < 8 || strlen($newPassword) > 72) {
            return ['success' => false, 'error' => 'New password must be between 8 and 72 characters long.'];
        }

        $stmt = $this->pdo->prepare('SELECT password_hash FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $currentHash = $stmt->fetchColumn();

        if (!$currentHash || !password_verify($currentPassword, (string) $currentHash)) {
            return ['success' => false, 'error' => 'Current password is incorrect.'];
        }

        $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
        $updateStmt = $this->pdo->prepare('UPDATE users SET password_hash = :hash, updated_at = UTC_TIMESTAMP() WHERE id = :id');
        $updateStmt->execute([
            ':hash' => $newHash,
            ':id'   => $userId,
        ]);

        return ['success' => true];
    }

    /**
     * Create an email verification token valid for 24 hours.
     * Stores a SHA-256 hash in the database to prevent token leakage.
     *
     * @param int $userId Target user ID.
     * @return string Crypto-safe plaintext verification token.
     */
    public function createEmailVerificationToken(int $userId): string
    {
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);

        // Remove old tokens
        $delStmt = $this->pdo->prepare('DELETE FROM email_verification_tokens WHERE user_id = :user_id');
        $delStmt->execute([':user_id' => $userId]);

        $insertStmt = $this->pdo->prepare('INSERT INTO email_verification_tokens (
            user_id, token, expires_at, created_at
        ) VALUES (
            :user_id, :token, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 24 HOUR), UTC_TIMESTAMP()
        )');
        $insertStmt->execute([
            ':user_id' => $userId,
            ':token'   => $tokenHash,
        ]);

        return $token;
    }

    /**
     * Verify email address using verification token.
     *
     * @param string $token Verification token.
     * @return bool True on success, false if invalid/expired.
     */
    public function verifyEmail(string $token): bool
    {
        $trimmed = trim($token);
        if ($trimmed === '') {
            return false;
        }

        $tokenHash = hash('sha256', $trimmed);

        $stmt = $this->pdo->prepare('SELECT * FROM email_verification_tokens 
            WHERE (token = :token_hash OR token = :plain_token) 
              AND expires_at > UTC_TIMESTAMP() 
            LIMIT 1');
        $stmt->execute([
            ':token_hash'  => $tokenHash,
            ':plain_token' => $trimmed,
        ]);
        $tokenRow = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$tokenRow) {
            return false;
        }

        $userId = (int) $tokenRow['user_id'];

        $this->pdo->beginTransaction();
        try {
            $userStmt = $this->pdo->prepare("UPDATE users SET 
                email_verified_at = UTC_TIMESTAMP(), 
                status = CASE WHEN status = 'pending_verification' THEN 'active' ELSE status END,
                updated_at = UTC_TIMESTAMP() 
                WHERE id = :id");
            $userStmt->execute([':id' => $userId]);

            // Clean up used token
            $delStmt = $this->pdo->prepare('DELETE FROM email_verification_tokens WHERE id = :id');
            $delStmt->execute([':id' => $tokenRow['id']]);

            $this->pdo->commit();
            return true;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            error_log('[AuthService::verifyEmail Error] ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Helper to generate a URL-safe slug.
     *
     * @param string $text Text to slugify.
     * @return string Normalized slug.
     */
    protected function generateSlug(string $text): string
    {
        $slug = preg_replace('~[^\pL\d]+~u', '-', $text);
        $slug = iconv('utf-8', 'us-ascii//TRANSLIT', (string) $slug);
        $slug = preg_replace('~[^-\w]+~', '', (string) $slug);
        $slug = trim((string) $slug, '-');
        $slug = preg_replace('~-+~', '-', (string) $slug);
        return strtolower((string) $slug);
    }
}
