<?php
// ===================== DATABASE CONFIG =====================
// To switch between local XAMPP and InfinityFree, change these values.

// --- Hostinger (live) ---
define('DB_HOST', 'localhost');
define('DB_NAME', 'u876774677_player_table');
define('DB_USER', 'u876774677_tccarte');
define('DB_PASS', '1kA?lDUJ^^');

// --- Local XAMPP (uncomment to test locally) ---
// define('DB_HOST', 'localhost');
// define('DB_NAME', 'battleship');
// define('DB_USER', 'root');
// define('DB_PASS', '');

function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }
    return $pdo;
}
