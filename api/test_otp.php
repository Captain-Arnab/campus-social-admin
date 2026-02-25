<?php
echo "<h2>Testing OTP Email Service</h2>";

$url = 'https://exdeos.com/AS/campus_social/api/send_email_otp.php';

$data = json_encode([
    'email' => 'arnab.vgs@gmail.com',
    'otp' => '123456',
    'sender_email' => 'micampusco@gmail.com',
    'sender_password' => 'rjhtknajobpkisob'
]);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Content-Length: ' . strlen($data)
]);

$result = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "<p><strong>HTTP Code:</strong> $httpCode</p>";
echo "<p><strong>Response:</strong></p>";
echo "<pre>" . htmlspecialchars($result) . "</pre>";

if ($error) {
    echo "<p><strong>Error:</strong> $error</p>";
}

if ($result) {
    echo "<p><strong>Decoded JSON:</strong></p>";
    echo "<pre>" . print_r(json_decode($result, true), true) . "</pre>";
}
?>