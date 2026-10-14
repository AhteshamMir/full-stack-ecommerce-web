<?php
/**
 * ShopSphere - Database Configuration & Connection
 *
 * Establishes and manages the PDO database connection as a singleton.
 * Loads environment variables from .env without external dependencies.
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

            // Skip comments and empty lines
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);

                // Strip surrounding single or double quotes
                if (
                    (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                    (str_starts_with($value, "'") && str_ends_with($value, "'"))
                ) {
                    $value = substr($value, 1, -1);
                }

                // Populate $_ENV, $_SERVER, and putenv if not already defined
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

// Load .env from project root
$envPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
shopsphere_load_env($envPath);

if (!function_exists('get_db_connection')) {
    /**
     * Get or initialize the singleton PDO database connection.
     *
     * @return PDO
     * @throws RuntimeException If connection fails and in non-production mode, or general 500 error in production.
     */
    function get_db_connection(): PDO
    {
        static $pdo = null;

        if ($pdo instanceof PDO) {
            return $pdo;
        }

        $host    = (string) shopsphere_env('DB_HOST', 'localhost');
        $port    = (string) shopsphere_env('DB_PORT', '3306');
        $dbName  = (string) shopsphere_env('DB_NAME', 'shopsphere');
        $user    = (string) shopsphere_env('DB_USER', 'shopsphere_user');
        $pass    = (string) shopsphere_env('DB_PASS', '');
        $charset = 'utf8mb4';

        $dsn = "mysql:host={$host};port={$port};dbname={$dbName};charset={$charset}";

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset} COLLATE {$charset}_unicode_ci",
        ];

        try {
            $pdo = new PDO($dsn, $user, $pass, $options);
            return $pdo;
        } catch (PDOException $e) {
            // Log error message securely on the server
            error_log('[ShopSphere DB Error] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());

            $isDev = (shopsphere_env('APP_ENV') === 'development') || (bool) shopsphere_env('APP_DEBUG', false);

            if ($isDev) {
                // In development, display safe escaped details for debugging
                $escapedMsg = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
                http_response_code(500);
                die("<div style='font-family:sans-serif;padding:20px;background:#f8d7da;color:#721c24;border:1px solid #f5c6cb;border-radius:5px;'>
                    <h3 style='margin-top:0;'>Database Connection Error (Development Mode)</h3>
                    <p><strong>Message:</strong> {$escapedMsg}</p>
                    <p><small>Please verify your database credentials and ensure MySQL is running.</small></p>
                </div>");
            } else {
                // In production, suppress details and output generic user-friendly message
                http_response_code(500);
                die("<div style='font-family:sans-serif;padding:20px;text-align:center;'>
                    <h2>500 Internal Server Error</h2>
                    <p>We are experiencing technical difficulties connecting to our services. Please try again later.</p>
                </div>");
            }
        }
    }
}

// Return the singleton connection instance when file is included/required
return get_db_connection();
