<?php
session_start();
include 'db.php';
require_once __DIR__ . '/admin_priv.php';

if (!isset($_SESSION['admin']) && !isset($_SESSION['subadmin'])) {
    header('Location: index.php');
    exit();
}
require_priv('institutions');

$message = '';
$msg_type = '';

function institutions_flash_redirect(string $message, string $type = 'success'): void
{
    $_SESSION['institutions_flash'] = ['type' => $type, 'message' => $message];
    header('Location: institutions.php');
    exit();
}

function institutions_upload_logo(?array $file): array
{
    if ($file === null || !isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'path' => null];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Logo upload failed.'];
    }

    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) {
        return ['ok' => false, 'error' => 'Logo must be an image (JPEG, PNG, GIF, WEBP).'];
    }
    if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
        return ['ok' => false, 'error' => 'Logo must be 5 MB or smaller.'];
    }

    $upload_dir = __DIR__ . '/uploads/institutions/';
    if (!is_dir($upload_dir) && !mkdir($upload_dir, 0777, true) && !is_dir($upload_dir)) {
        return ['ok' => false, 'error' => 'Could not create upload directory.'];
    }

    $filename = 'inst_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $upload_dir . $filename)) {
        return ['ok' => false, 'error' => 'Failed to save logo file.'];
    }

    return ['ok' => true, 'path' => 'uploads/institutions/' . $filename];
}

if (!empty($_SESSION['institutions_flash'])) {
    $flash = $_SESSION['institutions_flash'];
    unset($_SESSION['institutions_flash']);
    $message = (string) ($flash['message'] ?? '');
    $msg_type = (string) ($flash['type'] ?? 'success');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $short_code = trim((string) ($_POST['short_code'] ?? ''));
        $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

        if ($name === '') {
            institutions_flash_redirect('Institution name is required.', 'danger');
        }

        $logo = institutions_upload_logo($_FILES['logo_file'] ?? null);
        if (!$logo['ok']) {
            institutions_flash_redirect($logo['error'], 'danger');
        }

        $stmt = $conn->prepare('INSERT INTO institutions (name, short_code, logo_url, status) VALUES (?, ?, ?, ?)');
        $short_code_val = $short_code !== '' ? $short_code : '';
        $logo_url = $logo['path'] !== null ? $logo['path'] : '';
        $stmt->bind_param('ssss', $name, $short_code_val, $logo_url, $status);
        if ($stmt->execute()) {
            $stmt->close();
            institutions_flash_redirect('Institution created successfully.');
        }
        $err = $conn->errno === 1062 ? 'An institution with this name already exists.' : ('Database error: ' . $conn->error);
        $stmt->close();
        institutions_flash_redirect($err, 'danger');
    }

    if ($action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $short_code = trim((string) ($_POST['short_code'] ?? ''));
        $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

        if ($id <= 0 || $name === '') {
            institutions_flash_redirect('Institution name is required.', 'danger');
        }

        $existing = $conn->query('SELECT logo_url FROM institutions WHERE id = ' . $id)->fetch_assoc();
        if (!$existing) {
            institutions_flash_redirect('Institution not found.', 'danger');
        }

        $logo_url = (string) ($existing['logo_url'] ?? '');
        $logo = institutions_upload_logo($_FILES['logo_file'] ?? null);
        if (!$logo['ok']) {
            institutions_flash_redirect($logo['error'], 'danger');
        }
        if ($logo['path'] !== null) {
            $logo_url = $logo['path'];
        }

        $stmt = $conn->prepare('UPDATE institutions SET name = ?, short_code = ?, logo_url = ?, status = ? WHERE id = ?');
        $short_code_val = $short_code !== '' ? $short_code : '';
        $stmt->bind_param('ssssi', $name, $short_code_val, $logo_url, $status, $id);
        if ($stmt->execute()) {
            $stmt->close();
            institutions_flash_redirect('Institution updated successfully.');
        }
        $err = $conn->errno === 1062 ? 'An institution with this name already exists.' : ('Database error: ' . $conn->error);
        $stmt->close();
        institutions_flash_redirect($err, 'danger');
    }

    if ($action === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        $new_status = ($_POST['new_status'] ?? '') === 'inactive' ? 'inactive' : 'active';
        if ($id > 0) {
            $stmt = $conn->prepare('UPDATE institutions SET status = ? WHERE id = ?');
            $stmt->bind_param('si', $new_status, $id);
            $stmt->execute();
            $stmt->close();
            institutions_flash_redirect($new_status === 'active' ? 'Institution activated.' : 'Institution deactivated.');
        }
        institutions_flash_redirect('Invalid institution.', 'danger');
    }
}

