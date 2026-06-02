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

if($serverID === "bf250a67c19ba5bba15a67a4a395b403"){
    die("Wrong Method");
}

/* -----------------------------
   Fetch UNPROCESSED commands
------------------------------ */
$stmt = $db->prepare("
    SELECT id, uuid, commandType, commandContent
    FROM commands
    WHERE serverID = ?
    ORDER BY id ASC
");

$stmt->bind_param("s", $serverID);
$stmt->execute();
$result = $stmt->get_result();

$commandIDs = [];

while ($row = $result->fetch_assoc()) {
    $commandIDs[] = $row['id'];

    echo $row['id'] . ":" .
         $row['uuid'] . ":" .
         $row['commandType'] . ":" .
         $row['commandContent'] . "\n";
}

$stmt->close();

/* -----------------------------
   Delete previously processed commands
   (but only AFTER fetching new ones)
------------------------------ */
$cleanup = $db->prepare("
    DELETE FROM commands
    WHERE serverID = ?
    AND processed = 1
");
$cleanup->bind_param("s", $serverID);
$cleanup->execute();
$cleanup->close();

/* -----------------------------
   Mark fetched commands as processed
------------------------------ */
if (!empty($commandIDs)) {

    $placeholders = implode(',', array_fill(0, count($commandIDs), '?'));
    $types = str_repeat('i', count($commandIDs));

    $update = $db->prepare("
        UPDATE commands
        SET processed = 1
        WHERE id IN ($placeholders)
    ");

    $update->bind_param($types, ...$commandIDs);
    $update->execute();
    $update->close();
}

$db->close();
