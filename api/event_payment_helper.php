<?php
/**
 * Event payment helpers.
 *
 * Ledger: event_payments (source of truth for gateway).
 * Join rows: payment_status enum n/a|pending|paid on attendees/participant/volunteers.
 *
 * GATEWAY SWAP POINT
 * ------------------
 * Today: mock gateway (no merchant credentials yet).
 * Later: set keys in razorpay_config.local.php — when configured, live Razorpay is used
 * automatically unless 'force_mock' => true in that file.
 *
 * Only payment_gateway_create_order() and payment_gateway_verify_payment() need to change
 * for a new provider; confirm_intent / verify surrounding logic stays the same.
 */

function razorpay_config(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }
    $cfg = [
        'key_id' => '',
        'key_secret' => '',
        'currency' => 'INR',
        'force_mock' => false,
    ];
    $local = __DIR__ . '/razorpay_config.local.php';
    if (is_file($local)) {
        $loaded = include $local;
        if (is_array($loaded)) {
            $cfg = array_merge($cfg, $loaded);
        }
    }
    return $cfg;
}

function razorpay_is_configured(): bool
{
    $c = razorpay_config();
    return trim((string) ($c['key_id'] ?? '')) !== '' && trim((string) ($c['key_secret'] ?? '')) !== '';
}

/** True while merchant credentials are absent (or force_mock). */
function payment_gateway_is_mock(): bool
{
    $c = razorpay_config();
    if (!empty($c['force_mock'])) {
        return true;
    }
    return !razorpay_is_configured();
}

function schema_event_payments_has_is_mock($conn): bool
{
    static $has = null;
    if ($has !== null) {
        return $has;
    }
    $r = @$conn->query("SHOW COLUMNS FROM event_payments LIKE 'is_mock'");
    $has = $r && $r->num_rows > 0;
    return $has;
}

/**
 * Create a gateway order. Swap body for real provider later.
 *
 * @return array{ok:bool,order_id?:string,is_mock?:bool,error?:string,http?:int,raw?:array}
 */
function payment_gateway_create_order(float $amount, string $receipt, array $notes = []): array
{
    if (payment_gateway_is_mock()) {
        // --- MOCK (active until Razorpay keys are configured) ---
        $orderId = 'MOCK_ORDER_' . str_replace('.', '', uniqid('', true));
        return [
            'ok' => true,
            'order_id' => $orderId,
            'is_mock' => true,
            'raw' => [
                'id' => $orderId,
                'amount' => (int) round($amount * 100),
                'currency' => razorpay_config()['currency'] ?? 'INR',
                'receipt' => $receipt,
                'notes' => $notes,
                'mock' => true,
            ],
        ];
    }

    // --- LIVE Razorpay ---
    $live = razorpay_create_order($amount, $receipt, $notes);
    if (!$live['ok']) {
        return $live;
    }
    return [
        'ok' => true,
        'order_id' => (string) $live['order']['id'],
        'is_mock' => false,
        'raw' => $live['order'],
    ];
}

/**
 * Verify a payment. Mock uses simulate=success|failure; live uses Razorpay signature.
 *
 * @param array<string,mixed> $data request payload
 * @param array<string,mixed> $paymentRow pending event_payments row
 * @return array{ok:bool,payment_id?:string,failed?:bool,error?:string}
 */
function payment_gateway_verify_payment(array $data, array $paymentRow): array
{
    $orderFromRow = (string) ($paymentRow['gateway_order_id'] ?? '');
    $isMock = !empty($paymentRow['is_mock'])
        || (strpos($orderFromRow, 'MOCK_ORDER_') === 0)
        || payment_gateway_is_mock();

    if ($isMock) {
        // --- MOCK ---
        $simulate = strtolower(trim((string) ($data['simulate'] ?? '')));
        if ($simulate === '') {
            return [
                'ok' => false,
                'error' => 'Mock payment requires simulate=success or simulate=failure',
            ];
        }
        if ($simulate === 'failure' || $simulate === 'fail' || $simulate === 'failed') {
            return ['ok' => false, 'failed' => true, 'error' => 'Mock payment simulated as failure'];
        }
        if ($simulate !== 'success' && $simulate !== 'ok') {
            return [
                'ok' => false,
                'error' => 'simulate must be "success" or "failure"',
            ];
        }
        $paymentId = trim((string) ($data['gateway_payment_id'] ?? $data['razorpay_payment_id'] ?? ''));
        if ($paymentId === '') {
            $paymentId = 'MOCK_PAY_' . str_replace('.', '', uniqid('', true));
        }
        return ['ok' => true, 'payment_id' => $paymentId];
    }

    // --- LIVE Razorpay ---
    $orderId = trim((string) ($data['razorpay_order_id'] ?? $data['gateway_order_id'] ?? $data['order_id'] ?? ''));
    $paymentId = trim((string) ($data['razorpay_payment_id'] ?? $data['gateway_payment_id'] ?? ''));
    $signature = trim((string) ($data['razorpay_signature'] ?? $data['signature'] ?? ''));
    if ($orderId === '' || $paymentId === '' || $signature === '') {
        return [
            'ok' => false,
            'error' => 'razorpay_order_id, razorpay_payment_id, and razorpay_signature are required',
        ];
    }
    if (!razorpay_verify_signature($orderId, $paymentId, $signature)) {
        return ['ok' => false, 'error' => 'Payment signature verification failed'];
    }
    return ['ok' => true, 'payment_id' => $paymentId];
}

