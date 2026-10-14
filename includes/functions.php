<?php
/**
 * ShopSphere - Global Core Helper Functions
 *
 * Provides essential utility functions for sanitization, URL building, formatting,
 * file handling, pagination, setting retrieval, and response handling.
 *
 * @package ShopSphere\Includes
 */

declare(strict_types=1);

// Ensure core configurations are loaded
require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/config/database.php';

if (!function_exists('e')) {
    /**
     * Escape HTML special characters for safe output.
     *
     * @param string|null $string The raw input string.
     * @return string The sanitized, HTML-escaped string.
     */
    function e(?string $string): string
    {
        return htmlspecialchars((string) ($string ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('redirect')) {
    /**
     * Perform a secure HTTP redirect and terminate script execution.
     * Guards against HTTP response splitting / CRLF injection and unvalidated open redirects.
     *
     * @param string $url Target redirect URL.
     * @param int $statusCode HTTP status code (default: 302).
     * @return void
     */
    function redirect(string $url, int $statusCode = 302): void
    {
        // Strip carriage returns and newlines to prevent CRLF injection
        $url = str_replace(["\r", "\n", "\0"], '', $url);

        // Disallow dangerous URI schemes like javascript:, vbscript:, data:
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if ($scheme !== null && !in_array(strtolower($scheme), ['http', 'https'], true)) {
            $url = defined('APP_URL') ? APP_URL : '/';
        }

        // Protocol-relative URLs like "//attacker.com"
        if (str_starts_with($url, '//')) {
            $url = '/' . ltrim($url, '/');
        }

        // Validate destination host if absolute URL
        $host = parse_url($url, PHP_URL_HOST);
        if ($host !== null && defined('APP_URL')) {
            $appHost = parse_url(APP_URL, PHP_URL_HOST);
            if ($appHost !== null && strcasecmp($host, $appHost) !== 0) {
                // External redirect target detected, fallback safely to base APP_URL
                $url = APP_URL;
            }
        }

        header('Location: ' . $url, true, $statusCode);
        exit;
    }
}

if (!function_exists('url')) {
    /**
     * Generate an absolute application URL from a given relative path.
     *
     * @param string $path The relative path.
     * @return string Fully qualified application URL.
     */
    function url(string $path = ''): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        $base = defined('APP_URL') ? rtrim(APP_URL, '/') : '';
        $path = ltrim($path, '/');

        return $path === '' ? $base : $base . '/' . $path;
    }
}

if (!function_exists('asset')) {
    /**
     * Generate an absolute URL for a static asset or uploaded media.
     *
     * @param string $path Asset relative path.
     * @return string Fully qualified asset URL.
     */
    function asset(string $path = ''): string
    {
        $path = trim($path);
        if ($path === '') {
            return url('assets/images/misc/placeholder.svg');
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        $path = ltrim($path, '/');

        // Uploads reside directly under application root /uploads/
        if (str_starts_with($path, 'uploads/')) {
            return url($path);
        }

        if (!str_starts_with($path, 'assets/')) {
            $path = 'assets/' . $path;
        }

        return url($path);
    }
}

if (!function_exists('format_price')) {
    /**
     * Format a monetary amount with currency symbol and Pakistani Rupee formatting.
     *
     * @param float|int|string|null $amount Monetary value.
     * @return string Formatted price string (e.g. "Rs. 1,250.00").
     */
    function format_price(float|int|string|null $amount): string
    {
        $num = (float) ($amount ?? 0.0);
        $symbol = defined('CURRENCY_SYMBOL') ? CURRENCY_SYMBOL : 'Rs.';

        return $symbol . ' ' . number_format($num, 2, '.', ',');
    }
}

if (!function_exists('format_date')) {
    /**
     * Format a datetime string, timestamp, or DateTime object into a standard display string.
     *
     * @param string|int|DateTimeInterface|null $date Input datetime.
     * @param string $format Target date format.
     * @return string Formatted date string, or empty string on failure.
     */
    function format_date(string|int|DateTimeInterface|null $date, string $format = 'M j, Y h:i A'): string
    {
        if (empty($date)) {
            return '';
        }

        try {
            if ($date instanceof DateTimeInterface) {
                return $date->format($format);
            }

            if (is_numeric($date)) {
                $dt = new DateTime();
                $dt->setTimestamp((int) $date);
                return $dt->format($format);
            }

            $dt = new DateTime((string) $date);
            return $dt->format($format);
        } catch (Exception $e) {
            return (string) $date;
        }
    }
}

if (!function_exists('generate_slug')) {
    /**
     * Generate a URL-friendly slug from an input string.
     *
     * @param string $string Raw title or text.
     * @return string Lowercase hyphenated slug.
     */
    function generate_slug(string $string): string
    {
        // Replace non-letter or non-digits with hyphens
        $slug = preg_replace('~[^\pL\d]+~u', '-', $string);

        // Transliterate if iconv is available
        if (function_exists('iconv')) {
            $translit = iconv('utf-8', 'us-ascii//TRANSLIT', (string) $slug);
            if ($translit !== false) {
                $slug = $translit;
            }
        }

        // Remove unwanted characters
        $slug = preg_replace('~[^-\w]+~', '', (string) $slug);

        // Trim hyphens
        $slug = trim((string) $slug, '-');

        // Remove duplicate hyphens
        $slug = preg_replace('~-+~', '-', (string) $slug);

        // Lowercase
        return strtolower((string) $slug);
    }
}

if (!function_exists('generate_order_number')) {
    /**
     * Generate a unique ShopSphere order number like SS-20261013-XXXX.
     *
     * @return string Unique order number.
     */
    function generate_order_number(): string
    {
        $datePart = date('Ymd');
        $randomPart = strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));

        return sprintf('SS-%s-%s', $datePart, $randomPart);
    }
}

if (!function_exists('generate_random_token')) {
    /**
     * Generate a cryptographically secure random hexadecimal token.
     *
     * @param int $length Total characters of the token string.
     * @return string Hexadecimal token string.
     */
    function generate_random_token(int $length = 32): string
    {
        $bytes = (int) ceil(max(1, $length) / 2);
        return substr(bin2hex(random_bytes($bytes)), 0, $length);
    }
}

if (!function_exists('flash')) {
    /**
     * Store a flash message in the session for one-time display.
     *
     * @param string $key Flash message identifier (e.g. 'success', 'error', 'info').
     * @param mixed $value Message content or payload.
     * @return void
     */
    function flash(string $key, mixed $value): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $_SESSION['_flash'][$key] = $value;
    }
}

