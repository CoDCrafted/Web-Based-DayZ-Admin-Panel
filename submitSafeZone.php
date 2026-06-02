<?php

include('../essential/backbone.php');

/* -----------------------------
   Allow only POST
------------------------------ */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die("ERROR: Invalid request method");
}

/* -----------------------------
   Validate required fields
------------------------------ */
if (
    !isset($_POST['serverID']) ||
    !isset($_POST['zoneName']) ||
    !isset($_POST['radius']) ||
    !isset($_POST['coords'])
) {
    die("ERROR: Missing parameters");
}

$serverID = trim($_POST['serverID']);
$zoneName = trim($_POST['zoneName']);
$radius   = trim($_POST['radius']);
$coords   = trim($_POST['coords']);

if ($serverID === '' || $zoneName === '' || $radius === '' || $coords === '') {
    die("ERROR: Empty fields");
}

/* -----------------------------
   Parse coordinates (x y z)
------------------------------ */
$coordParts = preg_split('/\s+/', $coords);

if (count($coordParts) < 3) {
    die("ERROR: Invalid coordinates format");
}

$centerX = floatval($coordParts[0]);
$centerY = floatval($coordParts[1]);
$centerZ = floatval($coordParts[2]);
$radius  = floatval($radius);

/* -----------------------------
   Basic validation
------------------------------ */
if ($radius <= 0) {
    die("ERROR: Invalid radius");
}

/* -----------------------------
   DB Connection
------------------------------ */
$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($db->connect_error) {
    die("ERROR: DB connection failed");
}

/* -----------------------------
   Insert Safe Zone
------------------------------ */
$stmt = $db->prepare("
    INSERT INTO safezones
    (serverID, zoneName, centerX, centerY, centerZ, radius)
    VALUES (?, ?, ?, ?, ?, ?)
");

$stmt->bind_param(
    "ssdddd",
    $serverID,
    $zoneName,
    $centerX,
    $centerY,
    $centerZ,
    $radius
);

if ($stmt->execute()) {
    echo "SUCCESS: Safe Zone created";
} else {
    echo "ERROR: Failed to create Safe Zone";
}

$stmt->close();
$db->close();
