<?php
include 'db.php';
require_once __DIR__ . '/sms_helper.php';
$method = $_SERVER['REQUEST_METHOD'];

// Helper for security
function authorize() {
    $headers = apache_request_headers();
    if (!isset($headers['Authorization'])) {
        http_response_code(401);
        echo json_encode(["status" => "error", "message" => "Unauthorized access"]);
        exit();
    }
}

// --- POST REQUESTS ---
if ($method == 'POST') {
    $action = $_GET['action'] ?? 'register';
    
    // 1. REGISTER
    if ($action == 'register') {
        $data = json_decode(file_get_contents("php://input"), true);
        
        // Check if JSON decode failed
        if ($data === null) {
            echo json_encode(["status" => "error", "message" => "Invalid JSON data"]);
            exit();
        }
        
        // Validate required fields
        $required_fields = ['full_name', 'email', 'phone', 'password', 'is_student'];
        foreach ($required_fields as $field) {
            if (!isset($data[$field]) || $data[$field] === '' || $data[$field] === null) {
                echo json_encode(["status" => "error", "message" => "Field '$field' is required"]);
                exit();
            }
        }
        
        // Validate role-specific fields
        $is_student = (int)$data['is_student'];
        
        if ($is_student == 1) {
            if (!isset($data['roll_number']) || empty($data['roll_number'])) {
                echo json_encode(["status" => "error", "message" => "Roll number is required for students"]);
                exit();
            }
            
            // Check if roll number already exists
            $roll_number = $conn->real_escape_string($data['roll_number']);
            $check_roll = $conn->query("SELECT id FROM student_faculty WHERE roll_number = '$roll_number'");
            if ($check_roll->num_rows > 0) {
                echo json_encode(["status" => "error", "message" => "Roll number already registered"]);
                exit();
            }
        } else {
            if (!isset($data['emp_number']) || empty($data['emp_number'])) {
                echo json_encode(["status" => "error", "message" => "Employee ID is required for faculty"]);
                exit();
            }
            
            // Check if employee number already exists
            $emp_number = $conn->real_escape_string($data['emp_number']);
            $check_emp = $conn->query("SELECT id FROM student_faculty WHERE emp_number = '$emp_number' AND emp_number != 'NA'");
            if ($check_emp->num_rows > 0) {
                echo json_encode(["status" => "error", "message" => "Employee ID already registered"]);
                exit();
            }
        }
        
        $department_class_reg = isset($data['department_class']) ? trim((string) $data['department_class']) : '';
        $department_class_sql = $department_class_reg !== '' ? "'" . $conn->real_escape_string($department_class_reg) . "'" : 'NULL';

        // Sanitize inputs - bio and interests are optional
        $name = $conn->real_escape_string($data['full_name']);
        $email = $conn->real_escape_string($data['email']);
        $phone = $conn->real_escape_string($data['phone']);
        $bio = isset($data['bio']) && !empty($data['bio']) ? $conn->real_escape_string($data['bio']) : '';
        $interests = isset($data['interests']) && !empty($data['interests']) ? $conn->real_escape_string($data['interests']) : '';
        $pass = password_hash($data['password'], PASSWORD_DEFAULT);
        
        // Profile picture is optional
        $profile_pic = isset($data['profile_pic']) && !empty($data['profile_pic']) 
            ? $conn->real_escape_string($data['profile_pic']) 
            : 'default_avatar.png';

        // Check if email or phone already exists
        $check = $conn->query("SELECT id FROM users WHERE email = '$email' OR phone = '$phone'");
        if ($check->num_rows > 0) {
            echo json_encode(["status" => "error", "message" => "Email or Phone already registered"]);
            exit();
        }

        // Insert into users table
        $sql = "INSERT INTO users (full_name, email, phone, password, bio, interests, profile_pic, status, is_student) 
                VALUES ('$name', '$email', '$phone', '$pass', '$bio', '$interests', '$profile_pic', 'active', $is_student)";
        
        if ($conn->query($sql)) {
            $user_id = (int)$conn->insert_id;
            
            // Insert into student_faculty table
            if ($is_student == 1) {
                $roll_number = $conn->real_escape_string($data['roll_number']);
                $sf_sql = "INSERT INTO student_faculty (user_id, roll_number, emp_number, department_class) 
                          VALUES ($user_id, '$roll_number', 'NA', $department_class_sql)";
            } else {
                $emp_number = $conn->real_escape_string($data['emp_number']);
                $sf_sql = "INSERT INTO student_faculty (user_id, roll_number, emp_number, department_class) 
                          VALUES ($user_id, 'NA', '$emp_number', $department_class_sql)";
            }
            
            if ($conn->query($sf_sql)) {
                echo json_encode([
                    "status" => "success", 
                    "message" => "Registration successful",
                    "user_id" => $user_id,
                    "is_student" => $is_student
                ]);
            } else {
                // Rollback user creation if student_faculty insert fails
                $conn->query("DELETE FROM users WHERE id = $user_id");
                echo json_encode(["status" => "error", "message" => "Registration failed - could not save role information: " . $conn->error]);
            }
        } else {
            echo json_encode(["status" => "error", "message" => "Registration failed: " . $conn->error]);
        }
    }
    
    // 2a. SEND LOGIN OTP (SMS; mobile only — same identifier + phone as login)
    elseif ($action == 'send_login_otp') {
        $data = json_decode(file_get_contents("php://input"), true);
        
        if ($data === null) {
            echo json_encode(["status" => "error", "message" => "Invalid JSON data"]);
            exit();
        }
        
        $by_mobile = (int)($data['by_mobile'] ?? 0);
        if ($by_mobile !== 1) {
            echo json_encode(["status" => "error", "message" => "SMS OTP is only available when logging in with mobile"]);
            exit();
        }
        
        $identifier = $conn->real_escape_string($data['identifier'] ?? '');
        $email_or_phone = $data['email_or_phone'] ?? '';
        $is_student = (int)($data['is_student'] ?? 0);
        
        if ($identifier === '' || $email_or_phone === '') {
            echo json_encode(["status" => "error", "message" => "identifier and email_or_phone are required"]);
            exit();
        }
        
        $identifier_field = $is_student ? 'roll_number' : 'emp_number';
        $sf_query = "SELECT user_id FROM student_faculty WHERE $identifier_field = '$identifier'";
        $sf_result = $conn->query($sf_query);
        
        if ($sf_result->num_rows == 0) {
            echo json_encode(["status" => "error", "message" => "Invalid " . ($is_student ? "roll number" : "employee ID")]);
            exit();
        }
        
        $user_id = (int)$sf_result->fetch_assoc()['user_id'];
        $u2 = $conn->query("SELECT id, full_name, phone, status, is_student FROM users WHERE id = $user_id");
        if (!$u2 || $u2->num_rows == 0) {
            echo json_encode(["status" => "error", "message" => "User not found"]);
            exit();
        }
        $user = $u2->fetch_assoc();
        if (!sms_phones_match($email_or_phone, $user['phone'])) {
            echo json_encode(["status" => "error", "message" => "Phone number not found or doesn't match"]);
            exit();
        }
        
        if ((int)$user['is_student'] != $is_student) {
            echo json_encode(["status" => "error", "message" => "Account type mismatch"]);
            exit();
        }
        if ($user['status'] == 'blocked') {
            echo json_encode(["status" => "error", "message" => "Account blocked"]);
            exit();
        }
        
        $dest = sms_normalize_india_mobile($user['phone']);
        if ($dest === null) {
            echo json_encode(["status" => "error", "message" => "Invalid registered mobile number"]);
            exit();
        }
        
        $throttle = $conn->query("SELECT last_sent_at FROM login_otps WHERE user_id = $user_id");
        if ($throttle && $throttle->num_rows > 0) {
            $row = $throttle->fetch_assoc();
            if (!empty($row['last_sent_at'])) {
                $last = strtotime($row['last_sent_at']);
                if ($last !== false && (time() - $last) < 60) {
                    echo json_encode(["status" => "error", "message" => "Please wait a minute before requesting another OTP"]);
                    exit();
                }
            }
        }
        
        $otp = (string) random_int(100000, 999999);
        $hash = password_hash($otp, PASSWORD_DEFAULT);
        $phone_esc = $conn->real_escape_string($dest);
        $hash_esc = $conn->real_escape_string($hash);
        
        $sql = "INSERT INTO login_otps (user_id, phone, otp_hash, expires_at, failed_attempts, last_sent_at)
                VALUES ($user_id, '$phone_esc', '$hash_esc', DATE_ADD(NOW(), INTERVAL 10 MINUTE), 0, NOW())
                ON DUPLICATE KEY UPDATE
                    phone = VALUES(phone),
                    otp_hash = VALUES(otp_hash),
                    expires_at = VALUES(expires_at),
                    failed_attempts = 0,
                    last_sent_at = NOW()";
        
        if (!$conn->query($sql)) {
            echo json_encode([
                "status" => "error",
                "message" => "Could not create OTP. Ensure the login_otps table exists (see migrations/002_login_otp.sql).",
                "detail" => $conn->error
            ]);
            exit();
        }
        
        $message = sms_build_login_otp_message($otp);
        $send = sms_send_connectbind($dest, $message);
        if (!$send['ok']) {
            echo json_encode([
                "status" => "error",
                "message" => "SMS could not be sent",
                "detail" => $send['error'] ?? $send['body']
            ]);
            exit();
        }
        
        echo json_encode(["status" => "success", "message" => "OTP sent to your registered mobile number"]);
    }
    
    // 2. LOGIN (password or OTP when by_mobile=1)
    elseif ($action == 'login') {
        $data = json_decode(file_get_contents("php://input"), true);
        
        if ($data === null) {
            echo json_encode(["status" => "error", "message" => "Invalid JSON data"]);
            exit();
        }
        
        $identifier = $conn->real_escape_string($data['identifier']); // roll_number or emp_number
        $contact_raw = trim((string)($data['email_or_phone'] ?? ''));
        $password = $data['password'] ?? '';
        $is_student = (int)$data['is_student'];
        $by_mobile = (int)$data['by_mobile'];
        $otp_input = isset($data['otp']) ? trim((string) $data['otp']) : '';
        $use_otp = $otp_input !== '';
        
        if ($contact_raw === '') {
            echo json_encode(["status" => "error", "message" => "email_or_phone is required"]);
            exit();
        }
        
        $identifier_field = $is_student ? 'roll_number' : 'emp_number';
        $sf_query = "SELECT user_id FROM student_faculty WHERE $identifier_field = '$identifier'";
        $sf_result = $conn->query($sf_query);
        
        if ($sf_result->num_rows == 0) {
            echo json_encode(["status" => "error", "message" => "Invalid " . ($is_student ? "roll number" : "employee ID")]);
            exit();
        }
        
        $user_id = (int)$sf_result->fetch_assoc()['user_id'];
        
        $user_res = $conn->query("SELECT id, password, full_name, profile_pic, status, is_student, phone, email FROM users WHERE id = $user_id");
        if (!$user_res || $user_res->num_rows == 0) {
            echo json_encode(["status" => "error", "message" => "User not found"]);
            exit();
        }
        $user = $user_res->fetch_assoc();
        
        if ($by_mobile) {
            if (!sms_phones_match($contact_raw, $user['phone'])) {
                echo json_encode(["status" => "error", "message" => "Phone number not found or doesn't match"]);
                exit();
            }
        } else {
            if (strcasecmp($contact_raw, trim($user['email'])) !== 0) {
                echo json_encode(["status" => "error", "message" => "Email not found or doesn't match"]);
                exit();
            }
        }
        
        if ((int)$user['is_student'] != $is_student) {
            echo json_encode(["status" => "error", "message" => "Account type mismatch"]);
            exit();
        }
        
        if ($user['status'] == 'blocked') {
            echo json_encode(["status" => "error", "message" => "Account blocked"]);
            exit();
        }
        
        $issue_token = function () use ($conn, $user_id, $user) {
            $sf_data_result = $conn->query("SELECT roll_number, emp_number FROM student_faculty WHERE user_id = $user_id");
            $sf_data = $sf_data_result->fetch_assoc();
            $token = bin2hex(random_bytes(16));
            $response = [
                "status" => "success",
                "message" => "Login successful",
                "user_id" => $user_id,
                "user_name" => $user['full_name'],
                "is_student" => (int)$user['is_student'],
                "token" => $token
            ];
            if ($user['is_student'] == 1) {
                $response['roll_number'] = $sf_data['roll_number'];
            } else {
                $response['emp_number'] = $sf_data['emp_number'];
            }
            return $response;
        };
        
        if ($use_otp) {
            if (!$by_mobile) {
                echo json_encode(["status" => "error", "message" => "OTP login requires mobile (by_mobile = 1)"]);
                exit();
            }
            if (!preg_match('/^\d{6}$/', $otp_input)) {
                echo json_encode(["status" => "error", "message" => "Enter the 6-digit OTP"]);
                exit();
            }
            $otp_res = $conn->query("SELECT phone, otp_hash, expires_at, failed_attempts FROM login_otps WHERE user_id = $user_id");
            if (!$otp_res || $otp_res->num_rows == 0) {
                echo json_encode(["status" => "error", "message" => "No OTP found. Request a new code."]);
                exit();
            }
            $orow = $otp_res->fetch_assoc();
            if (strtotime($orow['expires_at']) < time()) {
                echo json_encode(["status" => "error", "message" => "OTP expired. Request a new code."]);
                exit();
            }
            if ((int)$orow['failed_attempts'] >= 5) {
                echo json_encode(["status" => "error", "message" => "Too many failed attempts. Request a new OTP."]);
                exit();
            }
            if (!password_verify($otp_input, $orow['otp_hash'])) {
                $conn->query("UPDATE login_otps SET failed_attempts = failed_attempts + 1 WHERE user_id = $user_id");
                echo json_encode(["status" => "error", "message" => "Invalid OTP"]);
                exit();
            }
            $conn->query("DELETE FROM login_otps WHERE user_id = $user_id");
            echo json_encode($issue_token());
        } else {
            if ($password === '' || $password === null) {
                echo json_encode(["status" => "error", "message" => "Password required, or use OTP with the SMS flow"]);
                exit();
            }
            if (password_verify($password, $user['password'])) {
                echo json_encode($issue_token());
            } else {
                echo json_encode(["status" => "error", "message" => "Invalid password"]);
            }
        }
    }

    // 3. UPDATE DETAILS
    elseif ($action == 'update_details') {
        authorize();
        $data = json_decode(file_get_contents("php://input"), true);
        $id = intval($data['user_id']);
        $interests = $conn->real_escape_string($data['interests']); 
        $bio = $conn->real_escape_string($data['bio'] ?? '');

        $sql = "UPDATE users SET interests='$interests', bio='$bio' WHERE id=$id";
        if (!$conn->query($sql)) {
            echo json_encode(["status" => "error", "message" => "Update failed: " . $conn->error]);
            exit();
        }
        if (array_key_exists('department_class', $data)) {
            $dc = trim((string) $data['department_class']);
            $dc_sql = $dc === '' ? 'NULL' : "'" . $conn->real_escape_string($dc) . "'";
            $conn->query("UPDATE student_faculty SET department_class = $dc_sql WHERE user_id = $id");
        }
        echo json_encode(["status" => "success", "message" => "Profile updated"]);
    }

    // 4. UPLOAD PICTURE
    elseif ($action == 'upload_pic') {
        authorize();
        $user_id = intval($_POST['user_id']);
        
        if (isset($_FILES['profile_pic'])) {
            $file = $_FILES['profile_pic'];
            if ($file['size'] > 2 * 1024 * 1024) {
                echo json_encode(["status" => "error", "message" => "File exceeds 2MB limit"]);
                exit();
            }
            
            if (!is_dir('../uploads/profiles/')) {
                mkdir('../uploads/profiles/', 0777, true);
            }

            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = "profile_" . $user_id . "_" . time() . "." . $ext;
            $path = "../uploads/profiles/" . $filename;
            
            if (move_uploaded_file($file['tmp_name'], $path)) {
                $conn->query("UPDATE users SET profile_pic='$filename' WHERE id=$user_id");
                echo json_encode(["status" => "success", "url" => $filename]);
            } else {
                echo json_encode(["status" => "error", "message" => "Upload failed"]);
            }
        } else {
            echo json_encode(["status" => "error", "message" => "No file uploaded"]);
        }
    }
}

