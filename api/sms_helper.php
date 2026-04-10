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
 * @param array<string, string>|null $templateOverrides Keys: template_id, tmid (optional). Uses defaults from config when omitted.
 * @return array{ok: bool, http_code: int, body: string, error?: string}
 */
function sms_send_connectbind($destination91, $plainMessage, $templateOverrides = null) {
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
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            return ['ok' => false, 'http_code' => $code, 'body' => '', 'error' => $err ?: 'SMS request failed'];
        }
        return ['ok' => $code >= 200 && $code < 300, 'http_code' => $code, 'body' => (string) $body];
    }

    $ctx = stream_context_create([
        'http' => [
            'timeout' => 30,
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
    return ['ok' => $code >= 200 && $code < 300, 'http_code' => $code, 'body' => (string) $body];
}

function sms_build_login_otp_message($otp) {
    $cfg = sms_load_config();
    $tpl = $cfg['otp_message_template'] ??
        'Your OTP for MiCampus login is {OTP}. Please do not share this code with anyone. Valid for 10 minutes. Micampus.co.in';
    return str_replace('{OTP}', (string) $otp, $tpl);
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
