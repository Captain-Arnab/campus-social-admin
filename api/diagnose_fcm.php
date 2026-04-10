<?php
/**
 * FCM Diagnostic Tool — run from browser or CLI to check push notification health.
 * Usage: php diagnose_fcm.php          (CLI)
 *    or: http://localhost/.../api/diagnose_fcm.php?event_id=42  (browser)
 *
 * DELETE THIS FILE after debugging — it exposes internal state.
 */
ini_set('display_errors', '1');
error_reporting(E_ALL);

$isCli = (php_sapi_name() === 'cli');
$nl    = $isCli ? "\n" : "<br>";
$hr    = $isCli ? str_repeat('─', 60) . "\n" : "<hr>";

if (!$isCli) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<pre style="font-family:monospace;font-size:14px;max-width:900px;margin:20px auto;">';
}

echo "=== FCM Diagnostic Report ==={$nl}{$nl}";

require_once __DIR__ . '/firebase_config.php';
require_once __DIR__ . '/db.php';

// 1. Service Account File
echo "1. SERVICE ACCOUNT FILE{$nl}";
global $firebase_service_account_path, $firebase_project_id;
if (file_exists($firebase_service_account_path)) {
    $key = json_decode(file_get_contents($firebase_service_account_path), true);
    echo "   Path  : {$firebase_service_account_path}{$nl}";
    echo "   Status: EXISTS ✓{$nl}";
    echo "   Email : " . ($key['client_email'] ?? 'MISSING') . $nl;
    echo "   Key   : " . (empty($key['private_key']) ? 'MISSING ✗' : 'present ✓') . $nl;
    echo "   Project (from JSON): " . ($key['project_id'] ?? 'N/A') . $nl;
} else {
    echo "   Status: MISSING ✗ — file not found at {$firebase_service_account_path}{$nl}";
    echo "   FIX: Download service account key from Firebase Console → Project Settings → Service Accounts{$nl}";
}
echo "   Project ID (config): {$firebase_project_id}{$nl}";
if (isset($key['project_id']) && $key['project_id'] !== $firebase_project_id) {
    echo "   ⚠ WARNING: Project ID mismatch! Config says '{$firebase_project_id}' but service account says '{$key['project_id']}'{$nl}";
}
echo $hr;

// 2. OAuth2 Token
echo "2. OAUTH2 ACCESS TOKEN{$nl}";
require_once __DIR__ . '/fcm_helper.php';
$token = fcm_get_access_token();
if ($token) {
    echo "   Status: OK ✓ (token obtained, length=" . strlen($token) . "){$nl}";
} else {
    echo "   Status: FAILED ✗ — cannot authenticate with Google{$nl}";
    echo "   FIX: Check service account JSON, ensure openssl extension is enabled, verify server clock{$nl}";
    echo "   Server time: " . date('Y-m-d H:i:s T') . " (UTC offset: " . date('P') . "){$nl}";
}
echo $hr;

// 3. Database — Token Stats
echo "3. FCM TOKEN DATABASE STATUS{$nl}";
if (!isset($conn) || !$conn) {
    echo "   Database: NOT CONNECTED ✗{$nl}";
} else {
    $colCheck = $conn->query("SHOW COLUMNS FROM user_fcm_tokens LIKE 'is_active'");
    $hasActive = ($colCheck && $colCheck->num_rows > 0);
    echo "   is_active column: " . ($hasActive ? 'EXISTS ✓' : 'MISSING — all tokens treated as active') . $nl;

    $r = $conn->query("SELECT COUNT(*) as c FROM user_fcm_tokens");
    $total = $r ? (int)$r->fetch_assoc()['c'] : 0;
    echo "   Total tokens in DB: {$total}{$nl}";

    if ($hasActive) {
        $r = $conn->query("SELECT COUNT(*) as c FROM user_fcm_tokens WHERE is_active = 1 OR is_active IS NULL");
        $active = $r ? (int)$r->fetch_assoc()['c'] : 0;
        $r = $conn->query("SELECT COUNT(*) as c FROM user_fcm_tokens WHERE is_active = 0");
        $inactive = $r ? (int)$r->fetch_assoc()['c'] : 0;
        echo "   Active tokens  : {$active}{$nl}";
        echo "   Inactive tokens: {$inactive}{$nl}";
    }

    $r = $conn->query("SELECT COUNT(DISTINCT user_id) as c FROM user_fcm_tokens" . ($hasActive ? " WHERE is_active = 1 OR is_active IS NULL" : ""));
    $users = $r ? (int)$r->fetch_assoc()['c'] : 0;
    echo "   Unique users with active tokens: {$users}{$nl}";
}
echo $hr;