if (!function_exists('get_flash')) {
    /**
     * Retrieve and immediately delete a flash message from the session.
     *
     * @param string $key Flash message identifier.
     * @param mixed $default Fallback value if key is not found.
     * @return mixed The flash message value or default.
     */
    function get_flash(string $key, mixed $default = null): mixed
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (isset($_SESSION['_flash'][$key])) {
            $value = $_SESSION['_flash'][$key];
            unset($_SESSION['_flash'][$key]);
            return $value;
        }

        return $default;
    }
}

if (!function_exists('old')) {
    /**
     * Retrieve previous form input value from session or request parameters.
     *
     * @param string $key Input field name.
     * @param mixed $default Fallback value if field is absent.
     * @return mixed The previous input value.
     */
    function old(string $key, mixed $default = ''): mixed
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $val = null;
        if (isset($_SESSION['_old_input'][$key])) {
            $val = $_SESSION['_old_input'][$key];
        } elseif (isset($_POST[$key])) {
            $val = $_POST[$key];
        } elseif (isset($_GET[$key])) {
            $val = $_GET[$key];
        } else {
            return $default;
        }

        if (is_string($val)) {
            return str_replace("\0", '', $val);
        }

        return $val;
    }
}

if (!function_exists('truncate')) {
    /**
     * Truncate a string to a given length without breaking multibyte characters.
     *
     * @param string $string Raw text.
     * @param int $length Maximum character length.
     * @param string $append Ellipsis string to append if truncated.
     * @return string Truncated text.
     */
    function truncate(string $string, int $length = 100, string $append = '...'): string
    {
        if (mb_strlen($string, 'UTF-8') <= $length) {
            return $string;
        }

        return mb_substr($string, 0, $length, 'UTF-8') . $append;
    }
}

if (!function_exists('time_ago')) {
    /**
     * Convert a datetime string or timestamp into human-readable relative time (e.g. "5 minutes ago").
     *
     * @param string|int|DateTimeInterface $datetime Datetime input.
     * @return string Human-friendly relative time string.
     */
    function time_ago(string|int|DateTimeInterface $datetime): string
    {
        try {
            if ($datetime instanceof DateTimeInterface) {
                $timestamp = $datetime->getTimestamp();
            } elseif (is_numeric($datetime)) {
                $timestamp = (int) $datetime;
            } else {
                $timestamp = (new DateTime((string) $datetime))->getTimestamp();
            }
        } catch (Exception $e) {
            return (string) $datetime;
        }

        $difference = time() - $timestamp;

        if ($difference < 0) {
            return 'just now';
        }

        $periods = [
            31536000 => ['year', 'years'],
            2592000  => ['month', 'months'],
            604800   => ['week', 'weeks'],
            86400    => ['day', 'days'],
            3600     => ['hour', 'hours'],
            60       => ['minute', 'minutes'],
            1        => ['second', 'seconds'],
        ];

        foreach ($periods as $seconds => [$singular, $plural]) {
            $count = (int) floor($difference / $seconds);
            if ($count >= 1) {
                return $count === 1 ? "1 {$singular} ago" : "{$count} {$plural} ago";
            }
        }

        return 'just now';
    }
}

