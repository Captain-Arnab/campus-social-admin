<?php
/**
 * Admin-only poster replace/remove.
 * Writes events.banners immediately (any status, including approved).
 * Organizer/student edits stay on the pending_edits path in api/events.php.
 */

require_once __DIR__ . '/event_delete_helper.php';

function admin_poster_limit_message(): string
{
    return 'Poster must be ≤5MB and ≤1080×1920 px';
}

function admin_poster_limits_ok(string $tmpPath, int $fileSize): bool
{
    if ($fileSize > 5 * 1024 * 1024) {
        return false;
    }
    $info = @getimagesize($tmpPath);
    if ($info === false) {
        return false;
    }
    $w = (int) ($info[0] ?? 0);
    $h = (int) ($info[1] ?? 0);
    return $w > 0 && $h > 0 && $w <= 1080 && $h <= 1920;
}

/**
 * @param array<string,mixed> $file One $_FILES entry
 * @return array{ok:bool,error?:string,filename?:string}
 */
function admin_poster_store_upload(array $file): array
{
    $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => 'Choose a poster image to replace the current one'];
    }
    if ($err !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => admin_poster_limit_message()];
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    $size = (int) ($file['size'] ?? 0);
    if ($tmp === '' || !is_uploaded_file($tmp) || !admin_poster_limits_ok($tmp, $size)) {
        return ['ok' => false, 'error' => admin_poster_limit_message()];
    }
    $dir = __DIR__ . '/uploads/events';
    if (!is_dir($dir) && !@mkdir($dir, 0777, true)) {
        return ['ok' => false, 'error' => 'Could not save poster'];
    }
    $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', basename((string) ($file['name'] ?? 'poster.jpg')));
    if (!is_string($safe) || $safe === '' || $safe === '.' || $safe === '..') {
        $safe = 'poster.jpg';
    }
    $filename = 'evt_' . time() . '_0_' . $safe;
    if (!move_uploaded_file($tmp, $dir . '/' . $filename)) {
        return ['ok' => false, 'error' => 'Could not save poster'];
    }
    return ['ok' => true, 'filename' => $filename];
}

/**
 * Replace or clear the live poster. Also updates a pending-edit banners copy
 * so a later organizer-edit approval does not put the old poster back.
 *
 * @param array<string,mixed>|null $file
 * @return array{ok:bool,error?:string,banners?:string}
 */
function admin_poster_apply(mysqli $conn, int $eventId, string $op, ?array $file = null): array
{
    if ($eventId <= 0) {
        return ['ok' => false, 'error' => 'Event not found'];
    }
    $ev = $conn->query('SELECT banners FROM events WHERE id = ' . (int) $eventId . ' LIMIT 1');
    if (!$ev || !($row = $ev->fetch_assoc())) {
        return ['ok' => false, 'error' => 'Event not found'];
    }
    $previous = (string) ($row['banners'] ?? '[]');
    $newFile = null;
    if ($op === 'remove') {
        $newJson = '[]';
    } elseif ($op === 'replace') {
        if ($file === null) {
            return ['ok' => false, 'error' => 'Choose a poster image to replace the current one'];
        }
        $stored = admin_poster_store_upload($file);
        if (!$stored['ok']) {
            return $stored;
        }
        $newFile = (string) $stored['filename'];
        $newJson = json_encode([$newFile]);
    } else {
        return ['ok' => false, 'error' => 'Unknown poster action'];
    }

    $stmt = $conn->prepare('UPDATE events SET banners = ? WHERE id = ?');
    if (!$stmt) {
        if ($newFile !== null) {
            @unlink(__DIR__ . '/uploads/events/' . $newFile);
        }
        return ['ok' => false, 'error' => 'Could not update poster'];
    }
    $stmt->bind_param('si', $newJson, $eventId);
    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) {
        if ($newFile !== null) {
            @unlink(__DIR__ . '/uploads/events/' . $newFile);
        }
        return ['ok' => false, 'error' => 'Could not update poster'];
    }

    $col = @$conn->query("SHOW COLUMNS FROM event_pending_edits LIKE 'banners'");
    if ($col && $col->num_rows > 0) {
        $pe = $conn->prepare('UPDATE event_pending_edits SET banners = ? WHERE event_id = ?');
        if ($pe) {
            $pe->bind_param('si', $newJson, $eventId);
            $pe->execute();
            $pe->close();
        }
    }

    $oldList = json_decode($previous, true);
    if (is_array($oldList)) {
        $paths = [];
        foreach ($oldList as $b) {
            if (!is_string($b) || $b === '' || $b === $newFile) {
                continue;
            }
            $resolved = event_delete_resolve_file_path($b);
            if ($resolved !== null) {
                $paths[] = $resolved;
            }
        }
        event_delete_uploaded_files($paths);
    }

    return ['ok' => true, 'banners' => $newJson];
}
