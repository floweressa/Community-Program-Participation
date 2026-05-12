<?php
// database.php - Central DB connection for Bayanihan

define('DB_HOST', 'localhost');
define('DB_USER', 'root');        // Change to your MySQL username
define('DB_PASS', '');            // Change to your MySQL password
define('DB_NAME', 'community_program_db');

function getDB(): mysqli {
    static $conn = null;

    if ($conn === null) {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

        if ($conn->connect_error) {
            die(json_encode([
                'error' => 'Database connection failed: ' . $conn->connect_error
            ]));
        }

        $conn->set_charset('utf8mb4');
    }

    return $conn;
}
?>