if (!function_exists('get_setting')) {
    /**
     * Retrieve a website configuration setting from the database with in-memory caching.
     *
     * @param string $key Setting identifier key.
     * @param mixed $default Default value if setting not present in database.
     * @return mixed Setting value or default.
     */
    function get_setting(string $key, mixed $default = null): mixed
    {
        static $settingsCache = [];

        if (array_key_exists($key, $settingsCache)) {
            return $settingsCache[$key];
        }

        try {
            $pdo = get_db();
            $tables = ['website_settings', 'settings'];
            foreach ($tables as $table) {
                try {
                    $stmt = $pdo->prepare("
                        SELECT setting_value FROM {$table} WHERE setting_key = :key1 
                        UNION 
                        SELECT value FROM {$table} WHERE `key` = :key2 
                        LIMIT 1
                    ");
                    $stmt->execute(['key1' => $key, 'key2' => $key]);
                    $value = $stmt->fetchColumn();

                    if ($value !== false && $value !== null) {
                        $settingsCache[$key] = $value;
                        return $value;
                    }
                } catch (PDOException) {
                    // Try next table candidate
                    continue;
                }
            }
        } catch (Throwable $e) {
            error_log('[ShopSphere Settings Error] ' . $e->getMessage());
        }

        $settingsCache[$key] = $default;
        return $default;
    }
}

if (!function_exists('get_settings_batch')) {
    /**
     * Retrieve multiple website settings at once.
     *
     * @param array $keys List of setting keys to retrieve.
     * @return array Associative array of key => value.
     */
    function get_settings_batch(array $keys): array
    {
        $result = [];
        if (empty($keys)) {
            return $result;
        }

        // Initialize defaults
        foreach ($keys as $k) {
            $result[$k] = null;
        }

        try {
            $pdo = get_db();
            $placeholders = implode(',', array_fill(0, count($keys), '?'));
            $tables = ['website_settings', 'settings'];

            foreach ($tables as $table) {
                try {
                    $query = "SELECT setting_key AS k, setting_value AS v FROM {$table} WHERE setting_key IN ({$placeholders})
                              UNION
                              SELECT `key` AS k, value AS v FROM {$table} WHERE `key` IN ({$placeholders})";

                    $stmt = $pdo->prepare($query);
                    $params = array_merge($keys, $keys);
                    $stmt->execute($params);

                    while ($row = $stmt->fetch()) {
                        if ($row['v'] !== null) {
                            $result[$row['k']] = $row['v'];
                        }
                    }
                    // If we found any results, break
                    if (array_filter($result, fn($v) => $v !== null)) {
                        break;
                    }
                } catch (PDOException) {
                    continue;
                }
            }
        } catch (Throwable $e) {
            error_log('[ShopSphere Settings Batch Error] ' . $e->getMessage());
        }

        return $result;
    }
}

if (!function_exists('is_ajax')) {
    /**
     * Determine whether the incoming HTTP request is an AJAX request or expects JSON.
     *
     * @return bool True if AJAX or JSON request, false otherwise.
     */
    function is_ajax(): bool
    {
        return (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
            || (isset($_SERVER['HTTP_ACCEPT']) && str_contains(strtolower($_SERVER['HTTP_ACCEPT']), 'application/json'));
    }
}

if (!function_exists('json_response')) {
    /**
     * Send a standardized JSON HTTP response and terminate execution.
     *
     * @param mixed $data Payload to encode as JSON.
     * @param int $code HTTP status code.
     * @return void
     */
    function json_response(mixed $data, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('upload_image')) {
    /**
     * Validate and securely upload an image file.
     *
     * @param array $file $_FILES['input_name'] array.
     * @param string $directory Target subfolder relative to APP_ROOT (e.g. 'uploads/products').
     * @param int|null $maxSize Maximum allowed file size in bytes (defaults to UPLOAD_MAX_SIZE).
     * @return array Result array with keys: success (bool), path (string|null), filename (string|null), error (string|null).
     */
    function upload_image(array $file, string $directory = 'uploads', ?int $maxSize = null): array
    {
        $maxSize = $maxSize ?? (defined('UPLOAD_MAX_SIZE') ? UPLOAD_MAX_SIZE : 5242880);

        // Check upload error
        if (!isset($file['error']) || is_array($file['error'])) {
            return ['success' => false, 'path' => null, 'filename' => null, 'error' => 'Invalid file parameter.'];
        }

        switch ($file['error']) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return ['success' => false, 'path' => null, 'filename' => null, 'error' => 'File exceeds maximum upload size limit.'];
            case UPLOAD_ERR_NO_FILE:
                return ['success' => false, 'path' => null, 'filename' => null, 'error' => 'No file was uploaded.'];
            default:
                return ['success' => false, 'path' => null, 'filename' => null, 'error' => 'An error occurred during file upload.'];
        }

        // Validate file size
        if ($file['size'] > $maxSize) {
            $maxMb = round($maxSize / (1024 * 1024), 2);
            return ['success' => false, 'path' => null, 'filename' => null, 'error' => "File size cannot exceed {$maxMb} MB."];
        }

        // Validate MIME type
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);

        $allowedMimes = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
        ];

        if (!array_key_exists($mime, $allowedMimes)) {
            return ['success' => false, 'path' => null, 'filename' => null, 'error' => 'Only JPG, PNG, WEBP, and GIF images are permitted.'];
        }

        // Verify valid image dimensions
        $imageSize = @getimagesize($file['tmp_name']);
        if ($imageSize === false) {
            return ['success' => false, 'path' => null, 'filename' => null, 'error' => 'Uploaded file is not a valid image.'];
        }

        // Target directory setup
        $root = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__);
        $cleanDir = trim($directory, '/\\');
        $targetDir = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $cleanDir);

        if (!is_dir($targetDir)) {
            if (!mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
                return ['success' => false, 'path' => null, 'filename' => null, 'error' => 'Failed to create upload destination directory.'];
            }
        }

        // Generate cryptographically unique safe filename
        $extension = $allowedMimes[$mime];
        $filename = bin2hex(random_bytes(16)) . '.' . $extension;
        $targetPath = $targetDir . DIRECTORY_SEPARATOR . $filename;

        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            return ['success' => false, 'path' => null, 'filename' => null, 'error' => 'Failed to move uploaded file to destination.'];
        }

        // Return relative path for web and database storage
        $relativePath = $cleanDir . '/' . $filename;

        return [
            'success'  => true,
            'path'     => $relativePath,
            'filename' => $filename,
            'error'    => null,
        ];
    }
}

