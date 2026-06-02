<?php

include('../essential/backbone.php');

if (!isset($_GET['serverID']) || trim($_GET['serverID']) === '') {
    die("ERROR: Missing serverID");
}

$serverID = trim($_GET['serverID']);

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($db->connect_error) {
    die("ERROR: DB connection failed");
}

$stmt = $db->prepare("
    SELECT zoneName, centerX, centerY, centerZ, radius
    FROM safezones
    WHERE serverID = ?
");

$stmt->bind_param("s", $serverID);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {

    echo $row['zoneName'] . ":" .
         $row['centerX'] . ":" .
         $row['centerY'] . ":" .
         $row['centerZ'] . ":" .
         $row['radius'] . "\n";
}

$stmt->close();
$db->close();
