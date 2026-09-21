<?php
/**
 * Database Helper - Singleton PDO Instance
 */

require_once __DIR__ . '/config.php';

class Database {
    private static ?PDO $instance = null;

    private function __construct() {}

    public static function getConnection(): PDO {
        if (self::$instance === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                DB_HOST,
                DB_PORT,
                DB_NAME,
                DB_CHARSET
            );

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 5,
            ];

            try {
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);
                // Ensure MySQL uses UTC for session timestamps
                self::$instance->exec("SET time_zone = '+00:00'");
            } catch (PDOException $e) {
                error_log("Database connection failure: " . $e->getMessage());
                if (APP_ENV === 'development') {
                    die("Database connection failed: " . htmlspecialchars($e->getMessage()));
                } else {
                    die("A database error occurred. Please contact the administrator.");
                }
            }
        }

        return self::$instance;
    }
}

/**
 * Global helper for getting DB connection
 */
function get_db(): PDO {
    return Database::getConnection();
}
