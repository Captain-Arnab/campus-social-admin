<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Use absolute path based on current file location
$vendorPath = __DIR__ . '/vendor/autoload.php';

if (!file_exists($vendorPath)) {
    echo json_encode(['status' => 'error', 'message' => 'PHPMailer not installed. Vendor path: ' . $vendorPath]);
    exit;
}

require $vendorPath;

$data = json_decode(file_get_contents('php://input'), true);

$email = $data['email'] ?? '';
$otp = $data['otp'] ?? '';
$senderEmail = $data['sender_email'] ?? '';
$senderPassword = $data['sender_password'] ?? '';

if (empty($email) || empty($otp)) {
    echo json_encode(['status' => 'error', 'message' => 'Email and OTP required']);
    exit;
}

if (empty($senderEmail) || empty($senderPassword)) {
    echo json_encode(['status' => 'error', 'message' => 'Sender email and password required']);
    exit;
}

$mail = new PHPMailer(true);

// Enable debug for troubleshooting (set to 0 in production)
$mail->SMTPDebug = 0;

try {
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = $senderEmail;
    $mail->Password = $senderPassword;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 587;
    $mail->SMTPOptions = [
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
        ]
    ];
    $mail->Timeout = 10;

    $mail->setFrom($senderEmail, 'MiCampusl');
    $mail->addAddress($email);

    $mail->isHTML(true);
    $mail->Subject = 'Your MiCampusl Verification Code';
    $mail->Body = "
        <h2>Email Verification</h2>
        <p>Your verification code is: <strong style='font-size: 24px; color: #FF5F15;'>$otp</strong></p>
        <p>This code will expire in 5 minutes.</p>
        <p>If you didn't request this code, please ignore this email.</p>
    ";

    $mail->send();
    echo json_encode(['status' => 'success', 'message' => 'OTP sent successfully']);
} catch (Exception $e) {
    $errorMsg = $e->getMessage();
    // Add more diagnostic info
    if (strpos($errorMsg, 'authenticate') !== false) {
        $errorMsg .= ' | Check: 1) Gmail app password is correct 2) 2FA is enabled 3) No extra spaces in password';
    }
    echo json_encode(['status' => 'error', 'message' => 'Mailer Error: ' . $errorMsg]);
}
?>