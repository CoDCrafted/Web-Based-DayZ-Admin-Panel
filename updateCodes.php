<?php
include('../essential/backbone.php');

$DELETE_OLD_UUIDS = true;

// Read raw POST body
$raw = file_get_contents("php://input");

// Convert raw body into key/value pairs
parse_str($raw, $parsed);

$serverID = $parsed['serverID'] ?? '';
$data     = $parsed['data'] ?? '';

if ($serverID === '' || $data === '') {
    file_put_contents("debug_fail.txt", "Missing fields\nRAW:\n$raw\n");
    exit;
}

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($db->connect_error) {
    file_put_contents("debug_fail.txt", "DB connection error: " . $db->connect_error . "\n");
    exit;
}

$db->begin_transaction();

try {

    $lines = explode("\n", trim($data));
    $batchUUIDs = [];

    /*
     * Prepare insert statement once (more efficient)
     */
    $insertStmt = $db->prepare("
        INSERT INTO player_codes (serverID, code, uuid, username, last_seen)
        VALUES (?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            code = VALUES(code),
            username = VALUES(username),
            last_seen = NOW()
    ");

    foreach ($lines as $line) {

        if (!str_contains($line, ':')) continue;

        // Expect format: code:uuid:username
        $parts = explode(':', $line, 3);
        if (count($parts) < 3) continue;

        $code     = trim($parts[0]);
        $uuid     = trim($parts[1]);
        $username = trim($parts[2]);

        if ($code === '' || $uuid === '') continue;

        $batchUUIDs[] = $uuid;

        $insertStmt->bind_param("ssss", $serverID, $code, $uuid, $username);
        $insertStmt->execute();
    }

    $insertStmt->close();

    /*
     * Delete records NOT in current batch
     */
    if ($DELETE_OLD_UUIDS) {

        if (!empty($batchUUIDs)) {

            $placeholders = implode(',', array_fill(0, count($batchUUIDs), '?'));
            $types = 's' . str_repeat('s', count($batchUUIDs));

            $sql = "
                DELETE FROM player_codes
                WHERE serverID = ?
                AND uuid NOT IN ($placeholders)
            ";

            $deleteStmt = $db->prepare($sql);

            $params = array_merge([$serverID], $batchUUIDs);
            $deleteStmt->bind_param($types, ...$params);

            $deleteStmt->execute();
            $deleteStmt->close();

        } else {

            // If batch is empty → remove all players for server
            $deleteStmt = $db->prepare("
                DELETE FROM player_codes
                WHERE serverID = ?
            ");

            $deleteStmt->bind_param("s", $serverID);
            $deleteStmt->execute();
            $deleteStmt->close();
        }
    }

    $db->commit();

} catch (Exception $e) {

    $db->rollback();
    file_put_contents("debug_fail.txt", "Transaction error: " . $e->getMessage());
    exit;
}

file_put_contents("debug_success.txt", "OK\nRAW:\n$raw\n");
