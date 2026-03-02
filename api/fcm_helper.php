<?php
/**
 * FCM HTTP v1 helper: get OAuth2 token from service account and send push notifications.
 * Requires firebase_config.php and a valid firebase-service-account.json (Service Account key from Firebase Console).
 */

if (!defined('FCM_HELPER_LOADED')) {
    require_once __DIR__ . '/firebase_config.php';
}

/**
 * Get OAuth2 access token using service account JSON (JWT grant).
 * @return string|null Access token or null on failure
 */
function fcm_get_access_token() {
    global $firebase_service_account_path;
    if (!file_exists($firebase_service_account_path)) {
        return null;
    }
    $key = json_decode(file_get_contents($firebase_service_account_path), true);
    if (!$key || empty($key['private_key']) || empty($key['client_email'])) {
        return null;
    }
    $now = time();
    $payload = [
        'iss' => $key['client_email'],
        'sub' => $key['client_email'],
        'aud' => 'https://oauth2.googleapis.com/token',
        'iat' => $now,
        'exp' => $now + 3600,
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging'
    ];
    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    $segments = [
        base64url_encode(json_encode($header)),
        base64url_encode(json_encode($payload))
    ];
    $signature = '';
    $ok = openssl_sign(implode('.', $segments), $signature, $key['private_key'], OPENSSL_ALGO_SHA256);
    if (!$ok) return null;
    $segments[] = base64url_encode($signature);
    $jwt = implode('.', $segments);
    $body = http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $jwt
    ]);
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $body
        ]
    ]);
    $resp = @file_get_contents('https://oauth2.googleapis.com/token', false, $ctx);
    if ($resp === false) return null;
    $data = json_decode($resp, true);
    return isset($data['access_token']) ? $data['access_token'] : null;
}

function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Send FCM v1 message to one token.
 * @param string $token FCM device token
 * @param string $title Notification title
 * @param string $body Notification body
 * @param array $data Optional key-value data payload
 * @return bool Success
 */
function fcm_send_to_token($token, $title, $body, $data = []) {
    global $firebase_project_id;
    $access_token = fcm_get_access_token();
    if (!$access_token) return false;
    $url = "https://fcm.googleapis.com/v1/projects/{$firebase_project_id}/messages:send";
    $payload = [
        'message' => [
            'token' => $token,
            'notification' => [
                'title' => $title,
                'body' => $body
            ]
        ]
    ];
    if (!empty($data)) {
        $payload['message']['data'] = $data;
    }
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAuthorization: Bearer {$access_token}\r\n",
            'content' => json_encode($payload)
        ]
    ]);
    $resp = @file_get_contents($url, false, $ctx);
    return $resp !== false && isset($http_response_header) && strpos($http_response_header[0], '200') !== false;
}

/**
 * Send same notification to multiple FCM tokens (one request per token).
 * @param array $tokens Array of FCM tokens
 * @param string $title Notification title
 * @param string $body Notification body
 * @param array $data Optional data payload
 * @return array ['success' => count, 'failed' => count]
 */
function fcm_send_to_tokens(array $tokens, $title, $body, $data = []) {
    $tokens = array_unique(array_filter($tokens));
    $success = 0;
    foreach ($tokens as $t) {
        if (fcm_send_to_token($t, $title, $body, $data)) {
            $success++;
        }
    }
    return ['success' => $success, 'failed' => count($tokens) - $success];
}
