<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include('../essential/backbone.php');
if (!file_exists('../essential/backbone.php')) {
    die("Error: backbone.php not found");
}

/* ----------------------------- Rate limiting ------------------------------ */

$rateLimitFile = __DIR__ . '/rate_limit.json';
$rateLimitSeconds = 0;
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

if (!file_exists($rateLimitFile)) {
    file_put_contents($rateLimitFile, json_encode([]), LOCK_EX);
}

$rateData = json_decode(file_get_contents($rateLimitFile), true);
if (!is_array($rateData)) {
    $rateData = [];
}

$now = microtime(true);

if (isset($rateData[$ip]) && ($now - $rateData[$ip]) < $rateLimitSeconds) {
    http_response_code(429);
    die("ERROR: One command every 2 seconds.");
}

$cutoff = $now - 60;
foreach ($rateData as $storedIp => $time) {
    if ($time < $cutoff) {
        unset($rateData[$storedIp]);
    }
}

$rateData[$ip] = $now;

file_put_contents(
    $rateLimitFile,
    json_encode($rateData, JSON_PRETTY_PRINT),
    LOCK_EX
);

/* ----------------------------- Auth ------------------------------ */

$username = $_COOKIE['user_name'] ?? '';
$sessionID = $_COOKIE['sessionId'] ?? '';

$loggedIn = confirmSessionKey($username, $sessionID);
$isAdmin  = checkIsUserAdmin($username, $sessionID);

$idx = getUserID();
$valid_until = validUntil($idx);

if ($valid_until < time()) {
    echo "Session expired. Refresh page to log back in.";
    exit;
}

if (!$loggedIn) {
    http_response_code(401);
    die("ERROR: Not logged in");
}

/* ----------------------------- Method check ------------------------------ */

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    die("ERROR: Invalid request method");
}

/* ----------------------------- Validate POST ------------------------------ */

$serverID       = trim($_POST['serverID'] ?? '');
$targetCode     = trim($_POST['targetCode'] ?? '');
$commandType    = trim($_POST['commandType'] ?? '');
$commandContent = trim($_POST['commandContent'] ?? '');

if ($serverID === '' || $commandType === '') {
    http_response_code(400);
    die("ERROR: Missing required fields");
}

/* ----------------------------- Database ------------------------------ */

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($db->connect_error) {
    http_response_code(500);
    die("ERROR: DB connection failed");
}

/* =========================================================
   PLAYER LIST COMMAND (SPECIAL CASE)
========================================================= */

