<?php
/**
 * ShopSphere - Main Application Configuration
 *
 * Loads environment variables, defines core application constants,
 * configures PHP error reporting based on environment, and sets application timezone.
 *
 * @package ShopSphere\Config
 */

declare(strict_types=1);

// Ensure env loader helper is available
if (!function_exists('shopsphere_load_env')) {
    /**
     * Parse and load environment variables from a .env file.
     *
     * @param string $path Path to the .env file.
     * @return void
     */
    function shopsphere_load_env(string $path): void
    {
        if (!file_exists($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);

                if (
                    (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                    (str_starts_with($value, "'") && str_ends_with($value, "'"))
                ) {
                    $value = substr($value, 1, -1);
                }

                if (!array_key_exists($key, $_ENV)) {
                    $_ENV[$key] = $value;
                    $_SERVER[$key] = $value;
                    putenv("{$key}={$value}");
                }
            }
        }
    }
}

if (!function_exists('shopsphere_env')) {
    /**
     * Retrieve an environment variable with optional default value and type casting.
     *
     * @param string $key Variable name.
     * @param mixed $default Default value if variable is not set.
     * @return mixed
     */
    function shopsphere_env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false || $value === null) {
            return $default;
        }

        $lower = strtolower((string) $value);
        return match ($lower) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => $value,
        };
    }
}

// Load .env from root
$envPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
shopsphere_load_env($envPath);

// Define Application Constants
if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

if (!defined('APP_URL')) {
    define('APP_URL', rtrim((string) shopsphere_env('APP_URL', 'http://localhost/shopsphere'), '/'));
}

if (!defined('APP_NAME')) {
    define('APP_NAME', (string) shopsphere_env('APP_NAME', 'ShopSphere'));
}

if (!defined('APP_ENV')) {
    define('APP_ENV', (string) shopsphere_env('APP_ENV', 'development'));
}

if (!defined('APP_DEBUG')) {
    define('APP_DEBUG', (bool) shopsphere_env('APP_DEBUG', true));
}

if (!defined('CURRENCY')) {
    define('CURRENCY', (string) shopsphere_env('CURRENCY', 'PKR'));
}

if (!defined('CURRENCY_SYMBOL')) {
    define('CURRENCY_SYMBOL', (string) shopsphere_env('CURRENCY_SYMBOL', 'Rs.'));
}

if (!defined('DEFAULT_COMMISSION_RATE')) {
    define('DEFAULT_COMMISSION_RATE', (float) shopsphere_env('DEFAULT_COMMISSION_RATE', 10.00));
}

if (!defined('UPLOAD_MAX_SIZE')) {
    define('UPLOAD_MAX_SIZE', (int) shopsphere_env('UPLOAD_MAX_SIZE', 5242880));
}

// Set error reporting based on APP_ENV and APP_DEBUG
if (APP_ENV === 'development' || APP_DEBUG === true) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
}

// Set application timezone
$timezone = (string) shopsphere_env('APP_TIMEZONE', 'UTC');
if (!empty($timezone)) {
    date_default_timezone_set($timezone);
} else {
    date_default_timezone_set('UTC');
}
