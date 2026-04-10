<?php
/**
 * FCM HTTP v1 helper: OAuth2 token + send push notifications.
 * Requires firebase_config.php and a valid firebase-service-account.json.
 *
 * Changes from original:
 *  - fcm_send_to_token()    → returns detailed result array (not just bool)
 *  - fcm_send_to_tokens()   → retry on transient errors, marks invalid tokens
 *  - fcm_send_to_topic()    → new: topic-based broadcast
 *  - fcm_invalidate_token() → new: marks a token inactive in DB
 *  - Internal token cache   → avoids re-fetching OAuth2 token on same request
 */

// Always load config (do not gate on FCM_HELPER_LOADED — callers may define that constant
// before including this file, which previously skipped config and broke OAuth2/FCM).
require_once __DIR__ . '/firebase_config.php';

// ─── OAuth2 token (cached per PHP process) ───────────────────────────────────

$_fcm_access_token_cache = null;

/**
 * Get OAuth2 access token using service account JSON (JWT grant).
 * Cached for the lifetime of the current PHP process/request.
 *
 * @return string|null Access token or null on failure
 */
function fcm_get_access_token(): ?string {
    global $firebase_service_account_path, $_fcm_access_token_cache;

    if ($_fcm_access_token_cache !== null) {
        return $_fcm_access_token_cache;
    }

    if (!file_exists($firebase_service_account_path)) {
        error_log('[FCM] Service account file not found: ' . $firebase_service_account_path);
        return null;
    }

    $key = json_decode(file_get_contents($firebase_service_account_path), true);
    if (!$key || empty($key['private_key']) || empty($key['client_email'])) {
        error_log('[FCM] Invalid service account JSON');
        return null;
    }

    $now      = time();
    $payload  = [
        'iss'   => $key['client_email'],
        'sub'   => $key['client_email'],
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
    ];
    $header   = ['alg' => 'RS256', 'typ' => 'JWT'];
    $segments = [
        base64url_encode(json_encode($header)),
        base64url_encode(json_encode($payload)),
    ];
    $signature = '';
    $ok = openssl_sign(
        implode('.', $segments),
        $signature,
        $key['private_key'],
        OPENSSL_ALGO_SHA256
    );
    if (!$ok) {
        error_log('[FCM] openssl_sign failed');
        return null;
    }
    $segments[] = base64url_encode($signature);
    $jwt        = implode('.', $segments);

    $body = http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion'  => $jwt,
    ]);
    $ctx = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $body,
            'timeout' => 10,
        ],
    ]);
    $resp = @file_get_contents('https://oauth2.googleapis.com/token', false, $ctx);
    if ($resp === false) {
        error_log('[FCM] OAuth2 token request failed');
        return null;
    }
    $data = json_decode($resp, true);
    if (empty($data['access_token'])) {
        error_log('[FCM] OAuth2 response missing access_token: ' . $resp);
        return null;
    }

    $_fcm_access_token_cache = $data['access_token'];
    return $_fcm_access_token_cache;
}

function base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

// ─── Single token send ────────────────────────────────────────────────────────

/**
 * Send FCM v1 message to one device token.
 *
 * @param string $token   FCM device token
 * @param string $title   Notification title
 * @param string $body    Notification body
 * @param array  $data    Optional key-value data payload (all values must be strings)
 * @return array {
 *   'ok'           => bool,
 *   'http_code'    => int,
 *   'invalid_token'=> bool,   // true when FCM says token is unregistered/invalid
 *   'retryable'    => bool,   // true on 500/503 — safe to retry
 *   'response'     => string, // raw FCM response body
 * }
 */
