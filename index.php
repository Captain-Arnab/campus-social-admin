<?php
session_start();
include 'db.php';

$login_error = false;

if (isset($_POST['login'])) {
    $username = $_POST['username'];
    $password = $_POST['password'];

    // First check in admins table
    $admin_sql = "SELECT * FROM admins WHERE username='$username' AND password='$password'";
    $admin_result = $conn->query($admin_sql);

    if ($admin_result->num_rows > 0) {
        // Admin login successful
        $_SESSION['admin'] = $username;
        $_SESSION['user_type'] = 'admin';
        header("Location: dashboard.php?msg=welcome");
        exit();
    } else {
        // Check in subadmins table
        $subadmin_sql = "SELECT * FROM subadmins WHERE username='$username' AND password='$password' AND status='active'";
        $subadmin_result = $conn->query($subadmin_sql);

        if ($subadmin_result->num_rows > 0) {
            $subadmin = $subadmin_result->fetch_assoc();
            $_SESSION['subadmin'] = $username;
            $_SESSION['subadmin_name'] = $subadmin['full_name'];
            $_SESSION['subadmin_id'] = $subadmin['id'];
            $_SESSION['user_type'] = 'subadmin';
            header("Location: dashboard.php?msg=welcome");
            exit();
        } else {
            $login_error = true;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Admin Login | Campus Social</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    
    <style>
        :root {
            --brand-color: #FF5F15;
            --brand-hover: #e04e0b;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }

        body::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: 
                radial-gradient(circle at 20% 50%, rgba(255, 95, 21, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 80% 80%, rgba(102, 126, 234, 0.1) 0%, transparent 50%);
            animation: pulse 15s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        .login-container {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 450px;
            padding: 20px;
        }

        .login-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 24px;
            padding: 50px 45px;
            box-shadow: 
                0 20px 60px rgba(0, 0, 0, 0.15),
                0 0 0 1px rgba(255, 255, 255, 0.1);
            text-align: center;
            animation: slideUp 0.6s ease-out;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .logo-container {
            margin-bottom: 35px;
        }

        .login-logo {
            height: 80px;
            width: auto;
            max-width: 240px;
            object-fit: contain;
            margin-bottom: 15px;
            animation: fadeIn 0.8s ease-out 0.2s both;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: scale(0.9); }
            to { opacity: 1; transform: scale(1); }
        }

        .brand-subtitle {
            color: #4b5563;
            font-size: 1rem;
            margin-bottom: 0;
            font-weight: 500;
            animation: fadeIn 0.8s ease-out 0.4s both;
        }

        .subtitle-highlight {
            color: var(--brand-color);
            font-weight: 600;
        }

        .divider {
            height: 2px;
            background: linear-gradient(90deg, transparent, #e5e7eb, transparent);
            margin: 30px 0;
        }

        .form-group {
            margin-bottom: 24px;
            text-align: left;
        }

        .form-label {
            font-size: 0.85rem;
            font-weight: 600;
            color: #374151;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: block;
            margin-bottom: 10px;
        }

        .form-control {
            background-color: #f9fafb;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            padding: 14px 18px;
            font-size: 0.95rem;
            color: #1f2937;
            transition: all 0.3s ease;
            width: 100%;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--brand-color);
            background-color: #fff;
            box-shadow: 0 0 0 4px rgba(255, 95, 21, 0.1);
        }

        .form-control::placeholder {
            color: #9ca3af;
        }

        .btn-login {
            background: linear-gradient(135deg, var(--brand-color) 0%, var(--brand-hover) 100%);
            border: none;
            color: white;
            font-weight: 600;
            padding: 16px;
            width: 100%;
            border-radius: 12px;
            font-size: 1rem;
            letter-spacing: 0.5px;
            transition: all 0.3s ease;
            margin-top: 10px;
            text-transform: uppercase;
            box-shadow: 0 8px 24px rgba(255, 95, 21, 0.3);
            cursor: pointer;
        }

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 32px rgba(255, 95, 21, 0.4);
        }

        @media (max-width: 576px) {
            .login-card {
                padding: 40px 30px;
            }

            .login-logo {
                height: 65px;
            }
        }
    </style>
</head>
<body>

    <div class="login-container">
        <div class="login-card">
            <div class="logo-container">
                <img src="assets/images/logo.jpeg" alt="Campus Social Logo" class="login-logo">
                <p class="brand-subtitle">Sign in to <span class="subtitle-highlight">Admin Portal</span></p>
            </div>

            <div class="divider"></div>
            
            <form method="POST">
                <div class="form-group">
                    <label class="form-label">Username</label>
                    <input type="text" name="username" class="form-control" placeholder="Enter your username" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Password</label>
                    <input type="password" name="password" class="form-control" placeholder="Enter your password" required>
                </div>
                
                <button type="submit" name="login" class="btn-login">Login</button>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            <?php if($login_error): ?>
                Swal.fire({
                    icon: 'error',
                    title: 'Access Denied',
                    text: 'Invalid Username or Password',
                    confirmButtonColor: '#FF5F15',
                    confirmButtonText: 'Try Again'
                });
            <?php endif; ?>

            const urlParams = new URLSearchParams(window.location.search);
            const msg = urlParams.get('msg');
            
            if (msg === 'logout') {
                const Toast = Swal.mixin({
                    toast: true,
                    position: 'top-end',
                    showConfirmButton: false,
                    timer: 3000,
                    timerProgressBar: true
                });

                Toast.fire({
                    icon: 'success',
                    title: 'Logged out successfully'
                });
                
                window.history.replaceState({}, document.title, window.location.pathname);
            }
        });
    </script>
</body>
</html>