/**
 * @return array{ok:bool,order?:array,error?:string,http?:int}
 */
function razorpay_create_order(float $amount, string $receipt, array $notes = []): array
{
    if (!razorpay_is_configured()) {
        return [
            'ok' => false,
            'http' => 503,
            'error' => 'Payment gateway is not configured. Ask admin to add Razorpay keys (api/razorpay_config.local.php).',
        ];
    }
    $c = razorpay_config();
    $amountPaise = (int) round($amount * 100);
    if ($amountPaise < 100) {
        return ['ok' => false, 'http' => 400, 'error' => 'Minimum payable amount is ₹1.00'];
    }
    $payload = json_encode([
        'amount' => $amountPaise,
        'currency' => $c['currency'] ?? 'INR',
        'receipt' => substr($receipt, 0, 40),
        'notes' => $notes,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://api.razorpay.com/v1/orders');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_USERPWD => $c['key_id'] . ':' . $c['key_secret'],
        CURLOPT_TIMEOUT => 30,
    ]);
    $body = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false || $http < 200 || $http >= 300) {
        $decoded = is_string($body) ? json_decode($body, true) : null;
        $msg = is_array($decoded) ? (string) ($decoded['error']['description'] ?? $decoded['error']['code'] ?? '') : '';
        if ($msg === '') {
            $msg = $err !== '' ? $err : ('Razorpay order failed (HTTP ' . $http . ')');
        }
        return ['ok' => false, 'http' => 502, 'error' => $msg];
    }
    $order = json_decode($body, true);
    if (!is_array($order) || empty($order['id'])) {
        return ['ok' => false, 'http' => 502, 'error' => 'Invalid Razorpay order response'];
    }
    return ['ok' => true, 'order' => $order];
}

function razorpay_verify_signature(string $orderId, string $paymentId, string $signature): bool
{
    if (!razorpay_is_configured() || $orderId === '' || $paymentId === '' || $signature === '') {
        return false;
    }
    $c = razorpay_config();
    $expected = hash_hmac('sha256', $orderId . '|' . $paymentId, (string) $c['key_secret']);
    return hash_equals($expected, $signature);
}

function schema_events_has_fee_modes($conn): bool
{
    static $has = null;
    if ($has !== null) {
        return $has;
    }
    $r = @$conn->query("SHOW COLUMNS FROM events LIKE 'participate_mode'");
    $has = $r && $r->num_rows > 0;
    return $has;
}

function schema_join_has_payment_status($conn, string $table): bool
{
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }
    $allowed = ['attendees', 'participant', 'volunteers'];
    if (!in_array($table, $allowed, true)) {
        return false;
    }
    $r = @$conn->query("SHOW COLUMNS FROM `$table` LIKE 'payment_status'");
    $cache[$table] = $r && $r->num_rows > 0;
    return $cache[$table];
}

/**
 * @return array{participate_mode:string,participate_fee:?float,attend_mode:string,attend_fee:?float,volunteer_mode:string}
 */
function event_fee_modes_from_row(array $event): array
{
    return [
        'participate_mode' => (string) ($event['participate_mode'] ?? 'without_fee'),
        'participate_fee' => isset($event['participate_fee']) && $event['participate_fee'] !== null && $event['participate_fee'] !== ''
            ? (float) $event['participate_fee'] : null,
        'attend_mode' => (string) ($event['attend_mode'] ?? 'without_fee'),
        'attend_fee' => isset($event['attend_fee']) && $event['attend_fee'] !== null && $event['attend_fee'] !== ''
            ? (float) $event['attend_fee'] : null,
        'volunteer_mode' => (string) ($event['volunteer_mode'] ?? 'enabled'),
    ];
}

