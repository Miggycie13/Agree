<?php
/**
 * AGREE database settings for InfinityFree.
 * Host, port, database, and user come from the MySQL Databases panel.
 */
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__)) {
    http_response_code(403);
    exit;
}

define('DB_HOST', 'sql204.infinityfree.com');
define('DB_PORT', '3306');
define('DB_NAME', 'if0_42202434_agree');
define('DB_USER', 'if0_42202434');
define('DB_PASS', 'Miggycie1329');
define('DB_CHARSET', 'utf8mb4');

define('ADMIN_INVITE_CODE', 'IBAN-AGREE-ADMIN');

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        try {
            $pdo->exec("SET time_zone = '+08:00'");
        } catch (Throwable $ignored) {
        }
    } catch (PDOException $e) {
        throw new RuntimeException('Database connection failed. Import schema.sql and review db.php.', 0, $e);
    }

    return $pdo;
}
