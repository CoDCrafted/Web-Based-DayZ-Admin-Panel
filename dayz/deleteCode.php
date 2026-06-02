<?php
include('../essential/backbone.php');

$raw = file_get_contents("php://input");
parse_str($raw, $parsed);

$serverID = $parsed['serverID'] ?? '';
$uid      = $parsed['uid'] ?? '';

if ($serverID === '' || $uid === '') {
    file_put_contents("delete_fail.txt", "Missing fields\nRAW:\n$raw\n");
    exit;
}

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($db->connect_error) {
    file_put_contents("delete_fail.txt", "DB error: " . $db->connect_error . "\n");
    exit;
}

$stmt = $db->prepare("DELETE FROM player_codes WHERE serverID = ? AND uuid = ?");
$stmt->bind_param("ss", $serverID, $uid);
$stmt->execute();
$stmt->close();

file_put_contents("delete_success.txt", "Deleted UID $uid\n");
echo "OK";
