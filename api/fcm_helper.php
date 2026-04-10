<?php
/**
 * FCM HTTP v1 helper: OAuth2 token + send push notifications.
 * Requires firebase_config.php and a valid firebase-service-account.json.
 *
 * Uses cURL when available (required when allow_url_fopen is disabled),
 * falls back to file_get_contents otherwise.
 */

require_once __DIR__ . '/firebase_config.php';

// ─── Internal HTTP helper (cURL preferred, file_get_contents fallback) ───────

/**
 * @return array{body: string|false, http_code: int, error: string}
 */
function _fcm_http_post(string $url, string $contentBody, array $headers = [], int $timeout = 10): array {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $contentBody,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body     = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        return [
            'body'      => ($body === false) ? false : (string) $body,
            'http_code' => $httpCode,
            'error'     => $curlErr,
        ];
    }

    if (!ini_get('allow_url_fopen')) {
        return ['body' => false, 'http_code' => 0, 'error' => 'Neither cURL nor allow_url_fopen available'];
    }

    $headerStr = implode("\r\n", $headers) . "\r\n";
    $ctx = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => $headerStr,
            'content'       => $contentBody,
            'timeout'       => $timeout,
            'ignore_errors' => true,
        ],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    $httpCode = 0;
    if (isset($http_response_header[0])) {
        preg_match('/HTTP\/\S+\s+(\d+)/', $http_response_header[0], $m);
        $httpCode = isset($m[1]) ? (int) $m[1] : 0;
    }

    return [
        'body'      => $body,
        'http_code' => $httpCode,
        'error'     => ($body === false) ? (error_get_last()['message'] ?? 'HTTP request failed') : '',
    ];
}

// ─── OAuth2 token (cached per PHP process) ───────────────────────────────────

$_fcm_access_token_cache = null;

/**
 * Get OAuth2 access token using service account JSON (JWT grant).
 * Cached for the lifetime of the current PHP process/request.
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
        error_log('[FCM] openssl_sign failed: ' . openssl_error_string());
        return null;
    }
    $segments[] = base64url_encode($signature);
    $jwt        = implode('.', $segments);

    $postBody = http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion'  => $jwt,
    ]);

    $resp = _fcm_http_post(
        'https://oauth2.googleapis.com/token',
        $postBody,
        ['Content-Type: application/x-www-form-urlencoded'],
        10
    );

    if ($resp['body'] === false) {
        error_log('[FCM] OAuth2 token request failed: ' . $resp['error']);
        return null;
    }

    $data = json_decode($resp['body'], true);
    if (empty($data['access_token'])) {
        error_log('[FCM] OAuth2 response missing access_token: ' . $resp['body']);
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
 * @return array{ok: bool, http_code: int, invalid_token: bool, retryable: bool, response: string}
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

    if (!empty($data)) {
        $stringData = [];
        foreach ($data as $k => $v) {
            $stringData[(string)$k] = (string)$v;
        }
        $payload['message']['data'] = $stringData;
    }

    $resp = _fcm_http_post(
        $url,
        json_encode($payload),
        [
            'Content-Type: application/json',
            "Authorization: Bearer {$access_token}",
        ],
        10
    );

    $result['response']  = $resp['body'] ?: '';
    $result['http_code'] = $resp['http_code'];
    $code                = $resp['http_code'];

    if ($resp['body'] === false) {
        $result['response'] = 'HTTP request failed: ' . $resp['error'];
        return $result;
    }

    if ($code === 200) {
        $result['ok'] = true;
        return $result;
    }

    if ($code === 404 || $code === 410) {
        $result['invalid_token'] = true;
        return $result;
    }

    if ($resp['body']) {
        $decoded  = json_decode($resp['body'], true);
        $fcmError = $decoded['error']['details'][0]['errorCode']
                 ?? $decoded['error']['status']
                 ?? '';
        if (in_array($fcmError, ['UNREGISTERED', 'INVALID_ARGUMENT'], true)) {
            $result['invalid_token'] = true;
            return $result;
        }
    }

    if ($code === 500 || $code === 503) {
        $result['retryable'] = true;
    }

    return $result;
}

// ─── Bulk token send (with retry + token invalidation) ───────────────────────

/**
 * Send the same notification to multiple FCM tokens.
 *
 * @return array{success: int, failed: int, invalid_tokens: string[], errors: string[]}
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
    $errors  = [];

    foreach ($tokens as $token) {
        if (!fcm_is_plausible_token($token)) {
            $failed++;
            $invalid[] = $token;
            $errors[]  = 'token=' . substr($token, 0, 12) . '… REJECTED (not a valid FCM token format)';
            continue;
        }

        $sent    = false;
        $attempt = 0;
        $lastRes = null;

        while ($attempt <= $maxRetries) {
            if ($attempt > 0) {
                sleep($attempt);
            }

            $res     = fcm_send_to_token($token, $title, $body, $data);
            $lastRes = $res;

            if ($res['ok']) {
                $sent = true;
                break;
            }

            if ($res['invalid_token']) {
                $invalid[] = $token;
                break;
            }

            if (!$res['retryable']) {
                error_log("[FCM] Non-retryable error for token=" . substr($token, 0, 20) . "…: HTTP {$res['http_code']} — {$res['response']}");
                break;
            }

            $attempt++;
        }

        if ($sent) {
            $success++;
        } else {
            $failed++;
            $shortToken = substr($token, 0, 12) . '…';
            $errDetail  = "token={$shortToken} http=" . ($lastRes['http_code'] ?? 0);
            if ($lastRes && $lastRes['invalid_token']) {
                $errDetail .= ' INVALID/UNREGISTERED';
            } elseif ($lastRes && $lastRes['response']) {
                $decoded = json_decode($lastRes['response'], true);
                $fcmErr  = $decoded['error']['message']
                        ?? $decoded['error']['status']
                        ?? substr($lastRes['response'], 0, 80);
                $errDetail .= ' ' . $fcmErr;
            }
            $errors[] = $errDetail;
        }
    }

    if (!empty($invalid)) {
        fcm_invalidate_tokens($invalid);
    }

    return [
        'success'        => $success,
        'failed'         => $failed,
        'invalid_tokens' => $invalid,
        'errors'         => $errors,
    ];
}

// ─── Topic-based send ─────────────────────────────────────────────────────────

/**
 * Send a notification to an FCM topic.
 *
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

    $resp = _fcm_http_post(
        $url,
        json_encode($payload),
        [
            'Content-Type: application/json',
            "Authorization: Bearer {$access_token}",
        ],
        10
    );

    $result['response']  = $resp['body'] ?: '';
    $result['http_code'] = $resp['http_code'];
    $result['ok']        = ($resp['http_code'] === 200);

    return $result;
}

// ─── Token validation ─────────────────────────────────────────────────────────

/**
 * Quick format check: a real FCM token contains a colon and is 100+ chars.
 * Rejects garbage like 32-char hex strings (APNs tokens, device fingerprints, etc.)
 */
