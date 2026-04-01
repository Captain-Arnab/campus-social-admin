<?php
session_start();
include 'db.php';
require_once __DIR__ . '/admin_priv.php';

if (!isset($_SESSION['admin']) && !isset($_SESSION['subadmin'])) {
    header("Location: index.php");
    exit();
}
require_priv('events');

$user_type = $_SESSION['user_type'] ?? 'admin';
$username = isset($_SESSION['admin']) ? $_SESSION['admin'] : $_SESSION['subadmin'];

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id <= 0) {
    header("Location: dashboard.php");
    exit();
}

// Fetch event
$stmt = $conn->prepare("SELECT * FROM events WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$event = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$event) {
    die("<div style='padding:50px; text-align:center; font-family:sans-serif;'><h4>Event not found.</h4><a href='dashboard.php'>Back to Dashboard</a></div>");
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $venue = trim($_POST['venue'] ?? '');
    $event_date = trim($_POST['event_date'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $rules = trim($_POST['rules'] ?? '');

    if ($title === '') $errors[] = "Title is required";
    if ($category === '') $errors[] = "Category is required";
    if ($venue === '') $errors[] = "Venue is required";
    if ($event_date === '') $errors[] = "Event date/time is required";

    // Basic date validation (expects HTML datetime-local format)
    $event_date_mysql = null;
    if ($event_date !== '') {
        $dt = DateTime::createFromFormat('Y-m-d\TH:i', $event_date);
        if (!$dt) {
            $errors[] = "Invalid date/time format";
        } else {
            $event_date_mysql = $dt->format('Y-m-d H:i:s');
        }
    }

    if (empty($errors)) {
        $old_status = $event['status'];

        // Admin can edit event details anytime (any status)
        $upd = $conn->prepare("UPDATE events SET title = ?, category = ?, venue = ?, event_date = ?, description = ?, rules = ? WHERE id = ?");
        $upd->bind_param("ssssssi", $title, $category, $venue, $event_date_mysql, $description, $rules, $id);

        if ($upd->execute() && $upd->affected_rows >= 0) {
            $upd->close();
            // Clear any pending edit from organizer/editor when admin edits directly
            @$conn->query("DELETE FROM event_pending_edits WHERE event_id = $id");

            // Log the update (status stays the same)
            $remarks = "Event details updated by " . $user_type . " (" . $username . ")";
            $log_stmt = $conn->prepare("INSERT INTO event_status_log (event_id, admin_type, admin_username, old_status, new_status, remarks) VALUES (?, ?, ?, ?, ?, ?)");
            $log_stmt->bind_param("isssss", $id, $user_type, $username, $old_status, $old_status, $remarks);
            $log_stmt->execute();
            $log_stmt->close();

            header("Location: event_details.php?id=" . $id . "&msg=updated");
            exit();
        } else {
            $errors[] = "Update failed: " . $conn->error;
            $upd->close();
        }
    }
}

// Prefill form values
$dt_prefill = '';
try {
    if (!empty($event['event_date'])) {
        $dt_prefill = (new DateTime($event['event_date']))->format('Y-m-d\TH:i');
    }
} catch (Exception $e) {
    $dt_prefill = '';
}

$banners = json_decode($event['banners'] ?? '[]');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Edit Event | Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --brand-color: #FF5F15;
            --bg-body: #f8f9fd;
            --card-shadow: 0 4px 20px rgba(0,0,0,0.03);
        }
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: var(--bg-body); color: #2d3436; }
        .container-compact { max-width: 900px; margin: 30px auto; padding: 0 20px; }
        .compact-card { background: white; border-radius: 16px; box-shadow: var(--card-shadow); border: 1px solid #f0f0f0; overflow: hidden; }
        .compact-body { padding: 25px; }
        .btn-brand { background: var(--brand-color); color: white; border: none; border-radius: 12px; font-weight: 700; padding: 10px 16px; }
        .btn-brand:hover { background: #e04e0b; color: white; }
        .banner-preview { width: 100%; height: 200px; border-radius: 14px; background: #f1f1f1; overflow: hidden; display:flex; align-items:center; justify-content:center; margin-bottom: 15px; }
        .banner-preview img { width: 100%; height: 100%; object-fit: cover; }
        .muted-label { font-size: 0.75rem; font-weight: 800; text-transform: uppercase; color: #95a5a6; }
    </style>
</head>
<body>
    <div class="container-compact">
        <div class="mb-3 d-flex justify-content-between align-items-center">
            <a href="event_details.php?id=<?php echo $id; ?>" class="text-decoration-none text-muted fw-bold small">
                <i class="fas fa-chevron-left me-1"></i> Back to Review
            </a>
            <span class="badge rounded-pill text-bg-warning text-uppercase" style="letter-spacing:0.5px;">
                <?php echo strtoupper($event['status']); ?>
            </span>
        </div>

        <div class="compact-card">
            <div class="compact-body">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                    <div>
                        <div class="muted-label mb-1">Editing Event</div>
                        <h4 class="fw-bold mb-0"><?php echo htmlspecialchars($event['title']); ?></h4>
                    </div>
                </div>

                <div class="mt-3">
                    <div class="banner-preview">
                        <?php if (!empty($banners) && is_array($banners)): ?>
                            <img src="./uploads/events/<?php echo htmlspecialchars($banners[0]); ?>" alt="Event banner" onerror="this.remove(); this.parentElement.innerHTML='<div class=\'text-muted\'><i class=\'fas fa-image me-2\'></i>No banner</div>';">
                        <?php else: ?>
                            <div class="text-muted"><i class="fas fa-image me-2"></i>No banner</div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <div class="fw-bold mb-1">Please fix the following:</div>
                        <ul class="mb-0">
                            <?php foreach ($errors as $e): ?>
                                <li><?php echo htmlspecialchars($e); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form method="POST" class="mt-3">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Title</label>
                            <input type="text" name="title" class="form-control" value="<?php echo htmlspecialchars($_POST['title'] ?? $event['title']); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Category</label>
                            <input type="text" name="category" class="form-control" value="<?php echo htmlspecialchars($_POST['category'] ?? $event['category']); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Venue</label>
                            <input type="text" name="venue" class="form-control" value="<?php echo htmlspecialchars($_POST['venue'] ?? $event['venue']); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Event Date & Time</label>
                            <input type="datetime-local" name="event_date" class="form-control" value="<?php echo htmlspecialchars($_POST['event_date'] ?? $dt_prefill); ?>" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold small">Description</label>
                            <textarea name="description" class="form-control" rows="5"><?php echo htmlspecialchars($_POST['description'] ?? $event['description']); ?></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold small">Event rules</label>
                            <textarea name="rules" class="form-control" rows="4" placeholder="Rules and guidelines for participants"><?php echo htmlspecialchars($_POST['rules'] ?? ($event['rules'] ?? '')); ?></textarea>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-brand">
                            <i class="fas fa-save me-2"></i>Save Changes
                        </button>
                        <a href="event_details.php?id=<?php echo $id; ?>" class="btn btn-outline-secondary" style="border-radius:12px; font-weight:700;">
                            Cancel
                        </a>
                    </div>
                    <div class="text-muted small mt-3">
                        Note: Admin/Subadmin can edit event details at any time.
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <?php if (!empty($errors)): ?>
    <script>
        Swal.fire({
            title: 'Please fix the following',
            html: '<ul class="text-start mb-0"><?php foreach ($errors as $e): ?><li><?php echo addslashes(htmlspecialchars($e)); ?></li><?php endforeach; ?></ul>',
            icon: 'error',
            confirmButtonColor: '#FF5F15'
        });
    </script>
    <?php endif; ?>
</body>
</html>

