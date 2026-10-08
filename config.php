<?php
// ---------------------------------------------------------------
// Travel Buea - backend settings
// These defaults match a fresh Laragon install (MySQL user "root",
// no password). Change them only if you changed Laragon's MySQL.
// ---------------------------------------------------------------
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'travelbuea');
define('DB_USER', 'root');
define('DB_PASS', '');

// Show technical error details in API responses (set to false when you go live)
define('DEBUG', true);

date_default_timezone_set('Africa/Douala');

function db() {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            array(
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            )
        );
    }
    return $pdo;
}