function fcm_send_to_token(
    string $token,
    string $title,
    string $body,
    array  $data = []
): array {
    global $firebase_project_id;

    $result = [
        'ok'            => false,
        'http_code'     => 0,
        'invalid_token' => false,
        'retryable'     => false,
        'response'      => '',
    ];

    $access_token = fcm_get_access_token();
    if (!$access_token) {
        $result['response'] = 'Could not obtain OAuth2 token';
        return $result;
    }

    $url     = "https://fcm.googleapis.com/v1/projects/{$firebase_project_id}/messages:send";
    $payload = [
        'message' => [
            'token'        => $token,
            'notification' => ['title' => $title, 'body' => $body],
        ],
    ];

    // Stringify data values (FCM requirement)
    if (!empty($data)) {
        $stringData = [];
        foreach ($data as $k => $v) {
            $stringData[(string)$k] = (string)$v;
        }
        $payload['message']['data'] = $stringData;
    }

    $ctx = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", [
                'Content-Type: application/json',
                "Authorization: Bearer {$access_token}",
            ]) . "\r\n",
            'content'       => json_encode($payload),
            'timeout'       => 10,
            'ignore_errors' => true, // so we can read error bodies
        ],
    ]);

    $resp = @file_get_contents($url, false, $ctx);
    $result['response'] = $resp ?: '';

    // Parse HTTP status from response headers
    if (isset($http_response_header[0])) {
        preg_match('/HTTP\/\S+\s+(\d+)/', $http_response_header[0], $m);
        $result['http_code'] = isset($m[1]) ? (int)$m[1] : 0;
    }

    $code = $result['http_code'];

    if ($code === 200) {
        $result['ok'] = true;
        return $result;
    }

    // Detect invalid / unregistered tokens (do NOT retry these)
    if ($code === 404 || $code === 410) {
        $result['invalid_token'] = true;
        return $result;
    }

    // Also check JSON error body for UNREGISTERED
    if ($resp) {
        $decoded = json_decode($resp, true);
        $fcmError = $decoded['error']['details'][0]['errorCode']
                 ?? $decoded['error']['status']
                 ?? '';
        if (in_array($fcmError, ['UNREGISTERED', 'INVALID_ARGUMENT'], true)) {
            $result['invalid_token'] = true;
            return $result;
        }
    }

    // 500 / 503 = transient server error → safe to retry
    if ($code === 500 || $code === 503) {
        $result['retryable'] = true;
    }

    return $result;
}

// ─── Bulk token send (with retry + token invalidation) ───────────────────────

/**
 * Send the same notification to multiple FCM tokens.
 * - Retries up to $maxRetries times on transient (500/503) errors.
 * - Marks invalid tokens inactive in DB (requires $conn global).
 *
 * @param string[] $tokens
 * @param string   $title
 * @param string   $body
 * @param array    $data
 * @param int      $maxRetries  Per-token retry attempts on transient errors
 * @return array {
 *   'success'       => int,
 *   'failed'        => int,
 *   'invalid_tokens'=> string[],  // tokens that should be removed from DB
 * }
 */
function fcm_send_to_tokens(
    array  $tokens,
    string $title,
    string $body,
    array  $data = [],
    int    $maxRetries = 2
): array {
    $tokens  = array_values(array_unique(array_filter($tokens)));
    $success = 0;
    $failed  = 0;
    $invalid = [];

    foreach ($tokens as $token) {
        $sent    = false;
        $attempt = 0;

        while ($attempt <= $maxRetries) {
            if ($attempt > 0) {
                // Exponential back-off: 1 s, 2 s
                sleep($attempt);
            }

            $res = fcm_send_to_token($token, $title, $body, $data);

            if ($res['ok']) {
                $sent = true;
                break;
            }

            if ($res['invalid_token']) {
                // No point retrying — token is gone
                $invalid[] = $token;
                break;
            }

            if (!$res['retryable']) {
                // Non-retryable error (auth, bad request, etc.)
                error_log("[FCM] Non-retryable error for token={$token}: HTTP {$res['http_code']} — {$res['response']}");
                break;
            }

            // Retryable: try again
            $attempt++;
        }

        if ($sent) {
            $success++;
        } else {
            $failed++;
        }
    }

    // Invalidate bad tokens in DB (best-effort; no fatal error if $conn unavailable)
    if (!empty($invalid)) {
        fcm_invalidate_tokens($invalid);
    }

    return [
        'success'        => $success,
        'failed'         => $failed,
        'invalid_tokens' => $invalid,
    ];
}

// ─── Topic-based send ─────────────────────────────────────────────────────────

/**
 * Send a notification to an FCM topic (e.g. "all_users", "students", "faculty").
 * Subscribers must be managed via FCM topic subscription API from the Flutter side.
 *
 * @param string $topic  Topic name WITHOUT the /topics/ prefix
 * @param string $title
 * @param string $body
 * @param array  $data
 * @return array Same shape as fcm_send_to_token()
 */
function fcm_send_to_topic(
    string $topic,
    string $title,
    string $body,
    array  $data = []
): array {
    global $firebase_project_id;

    $result = [
        'ok'            => false,
        'http_code'     => 0,
        'invalid_token' => false,
        'retryable'     => false,
        'response'      => '',
    ];

    $access_token = fcm_get_access_token();
    if (!$access_token) {
        $result['response'] = 'Could not obtain OAuth2 token';
        return $result;
    }

    $url     = "https://fcm.googleapis.com/v1/projects/{$firebase_project_id}/messages:send";
    $payload = [
        'message' => [
            'topic'        => $topic,
            'notification' => ['title' => $title, 'body' => $body],
        ],
    ];

    if (!empty($data)) {
        $stringData = [];
        foreach ($data as $k => $v) {
            $stringData[(string)$k] = (string)$v;
        }
        $payload['message']['data'] = $stringData;
    }

    $ctx = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", [
                'Content-Type: application/json',
                "Authorization: Bearer {$access_token}",
            ]) . "\r\n",
            'content'       => json_encode($payload),
            'timeout'       => 10,
            'ignore_errors' => true,
        ],
    ]);

    $resp = @file_get_contents($url, false, $ctx);
    $result['response'] = $resp ?: '';

    if (isset($http_response_header[0])) {
        preg_match('/HTTP\/\S+\s+(\d+)/', $http_response_header[0], $m);
        $result['http_code'] = isset($m[1]) ? (int)$m[1] : 0;
    }

    $result['ok'] = ($result['http_code'] === 200);
    return $result;
}

