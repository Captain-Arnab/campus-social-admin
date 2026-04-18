<?php
session_start();
include 'db.php';
require_once __DIR__ . '/admin_priv.php';

if (!isset($_SESSION['admin']) && !isset($_SESSION['subadmin'])) {
    header("Location: index.php");
    exit();
}
require_priv('ad_posts');

$message = '';
$msg_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $title = trim($_POST['title'] ?? '');
        $media_type = $_POST['media_type'] ?? '';
        $link_url = trim($_POST['link_url'] ?? '');
        $sort_order = (int) ($_POST['sort_order'] ?? 0);
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if ($title === '' || !in_array($media_type, ['image', 'video', 'link'])) {
            $message = 'Title and media type are required.';
            $msg_type = 'danger';
        } else {
            $media_url = '';

            if ($media_type === 'link') {
                $media_url = $link_url;
                if ($media_url === '') {
                    $message = 'URL is required for link type.';
                    $msg_type = 'danger';
                }
            } else {
                if (isset($_FILES['media_file']) && $_FILES['media_file']['error'] === UPLOAD_ERR_OK) {
                    $upload_dir = __DIR__ . '/uploads/ads/';
                    if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

                    $ext = pathinfo($_FILES['media_file']['name'], PATHINFO_EXTENSION);
                    $filename = 'ad_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
                    if (move_uploaded_file($_FILES['media_file']['tmp_name'], $upload_dir . $filename)) {
                        $media_url = 'uploads/ads/' . $filename;
                    } else {
                        $message = 'Failed to upload file.';
                        $msg_type = 'danger';
                    }
                } else {
                    $message = 'Media file is required for image/video type.';
                    $msg_type = 'danger';
                }
            }

            if ($media_url !== '' && $msg_type !== 'danger') {
                $stmt = $conn->prepare("INSERT INTO ad_posts (title, media_type, media_url, link_url, is_active, sort_order, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $admin_id = isset($_SESSION['admin']) ? 1 : (int) ($_SESSION['subadmin_id'] ?? 0);
                $stmt->bind_param('ssssiis', $title, $media_type, $media_url, $link_url, $is_active, $sort_order, $admin_id);
                if ($stmt->execute()) {
                    $message = 'Ad post created successfully.';
                    $msg_type = 'success';
                } else {
                    $message = 'Database error: ' . $conn->error;
                    $msg_type = 'danger';
                }
                $stmt->close();
            }
        }
    } elseif ($action === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        $new_status = (int) ($_POST['new_status'] ?? 0);
        $conn->query("UPDATE ad_posts SET is_active = $new_status WHERE id = $id");
        $message = 'Post status updated.';
        $msg_type = 'success';
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $conn->query("DELETE FROM ad_posts WHERE id = $id");
        $message = 'Post deleted.';
        $msg_type = 'success';
    } elseif ($action === 'update_order') {
        $id = (int) ($_POST['id'] ?? 0);
        $sort_order = (int) ($_POST['sort_order'] ?? 0);
        $conn->query("UPDATE ad_posts SET sort_order = $sort_order WHERE id = $id");
        $message = 'Sort order updated.';
        $msg_type = 'success';
    }
}

