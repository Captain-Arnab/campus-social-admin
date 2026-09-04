<?php

/**
 * India mobile to 91XXXXXXXXXX for SMS gateway destination param.
 */
function sms_normalize_india_mobile($raw) {
    $d = preg_replace('/\D/', '', (string) $raw);
    if (strlen($d) === 10) {
        return '91' . $d;
    }
    if (strlen($d) === 12 && substr($d, 0, 2) === '91') {
        return $d;
    }
    if (strlen($d) === 11 && $d[0] === '0') {
        return '91' . substr($d, 1);
    }
    return null;
}

function sms_phones_match($a, $b) {
    $na = sms_normalize_india_mobile($a);
    $nb = sms_normalize_india_mobile($b);
    return $na !== null && $nb !== null && $na === $nb;
}

/**
 * Login-friendly match: same as sms_phones_match, or last 10 digits equal (handles formatting drift).
 */
function sms_phones_match_loose($a, $b) {
    if (sms_phones_match($a, $b)) {
        return true;
    }
    $da = preg_replace('/\D/', '', (string) $a);
    $db = preg_replace('/\D/', '', (string) $b);
    if (strlen($da) >= 10 && strlen($db) >= 10) {
        return substr($da, -10) === substr($db, -10);
    }
    return false;
}

if (!defined('DLT_TEMPLATE_FORGOT_PASSWORD_OTP')) {
    define('DLT_TEMPLATE_FORGOT_PASSWORD_OTP', '1777178850902651646');
}

function sms_load_config() {
    $defaults = [
        'base_url' => getenv('SMS_GATEWAY_BASE_URL') ?: '',
        'username' => getenv('SMS_GATEWAY_USERNAME') ?: '',
        'password' => getenv('SMS_GATEWAY_PASSWORD') ?: '',
        'sender_id' => getenv('SMS_GATEWAY_SENDER_ID') ?: 'MiCamp',
        'entity_id' => getenv('SMS_GATEWAY_ENTITY_ID') ?: '',
        'template_id' => getenv('SMS_GATEWAY_TEMPLATE_ID') ?: '',
        'tmid' => getenv('SMS_GATEWAY_TMID') ?: '',
        'otp_message_template' => getenv('SMS_OTP_MESSAGE_TEMPLATE') ?:
            'Your OTP for MiCampus login is {OTP}. Please do not share this code with anyone. Valid for 10 minutes. Micampus.co.in',
        'forgot_password_otp_template_id' => getenv('SMS_FORGOT_PASSWORD_OTP_TEMPLATE_ID')
            ?: DLT_TEMPLATE_FORGOT_PASSWORD_OTP,
        'forgot_password_otp_tmid' => getenv('SMS_FORGOT_PASSWORD_OTP_TMID') ?: '',
        'forgot_password_otp_message_template' => getenv('SMS_FORGOT_PASSWORD_OTP_MESSAGE') ?:
            "Your OTP for MiCampus password reset is {OTP}.\nPlease do not share this code with anyone.\nValid for 10 minutes.\nMicampus.co.in",
        'event_created_template_id' => getenv('SMS_EVENT_CREATED_TEMPLATE_ID') ?: '1707177546592758639',
        'event_created_tmid' => getenv('SMS_EVENT_CREATED_TMID') ?: '',
        'event_created_message_template' => getenv('SMS_EVENT_CREATED_MESSAGE') ?:
            "A new event has been created successfully.\nEvent Name: {#var#}\nPlease login to the admin panel for more details.\n-Team MiCampus",
        'admin_event_notify_phones' => [],
    ];
    $local = __DIR__ . '/sms_config.local.php';
    if (is_readable($local)) {
        $cfg = include $local;
        if (is_array($cfg)) {
            return array_merge($defaults, $cfg);
        }
    }
    return $defaults;
}

/**
 * Interpret ConnectBind-style gateway HTTP + body. Many gateways return HTTP 200
 * with an error string in the body — HTTP status alone is not enough.
 *
 * @return array{ok: bool, error?: string}
 */
