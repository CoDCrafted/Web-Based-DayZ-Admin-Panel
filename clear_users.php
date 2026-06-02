<?php
include('../essential/backbone.php');

$DELETE_USERS = true;  // Set false if you ever want to disable cleanup

// Read raw POST body
$raw = file_get_contents("php://input");

// Convert raw body into key/value pairs
parse_str($raw, $parsed);

$serverID = $parsed['serverID'] ?? '';

if ($serverID === '') {
    file_put_contents("debug_fail.txt", "Missing serverID\nRAW:\n$raw\n");
    exit;
}

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($db->connect_error) {
    file_put_contents("debug_fail.txt", "DB connection error: " . $db->connect_error . "\n");
    exit;
}

$db->begin_transaction();

try {

    if ($DELETE_USERS) {
        $stmt = $db->prepare("
            DELETE FROM player_codes
            WHERE serverID = ?
        ");

        $stmt->bind_param("s", $serverID);
        $stmt->execute();

        $affected = $stmt->affected_rows;
        $stmt->close();

        file_put_contents("debug_success.txt", "Deleted $affected rows for serverID $serverID\nRAW:\n$raw\n");
    }

    $db->commit();

} catch (Exception $e) {

    $db->rollback();
    file_put_contents("debug_fail.txt", "Transaction error: " . $e->getMessage() . "\nRAW:\n$raw\n");
    exit;
}