$posts = $conn->query("SELECT * FROM ad_posts ORDER BY sort_order ASC, created_at DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Ad Posts | Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <style>
        :root { --brand-color: #FF5F15; --brand-soft: rgba(255, 95, 21, 0.08); --bg-body: #f8f9fd; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: var(--bg-body); }
        .main-content { margin-left: 280px; padding: 30px; }
        @media (max-width: 991px) { .main-content { margin-left: 0; padding: 15px; } }
        .card-custom { background: white; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.04); border: 1px solid #f0f0f0; margin-bottom: 25px; }
        .btn-brand { background: var(--brand-color); color: white; border: none; border-radius: 10px; font-weight: 600; }
        .btn-brand:hover { background: #e04e0b; color: white; }
        .ad-thumb { width: 80px; height: 50px; border-radius: 8px; overflow: hidden; background: #f1f1f1; flex-shrink: 0; }
        .ad-thumb img, .ad-thumb video { width: 100%; height: 100%; object-fit: cover; }
        .media-type-badge { font-size: 0.65rem; font-weight: 700; padding: 3px 8px; border-radius: 6px; text-transform: uppercase; }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h5 class="fw-bold m-0">Advertisement Posts</h5>
                <p class="text-muted small m-0">Manage home screen banners and ads for the app</p>
            </div>
            <button class="btn btn-brand" data-bs-toggle="modal" data-bs-target="#createAdModal">
                <i class="fas fa-plus me-2"></i>New Ad Post
            </button>
        </div>

        <?php if ($message): ?>
        <div class="alert alert-<?php echo $msg_type; ?> alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <div class="card-custom">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th class="ps-4" style="font-size:0.7rem;text-transform:uppercase;color:#a0a0a0;">Order</th>
                            <th style="font-size:0.7rem;text-transform:uppercase;color:#a0a0a0;">Preview</th>
                            <th style="font-size:0.7rem;text-transform:uppercase;color:#a0a0a0;">Title</th>
                            <th style="font-size:0.7rem;text-transform:uppercase;color:#a0a0a0;">Type</th>
                            <th style="font-size:0.7rem;text-transform:uppercase;color:#a0a0a0;">Status</th>
                            <th style="font-size:0.7rem;text-transform:uppercase;color:#a0a0a0;">Created</th>
                            <th class="text-end pe-4" style="font-size:0.7rem;text-transform:uppercase;color:#a0a0a0;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($posts && $posts->num_rows > 0): ?>
                            <?php while ($p = $posts->fetch_assoc()): ?>
                            <tr>
                                <td class="ps-4">
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="action" value="update_order">
                                        <input type="hidden" name="id" value="<?php echo $p['id']; ?>">
                                        <input type="number" name="sort_order" value="<?php echo $p['sort_order']; ?>" style="width:60px;" class="form-control form-control-sm d-inline" onchange="this.form.submit()">
                                    </form>
                                </td>
                                <td>
                                    <div class="ad-thumb">
                                        <?php if ($p['media_type'] === 'image'): ?>
                                            <img src="<?php echo htmlspecialchars($p['media_url']); ?>" alt="">
                                        <?php elseif ($p['media_type'] === 'video'): ?>
                                            <video muted><source src="<?php echo htmlspecialchars($p['media_url']); ?>"></video>
                                        <?php else: ?>
                                            <div class="d-flex align-items-center justify-content-center h-100 text-muted"><i class="fas fa-link"></i></div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="fw-bold" style="font-size:0.9rem;"><?php echo htmlspecialchars($p['title']); ?></div>
                                    <?php if ($p['link_url']): ?>
                                    <small class="text-muted text-truncate d-block" style="max-width:200px;"><?php echo htmlspecialchars($p['link_url']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $type_colors = ['image' => 'bg-info', 'video' => 'bg-warning', 'link' => 'bg-primary'];
                                    ?>
                                    <span class="media-type-badge <?php echo $type_colors[$p['media_type']] ?? 'bg-secondary'; ?> text-white">
                                        <?php echo $p['media_type']; ?>
                                    </span>
                                </td>
                                <td>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="id" value="<?php echo $p['id']; ?>">
                                        <input type="hidden" name="new_status" value="<?php echo $p['is_active'] ? 0 : 1; ?>">
                                        <button type="submit" class="btn btn-sm <?php echo $p['is_active'] ? 'btn-success' : 'btn-outline-secondary'; ?>">
                                            <?php echo $p['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </button>
                                    </form>
                                </td>
                                <td><small class="text-muted"><?php echo date('M d, Y', strtotime($p['created_at'])); ?></small></td>
                                <td class="text-end pe-4">
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Delete this ad post?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $p['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="7" class="text-center py-5 text-muted">No ad posts yet. Click "New Ad Post" to create one.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Create Ad Modal -->
    <div class="modal fade" id="createAdModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="create">
                    <div class="modal-header">
                        <h5 class="modal-title fw-bold">New Ad Post</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label fw-semibold small">Title</label>
                            <input type="text" name="title" class="form-control" required placeholder="Ad title or description">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold small">Media Type</label>
                            <select name="media_type" class="form-select" id="mediaTypeSelect" required onchange="toggleMediaFields()">
                                <option value="image">Image</option>
                                <option value="video">Video</option>
                                <option value="link">Link (external URL)</option>
                            </select>
                        </div>
                        <div class="mb-3" id="fileUploadGroup">
                            <label class="form-label fw-semibold small">Upload File</label>
                            <input type="file" name="media_file" class="form-control" accept="image/*,video/*" id="mediaFileInput">
                            <div class="form-text">Image (JPEG, PNG, GIF, WEBP) or Video (MP4, WEBM). Max 20 MB.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold small">Link URL <small class="text-muted">(click-through for image/video; main URL for link type)</small></label>
                            <input type="url" name="link_url" class="form-control" id="linkUrlInput" placeholder="https://...">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold small">Sort Order</label>
                            <input type="number" name="sort_order" class="form-control" value="0" min="0">
                        </div>
                        <div class="form-check">
                            <input type="checkbox" name="is_active" class="form-check-input" id="isActiveCheck" checked>
                            <label class="form-check-label small" for="isActiveCheck">Active (visible in app)</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-brand"><i class="fas fa-plus me-1"></i>Create</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
    function toggleMediaFields() {
        const type = document.getElementById('mediaTypeSelect').value;
        const fileGroup = document.getElementById('fileUploadGroup');
        const fileInput = document.getElementById('mediaFileInput');
        const linkInput = document.getElementById('linkUrlInput');

        if (type === 'link') {
            fileGroup.style.display = 'none';
            fileInput.removeAttribute('required');
            linkInput.setAttribute('required', 'required');
        } else {
            fileGroup.style.display = 'block';
            fileInput.setAttribute('required', 'required');
            linkInput.removeAttribute('required');
        }
    }
    toggleMediaFields();
    </script>
</body>
</html>