// ─── Token invalidation helper ────────────────────────────────────────────────

/**
 * Mark one or more FCM tokens as inactive in user_fcm_tokens.
 * Called automatically by fcm_send_to_tokens() when FCM reports UNREGISTERED.
 *
 * @param string[] $tokens
 */
function fcm_invalidate_tokens(array $tokens): void {
    global $conn;

    if (empty($tokens) || !isset($conn) || !$conn) {
        return;
    }

    // Only invalidate if is_active column exists (migration may not have run)
    $colCheck = @$conn->query("SHOW COLUMNS FROM user_fcm_tokens LIKE 'is_active'");
    if (!$colCheck || $colCheck->num_rows === 0) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($tokens), '?'));
    $stmt = $conn->prepare(
        "UPDATE user_fcm_tokens SET is_active = 0 WHERE fcm_token IN ($placeholders)"
    );
    if (!$stmt) {
        return;
    }

    $stmt->bind_param(str_repeat('s', count($tokens)), ...$tokens);
    $stmt->execute();
    $stmt->close();
}

// ─── Notification log helper ──────────────────────────────────────────────────

/**
 * Insert a row into notification_log and return its ID.
 * Returns null if the table doesn't exist or insert fails.
 *
 * @param array $params {
 *   type, ref_id, ref_date, title, body,
 *   recipient_type, event_id,
 *   tokens_targeted, tokens_sent, tokens_failed,
 *   status, error_message
 * }
 * @return int|null
 */
function fcm_log_notification(array $params): ?int {
    global $conn;

    if (!isset($conn) || !$conn) {
        return null;
    }

    // Silently skip if migration hasn't been run
    $check = @$conn->query("SHOW TABLES LIKE 'notification_log'");
    if (!$check || $check->num_rows === 0) {
        return null;
    }

    $type           = $params['type']            ?? 'unknown';
    // Bind as int; 0 when absent (avoids mysqli NULL quirks on some PHP builds)
    $ref_id         = isset($params['ref_id']) && $params['ref_id'] !== null
        ? (int) $params['ref_id']
        : 0;
    // NULL for SQL NULL on nullable DATE (avoid inserting '' into DATE)
    $ref_date = isset($params['ref_date']) && $params['ref_date'] !== '' && $params['ref_date'] !== null
        ? (string) $params['ref_date']
        : null;
    $title          = $params['title']           ?? '';
    $body           = $params['body']            ?? '';
    $recipient_type = $params['recipient_type']  ?? 'all';
    $event_id       = isset($params['event_id']) && $params['event_id'] !== null
        ? (int) $params['event_id']
        : 0;
    $targeted       = (int) ($params['tokens_targeted'] ?? 0);
    $sent           = (int) ($params['tokens_sent'] ?? 0);
    $failed         = (int) ($params['tokens_failed'] ?? 0);
    $status         = $params['status']          ?? 'sent';
    $error_msg      = isset($params['error_message']) && $params['error_message'] !== null
        ? (string) $params['error_message']
        : '';

    $stmt = $conn->prepare(
        "INSERT INTO notification_log
           (type, ref_id, ref_date, title, body, recipient_type, event_id,
            tokens_targeted, tokens_sent, tokens_failed, status, error_message)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    if (!$stmt) {
        error_log('[FCM] notification_log insert prepare failed: ' . $conn->error);
        return null;
    }

    // Exactly 12 types: s i s s s s i i i i s s
    $types = 'sissssiiiiss';
    if (strlen($types) !== 12) {
        error_log('[FCM] notification_log bind_param type string length bug');
        $stmt->close();
        return null;
    }
    $stmt->bind_param(
        $types,
        $type,
        $ref_id,
        $ref_date,
        $title,
        $body,
        $recipient_type,
        $event_id,
        $targeted,
        $sent,
        $failed,
        $status,
        $error_msg
    );
    if ($stmt->execute()) {
        $id = (int) $stmt->insert_id;
        $stmt->close();
        return $id;
    }

    error_log('[FCM] notification_log insert failed: ' . $stmt->error . ' | errno=' . $stmt->errno);
    $stmt->close();
    return null;
}