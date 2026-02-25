<?php
include 'db.php';
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
                $sf_sql = "INSERT INTO student_faculty (user_id, roll_number, emp_number) 
                          VALUES ($user_id, '$roll_number', 'NA')";
            } else {
                $emp_number = $conn->real_escape_string($data['emp_number']);
                $sf_sql = "INSERT INTO student_faculty (user_id, roll_number, emp_number) 
                          VALUES ($user_id, 'NA', '$emp_number')";
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
    
    // 2. LOGIN
    elseif ($action == 'login') {
        $data = json_decode(file_get_contents("php://input"), true);
        
        if ($data === null) {
            echo json_encode(["status" => "error", "message" => "Invalid JSON data"]);
            exit();
        }
        
        $identifier = $conn->real_escape_string($data['identifier']); // roll_number or emp_number
        $email_or_phone = $conn->real_escape_string($data['email_or_phone']);
        $password = $data['password'];
        $is_student = (int)$data['is_student'];
        $by_mobile = (int)$data['by_mobile'];
        
        // First, find user by identifier in student_faculty table
        $identifier_field = $is_student ? 'roll_number' : 'emp_number';
        $sf_query = "SELECT user_id FROM student_faculty WHERE $identifier_field = '$identifier'";
        $sf_result = $conn->query($sf_query);
        
        if ($sf_result->num_rows == 0) {
            echo json_encode(["status" => "error", "message" => "Invalid " . ($is_student ? "roll number" : "employee ID")]);
            exit();
        }
        
        $sf_row = $sf_result->fetch_assoc();
        $user_id = (int)$sf_row['user_id'];
        
        // Now verify email/phone and password
        $field = $by_mobile ? 'phone' : 'email';
        $user_query = "SELECT id, password, full_name, profile_pic, status, is_student 
                       FROM users 
                       WHERE id = $user_id AND $field = '$email_or_phone'";
        $result = $conn->query($user_query);
        
        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();
            
            // Check if user role matches
            if ((int)$user['is_student'] != $is_student) {
                echo json_encode(["status" => "error", "message" => "Account type mismatch"]);
                exit();
            }
            
            if ($user['status'] == 'blocked') {
                echo json_encode(["status" => "error", "message" => "Account blocked"]);
                exit();
            }
            
            if (password_verify($password, $user['password'])) {
                // Get role-specific information
                $sf_data_result = $conn->query("SELECT roll_number, emp_number FROM student_faculty WHERE user_id = $user_id");
                $sf_data = $sf_data_result->fetch_assoc();
                
                // Generate a simple token (in production use JWT)
                $token = bin2hex(random_bytes(16));
                
                $response = [
                    "status" => "success", 
                    "message" => "Login successful",
                    "user_id" => $user_id,
                    "user_name" => $user['full_name'],
                    "is_student" => (int)$user['is_student'],
                    "token" => $token
                ];
                
                // Add role-specific data
                if ($user['is_student'] == 1) {
                    $response['roll_number'] = $sf_data['roll_number'];
                } else {
                    $response['emp_number'] = $sf_data['emp_number'];
                }
                
                echo json_encode($response);
            } else {
                echo json_encode(["status" => "error", "message" => "Invalid password"]);
            }
        } else {
            $msg = $by_mobile ? "Phone number not found or doesn't match" : "Email not found or doesn't match";
            echo json_encode(["status" => "error", "message" => $msg]);
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
        if ($conn->query($sql)) {
            echo json_encode(["status" => "success", "message" => "Profile updated"]);
        } else {
            echo json_encode(["status" => "error", "message" => "Update failed: " . $conn->error]);
        }
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
        $sf_result = $conn->query("SELECT roll_number, emp_number FROM student_faculty WHERE user_id = $user_id");
        $sf_data = $sf_result->fetch_assoc();

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
                "is_student" => (int)$user['is_student']
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
            $response['data']['roll_number'] = $sf_data['roll_number'];
        } else {
            $response['data']['emp_number'] = $sf_data['emp_number'];
        }
        
        echo json_encode($response);
    } else {
        echo json_encode(["status" => "error", "message" => "User not found"]);
    }
}
?>