// 4. Event-specific check
$event_id = $isCli ? ($argv[1] ?? 42) : ($_GET['event_id'] ?? 42);
$event_id = (int)$event_id;
echo "4. EVENT #{$event_id} TOKEN CHECK{$nl}";

if (isset($conn) && $conn) {
    $ev = $conn->query("SELECT id, title FROM events WHERE id = {$event_id}");
    $evRow = $ev ? $ev->fetch_assoc() : null;
    if ($evRow) {
        echo "   Event: {$evRow['title']}{$nl}";
    } else {
        echo "   Event not found!{$nl}";
    }

    $activeFilter = $hasActive ? " AND (t.is_active = 1 OR t.is_active IS NULL)" : "";

    $sql = "SELECT t.fcm_token, t.user_id, t.is_active, t.updated_at
            FROM user_fcm_tokens t
            INNER JOIN volunteers v ON v.user_id = t.user_id AND v.event_id = {$event_id} AND v.status = 'active'
            WHERE 1=1 {$activeFilter}
            UNION
            SELECT t.fcm_token, t.user_id, t.is_active, t.updated_at
            FROM user_fcm_tokens t
            INNER JOIN participant p ON p.user_id = t.user_id AND p.event_id = {$event_id} AND p.status = 'active'
            WHERE 1=1 {$activeFilter}";

    $r = $conn->query($sql);
    if ($r && $r->num_rows > 0) {
        echo "   Tokens that would be targeted:{$nl}";
        $testTokens = [];
        while ($row = $r->fetch_assoc()) {
            $short = substr($row['fcm_token'], 0, 20) . '…';
            $active = $row['is_active'] ?? 'NULL';
            echo "     user={$row['user_id']} active={$active} updated={$row['updated_at']} token={$short}{$nl}";
            $testTokens[] = $row['fcm_token'];
        }

        // 5. Live send test (dry run: send to first token only if OAuth2 works)
        echo $hr;
        echo "5. LIVE TOKEN VALIDATION (sending test to each token){$nl}";
        if ($token) {
            foreach ($testTokens as $i => $t) {
                $short = substr($t, 0, 20) . '…';
                $res   = fcm_send_to_token($t, 'FCM Diagnostic', 'Token validation test', ['type' => 'diagnostic']);
                $status = $res['ok'] ? 'DELIVERED ✓' : 'FAILED ✗';
                if ($res['invalid_token']) {
                    $status .= ' (UNREGISTERED — token is dead)';
                }
                echo "   [{$i}] {$short} → HTTP {$res['http_code']} {$status}{$nl}";
                if (!$res['ok'] && $res['response']) {
                    $decoded = json_decode($res['response'], true);
                    $msg = $decoded['error']['message'] ?? $decoded['error']['status'] ?? substr($res['response'], 0, 120);
                    echo "       Error: {$msg}{$nl}";
                }
            }
        } else {
            echo "   SKIPPED — OAuth2 token not available{$nl}";
        }
    } else {
        echo "   No active tokens found for this event's volunteers/participants{$nl}";
    }
}

echo $hr;
echo "6. RECENT NOTIFICATION LOG{$nl}";
if (isset($conn) && $conn) {
    $r = $conn->query("SELECT id, type, tokens_targeted, tokens_sent, tokens_failed, status, error_message, sent_at FROM notification_log ORDER BY id DESC LIMIT 10");
    if ($r) {
        echo sprintf("   %-4s %-12s %-4s %-4s %-4s %-8s %-20s %s{$nl}", 'ID', 'Type', 'Tgt', 'Sent', 'Fail', 'Status', 'Sent At', 'Error');
        while ($row = $r->fetch_assoc()) {
            echo sprintf("   %-4s %-12s %-4s %-4s %-4s %-8s %-20s %s{$nl}",
                $row['id'], $row['type'], $row['tokens_targeted'], $row['tokens_sent'],
                $row['tokens_failed'], $row['status'], $row['sent_at'],
                substr($row['error_message'] ?: '-', 0, 50)
            );
        }
    }
}

echo "{$nl}=== Diagnostic complete ==={$nl}";

if (!$isCli) {
    echo '</pre>';
}

if (isset($conn) && $conn instanceof mysqli) {
    @$conn->close();
}
