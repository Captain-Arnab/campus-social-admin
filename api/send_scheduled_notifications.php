<?php
/**
 * Send scheduled greeting notifications at 10 AM IST.
 * On listed days (notification_dates + celebration_days), every user gets a notification.
 *
 * Run via cron at 10:00 AM IST, e.g.:
 *   0 10 * * * cd /path/to/api && php send_scheduled_notifications.php
 *
 * UTC example (10 AM IST = 04:30 UTC):
 *   30 4 * * * cd /path/to/api && php send_scheduled_notifications.php
 */
if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json');
}

date_default_timezone_set('Asia/Kolkata');

// Copy this file to your api/ folder (same directory as db.php and fcm_helper.php).
define('FCM_HELPER_LOADED', true);
require_once __DIR__ . '/fcm_helper.php';
require_once __DIR__ . '/db.php';

if (!isset($conn) || !$conn) {
    exit_with("Database connection failed", 1);
}

$today = date('Y-m-d');
$totalSent = 0;
$totalFailed = 0;

// Get all FCM tokens (every user gets greetings on listed days)
$tres = $conn->query("SELECT fcm_token FROM user_fcm_tokens");
$allTokens = [];
if ($tres) {
    while ($tr = $tres->fetch_assoc()) {
        $allTokens[] = $tr['fcm_token'];
    }
}
$allTokens = array_unique(array_filter($allTokens));

if (empty($allTokens)) {
    exit_with("No FCM tokens found. No notifications sent for $today.", 0);
}

// 1) Greetings from notification_dates (today)
$stmt = $conn->prepare("
    SELECT nd.id, nd.event_id, nd.notify_date, nd.title, nd.message, e.title AS event_title, e.venue
    FROM notification_dates nd
    LEFT JOIN events e ON e.id = nd.event_id
    WHERE nd.notify_date = ?
    ORDER BY nd.id
");
$stmt->bind_param('s', $today);
$stmt->execute();
$result = $stmt->get_result();
$notificationRows = [];
while ($row = $result->fetch_assoc()) {
    $notificationRows[] = $row;
}
$stmt->close();

foreach ($notificationRows as $nd) {
    $title = !empty($nd['title']) ? $nd['title'] : ($nd['event_title'] ?? 'MiCampus');
    $body = !empty($nd['message']) ? $nd['message'] : (
        isset($nd['event_title'], $nd['venue'])
            ? $nd['event_title'] . ' at ' . $nd['venue']
            : 'Have a great day!'
    );
    $data = ['type' => 'greeting', 'event_id' => (string)($nd['event_id'] ?: '')];
    $out = fcm_send_to_tokens($allTokens, $title, $body, $data);
    $totalSent += $out['success'];
    $totalFailed += $out['failed'];
}

// 2) Greetings from celebration_days (today) – e.g. "Happy Holi!"
$celebrations = [];
$tableCheck = $conn->query("SHOW TABLES LIKE 'celebration_days'");
if ($tableCheck && $tableCheck->num_rows > 0) {
    $cstmt = $conn->prepare("SELECT occasion_name FROM celebration_days WHERE occasion_date = ?");
    $cstmt->bind_param('s', $today);
    $cstmt->execute();
    $cres = $cstmt->get_result();
    while ($crow = $cres->fetch_assoc()) {
        $celebrations[] = $crow['occasion_name'];
    }
    $cstmt->close();
}

foreach ($celebrations as $occasion) {
    $title = 'MiCampus';
    $body = 'Happy ' . $occasion . '!';
    $data = ['type' => 'greeting', 'occasion' => $occasion];
    $out = fcm_send_to_tokens($allTokens, $title, $body, $data);
    $totalSent += $out['success'];
    $totalFailed += $out['failed'];
}

$conn->close();

$totalNotifications = count($notificationRows) + count($celebrations);
if ($totalNotifications === 0) {
    exit_with("No listed days for today ($today). No greetings sent.", 0);
}

exit_with("Greetings sent for $today: $totalNotifications message(s), delivered to $totalSent token(s), failed: $totalFailed.", 0);

function exit_with($msg, $code) {
    if (php_sapi_name() === 'cli') {
        echo $msg . PHP_EOL;
    } else {
        echo json_encode(['status' => $code === 0 ? 'ok' : 'error', 'message' => $msg]);
    }
    exit($code);
}
