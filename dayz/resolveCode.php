<?php
include('../essential/backbone.php');

$serverID = $_GET['serverID'] ?? '';
$code     = $_GET['code'] ?? '';

if ($serverID === '' || $code === '') {
	die("ERROR");
}

/*
 You can:
 - store mappings in memory (Redis)
 - or temporary DB table
 - or cache pushed from server
*/

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

$stmt = $db->prepare("
	SELECT uuid FROM player_codes
	WHERE serverID = ? AND code = ?
	LIMIT 1
");

$stmt->bind_param("ss", $serverID, $code);
$stmt->execute();
$res = $stmt->get_result();

if ($row = $res->fetch_assoc()) {
	echo $row['uuid'];
}
