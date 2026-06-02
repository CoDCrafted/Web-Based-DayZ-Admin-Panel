<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include('../essential/backbone.php');
if (!file_exists('../essential/backbone.php')) {
    die("Error: backbone.php not found");
}

/* ----------------------------- Rate limiting (1 request / 2 seconds per IP) ------------------------------ */

$rateLimitFile = __DIR__ . '/rate_limit.json';
$rateLimitSeconds = 2;
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

// Create file if it doesn't exist
if (!file_exists($rateLimitFile)) {
    file_put_contents($rateLimitFile, json_encode([]), LOCK_EX);
}

// Load rate limit data
$rateData = json_decode(file_get_contents($rateLimitFile), true);
if (!is_array($rateData)) {
    $rateData = [];
}

$now = microtime(true);

// Enforce limit
if (isset($rateData[$ip]) && ($now - $rateData[$ip]) < $rateLimitSeconds) {
    http_response_code(429);
    die("ERROR: One command every 2 seconds.");
}

// Optional cleanup (keep file small)
$cutoff = $now - 60;
foreach ($rateData as $storedIp => $time) {
    if ($time < $cutoff) {
        unset($rateData[$storedIp]);
    }
}

// Update timestamp
$rateData[$ip] = $now;

// Save back to file
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

// Uncomment when ready
// if (!$loggedIn) {
//     http_response_code(401);
//     die("ERROR: Not logged in");
// }
// if (!$isAdmin) {
//     http_response_code(403);
//     die("ERROR: Admin privileges required");
// }

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    die("ERROR: Invalid request method");
}

/* ----------------------------- Validate POST parameters ------------------------------ */

$serverID       = trim($_POST['serverID'] ?? '');
$targetCode     = trim($_POST['targetCode'] ?? ''); // <-- CODE, not UID
$commandType    = trim($_POST['commandType'] ?? '');
$commandContent = trim($_POST['commandContent'] ?? '');

if ($serverID === '' || $targetCode === '' || $commandType === '') {
    http_response_code(400);
    die("ERROR: Missing required fields");
}

if ($serverID != "VUE123" && $serverID != "COD123") {
    die("ERROR: Invalid server ID");
}

if($commandType == "global")
	$commandContent = $commandContent . " - cyenox.com demo panel.";

/* ----------------------------- Database lookup: CODE → UID + USERNAME ------------------------------ */

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($db->connect_error) {
    http_response_code(500);
    die("ERROR: DB connection failed");
}

$lookup = $db->prepare("
    SELECT uuid, username
    FROM player_codes
    WHERE code = ? AND serverID = ?
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

/* ----------------------------- TELEPORT: Try to resolve username → UID ------------------------------ */

if ($commandType === "teleport") {
    // Coordinates contain spaces, usernames do not
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

        // Replace with UID if found
        if (!empty($teleportUID)) {
            $commandContent = $teleportUID;
        }
    }
}

/* ----------------------------- Item blacklist check (for give) ------------------------------ */

if ($commandType === "give") {
    $blacklistFile = __DIR__ . "/blacklist.txt";
    if (!file_exists($blacklistFile)) {
        http_response_code(500);
        die("ERROR: blacklist.txt missing");
    }

    $blockedItems = file($blacklistFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $blockedItems = array_map('trim', $blockedItems);
    $blockedItems = array_map('strtolower', $blockedItems);

    $itemLower = strtolower($commandContent);
    if (in_array($itemLower, $blockedItems)) {
        http_response_code(403);
        die("ERROR: This item is blacklisted");
    }
}

/* ----------------------------- Insert new command ------------------------------ */

$stmt = $db->prepare("
    INSERT INTO commands (serverID, uuid, commandType, commandContent)
    VALUES (?, ?, ?, ?)
");
if (!$stmt) {
    http_response_code(500);
    die("ERROR: Prepare failed");
}

$stmt->bind_param(
    "ssss",
    $serverID,
    $resolvedUID,
    $commandType,
    $commandContent
);

$stmt->execute();

if ($stmt->affected_rows !== 1) {
    http_response_code(500);
    die("ERROR: Insert failed");
}

$stmt->close();
$db->close();

/* ----------------------------- Success response ------------------------------ */

echo "OK: Command queued for $resolvedUsername";
