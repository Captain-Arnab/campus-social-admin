<?php
include 'db.php';

// Endpoint: POST /forgot_password.php?action=check_email OR action=reset

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $data = json_decode(file_get_contents("php://input"), true);
    $action = $_GET['action'] ?? 'check_email';

    // Step 1: Verify Email
    if ($action == 'check_email') {
        $email = $conn->real_escape_string($data['email']);
        $check = $conn->query("SELECT id FROM users WHERE email = '$email'");
        
        if ($check->num_rows > 0) {
            // In a real app, send OTP email here
            echo json_encode(["status" => "success", "message" => "Email found. Please enter new password."]);
        } else {
            echo json_encode(["status" => "error", "message" => "Email not registered"]);
        }
    }

    // Step 2: Reset Password
    elseif ($action == 'reset') {
        $email = $conn->real_escape_string($data['email']);
        $new_pass = password_hash($data['password'], PASSWORD_DEFAULT);
        
        $sql = "UPDATE users SET password = '$new_pass' WHERE email = '$email'";
        if ($conn->query($sql)) {
            echo json_encode(["status" => "success", "message" => "Password changed successfully"]);
        } else {
            echo json_encode(["status" => "error", "message" => "Update failed"]);
        }
    }
}
?>