// --- GET REQUESTS ---
if ($method == 'GET') {
    authorize();
    $id = intval($_GET['id']);
    
    $user = $conn->query("SELECT id, full_name, email, phone, bio, interests, profile_pic, is_student FROM users WHERE id=$id")->fetch_assoc();
    
    if ($user) {
        $user_id = (int)$user['id'];
        $created = (int)$conn->query("SELECT count(*) as c FROM events WHERE organizer_id=$user_id")->fetch_assoc()['c'];
        $attended = (int)$conn->query("SELECT count(*) as c FROM attendees WHERE user_id=$user_id")->fetch_assoc()['c'];
        $favorites = (int)$conn->query("SELECT count(*) as c FROM favorites WHERE user_id=$user_id")->fetch_assoc()['c'];
        $volunteering = (int)$conn->query("SELECT count(*) as c FROM volunteers WHERE user_id=$user_id")->fetch_assoc()['c'];
        $participating = (int)$conn->query("SELECT count(*) as c FROM participant WHERE user_id=$user_id")->fetch_assoc()['c'];
        
        // Get role-specific information
        $sf_result = $conn->query("SELECT roll_number, emp_number, department_class FROM student_faculty WHERE user_id = $user_id");
        $sf_data = $sf_result ? $sf_result->fetch_assoc() : null;

        $response = [
            "status" => "success",
            "data" => [
                "id" => $user_id,
                "full_name" => $user['full_name'],
                "email" => $user['email'],
                "phone" => $user['phone'],
                "bio" => $user['bio'],
                "interests" => $user['interests'],
                "profile_pic" => $user['profile_pic'],
                "is_student" => (int)$user['is_student'],
                "department_class" => $sf_data['department_class'] ?? null
            ],
            "stats" => [
                "created" => $created,
                "attended" => $attended,
                "favorites" => $favorites,
                "volunteering" => $volunteering,
                "participating" => $participating
            ]
        ];
        
        // Add role-specific data
        if ($user['is_student'] == 1) {
            $response['data']['roll_number'] = $sf_data['roll_number'] ?? '';
        } else {
            $response['data']['emp_number'] = $sf_data['emp_number'] ?? '';
        }
        
        echo json_encode($response);
    } else {
        echo json_encode(["status" => "error", "message" => "User not found"]);
    }
}
?>