function sms_interpret_gateway_response(int $httpCode, string $body): array
{
    $bodyTrim = trim($body);
    if ($httpCode < 200 || $httpCode >= 300) {
        return [
            'ok' => false,
            'error' => 'HTTP ' . $httpCode . ($bodyTrim !== '' ? ': ' . $bodyTrim : ''),
        ];
    }
    if ($bodyTrim === '') {
        return ['ok' => false, 'error' => 'Empty gateway response body'];
    }

    $lower = strtolower($bodyTrim);
    $failNeedles = [
        'error',
        'invalid',
        'fail',
        'insufficient',
        'insufficient credit',
        'insufficient balance',
        'reject',
        'unauthor',
        'unauthorized',
        'forbidden',
        'template not',
        'invalid template',
        'dlt reject',
        'blacklist',
        'expired',
        'not registered',
        'not approved',
        'ndnc',
    ];
    // Allow success phrases that may contain the word "template" etc.
    $successNeedles = [
        'message sent',
        'msg sent',
        'submitted',
        'success',
        'accepted',
        'gid=',
        'msgid',
        'message id',
    ];
    foreach ($successNeedles as $okWord) {
        if (strpos($lower, $okWord) !== false) {
            return ['ok' => true];
        }
    }
    // ConnectBind often returns "1701|<mobile>|<id>" on success
    if (preg_match('/^1701\|/', $bodyTrim)) {
        return ['ok' => true];
    }
    // Numeric-only or pipe-delimited acceptance codes
    if (preg_match('/^\d+(\|\d+)+$/', $bodyTrim) || preg_match('/^\d{4,}$/', $bodyTrim)) {
        return ['ok' => true];
    }
    foreach ($failNeedles as $bad) {
        if (strpos($lower, $bad) !== false) {
            return ['ok' => false, 'error' => $bodyTrim];
        }
    }
    // Unknown body with HTTP 200 — treat as success but leave body for logs
    return ['ok' => true];
}

/**
 * Persist SMS attempt for debugging (best effort).
 *
 * @param mysqli|null $conn
 * @param array<string,mixed> $result from sms_send_connectbind
 */