if ($commandType === "player_list") {

    $listStmt = $db->prepare("
        SELECT username
        FROM player_codes
        WHERE serverID = ?
        ORDER BY username ASC
    ");

    if (!$listStmt) {
        http_response_code(500);
        die("ERROR: Prepare failed");
    }

    $listStmt->bind_param("s", $serverID);
    $listStmt->execute();
    $result = $listStmt->get_result();

    $players = [];

    while ($row = $result->fetch_assoc()) {
        $players[] = $row['username'];
    }

    $listStmt->close();
    $db->close();

    if (empty($players)) {
        echo "No players online.";
    } else {
        echo implode("\n", $players);
    }

    exit;
}

/* =========================================================
   NORMAL COMMAND FLOW
========================================================= */

/* ----------------------------- GIVE quantity support ------------------------------ */

$itemQuantity = 1;

if ($commandType === "give") {

    if (strpos($commandContent, ":") !== false) {

        $parts = explode(":", $commandContent, 2);

        $commandContent = trim($parts[0]); // actual item name
        $itemQuantity = intval($parts[1]);

        if ($itemQuantity < 1) {
            $itemQuantity = 1;
        }

        // Optional safety cap (prevents abuse)
        if ($itemQuantity > 100) {
            $itemQuantity = 100;
        }
    }
}


if ($targetCode === '') {
    http_response_code(400);
    die("ERROR: target required for this command");
}

$allTargets = [];

/* ----------------------------- Resolve target(s) ------------------------------ */

if (strtolower($targetCode) === "all") {

    $q = $db->prepare("
        SELECT uuid, username
        FROM player_codes
        WHERE serverID = ?
    ");
    $q->bind_param("s", $serverID);
    $q->execute();
    $result = $q->get_result();

    while ($row = $result->fetch_assoc()) {
        $allTargets[] = $row;
    }

    $q->close();

    if (empty($allTargets)) {
        http_response_code(404);
        die("ERROR: No players found for this server");
    }

} else {

    // Multiple usernames support
    if (strpos($targetCode, ",") !== false) {

        $usernames = array_unique(array_filter(array_map('trim', explode(",", $targetCode))));
        $lookup = $db->prepare("
            SELECT uuid, username
            FROM player_codes
            WHERE username = ? AND serverID = ?
            LIMIT 1
        ");

        foreach ($usernames as $name) {

            $lookup->bind_param("ss", $name, $serverID);
            $lookup->execute();
            $lookup->bind_result($resolvedUID, $resolvedUsername);
            $lookup->fetch();

            if (!empty($resolvedUID)) {
                $allTargets[] = [
                    "uuid" => $resolvedUID,
                    "username" => $resolvedUsername
                ];
            }

            $lookup->reset();
        }

        $lookup->close();

        if (empty($allTargets)) {
            http_response_code(404);
            die("ERROR: None of the specified players were found");
        }

    } else {

        // Single username
        $lookup = $db->prepare("
            SELECT uuid, username
            FROM player_codes
            WHERE username = ? AND serverID = ?
            LIMIT 1
        ");

        $lookup->bind_param("ss", $targetCode, $serverID);
        $lookup->execute();
        $lookup->bind_result($resolvedUID, $resolvedUsername);
        $lookup->fetch();
        $lookup->close();

        if (!$resolvedUID) {
            http_response_code(404);
            die("ERROR: Player not found");
        }

        $allTargets[] = [
            "uuid" => $resolvedUID,
            "username" => $resolvedUsername
        ];
    }
}

/* ----------------------------- TELEPORT username → UID ------------------------------ */

if ($commandType === "teleport") {
    if (strpos($commandContent, " ") === false) {

        $lookup2 = $db->prepare("
            SELECT uuid
            FROM player_codes
            WHERE username = ? AND serverID = ?
            LIMIT 1
        ");
        $lookup2->bind_param("ss", $commandContent, $serverID);
        $lookup2->execute();
        $lookup2->bind_result($teleportUID);
        $lookup2->fetch();
        $lookup2->close();

        if (!empty($teleportUID)) {
            $commandContent = $teleportUID;
        }
    }
}

/* ----------------------------- Insert Command(s) ------------------------------ */

$stmt = $db->prepare("
    INSERT INTO commands (serverID, uuid, commandType, commandContent)
    VALUES (?, ?, ?, ?)
");

if (!$stmt) {
    http_response_code(500);
    die("ERROR: Prepare failed");
}

foreach ($allTargets as $t) {

    $uuid = $t["uuid"];

    $repeat = ($commandType === "give") ? $itemQuantity : 1;

    for ($i = 0; $i < $repeat; $i++) {

        $stmt->bind_param(
            "ssss",
            $serverID,
            $uuid,
            $commandType,
            $commandContent
        );

        $stmt->execute();

        if ($stmt->affected_rows !== 1) {
            http_response_code(500);
            die("ERROR: Insert failed for UUID: " . $uuid);
        }
    }
}


$stmt->close();
$db->close();

/* ----------------------------- Success ------------------------------ */

if (strtolower($targetCode) === "all") {
    echo "OK: Command queued for ALL players (" . count($allTargets) . ")";
} elseif (strpos($targetCode, ",") !== false) {
    echo "OK: Command queued for " . count($allTargets) . " players";
} else {
    echo "OK: Command queued for " . $allTargets[0]["username"];
}
