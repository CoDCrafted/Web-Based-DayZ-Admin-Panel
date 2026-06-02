<?php
include('../essential/backbone.php');

$uid = $_GET['uid'] ?? '';
$serverID = $_GET['serverID'] ?? '';

if ($uid === '' || $serverID === '') {
    echo "0";
    exit;
}

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

$stmt = $db->prepare("SELECT 1 FROM player_codes WHERE uuid = ? AND serverID = ? LIMIT 1");
$stmt->bind_param("ss", $uid, $serverID);
$stmt->execute();
$stmt->store_result();

echo $stmt->num_rows > 0 ? "0" : "0"; //disabled for now