$institutions = $conn->query('SELECT * FROM institutions ORDER BY name ASC');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Institutions | Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --brand-color: #FF5F15; --brand-soft: rgba(255, 95, 21, 0.08); }
        .card-custom { background: white; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.04); border: 1px solid #f0f0f0; margin-bottom: 25px; }
        .btn-brand { background: var(--brand-color); color: white; border: none; border-radius: 10px; font-weight: 600; }
        .btn-brand:hover { background: #e04e0b; color: white; }
        .inst-thumb { width: 48px; height: 48px; border-radius: 10px; overflow: hidden; background: #f1f1f1; flex-shrink: 0; display: flex; align-items: center; justify-content: center; color: #aaa; }
        .inst-thumb img { width: 100%; height: 100%; object-fit: cover; }
        @media (max-width: 767.98px) {
            .card-custom .table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; }
            .card-custom table { min-width: 680px; font-size: 0.875rem; }
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="main-content">
        <div class="page-hero">
            <div class="min-w-0">
                <div class="page-kicker">User administration</div>
                <h1 class="page-title">Institutions</h1>
                <p class="page-sub">Manage colleges and universities available at registration</p>
            </div>
            <button type="button" class="btn btn-brand flex-shrink-0" data-bs-toggle="modal" data-bs-target="#createInstitutionModal">
                <i class="fas fa-plus me-2"></i>Add Institution
            </button>
        </div>

        <?php if ($message): ?>
        <div class="alert alert-<?php echo htmlspecialchars($msg_type); ?> alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <div class="card-custom">
            <div class="table-responsive">
                <table class="table table-hover mb-0 w-100">
                    <thead>
                        <tr>
                            <th class="ps-4" style="font-size:0.7rem;text-transform:uppercase;color:#a0a0a0;">Logo</th>
                            <th style="font-size:0.7rem;text-transform:uppercase;color:#a0a0a0;">Name</th>
                            <th style="font-size:0.7rem;text-transform:uppercase;color:#a0a0a0;">Short code</th>
                            <th style="font-size:0.7rem;text-transform:uppercase;color:#a0a0a0;">Status</th>
                            <th style="font-size:0.7rem;text-transform:uppercase;color:#a0a0a0;">Updated</th>
                            <th class="text-end pe-4" style="font-size:0.7rem;text-transform:uppercase;color:#a0a0a0;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($institutions && $institutions->num_rows > 0): ?>
                            <?php while ($row = $institutions->fetch_assoc()): ?>
                            <tr>
                                <td class="ps-4">
                                    <div class="inst-thumb">
                                        <?php if (!empty($row['logo_url'])): ?>
                                            <img src="<?php echo htmlspecialchars($row['logo_url']); ?>" alt="">
                                        <?php else: ?>
                                            <i class="fas fa-university"></i>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="fw-bold" style="font-size:0.9rem;"><?php echo htmlspecialchars($row['name']); ?></div>
                                    <small class="text-muted">ID #<?php echo (int) $row['id']; ?></small>
                                </td>
                                <td>
                                    <?php if (!empty($row['short_code'])): ?>
                                        <span class="badge bg-light text-dark border"><?php echo htmlspecialchars($row['short_code']); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                                        <input type="hidden" name="new_status" value="<?php echo $row['status'] === 'active' ? 'inactive' : 'active'; ?>">
                                        <button type="submit" class="btn btn-sm <?php echo $row['status'] === 'active' ? 'btn-success' : 'btn-outline-secondary'; ?>">
                                            <?php echo $row['status'] === 'active' ? 'Active' : 'Inactive'; ?>
                                        </button>
                                    </form>
                                </td>
                                <td><small class="text-muted"><?php echo date('M d, Y', strtotime($row['updated_at'])); ?></small></td>
                                <td class="text-end pe-4">
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-secondary"
                                        data-bs-toggle="modal"
                                        data-bs-target="#editInstitutionModal"
                                        data-id="<?php echo (int) $row['id']; ?>"
                                        data-name="<?php echo htmlspecialchars($row['name'], ENT_QUOTES); ?>"
                                        data-short-code="<?php echo htmlspecialchars((string) $row['short_code'], ENT_QUOTES); ?>"
                                        data-status="<?php echo htmlspecialchars($row['status'], ENT_QUOTES); ?>"
                                        data-logo="<?php echo htmlspecialchars((string) $row['logo_url'], ENT_QUOTES); ?>"
                                    >
                                        <i class="fas fa-pen"></i> Edit
                                    </button>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="6" class="text-center py-5 text-muted">No institutions yet. Click "Add Institution" to create one.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Create -->
    <div class="modal fade" id="createInstitutionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="create">
                    <div class="modal-header">
                        <h5 class="modal-title fw-bold">Add Institution</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label fw-semibold small">Name</label>
                            <input type="text" name="name" class="form-control" required maxlength="150" placeholder="e.g. Guru Nanak University">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold small">Short code <small class="text-muted">(optional)</small></label>
                            <input type="text" name="short_code" class="form-control" maxlength="20" placeholder="e.g. GNU">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold small">Logo <small class="text-muted">(optional)</small></label>
                            <input type="file" name="logo_file" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp">
                        </div>
                        <div class="mb-0">
                            <label class="form-label fw-semibold small">Status</label>
                            <select name="status" class="form-select">
                                <option value="active" selected>Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
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

    <!-- Edit -->
    <div class="modal fade" id="editInstitutionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id" id="editInstitutionId" value="">
                    <div class="modal-header">
                        <h5 class="modal-title fw-bold">Edit Institution</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label fw-semibold small">Name</label>
                            <input type="text" name="name" id="editInstitutionName" class="form-control" required maxlength="150">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold small">Short code <small class="text-muted">(optional)</small></label>
                            <input type="text" name="short_code" id="editInstitutionShortCode" class="form-control" maxlength="20">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold small">Logo <small class="text-muted">(optional — leave blank to keep current)</small></label>
                            <input type="file" name="logo_file" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp">
                            <div class="form-text" id="editInstitutionLogoHint"></div>
                        </div>
                        <div class="mb-0">
                            <label class="form-label fw-semibold small">Status</label>
                            <select name="status" id="editInstitutionStatus" class="form-select">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-brand"><i class="fas fa-save me-1"></i>Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    document.getElementById('editInstitutionModal').addEventListener('show.bs.modal', function (event) {
        var btn = event.relatedTarget;
        if (!btn) return;
        document.getElementById('editInstitutionId').value = btn.getAttribute('data-id') || '';
        document.getElementById('editInstitutionName').value = btn.getAttribute('data-name') || '';
        document.getElementById('editInstitutionShortCode').value = btn.getAttribute('data-short-code') || '';
        document.getElementById('editInstitutionStatus').value = btn.getAttribute('data-status') || 'active';
        var logo = btn.getAttribute('data-logo') || '';
        document.getElementById('editInstitutionLogoHint').textContent = logo
            ? ('Current: ' + logo)
            : 'No logo uploaded yet.';
    });
    </script>
</body>
</html>
