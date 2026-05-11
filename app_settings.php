<?php
session_start();
include 'db.php';
require_once __DIR__ . '/admin_priv.php';

if (!isset($_SESSION['admin']) && !isset($_SESSION['subadmin'])) {
    header("Location: index.php");
    exit();
}
require_priv('app_settings');

$message = '';
$msg_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_logo' && isset($_FILES['app_logo'])) {
        $file = $_FILES['app_logo'];
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $message = 'Upload error.';
            $msg_type = 'danger';
        } elseif (!in_array($file['type'], $allowed)) {
            $message = 'Only JPEG, PNG, GIF, WEBP images are allowed.';
            $msg_type = 'danger';
        } elseif ($file['size'] > 5 * 1024 * 1024) {
            $message = 'File must be 5 MB or less.';
            $msg_type = 'danger';
        } else {
            $upload_dir = __DIR__ . '/uploads/app/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'app_logo_' . time() . '.' . $ext;
            if (move_uploaded_file($file['tmp_name'], $upload_dir . $filename)) {
                $stmt = $conn->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES ('app_logo', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
                $path = 'uploads/app/' . $filename;
                $stmt->bind_param('s', $path);
                $stmt->execute();
                $stmt->close();
                $message = 'App logo updated successfully.';
                $msg_type = 'success';
            } else {
                $message = 'Failed to save uploaded file.';
                $msg_type = 'danger';
            }
        }
    }
}

$current_logo = 'default_logo.png';
$logo_res = $conn->query("SELECT setting_value FROM app_settings WHERE setting_key = 'app_logo'");
if ($logo_res && $row = $logo_res->fetch_assoc()) {
    $current_logo = $row['setting_value'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>App Settings | Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --brand-color: #FF5F15; --brand-soft: rgba(255, 95, 21, 0.08); --bg-body: #f8f9fd; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: var(--bg-body); }
        .main-content { margin-left: 280px; padding: 30px; box-sizing: border-box; width: 100%; max-width: 100%; }
        @media (max-width: 991px) { .main-content { margin-left: 0; padding: 12px; } }
        .settings-card { background: white; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.04); border: 1px solid #f0f0f0; padding: 30px; margin-bottom: 25px; }
        .logo-preview { width: 200px; height: 200px; border-radius: 16px; border: 2px dashed #ddd; display: flex; align-items: center; justify-content: center; overflow: hidden; background: #fafafa; }
        @media (max-width: 767.98px) {
            .settings-card { padding: 16px 14px; border-radius: 14px; }
            .logo-preview {
                width: min(100%, 220px);
                height: auto;
                aspect-ratio: 1;
                max-width: 100%;
                margin-left: auto;
                margin-right: auto;
            }
        }
        .logo-preview img { max-width: 100%; max-height: 100%; object-fit: contain; }
        .btn-brand { background: var(--brand-color); color: white; border: none; border-radius: 10px; font-weight: 600; padding: 10px 24px; }
        .btn-brand:hover { background: #e04e0b; color: white; }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h5 class="fw-bold m-0">App Settings</h5>
                <p class="text-muted small m-0">Configure Android app branding</p>
            </div>
        </div>

        <?php if ($message): ?>
        <div class="alert alert-<?php echo $msg_type; ?> alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <div class="settings-card">
            <h6 class="fw-bold mb-3"><i class="fas fa-mobile-alt text-primary me-2"></i> App Logo</h6>
            <p class="text-muted small">Upload a new logo for the Android app. This logo will be fetched by the app dynamically. Recommended size: 512x512 px, PNG or JPEG.</p>
            <div class="row align-items-center g-4">
                <div class="col-12 col-sm-auto d-flex justify-content-center justify-content-sm-start">
                    <div class="logo-preview" id="logoPreview">
                        <?php if ($current_logo && $current_logo !== 'default_logo.png'): ?>
                            <img src="<?php echo htmlspecialchars($current_logo); ?>" alt="App Logo" id="previewImg">
                        <?php else: ?>
                            <div class="text-center text-muted">
                                <i class="fas fa-image fa-3x mb-2"></i>
                                <div class="small">No logo set</div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-12 col-sm min-w-0">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="update_logo">
                        <div class="mb-3">
                            <label class="form-label fw-semibold small">Select New Logo</label>
                            <input type="file" name="app_logo" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp" required onchange="previewLogo(this)">
                            <div class="form-text">JPEG, PNG, GIF, or WEBP. Max 5 MB.</div>
                        </div>
                        <button type="submit" class="btn btn-brand"><i class="fas fa-upload me-2"></i>Update Logo</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function previewLogo(input) {
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById('logoPreview').innerHTML = '<img src="' + e.target.result + '" alt="Preview" id="previewImg">';
            };
            reader.readAsDataURL(input.files[0]);
        }
    }
    </script>
</body>
</html>