if (!function_exists('delete_file')) {
    /**
     * Safely delete a file from the filesystem preventing directory traversal.
     *
     * @param string $path File path relative to APP_ROOT or absolute path within APP_ROOT.
     * @return bool True if file was deleted or did not exist, false if deletion failed or access denied.
     */
    function delete_file(string $path): bool
    {
        $root = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__);
        $realRoot = realpath($root);

        if ($realRoot === false) {
            return false;
        }

        // Check if path is already absolute or relative
        if (!str_starts_with($path, $realRoot)) {
            $cleanPath = ltrim($path, '/\\');
            $fullPath = $realRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $cleanPath);
        } else {
            $fullPath = $path;
        }

        $realTarget = realpath($fullPath);

        // If file doesn't exist, consider it cleaned up
        if ($realTarget === false || !file_exists($realTarget)) {
            return true;
        }

        // Prevent traversal attack outside of APP_ROOT
        if (!str_starts_with($realTarget, $realRoot)) {
            error_log('[ShopSphere Security] Attempted file deletion outside APP_ROOT: ' . $fullPath);
            return false;
        }

        if (is_file($realTarget)) {
            return unlink($realTarget);
        }

        return false;
    }
}

if (!function_exists('paginate')) {
    /**
     * Calculate pagination metrics and metadata.
     *
     * @param int $total Total number of items.
     * @param int $perPage Number of items to display per page.
     * @param int $currentPage Requested page number.
     * @return array Pagination metadata.
     */
    function paginate(int $total, int $perPage = 15, int $currentPage = 1): array
    {
        $perPage = max(1, $perPage);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $currentPage = max(1, min($currentPage, $totalPages));
        $offset = ($currentPage - 1) * $perPage;

        $from = $total > 0 ? $offset + 1 : 0;
        $to = min($offset + $perPage, $total);

        return [
            'total'        => $total,
            'per_page'     => $perPage,
            'current_page' => $currentPage,
            'total_pages'  => $totalPages,
            'offset'       => $offset,
            'has_prev'     => $currentPage > 1,
            'has_next'     => $currentPage < $totalPages,
            'prev_page'    => $currentPage > 1 ? $currentPage - 1 : null,
            'next_page'    => $currentPage < $totalPages ? $currentPage + 1 : null,
            'from'         => $from,
            'to'           => $to,
        ];
    }
}

if (!function_exists('get_db')) {
    /**
     * Retrieve the database connection singleton.
     *
     * @return PDO The active PDO database connection instance.
     */
    function get_db(): PDO
    {
        return get_db_connection();
    }
}