function fcm_is_plausible_token(string $token): bool {
    $len = strlen($token);
    if ($len < 50) {
        return false;
    }
    if (strpos($token, ':') === false) {
        return false;
    }
    return true;
}

// ─── Token invalidation helper ────────────────────────────────────────────────

/**
 * Mark one or more FCM tokens as inactive in user_fcm_tokens.
 * Also deletes clearly-invalid tokens (no colon, < 50 chars) outright.
 */
function fcm_invalidate_tokens(array $tokens): void {
    global $conn;

    if (empty($tokens) || !isset($conn) || !$conn) {
        return;
    }

    $toDelete     = [];
    $toDeactivate = [];

    foreach ($tokens as $t) {
        if (!fcm_is_plausible_token($t)) {
            $toDelete[] = $t;
        } else {
            $toDeactivate[] = $t;
        }
    }

    if (!empty($toDelete)) {
        $ph   = implode(',', array_fill(0, count($toDelete), '?'));
        $stmt = $conn->prepare("DELETE FROM user_fcm_tokens WHERE fcm_token IN ($ph)");
        if ($stmt) {
            $stmt->bind_param(str_repeat('s', count($toDelete)), ...$toDelete);
            $stmt->execute();
            $deleted = $stmt->affected_rows;
            $stmt->close();
            if ($deleted > 0) {
                error_log("[FCM] Deleted {$deleted} garbage token(s) from DB");
            }
        }
    }

    if (!empty($toDeactivate)) {
        $colCheck = @$conn->query("SHOW COLUMNS FROM user_fcm_tokens LIKE 'is_active'");
        if (!$colCheck || $colCheck->num_rows === 0) {
            return;
        }
        $ph   = implode(',', array_fill(0, count($toDeactivate), '?'));
        $stmt = $conn->prepare("UPDATE user_fcm_tokens SET is_active = 0 WHERE fcm_token IN ($ph)");
        if ($stmt) {
            $stmt->bind_param(str_repeat('s', count($toDeactivate)), ...$toDeactivate);
            $stmt->execute();
            $updated = $stmt->affected_rows;
            $stmt->close();
            if ($updated > 0) {
                error_log("[FCM] Deactivated {$updated} unregistered token(s)");
            }
        }
    }
}

// ─── Notification log helper ──────────────────────────────────────────────────

/**
 * Insert a row into notification_log and return its ID.
 */
function fcm_log_notification(array $params): ?int {
    global $conn;

    if (!isset($conn) || !$conn) {
        return null;
    }

    $check = @$conn->query("SHOW TABLES LIKE 'notification_log'");
    if (!$check || $check->num_rows === 0) {
        return null;
    }

    $type           = $params['type']            ?? 'unknown';
    $ref_id         = isset($params['ref_id']) && $params['ref_id'] !== null
        ? (int) $params['ref_id']
        : 0;
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