function event_role_mode_fee(array $modes, string $role): array
{
    if ($role === 'attendee') {
        return ['mode' => $modes['attend_mode'], 'fee' => $modes['attend_fee']];
    }
    if ($role === 'participant') {
        return ['mode' => $modes['participate_mode'], 'fee' => $modes['participate_fee']];
    }
    $mode = ($modes['volunteer_mode'] ?? 'enabled') === 'disabled' ? 'disabled' : 'without_fee';
    return ['mode' => $mode, 'fee' => null];
}

function event_join_disabled_message(): array
{
    return [
        'status' => 'error',
        'message' => 'This option is not available for this event',
        'option_disabled' => true,
    ];
}

function event_role_is_paid_locked(mysqli $conn, int $eventId, int $userId, string $role): bool
{
    $role = strtolower($role);
    if ($role === 'attend') {
        $role = 'attendee';
    }
    $table = $role === 'attendee' ? 'attendees' : ($role === 'participant' ? 'participant' : ($role === 'volunteer' ? 'volunteers' : ''));
    if ($table !== '' && schema_join_has_payment_status($conn, $table)) {
        if ($table === 'attendees') {
            $q = $conn->prepare("SELECT payment_status FROM attendees WHERE event_id = ? AND user_id = ? LIMIT 1");
        } elseif ($table === 'participant') {
            $q = $conn->prepare("SELECT payment_status FROM participant WHERE event_id = ? AND user_id = ? AND status = 'active' LIMIT 1");
        } else {
            $q = $conn->prepare("SELECT payment_status FROM volunteers WHERE event_id = ? AND user_id = ? AND status = 'active' LIMIT 1");
        }
        if ($q) {
            $q->bind_param('ii', $eventId, $userId);
            $q->execute();
            $row = $q->get_result()->fetch_assoc();
            $q->close();
            if ($row && ($row['payment_status'] ?? '') === 'paid') {
                return true;
            }
        }
    }
    $chk = @$conn->query(
        "SELECT 1 FROM event_payments
         WHERE event_id = " . (int) $eventId . " AND user_id = " . (int) $userId . "
           AND role = '" . $conn->real_escape_string($role) . "' AND status = 'confirmed' LIMIT 1"
    );
    return $chk && $chk->num_rows > 0;
}

function event_user_has_paid_lock(mysqli $conn, int $eventId, int $userId): bool
{
    foreach (['attendee', 'participant', 'volunteer'] as $role) {
        if (event_role_is_paid_locked($conn, $eventId, $userId, $role)) {
            return true;
        }
    }
    return false;
}

/**
 * Current join row for a user on an event (for event GET my_registration).
 * Preference when multiple (should be rare): volunteer → participant → attendee.
 *
 * @return array{role:string,payment_status:string}|null
 */
function event_user_my_registration(mysqli $conn, int $eventId, int $userId): ?array
{
    if ($eventId <= 0 || $userId <= 0) {
        return null;
    }

    $checks = [
        ['role' => 'volunteer', 'table' => 'volunteers', 'extra' => " AND status = 'active'"],
        ['role' => 'participant', 'table' => 'participant', 'extra' => " AND status = 'active'"],
        ['role' => 'attendee', 'table' => 'attendees', 'extra' => ''],
    ];

    foreach ($checks as $c) {
        $psSelect = schema_join_has_payment_status($conn, $c['table'])
            ? 'payment_status'
            : "'n/a' AS payment_status";
        $sql = "SELECT {$psSelect} FROM `{$c['table']}`
                WHERE event_id = ? AND user_id = ?{$c['extra']} LIMIT 1";
        $st = $conn->prepare($sql);
        if (!$st) {
            continue;
        }
        $st->bind_param('ii', $eventId, $userId);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$row) {
            continue;
        }
        $ps = strtolower(trim((string) ($row['payment_status'] ?? 'n/a')));
        if (!in_array($ps, ['n/a', 'pending', 'paid'], true)) {
            $ps = 'n/a';
        }
        // Ledger fallback if join row still n/a but payment confirmed
        if ($ps !== 'paid' && event_role_is_paid_locked($conn, $eventId, $userId, $c['role'])) {
            $ps = 'paid';
        }
        return [
            'role' => $c['role'],
            'payment_status' => $ps,
        ];
    }

    return null;
}

function event_paid_lock_error(): array
{
    return [
        'status' => 'error',
        'message' => 'This registration is paid and cannot be changed or cancelled',
        'payment_locked' => true,
    ];
}

