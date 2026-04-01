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
    $local = __DIR__ . '/sms_config.local.php';
    if (is_readable($local)) {
        $cfg = include $local;
        if (is_array($cfg)) {
            return $cfg;
        }
    }
    return [
        'base_url' => getenv('SMS_GATEWAY_BASE_URL') ?: '',
        'username' => getenv('SMS_GATEWAY_USERNAME') ?: '',
        'password' => getenv('SMS_GATEWAY_PASSWORD') ?: '',
        'sender_id' => getenv('SMS_GATEWAY_SENDER_ID') ?: 'MiCamp',
        'entity_id' => getenv('SMS_GATEWAY_ENTITY_ID') ?: '',
        'template_id' => getenv('SMS_GATEWAY_TEMPLATE_ID') ?: '',
        'tmid' => getenv('SMS_GATEWAY_TMID') ?: '',
        'otp_message_template' => getenv('SMS_OTP_MESSAGE_TEMPLATE') ?:
            'Your OTP for MiCampus login is {OTP}. Please do not share this code with anyone. Valid for 10 minutes. Micampus.co.in',
    ];
}

/**
 * @return array{ok: bool, http_code: int, body: string, error?: string}
 */
function sms_send_connectbind($destination91, $plainMessage) {
    $cfg = sms_load_config();
    $required = ['base_url', 'username', 'password', 'entity_id', 'template_id', 'tmid'];
    foreach ($required as $k) {
        if (empty($cfg[$k])) {
            return ['ok' => false, 'http_code' => 0, 'body' => '', 'error' => 'SMS gateway not configured'];
        }
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
        'tempid' => $cfg['template_id'],
        'tmid' => $cfg['tmid'],
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
