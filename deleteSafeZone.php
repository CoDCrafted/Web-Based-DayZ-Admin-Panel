<?php

include('../essential/backbone.php');

/* -----------------------------
   POST only
------------------------------ */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die("ERROR: Invalid request method");
}

if (
    !isset($_POST['serverID']) ||
    !isset($_POST['zoneID'])
) {
    die("ERROR: Missing parameters");
}

$serverID = trim($_POST['serverID']);
$zoneID   = intval($_POST['zoneID']);

if ($serverID === '' || $zoneID <= 0) {
    die("ERROR: Invalid data");
}

/* -----------------------------
   DB
------------------------------ */
$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($db->connect_error) {
    die("ERROR: DB connection failed");
}

/* -----------------------------
   Delete zone (serverID protected)
------------------------------ */
$stmt = $db->prepare("
    DELETE FROM safezones
    WHERE id = ?
    AND serverID = ?
");

$stmt->bind_param("is", $zoneID, $serverID);

if ($stmt->execute()) {
    echo "SUCCESS: Safe Zone deleted";
} else {
    echo "ERROR: Failed to delete";
}

$stmt->close();
$db->close();