/** @return list<array{id:int,name:string}> */
function volunteer_committees_list(mysqli $conn): array
{
    $out = [];
    $r = @$conn->query("SELECT id, name FROM volunteer_committees WHERE status = 'active' ORDER BY sort_order ASC, name ASC");
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $out[] = ['id' => (int) $row['id'], 'name' => $row['name']];
        }
    }
    return $out;
}

/**
 * Create pending event_payments + gateway order (mock or live).
 *
 * @param array<string,mixed> $meta optional join payload stored until verify
 * @return array response payload (status success|error)
 */
function event_payment_confirm_intent(mysqli $conn, int $eventId, int $userId, string $role, array $meta = []): array
{
    $role = strtolower($role);
    if ($role === 'attend') {
        $role = 'attendee';
    }
    if (!in_array($role, ['attendee', 'participant', 'volunteer'], true)) {
        return ['status' => 'error', 'message' => 'Invalid role'];
    }

    if (!schema_events_has_fee_modes($conn)) {
        return ['status' => 'error', 'message' => 'Paid events not installed. Run uploads/migrations/003_paid_events.sql'];
    }

    $ev = $conn->query(
        "SELECT id, title, participate_mode, participate_fee, attend_mode, attend_fee, volunteer_mode, status
         FROM events WHERE id = " . (int) $eventId . " LIMIT 1"
    );
    if (!$ev || !($event = $ev->fetch_assoc())) {
        return ['status' => 'error', 'message' => 'Event not found'];
    }
    if (($event['status'] ?? '') !== 'approved') {
        return ['status' => 'error', 'message' => 'Event is not open for registration'];
    }

    $modes = event_fee_modes_from_row($event);
    $mf = event_role_mode_fee($modes, $role);
    if ($mf['mode'] === 'disabled') {
        return event_join_disabled_message();
    }
    if ($mf['mode'] !== 'with_fee') {
        return [
            'status' => 'error',
            'message' => 'This option does not require payment. Use the normal join action.',
            'payment_required' => false,
        ];
    }
    $amount = (float) ($mf['fee'] ?? 0);
    if ($amount <= 0) {
        return ['status' => 'error', 'message' => 'Event fee is not configured'];
    }

    // Cancel other pending intents for this user+event so they can change their mind.
    @$conn->query(
        "UPDATE event_payments SET status = 'failed'
         WHERE event_id = " . (int) $eventId . " AND user_id = " . (int) $userId . "
           AND status = 'pending'"
    );

    $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE);
    $isMockFlag = payment_gateway_is_mock() ? 1 : 0;
    $hasIsMock = schema_event_payments_has_is_mock($conn);

    if ($hasIsMock) {
        $stmt = $conn->prepare(
            "INSERT INTO event_payments (event_id, user_id, role, amount, status, is_mock, meta_json)
             VALUES (?, ?, ?, ?, 'pending', ?, ?)"
        );
        if (!$stmt) {
            return ['status' => 'error', 'message' => 'Could not create payment intent'];
        }
        $stmt->bind_param('iisdis', $eventId, $userId, $role, $amount, $isMockFlag, $metaJson);
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO event_payments (event_id, user_id, role, amount, status, meta_json)
             VALUES (?, ?, ?, ?, 'pending', ?)"
        );
        if (!$stmt) {
            return ['status' => 'error', 'message' => 'Could not create payment intent'];
        }
        $stmt->bind_param('iisds', $eventId, $userId, $role, $amount, $metaJson);
    }
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        return ['status' => 'error', 'message' => 'Could not create payment intent: ' . $err];
    }
    $paymentRowId = (int) $stmt->insert_id;
    $stmt->close();

    $receipt = 'ep_' . $paymentRowId;
    $orderRes = payment_gateway_create_order($amount, $receipt, [
        'event_id' => (string) $eventId,
        'user_id' => (string) $userId,
        'role' => $role,
        'payment_row_id' => (string) $paymentRowId,
    ]);
    if (!$orderRes['ok']) {
        @$conn->query("UPDATE event_payments SET status = 'failed' WHERE id = $paymentRowId");
        return [
            'status' => 'error',
            'message' => $orderRes['error'] ?? 'Payment order failed',
            'http' => $orderRes['http'] ?? 500,
        ];
    }
    $orderId = (string) $orderRes['order_id'];
    $esc = $conn->real_escape_string($orderId);
    @$conn->query("UPDATE event_payments SET gateway_order_id = '$esc' WHERE id = $paymentRowId");

    $cfg = razorpay_config();
    $isMock = !empty($orderRes['is_mock']);
    $response = [
        'status' => 'success',
        'message' => $isMock
            ? 'Mock payment intent created — show Simulate Payment UI'
            : 'Payment intent created',
        'payment_required' => true,
        'payment_id' => $paymentRowId,
        'amount' => $amount,
        'currency' => $cfg['currency'] ?? 'INR',
        'order_id' => $orderId,
        'gateway_order_id' => $orderId,
        'razorpay_order_id' => $orderId,
        'event_id' => $eventId,
        'role' => $role,
        'event_title' => (string) ($event['title'] ?? ''),
        'mock_gateway' => $isMock,
        'is_mock' => $isMock ? 1 : 0,
    ];
    if (!$isMock) {
        $response['razorpay_key_id'] = $cfg['key_id'];
    }
    return $response;
}

