<?php
echo "<h2>SMTP Connection Diagnostic Test</h2>";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$vendorPath = __DIR__ . '/vendor/autoload.php';

if (!file_exists($vendorPath)) {
    echo "<p style='color: red;'><strong>Error:</strong> PHPMailer not installed. Path: " . $vendorPath . "</p>";
    exit;
}

require $vendorPath;

// Test credentials
$senderEmail = 'micampusco@gmail.com';
$senderPassword = 'rjhtknajobpkisob';

echo "<p><strong>Testing with:</strong></p>";
echo "<ul>";
echo "<li>Email: " . htmlspecialchars($senderEmail) . "</li>";
echo "<li>Password: " . str_repeat("*", strlen($senderPassword) - 4) . substr($senderPassword, -4) . " (length: " . strlen($senderPassword) . ")</li>";
echo "</ul>";

$mail = new PHPMailer(true);

// Enable verbose debugging
$mail->SMTPDebug = 2;

// Capture debug output
ob_start();

try {
    echo "<p><strong>Connecting to SMTP...</strong></p>";
    
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = $senderEmail;
    $mail->Password = $senderPassword;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 587;
    $mail->Timeout = 10;
    $mail->SMTPOptions = [
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
        ]
    ];

    // Try to authenticate (don't send email yet)
    $mail->smtpConnect();
    
    echo "<p style='color: green;'><strong>✓ SMTP Connection Successful!</strong></p>";
    echo "<p style='color: green;'>✓ Authentication Successful!</p>";
    
    $mail->smtpClose();
    
} catch (Exception $e) {
    echo "<p style='color: red;'><strong>✗ Connection Failed:</strong></p>";
    echo "<p style='color: red;'>" . htmlspecialchars($e->getMessage()) . "</p>";
    
    // Get debug output
    $debugOutput = ob_get_clean();
    if ($debugOutput) {
        echo "<hr>";
        echo "<p><strong>Debug Output:</strong></p>";
        echo "<pre style='background: #f5f5f5; padding: 10px; overflow-x: auto;'>" . htmlspecialchars($debugOutput) . "</pre>";
    }
    exit;
}

$debugOutput = ob_get_clean();
if ($debugOutput) {
    echo "<hr>";
    echo "<p><strong>Debug Output:</strong></p>";
    echo "<pre style='background: #f5f5f5; padding: 10px; overflow-x: auto;'>" . htmlspecialchars($debugOutput) . "</pre>";
}

echo "<hr>";
echo "<p style='color: green;'><strong>Recommendation:</strong> Your SMTP connection is working! The issue might be:</p>";
echo "<ul>";
echo "<li>The recipient email address is invalid</li>";
echo "<li>Email is being marked as spam by Gmail</li>";
echo "<li>Check your 'Sent Mail' folder in Gmail</li>";
echo "</ul>";
?>
