<?php
/**
 * FCM Diagnostic Tool — run from CLI or browser to check push notification health.
 * Usage: php diagnose_fcm.php 42       (CLI, event_id=42)
 *    or: http://…/api/diagnose_fcm.php?event_id=42
 *
 * DELETE THIS FILE after debugging.
 */
ini_set('display_errors', '1');
error_reporting(E_ALL);

$nl = "\n";
$hr = str_repeat('─', 60) . "\n";

echo "=== FCM Diagnostic Report ==={$nl}{$nl}";

require_once __DIR__ . '/firebase_config.php';
require_once __DIR__ . '/db.php';

// 1. Service Account File
echo "1. SERVICE ACCOUNT FILE{$nl}";
global $firebase_service_account_path, $firebase_project_id;
$keyData = null;
if (file_exists($firebase_service_account_path)) {
    $keyData = json_decode(file_get_contents($firebase_service_account_path), true);
    echo "   Path  : {$firebase_service_account_path}{$nl}";
    echo "   Status: EXISTS{$nl}";
    echo "   Email : " . ($keyData['client_email'] ?? 'MISSING') . $nl;
    echo "   Key   : " . (empty($keyData['private_key']) ? 'MISSING' : 'present (' . strlen($keyData['private_key']) . ' bytes)') . $nl;
    echo "   Project (from JSON): " . ($keyData['project_id'] ?? 'N/A') . $nl;
} else {
    echo "   Status: MISSING — file not found at {$firebase_service_account_path}{$nl}";
    echo "   FIX: Download from Firebase Console > Project Settings > Service Accounts{$nl}";
}
echo "   Project ID (config): {$firebase_project_id}{$nl}";
if (isset($keyData['project_id']) && $keyData['project_id'] !== $firebase_project_id) {
    echo "   WARNING: Project ID mismatch! Config='{$firebase_project_id}' vs JSON='{$keyData['project_id']}'{$nl}";
}
echo $hr;

// 2. OAuth2 — step-by-step debugging
echo "2. OAUTH2 ACCESS TOKEN (step-by-step){$nl}";
echo "   Server time     : " . date('Y-m-d H:i:s T') . " (UTC offset: " . date('P') . "){$nl}";
echo "   PHP version     : " . phpversion() . $nl;
echo "   openssl loaded  : " . (extension_loaded('openssl') ? 'YES' : 'NO — REQUIRED') . $nl;
if (extension_loaded('openssl')) {
    echo "   openssl version : " . OPENSSL_VERSION_TEXT . $nl;
}
echo "   allow_url_fopen : " . (ini_get('allow_url_fopen') ? 'YES' : 'NO — REQUIRED for file_get_contents()') . $nl;

$oauth2Token = null;
if ($keyData && !empty($keyData['private_key']) && !empty($keyData['client_email'])) {
    // Step A: Sign JWT
    echo "{$nl}   [Step A] Signing JWT...{$nl}";
    $now     = time();
    $payload = [
        'iss'   => $keyData['client_email'],
        'sub'   => $keyData['client_email'],
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
    ];
    $header   = ['alg' => 'RS256', 'typ' => 'JWT'];
    $segments = [
        rtrim(strtr(base64_encode(json_encode($header)), '+/', '-_'), '='),
        rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '='),
    ];
    $signature = '';
    $signOk    = @openssl_sign(implode('.', $segments), $signature, $keyData['private_key'], OPENSSL_ALGO_SHA256);

    if (!$signOk) {
        $sslErr = openssl_error_string();
        echo "   FAILED: openssl_sign error: {$sslErr}{$nl}";
        echo "   FIX: The private_key in the service account JSON may be corrupt.{$nl}";
        echo "         Re-download from Firebase Console.{$nl}";
    } else {
        echo "   OK — JWT signed successfully{$nl}";
        $segments[] = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
        $jwt = implode('.', $segments);

        // Step B: Exchange JWT for access token
        echo "   [Step B] Exchanging JWT with Google OAuth2...{$nl}";
        $postBody = http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]);

        $usesCurl = function_exists('curl_init');
        echo "   HTTP method: " . ($usesCurl ? 'cURL' : 'file_get_contents') . $nl;

        if ($usesCurl) {
            $ch = curl_init('https://oauth2.googleapis.com/token');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postBody);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            $resp    = curl_exec($ch);
            $curlErr = curl_error($ch);
            $curlCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($resp === false) {
                echo "   FAILED: cURL error: {$curlErr}{$nl}";
            } else {
                echo "   HTTP {$curlCode} — response length: " . strlen($resp) . $nl;
            }
        } else {
            $ctx = stream_context_create([
                'http' => [
                    'method'  => 'POST',
                    'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
                    'content' => $postBody,
                    'timeout' => 15,
                ],
            ]);
            $resp = @file_get_contents('https://oauth2.googleapis.com/token', false, $ctx);
            if ($resp === false) {
                $lastErr = error_get_last();
                echo "   FAILED: file_get_contents error: " . ($lastErr['message'] ?? 'unknown') . $nl;
                echo "   FIX: Check allow_url_fopen, SSL certs, and network/firewall rules.{$nl}";
            } else {
                echo "   OK — response length: " . strlen($resp) . $nl;
            }
        }

        if ($resp && $resp !== false) {
            $data = json_decode($resp, true);
            if (!empty($data['access_token'])) {
                $oauth2Token = $data['access_token'];
                echo "   ACCESS TOKEN: obtained (length=" . strlen($oauth2Token) . "){$nl}";
            } else {
                echo "   FAILED: No access_token in response{$nl}";
                echo "   Google response: " . substr($resp, 0, 500) . $nl;
                if (!empty($data['error'])) {
                    echo "   Error: {$data['error']} — " . ($data['error_description'] ?? '') . $nl;
                }
            }
        }
    }
} else {
    echo "   SKIPPED — service account data incomplete{$nl}";
}
echo $hr;