function sms_log_attempt($conn, string $purpose, string $destination, array $result, ?int $jobId = null, ?int $userId = null): void
{
    $ok = !empty($result['ok']) ? 1 : 0;
    $http = (int) ($result['http_code'] ?? 0);
    $body = (string) ($result['body'] ?? '');
    $err = (string) ($result['error'] ?? '');
    if (function_exists('mb_substr')) {
        $body = mb_substr($body, 0, 2000);
        $err = mb_substr($err, 0, 2000);
    } else {
        $body = substr($body, 0, 2000);
        $err = substr($err, 0, 2000);
    }

    error_log(sprintf(
        '[sms_log] purpose=%s dest=%s ok=%d http=%d err=%s body=%s',
        $purpose,
        $destination,
        $ok,
        $http,
        $err,
        substr($body, 0, 300)
    ));

    if (!$conn) {
        return;
    }
    static $ensured = null;
    if ($ensured === null) {
        $ensured = (bool) @$conn->query(
            "CREATE TABLE IF NOT EXISTS `sms_log` (
              `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              `purpose` VARCHAR(64) NOT NULL DEFAULT '',
              `destination` VARCHAR(20) NOT NULL DEFAULT '',
              `user_id` INT NULL,
              `job_id` BIGINT UNSIGNED NULL,
              `ok` TINYINT(1) NOT NULL DEFAULT 0,
              `http_code` INT NOT NULL DEFAULT 0,
              `gateway_body` TEXT NULL,
              `error_message` TEXT NULL,
              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_sms_created` (`created_at`),
              KEY `idx_sms_purpose_ok` (`purpose`, `ok`, `created_at`),
              KEY `idx_sms_job` (`job_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }
    if (!$ensured) {
        return;
    }
    $purposeEsc = $conn->real_escape_string(substr($purpose, 0, 64));
    $destEsc = $conn->real_escape_string(substr($destination, 0, 20));
    $uidSql = $userId === null ? 'NULL' : (string) (int) $userId;
    $jidSql = $jobId === null ? 'NULL' : (string) (int) $jobId;
    $bodyEsc = $conn->real_escape_string($body);
    $errEsc = $conn->real_escape_string($err);
    @$conn->query(
        "INSERT INTO sms_log (purpose, destination, user_id, job_id, ok, http_code, gateway_body, error_message)
         VALUES ('$purposeEsc', '$destEsc', $uidSql, $jidSql, $ok, $http, '$bodyEsc', '$errEsc')"
    );
}

/**
 * @param array<string, string>|null $templateOverrides Keys: template_id, tmid (optional). Uses defaults from config when omitted.
 * @param int $timeoutSec HTTP timeout (OTP uses a shorter value so the API stays under client limits)
 * @return array{ok: bool, http_code: int, body: string, error?: string}
 */
function sms_send_connectbind($destination91, $plainMessage, $templateOverrides = null, int $timeoutSec = 30) {
    $cfg = sms_load_config();
    $tempid = $cfg['template_id'];
    $tmid = $cfg['tmid'] ?? '';
    if (is_array($templateOverrides)) {
        if (!empty($templateOverrides['template_id'])) {
            $tempid = $templateOverrides['template_id'];
        }
        if (array_key_exists('tmid', $templateOverrides) && $templateOverrides['tmid'] !== '' && $templateOverrides['tmid'] !== null) {
            $tmid = $templateOverrides['tmid'];
        }
    }
    $required = ['base_url', 'username', 'password', 'entity_id'];
    foreach ($required as $k) {
        if (empty($cfg[$k])) {
            return ['ok' => false, 'http_code' => 0, 'body' => '', 'error' => 'SMS gateway not configured'];
        }
    }
    if ($tempid === '' || $tempid === null || $tmid === '' || $tmid === null) {
        return ['ok' => false, 'http_code' => 0, 'body' => '', 'error' => 'SMS template not configured'];
    }
    $timeoutSec = max(3, min(30, $timeoutSec));
    $query = http_build_query([
        'username' => $cfg['username'],
        'password' => $cfg['password'],
        'type' => '0',
        'dlr' => '1',
        'destination' => $destination91,
        'source' => $cfg['sender_id'],
        'message' => $plainMessage,
        'entityid' => $cfg['entity_id'],
        'tempid' => $tempid,
        'tmid' => $tmid,
    ], '', '&', PHP_QUERY_RFC3986);

    $url = rtrim($cfg['base_url'], '?&') . '?' . $query;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSec);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(5, $timeoutSec));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            return ['ok' => false, 'http_code' => $code, 'body' => '', 'error' => $err ?: 'SMS request failed'];
        }
        $body = (string) $body;
        $parsed = sms_interpret_gateway_response($code, $body);
        if (!$parsed['ok']) {
            return [
                'ok' => false,
                'http_code' => $code,
                'body' => $body,
                'error' => $parsed['error'] ?? 'Gateway rejected SMS',
            ];
        }
        return ['ok' => true, 'http_code' => $code, 'body' => $body];
    }

    $ctx = stream_context_create([
        'http' => [
            'timeout' => $timeoutSec,
            'ignore_errors' => true,
        ],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
        $code = (int) $m[1];
    }
    if ($body === false) {
        return ['ok' => false, 'http_code' => $code, 'body' => '', 'error' => 'SMS request failed'];
    }
    $body = (string) $body;
    $parsed = sms_interpret_gateway_response($code, $body);
    if (!$parsed['ok']) {
        return [
            'ok' => false,
            'http_code' => $code,
            'body' => $body,
            'error' => $parsed['error'] ?? 'Gateway rejected SMS',
        ];
    }
    return ['ok' => true, 'http_code' => $code, 'body' => $body];
}

function sms_build_login_otp_message($otp) {
    $cfg = sms_load_config();
    $tpl = $cfg['otp_message_template'] ??
        'Your OTP for MiCampus login is {OTP}. Please do not share this code with anyone. Valid for 10 minutes. Micampus.co.in';
    return str_replace('{OTP}', (string) $otp, $tpl);
}

/**
 * DLT-approved password-reset OTP body (template ID DLT_TEMPLATE_FORGOT_PASSWORD_OTP).
 * Line breaks / wording must match the registered template exactly.
 */
function sms_build_forgot_password_otp_message($otp) {
    $cfg = sms_load_config();
    $tpl = $cfg['forgot_password_otp_message_template'] ??
        "Your OTP for MiCampus password reset is {OTP}.\nPlease do not share this code with anyone.\nValid for 10 minutes.\nMicampus.co.in";
    return str_replace('{OTP}', (string) $otp, $tpl);
}

/**
 * ConnectBind overrides for forgot-password OTP (dedicated DLT template).
 *
 * @return array{template_id: string, tmid?: string}
 */
function sms_forgot_password_otp_template_overrides(): array
{
    $cfg = sms_load_config();
    $tid = trim((string) ($cfg['forgot_password_otp_template_id'] ?? ''));
    if ($tid === '') {
        $tid = DLT_TEMPLATE_FORGOT_PASSWORD_OTP;
    }
    $overrides = ['template_id' => $tid];
    $tmidExtra = trim((string) ($cfg['forgot_password_otp_tmid'] ?? ''));
    if ($tmidExtra !== '') {
        $overrides['tmid'] = $tmidExtra;
    }
    return $overrides;
}

/**
 * Send login / password-reset OTP SMS (used by users.php, forgot_password.php, and background worker).
 * Same ConnectBind path as login; password_reset_otp only swaps DLT template_id.
 *
 * @param array<string,mixed> $payload destination (91XXXXXXXXXX), message, optional user_id, job_id, purpose
 * @param mysqli|null $conn
 */
function process_job_login_otp_sms(array $payload, $conn = null, int $timeoutSec = 10): void
{
    $dest = (string) ($payload['destination'] ?? '');
    $message = (string) ($payload['message'] ?? '');
    $userId = isset($payload['user_id']) ? (int) $payload['user_id'] : null;
    $jobId = isset($payload['job_id']) ? (int) $payload['job_id'] : null;
    $purpose = trim((string) ($payload['purpose'] ?? 'login_otp'));
    if ($purpose === '') {
        $purpose = 'login_otp';
    }
    if ($dest === '' || $message === '') {
        throw new InvalidArgumentException('destination and message required');
    }
    if (!preg_match('/^91\d{10}$/', $dest)) {
        throw new InvalidArgumentException('destination must be 91XXXXXXXXXX, got: ' . $dest);
    }

    // Login uses default config template_id; forgot-password uses dedicated DLT template.
    $overrides = ($purpose === 'password_reset_otp')
        ? sms_forgot_password_otp_template_overrides()
        : null;

    $send = sms_send_connectbind($dest, $message, $overrides, $timeoutSec);
    sms_log_attempt($conn, $purpose, $dest, $send, $jobId, $userId > 0 ? $userId : null);
    if (!$send['ok']) {
        $detail = $send['error'] ?? '';
        if ($detail === '' && !empty($send['body'])) {
            $detail = (string) $send['body'];
        }
        throw new RuntimeException(
            'SMS failed http=' . (int) ($send['http_code'] ?? 0) . ' ' . $detail
        );
    }
}

/**
 * Plain text must match the registered DLT template; {#var#} is the event name placeholder.
 */
function sms_build_event_created_message($eventName) {
    $cfg = sms_load_config();
    $tpl = (string) ($cfg['event_created_message_template'] ??
        "A new event has been created successfully.\nEvent Name: {#var#}\nPlease login to the admin panel for more details.\n-Team MiCampus");
    $name = trim(preg_replace('/\s+/u', ' ', (string) $eventName));
    if (function_exists('mb_substr')) {
        $name = mb_substr($name, 0, 100, 'UTF-8');
    } else {
        $name = substr($name, 0, 100);
    }
    return str_replace('{#var#}', $name, $tpl);
}

/**
 * @param mysqli $conn
 * @return list<string> Distinct destinations as 91XXXXXXXXXX
 */
function sms_admin_event_notify_destinations($conn) {
    $cfg = sms_load_config();
    $seen = [];
    $extra = $cfg['admin_event_notify_phones'] ?? [];
    if (!is_array($extra)) {
        $extra = [];
    }
    foreach ($extra as $p) {
        $n = sms_normalize_india_mobile($p);
        if ($n !== null) {
            $seen[$n] = true;
        }
    }
    $q = @$conn->query("SHOW COLUMNS FROM `admins` LIKE 'phone'");
    if ($q && $q->num_rows > 0) {
        $r = @$conn->query("SELECT DISTINCT TRIM(phone) AS p FROM admins WHERE phone IS NOT NULL AND TRIM(phone) <> ''");
        if ($r) {
            while ($row = $r->fetch_assoc()) {
                $n = sms_normalize_india_mobile($row['p']);
                if ($n !== null) {
                    $seen[$n] = true;
                }
            }
        }
    }
    $q = @$conn->query("SHOW COLUMNS FROM `subadmins` LIKE 'phone'");
    if ($q && $q->num_rows > 0) {
        $r = @$conn->query("SELECT DISTINCT TRIM(phone) AS p FROM subadmins WHERE status = 'active' AND phone IS NOT NULL AND TRIM(phone) <> ''");
        if ($r) {
            while ($row = $r->fetch_assoc()) {
                $n = sms_normalize_india_mobile($row['p']);
                if ($n !== null) {
                    $seen[$n] = true;
                }
            }
        }
    }
    return array_keys($seen);
}

/**
 * Notify admins by SMS when a host creates an event (non-blocking for API: failures are ignored).
 */
function sms_notify_admins_event_created($conn, $eventTitle) {
    $cfg = sms_load_config();
    $tid = trim((string) ($cfg['event_created_template_id'] ?? ''));
    if ($tid === '') {
        return;
    }
    $phones = sms_admin_event_notify_destinations($conn);
    if ($phones === []) {
        return;
    }
    $msg = sms_build_event_created_message($eventTitle);
    $tmidExtra = trim((string) ($cfg['event_created_tmid'] ?? ''));
    $overrides = ['template_id' => $tid];
    if ($tmidExtra !== '') {
        $overrides['tmid'] = $tmidExtra;
    }
    foreach ($phones as $dest) {
        sms_send_connectbind($dest, $msg, $overrides);
    }
}