/**
 * Verify payment (mock simulate or live signature) then invoke $onConfirmed to insert the join row.
 *
 * Mock body: { event_id, user_id, order_id|gateway_order_id, simulate: "success"|"failure", role? }
 * Live body: { ..., razorpay_order_id, razorpay_payment_id, razorpay_signature }
 *
 * @param callable(array $paymentRow, array $meta): array $onConfirmed
 */
function event_payment_verify(mysqli $conn, array $data, callable $onConfirmed): array
{
    $eventId = (int) ($data['event_id'] ?? 0);
    $userId = (int) ($data['user_id'] ?? 0);
    $role = strtolower(trim((string) ($data['role'] ?? '')));
    if ($role === 'attend') {
        $role = 'attendee';
    }
    $orderId = trim((string) (
        $data['order_id']
        ?? $data['gateway_order_id']
        ?? $data['razorpay_order_id']
        ?? ''
    ));

    if ($eventId <= 0 || $userId <= 0 || $orderId === '') {
        return [
            'status' => 'error',
            'message' => 'event_id, user_id, and order_id (or gateway_order_id) are required',
        ];
    }

    $escOrder = $conn->real_escape_string($orderId);
    $rowQ = $conn->query(
        "SELECT * FROM event_payments
         WHERE gateway_order_id = '$escOrder' AND event_id = $eventId AND user_id = $userId
         LIMIT 1"
    );
    if (!$rowQ || !($paymentRow = $rowQ->fetch_assoc())) {
        return ['status' => 'error', 'message' => 'Payment intent not found'];
    }

    if ($role === '' || !in_array($role, ['attendee', 'participant', 'volunteer'], true)) {
        $role = (string) $paymentRow['role'];
    }
    if ((string) $paymentRow['role'] !== $role) {
        return ['status' => 'error', 'message' => 'role does not match payment intent'];
    }

    if (($paymentRow['status'] ?? '') === 'confirmed') {
        $meta = json_decode((string) ($paymentRow['meta_json'] ?? '{}'), true);
        if (!is_array($meta)) {
            $meta = [];
        }
        $result = $onConfirmed($paymentRow, $meta);
        $result['already_confirmed'] = true;
        return $result;
    }
    if (($paymentRow['status'] ?? '') !== 'pending') {
        return ['status' => 'error', 'message' => 'Payment intent is no longer pending'];
    }

    $verified = payment_gateway_verify_payment($data, $paymentRow);
    $payId = (int) $paymentRow['id'];

    if (!empty($verified['failed'])) {
        @$conn->query("UPDATE event_payments SET status = 'failed' WHERE id = $payId");
        return [
            'status' => 'error',
            'message' => $verified['error'] ?? 'Payment failed',
            'payment_failed' => true,
            'registration_confirmed' => false,
        ];
    }
    if (!$verified['ok']) {
        return [
            'status' => 'error',
            'message' => $verified['error'] ?? 'Payment verification failed',
        ];
    }

    $paymentId = (string) ($verified['payment_id'] ?? '');
    $pidEsc = $conn->real_escape_string($paymentId);
    @$conn->query(
        "UPDATE event_payments SET status = 'confirmed', gateway_payment_id = '$pidEsc' WHERE id = $payId"
    );
    $paymentRow['status'] = 'confirmed';
    $paymentRow['gateway_payment_id'] = $paymentId;

    $meta = json_decode((string) ($paymentRow['meta_json'] ?? '{}'), true);
    if (!is_array($meta)) {
        $meta = [];
    }
    $result = $onConfirmed($paymentRow, $meta);
    $result['mock_gateway'] = !empty($paymentRow['is_mock']) || (strpos($orderId, 'MOCK_ORDER_') === 0);
    return $result;
}