// 3. Database — Token Stats
echo "3. FCM TOKEN DATABASE STATUS{$nl}";
if (!isset($conn) || !$conn) {
    echo "   Database: NOT CONNECTED{$nl}";
} else {
    $colCheck  = @$conn->query("SHOW COLUMNS FROM user_fcm_tokens LIKE 'is_active'");
    $hasActive = ($colCheck && $colCheck->num_rows > 0);
    echo "   is_active column: " . ($hasActive ? 'EXISTS' : 'MISSING — token cleanup disabled, all tokens always targeted') . $nl;

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

    $r = $conn->query(
        "SELECT COUNT(DISTINCT user_id) as c FROM user_fcm_tokens"
        . ($hasActive ? " WHERE is_active = 1 OR is_active IS NULL" : "")
    );
    $users = $r ? (int)$r->fetch_assoc()['c'] : 0;
    echo "   Unique users with active tokens: {$users}{$nl}";

    // Show token age
    $r = $conn->query("SELECT MIN(updated_at) as oldest, MAX(updated_at) as newest FROM user_fcm_tokens");
    if ($r) {
        $row = $r->fetch_assoc();
        echo "   Oldest token updated: " . ($row['oldest'] ?? 'N/A') . $nl;
        echo "   Newest token updated: " . ($row['newest'] ?? 'N/A') . $nl;
    }
}
echo $hr;

// 4. Event-specific check
$event_id = (int)($argv[1] ?? $_GET['event_id'] ?? 42);
echo "4. EVENT #{$event_id} TOKEN CHECK{$nl}";

if (isset($conn) && $conn) {
    $ev    = $conn->query("SELECT id, title FROM events WHERE id = {$event_id}");
    $evRow = $ev ? $ev->fetch_assoc() : null;
    if ($evRow) {
        echo "   Event: {$evRow['title']}{$nl}";
    } else {
        echo "   Event not found!{$nl}";
    }

    $selectCols = "t.fcm_token, t.user_id, t.updated_at";
    if ($hasActive) {
        $selectCols .= ", t.is_active";
    }

    $sql = "SELECT {$selectCols}
            FROM user_fcm_tokens t
            INNER JOIN volunteers v ON v.user_id = t.user_id AND v.event_id = {$event_id} AND v.status = 'active'
            UNION
            SELECT {$selectCols}
            FROM user_fcm_tokens t
            INNER JOIN participant p ON p.user_id = t.user_id AND p.event_id = {$event_id} AND p.status = 'active'";

    $r = @$conn->query($sql);
    if ($r && $r->num_rows > 0) {
        echo "   Tokens that would be targeted:{$nl}";
        $testTokens = [];
        while ($row = $r->fetch_assoc()) {
            $short  = substr($row['fcm_token'], 0, 20) . '...';
            $active = $hasActive ? ($row['is_active'] ?? 'NULL') : 'N/A';
            echo "     user={$row['user_id']} active={$active} updated={$row['updated_at']} token={$short}{$nl}";
            $testTokens[] = $row['fcm_token'];
        }

        // 5. Live send test
        echo $hr;
        echo "5. LIVE TOKEN VALIDATION{$nl}";
        if ($oauth2Token) {
            require_once __DIR__ . '/fcm_helper.php';
            foreach ($testTokens as $i => $t) {
                $short = substr($t, 0, 20) . '...';
                $res   = fcm_send_to_token($t, 'FCM Diagnostic', 'Token validation test', ['type' => 'diagnostic']);
                $status = $res['ok'] ? 'DELIVERED' : 'FAILED';
                if ($res['invalid_token']) {
                    $status .= ' (UNREGISTERED — token is dead, should be removed)';
                }
                echo "   [{$i}] {$short} -> HTTP {$res['http_code']} {$status}{$nl}";
                if (!$res['ok'] && $res['response']) {
                    $decoded = json_decode($res['response'], true);
                    $msg = $decoded['error']['message'] ?? $decoded['error']['status'] ?? substr($res['response'], 0, 120);
                    echo "       Error: {$msg}{$nl}";
                }
            }
        } else {
            echo "   SKIPPED — OAuth2 token not available (fix OAuth2 first){$nl}";
        }
    } else {
        $err = $conn->error ? " (SQL error: {$conn->error})" : "";
        echo "   No tokens found for this event's volunteers/participants{$err}{$nl}";
    }
}

echo $hr;
echo "6. RECENT NOTIFICATION LOG{$nl}";
if (isset($conn) && $conn) {
    $r = @$conn->query("SELECT id, type, tokens_targeted, tokens_sent, tokens_failed, status, error_message, sent_at FROM notification_log ORDER BY id DESC LIMIT 10");
    if ($r && $r->num_rows > 0) {
        echo sprintf("   %-4s %-12s %-4s %-4s %-4s %-8s %-20s %s{$nl}", 'ID', 'Type', 'Tgt', 'OK', 'Fail', 'Status', 'Sent At', 'Error');
        while ($row = $r->fetch_assoc()) {
            echo sprintf("   %-4s %-12s %-4s %-4s %-4s %-8s %-20s %s{$nl}",
                $row['id'], $row['type'], $row['tokens_targeted'], $row['tokens_sent'],
                $row['tokens_failed'], $row['status'], $row['sent_at'],
                substr($row['error_message'] ?: '-', 0, 50)
            );
        }
    } else {
        echo "   No entries or table missing{$nl}";
    }
}

echo "{$nl}=== Diagnostic complete ==={$nl}";

if (isset($conn) && $conn instanceof mysqli) {
    @$conn->close();
}
