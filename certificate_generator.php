<?php
session_start();
include 'db.php';
require_once __DIR__ . '/admin_priv.php';
require_once __DIR__ . '/event_date_range_schema.php';

if (!isset($_SESSION['admin']) && !isset($_SESSION['subadmin'])) {
    header('Location: index.php');
    exit();
}
require_priv('certificates');

$achievement = (string) ($_GET['achievement'] ?? 'participated');
if (!in_array($achievement, ['participated', 'first', 'second', 'third'], true)) {
    $achievement = 'participated';
}

$defaults = [
    'university_name' => 'GURU NANAK UNIVERSITY',
    'university_location' => 'HYDERABAD, TELANGANA, INDIA',
    'university_establishment' => '(Established under the Telangana State Private Universities Act, 2024)',
    'university_motto' => 'INNOVATION • INTEGRITY • EXCELLENCE • EMPATHY • LEADERSHIP',
    'participant_name' => trim((string) ($_GET['name'] ?? '')),
    'event_title' => trim((string) ($_GET['title'] ?? '')),
    'organised_by' => trim((string) ($_GET['organised_by'] ?? '')),
    'signatory_title' => trim((string) ($_GET['signatory'] ?? 'Registrar')),
    'certificate_date' => date('F d, Y'),
    'certificate_id' => 'GNU/' . date('Y') . '/' . str_pad((string) mt_rand(1, 9999), 4, '0', STR_PAD_LEFT),
    'event_id' => (int) ($_GET['event_id'] ?? 0),
    'user_id' => (int) ($_GET['user_id'] ?? 0),
    'type' => in_array($_GET['type'] ?? '', ['participant', 'volunteer'], true) ? $_GET['type'] : 'participant',
    'achievement' => $achievement,
];

$signatory_options = ['Registrar', 'Dean', 'Director', 'Rector'];
if (!in_array($defaults['signatory_title'], $signatory_options, true)) {
    $signatory_options[] = $defaults['signatory_title'];
}

if ($defaults['event_id'] > 0) {
    $eid = $defaults['event_id'];
    $ev = $conn->query(
        "SELECT e.title, e.category, u.full_name AS organizer_name
         FROM events e
         JOIN users u ON e.organizer_id = u.id
         WHERE e.id = $eid LIMIT 1"
    );
    if ($ev && $ev->num_rows > 0) {
        $er = $ev->fetch_assoc();
        if ($defaults['event_title'] === '') {
            $defaults['event_title'] = $er['title'];
        }
        if ($defaults['organised_by'] === '') {
            $defaults['organised_by'] = trim($er['category'] . ' — ' . $er['organizer_name']);
        }
    }
    if ($defaults['user_id'] > 0 && $defaults['participant_name'] === '') {
        $u = $conn->query('SELECT full_name FROM users WHERE id = ' . (int) $defaults['user_id'] . ' LIMIT 1');
        if ($u && $u->num_rows > 0) {
            $defaults['participant_name'] = $u->fetch_assoc()['full_name'];
        }
    }
    if (!isset($_GET['achievement'])) {
        $wp = (int) ($_GET['position'] ?? 0);
        if ($wp === 0 && $defaults['user_id'] > 0) {
            $w = @$conn->query(
                'SELECT position FROM event_winners WHERE event_id = ' . $eid
                . ' AND user_id = ' . (int) $defaults['user_id'] . ' LIMIT 1'
            );
            if ($w && $w->num_rows > 0) {
                $wp = (int) $w->fetch_assoc()['position'];
            }
        }
        if ($wp === 1) {
            $defaults['achievement'] = 'first';
        } elseif ($wp === 2) {
            $defaults['achievement'] = 'second';
        } elseif ($wp === 3) {
            $defaults['achievement'] = 'third';
        }
    }
}

$past_sql = events_sql_past_naked($conn);
$past_events_list = [];
$past_res = $conn->query(
    "SELECT e.id, e.title, e.event_date, e.category, u.full_name AS organizer_name
     FROM events e
     JOIN users u ON e.organizer_id = u.id
     WHERE e.status = 'approved' AND ($past_sql)
     ORDER BY e.event_date DESC
     LIMIT 200"
);
if ($past_res) {
    while ($pe = $past_res->fetch_assoc()) {
        $cat = trim((string) ($pe['category'] ?? ''));
        $org = trim((string) ($pe['organizer_name'] ?? ''));
        $pe['organised_by'] = ($cat !== '' && $org !== '') ? $cat . ' — ' . $org : ($cat !== '' ? $cat : $org);
        $past_events_list[] = $pe;
    }
}
$cert_events_json = [];
foreach ($past_events_list as $pe) {
    $cert_events_json[] = [
        'id' => (int) $pe['id'],
        'title' => (string) $pe['title'],
        'event_date' => $pe['event_date'] ? date('M d, Y', strtotime($pe['event_date'])) : '',
        'organised_by' => (string) ($pe['organised_by'] ?? ''),
        'text' => (string) $pe['title'] . ($pe['event_date'] ? ' (' . date('M d, Y', strtotime($pe['event_date'])) . ')' : ''),
    ];
}

$default_logo_path = 'assets/images/logo.jpeg';
$logo_path = $default_logo_path;
$brand_glob = glob(__DIR__ . '/uploads/certificates/brand_logo.*');
if ($brand_glob && is_file($brand_glob[0])) {
    $logo_path = 'uploads/certificates/' . basename($brand_glob[0]);
}
$logo_exists = is_file(__DIR__ . '/' . $logo_path);
$defaults['logo_url'] = $logo_path;
$defaults['logo_tagline'] = "EMPOWERING YOUTH\nTRANSFORMING LIVES";
$defaults['logo_show'] = true;

$qr_path = '';
$qr_glob = glob(__DIR__ . '/uploads/certificates/brand_qr.*');
if ($qr_glob && is_file($qr_glob[0])) {
    $qr_path = 'uploads/certificates/' . basename($qr_glob[0]);
}
$defaults['qr_url'] = $qr_path;
$defaults['qr_show'] = true;
$defaults['qr_scan_label'] = 'Scan to Verify';

$default_seal_path = 'assets/images/certificate_seal_default.svg';
$seal_path = $default_seal_path;
$seal_glob = glob(__DIR__ . '/uploads/certificates/brand_seal.*');
if ($seal_glob && is_file($seal_glob[0])) {
    $seal_path = 'uploads/certificates/' . basename($seal_glob[0]);
}
$defaults['seal_url'] = $seal_path;
// Custom brand_seal is usually a signature/stamp image — show it by default when present.
$defaults['seal_show'] = ($seal_glob && is_file($seal_glob[0]));
$defaults['signatory_location'] = 'Hyderabad, Telangana, India';

$cert_template_path = 'assets/images/certificate_template.jpeg';
$cert_template_abs = __DIR__ . '/' . $cert_template_path;
$cert_canvas_w = 1056;
$cert_canvas_h = 744;
if (is_file($cert_template_abs)) {
    $cert_sz = @getimagesize($cert_template_abs);
    if ($cert_sz && !empty($cert_sz[0]) && !empty($cert_sz[1])) {
        $cert_canvas_w = (int) $cert_sz[0];
        $cert_canvas_h = (int) $cert_sz[1];
    }
}
$cert_font_base = max(12, (int) round($cert_canvas_w / 66));

function format_cert_display_name(string $raw): string
{
    $t = trim($raw);
    if ($t === '') {
        return 'Participant Name';
    }
    $parts = preg_split('/\s+/', $t, -1, PREG_SPLIT_NO_EMPTY);
    $out = [];
    foreach ($parts as $w) {
        $out[] = mb_strtoupper(mb_substr($w, 0, 1)) . mb_strtolower(mb_substr($w, 1));
    }
    return implode(' ', $out);
}

function cert_achievement_phrase(string $key): string
{
    switch ($key) {
        case 'first':
            return 'has stood <strong>FIRST</strong> in the event';
        case 'second':
            return 'has stood <strong>SECOND</strong> in the event';
        case 'third':
            return 'has stood <strong>THIRD</strong> in the event';
        default:
            return 'has successfully participated in the event';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Certificate Generator | Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700&family=Playfair+Display:wght@600;700&family=Cormorant+Garamond:wght@500;600;700&family=Pinyon+Script&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.0.13/dist/css/select2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
    <link href="assets/css/certificate_generator.css" rel="stylesheet">
    <link href="assets/css/certificate_generator_layout.css" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: #f8f9fd; }
        .main-content { margin-left: 280px; padding: 20px 24px; box-sizing: border-box; max-width: 100%; }
        @media (max-width: 991px) { .main-content { margin-left: 0; padding: 12px; } }
    </style>
</head>
<body>
<?php include 'sidebar.php'; ?>

<div class="main-content">
    <div class="cert-gen-page-header d-flex flex-wrap justify-content-between align-items-start gap-2">
        <div>
            <h4 class="fw-bold m-0">Certificate Generator</h4>
            <p class="text-muted small mb-0">GNU appreciation template — live preview updates as you edit.</p>
        </div>
        <a href="events.php?view=past" class="btn btn-outline-secondary rounded-3 btn-sm"><i class="fas fa-history me-1"></i>Past events</a>
    </div>

    <div class="cert-gen-shell">
        <div class="cert-gen-editor card border-0 shadow-sm rounded-4">
            <div class="cert-gen-editor-scroll">
                <div class="accordion cert-gen-accordion" id="certEditAccordion">
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#accRecipient" aria-expanded="true">Recipient &amp; event</button>
                        </h2>
                        <div id="accRecipient" class="accordion-collapse collapse show" data-bs-parent="#certEditAccordion">
                            <div class="accordion-body">
                                <div class="cert-gen-field">
                                    <label class="d-block">Certificate for</label>
                                    <div class="btn-group btn-group-sm w-100 cert-role-toggle" role="group" aria-label="Participant or volunteer">
                                        <input type="radio" class="btn-check" name="cert_staff_role" id="role_participant" value="participant" <?php echo $defaults['type'] === 'participant' ? 'checked' : ''; ?> autocomplete="off">
                                        <label class="btn btn-outline-secondary" for="role_participant">Participant</label>
                                        <input type="radio" class="btn-check" name="cert_staff_role" id="role_volunteer" value="volunteer" <?php echo $defaults['type'] === 'volunteer' ? 'checked' : ''; ?> autocomplete="off">
                                        <label class="btn btn-outline-secondary" for="role_volunteer">Volunteer</label>
                                    </div>
                                </div>
                                <div class="cert-gen-field" id="wrap_event_select">
                                    <label for="f_event_select">Event</label>
                                    <select id="f_event_select" class="form-select form-select-sm cert-select2" data-placeholder="Search past events…">
                                        <option value=""></option>
                                        <?php foreach ($past_events_list as $pe): ?>
                                        <option value="<?php echo (int) $pe['id']; ?>"
                                            data-title="<?php echo htmlspecialchars($pe['title']); ?>"
                                            data-organised="<?php echo htmlspecialchars($pe['organised_by'] ?? ''); ?>"
                                            <?php echo $defaults['event_id'] === (int) $pe['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($pe['title']); ?> (<?php echo date('M d, Y', strtotime($pe['event_date'])); ?>)
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-check form-check-sm cert-gen-check mb-2">
                                    <input class="form-check-input" type="checkbox" id="f_manual_event" value="1">
                                    <label class="form-check-label small" for="f_manual_event">Manual event title (custom text)</label>
                                </div>
                                <div class="cert-gen-field" id="wrap_event_manual" style="display:none;">
                                    <label for="f_event_title">Event title (manual)</label>
                                    <input type="text" class="form-control form-control-sm cert-field" id="f_event_title" value="<?php echo htmlspecialchars($defaults['event_title']); ?>" placeholder="e.g. Robo race">
                                </div>
                                <div class="cert-gen-field" id="wrap_recipient_select">
                                    <label for="f_recipient_select" id="lbl_recipient_select">Participant</label>
                                    <select id="f_recipient_select" class="form-select form-select-sm cert-select2" data-placeholder="Select event first, then choose name…">
                                        <option value=""></option>
                                    </select>
                                </div>
                                <div class="cert-name-style-preview mb-2" id="participant_name_preview" aria-hidden="true"></div>
                                <div class="form-check form-check-sm cert-gen-check mb-2">
                                    <input class="form-check-input" type="checkbox" id="f_manual_recipient" value="1">
                                    <label class="form-check-label small" for="f_manual_recipient">Manual name entry (type custom text)</label>
                                </div>
                                <div class="cert-gen-field" id="wrap_recipient_manual" style="display:none;">
                                    <label for="f_participant_name" id="lbl_participant_manual">Name on certificate</label>
                                    <input type="text" class="form-control form-control-sm cert-field" id="f_participant_name" value="<?php echo htmlspecialchars($defaults['participant_name']); ?>" placeholder="e.g. Pusala Dhanunjay">
                                    <div class="form-text">Title case — shown in gold script on the certificate.</div>
                                </div>
                                <p class="small text-muted mb-2" id="cert_link_hint" style="display:none;"></p>
                                <input type="hidden" id="f_event_id" value="<?php echo (int) $defaults['event_id']; ?>">
                                <input type="hidden" id="f_user_id" value="<?php echo (int) $defaults['user_id']; ?>">
                                <input type="hidden" id="f_type" value="<?php echo htmlspecialchars($defaults['type']); ?>">
                                <div class="cert-gen-field">
                                    <label for="f_achievement">Achievement</label>
                                    <select class="form-select form-select-sm cert-field" id="f_achievement">
                                        <option value="participated" <?php echo $defaults['achievement'] === 'participated' ? 'selected' : ''; ?>>Successfully participated</option>
                                        <option value="first" <?php echo $defaults['achievement'] === 'first' ? 'selected' : ''; ?>>Stood FIRST</option>
                                        <option value="second" <?php echo $defaults['achievement'] === 'second' ? 'selected' : ''; ?>>Stood SECOND</option>
                                        <option value="third" <?php echo $defaults['achievement'] === 'third' ? 'selected' : ''; ?>>Stood THIRD</option>
                                    </select>
                                </div>
                                <div class="cert-gen-field">
                                    <label for="f_organised_by">Organised by</label>
                                    <input type="text" class="form-control form-control-sm cert-field" id="f_organised_by" value="<?php echo htmlspecialchars($defaults['organised_by']); ?>">
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#accUniversity">University header</button>
                        </h2>
                        <div id="accUniversity" class="accordion-collapse collapse" data-bs-parent="#certEditAccordion">
                            <div class="accordion-body">
                                <div class="cert-gen-field">
                                    <label for="f_university_name">University name</label>
                                    <input type="text" class="form-control form-control-sm cert-field" id="f_university_name" value="<?php echo htmlspecialchars($defaults['university_name']); ?>">
                                </div>
                                <div class="cert-gen-field">
                                    <label for="f_university_location">Location</label>
                                    <input type="text" class="form-control form-control-sm cert-field" id="f_university_location" value="<?php echo htmlspecialchars($defaults['university_location']); ?>">
                                </div>
                                <div class="cert-gen-field">
                                    <label for="f_university_establishment">Establishment line</label>
                                    <input type="text" class="form-control form-control-sm cert-field" id="f_university_establishment" value="<?php echo htmlspecialchars($defaults['university_establishment']); ?>">
                                </div>
                                <div class="cert-gen-field">
                                    <label for="f_university_motto">Motto line</label>
                                    <input type="text" class="form-control form-control-sm cert-field" id="f_university_motto" value="<?php echo htmlspecialchars($defaults['university_motto']); ?>">
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#accMedia">Logo &amp; QR code</button>
                        </h2>
                        <div id="accMedia" class="accordion-collapse collapse" data-bs-parent="#certEditAccordion">
                            <div class="accordion-body">
                                <p class="small fw-bold text-muted mb-2">Logo</p>
                                <div class="cert-gen-media-row">
                                    <img id="logoFormPreview" src="<?php echo $logo_exists ? htmlspecialchars($logo_path) : ''; ?>" alt="" class="cert-gen-thumb" <?php echo $logo_exists ? '' : 'style="display:none"'; ?>>
                                    <div class="form-check form-switch mb-0">
                                        <input class="form-check-input" type="checkbox" id="f_logo_show" <?php echo $defaults['logo_show'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label small" for="f_logo_show">Show logo</label>
                                    </div>
                                </div>
                                <div class="cert-gen-field">
                                    <input type="file" class="form-control form-control-sm" id="f_logo_file" accept="image/jpeg,image/png,image/gif,image/webp">
                                </div>
                                <div class="cert-gen-field">
                                    <input type="text" class="form-control form-control-sm" id="f_logo_url" value="<?php echo htmlspecialchars($defaults['logo_url']); ?>" placeholder="Logo path or URL">
                                </div>
                                <div class="cert-gen-field">
                                    <textarea class="form-control form-control-sm cert-field" id="f_logo_tagline" rows="2" placeholder="Tagline under logo"><?php echo htmlspecialchars($defaults['logo_tagline']); ?></textarea>
                                </div>
                                <div class="cert-gen-btn-row">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btnLogoReset">Reset</button>
                                    <button type="button" class="btn btn-sm btn-outline-primary" id="btnLogoSaveDefault">Save default</button>
                                </div>
                                <p class="small fw-bold text-muted mb-2 mt-3">QR code</p>
                                <div class="cert-gen-media-row">
                                    <div id="qrFormPreviewWrap" class="cert-gen-thumb d-flex align-items-center justify-content-center bg-white" <?php echo $qr_path ? '' : 'style="display:none"'; ?>>
                                        <img id="qrFormPreview" src="<?php echo $qr_path ? htmlspecialchars($qr_path) : ''; ?>" alt="" style="width:44px;height:44px;object-fit:contain;">
                                    </div>
                                    <div class="form-check form-switch mb-0">
                                        <input class="form-check-input" type="checkbox" id="f_qr_show" <?php echo $defaults['qr_show'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label small" for="f_qr_show">Show QR</label>
                                    </div>
                                </div>
                                <div class="cert-gen-field">
                                    <input type="file" class="form-control form-control-sm" id="f_qr_file" accept="image/jpeg,image/png,image/gif,image/webp">
                                </div>
                                <div class="cert-gen-field">
                                    <input type="text" class="form-control form-control-sm" id="f_qr_url" value="<?php echo htmlspecialchars($defaults['qr_url']); ?>" placeholder="QR image path">
                                </div>
                                <div class="cert-gen-field">
                                    <input type="text" class="form-control form-control-sm cert-field" id="f_qr_scan_label" value="<?php echo htmlspecialchars($defaults['qr_scan_label']); ?>" placeholder="Scan to Verify">
                                </div>
                                <div class="cert-gen-btn-row">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btnQrReset">Remove QR</button>
                                    <button type="button" class="btn btn-sm btn-outline-primary" id="btnQrSaveDefault">Save default</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#accFooter">Signatory, date &amp; ID</button>
                        </h2>
                        <div id="accFooter" class="accordion-collapse collapse" data-bs-parent="#certEditAccordion">
                            <div class="accordion-body">
                                <div class="cert-gen-field">
                                    <label for="f_signatory_title">Signatory title</label>
                                    <select class="form-select form-select-sm cert-field" id="f_signatory_title">
                                        <?php foreach ($signatory_options as $opt): ?>
                                        <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo $defaults['signatory_title'] === $opt ? 'selected' : ''; ?>><?php echo htmlspecialchars($opt); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="cert-gen-field">
                                    <label for="f_signatory_location">Signatory address line</label>
                                    <input type="text" class="form-control form-control-sm cert-field" id="f_signatory_location" value="<?php echo htmlspecialchars($defaults['signatory_location']); ?>" placeholder="Hyderabad, Telangana, India">
                                    <div class="form-text">Kept on white area — avoids overlap with corner graphic.</div>
                                </div>
                                <p class="small fw-bold text-muted mb-2 mt-2">Signature / seal image</p>
                                <div class="form-text mb-2">Handwritten signature or stamp image (uploads/certificates/brand_seal.*). Text under the line (Registrar / university) always shows.</div>
                                <div class="cert-gen-media-row">
                                    <div id="sealFormPreviewWrap" class="cert-gen-thumb d-flex align-items-center justify-content-center bg-white p-1">
                                        <img id="sealFormPreview" src="<?php echo htmlspecialchars($seal_path); ?>" alt="" style="width:44px;height:44px;object-fit:contain;">
                                    </div>
                                    <div class="form-check form-switch mb-0">
                                        <input class="form-check-input" type="checkbox" id="f_seal_show" <?php echo $defaults['seal_show'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label small" for="f_seal_show">Show signature image</label>
                                    </div>
                                </div>
                                <div class="cert-gen-field">
                                    <input type="file" class="form-control form-control-sm" id="f_seal_file" accept="image/jpeg,image/png,image/gif,image/webp,image/svg+xml">
                                </div>
                                <div class="cert-gen-field">
                                    <input type="text" class="form-control form-control-sm" id="f_seal_url" value="<?php echo htmlspecialchars($defaults['seal_url']); ?>" placeholder="Seal image path">
                                </div>
                                <div class="cert-gen-btn-row">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btnSealReset">Default seal</button>
                                    <button type="button" class="btn btn-sm btn-outline-primary" id="btnSealSaveDefault">Save default</button>
                                </div>
                                <div class="cert-gen-inline-2 mt-2">
                                    <div class="cert-gen-field">
                                        <label for="f_certificate_date">Date</label>
                                        <input type="text" class="form-control form-control-sm cert-field" id="f_certificate_date" value="<?php echo htmlspecialchars($defaults['certificate_date']); ?>">
                                    </div>
                                    <div class="cert-gen-field">
                                        <label for="f_certificate_id">Certificate ID</label>
                                        <input type="text" class="form-control form-control-sm cert-field" id="f_certificate_id" value="<?php echo htmlspecialchars($defaults['certificate_id']); ?>">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="cert-gen-editor-actions">
                <div class="d-grid gap-2">
                    <button type="button" class="btn btn-dark btn-sm rounded-3 fw-bold" id="btnPrint"><i class="fas fa-print me-1"></i>Print / PDF</button>
                    <button type="button" class="btn btn-outline-primary btn-sm rounded-3 fw-bold" id="btnDownloadPng"><i class="fas fa-download me-1"></i>Download PNG</button>
                    <button type="button" class="btn btn-warning btn-sm rounded-3 fw-bold text-dark" id="btnSaveToUser" <?php echo ($defaults['event_id'] > 0 && $defaults['user_id'] > 0) ? '' : 'disabled'; ?>>
                        <i class="fas fa-cloud-upload-alt me-1"></i>Save to user
                    </button>
                </div>
            </div>
        </div>

        <div class="cert-gen-preview-col">
            <div class="cert-gen-preview-card cert-print-area">
                <div class="cert-gen-preview-toolbar">
                    <span><i class="fas fa-eye me-1"></i> Live preview</span>
                    <span class="text-muted" id="previewFitHint">Auto-fits to panel</span>
                </div>
                <div class="cert-gen-preview-viewport" id="previewViewport">
                    <div class="cert-gen-preview-scaler" id="previewScaler" style="width:<?php echo (int) max(200, (int) round($cert_canvas_w * 0.4)); ?>px;height:<?php echo (int) max(140, (int) round($cert_canvas_h * 0.4)); ?>px;">
                    <div class="cert-gen-preview-zoom" id="previewZoom" style="width:<?php echo (int) $cert_canvas_w; ?>px;height:<?php echo (int) $cert_canvas_h; ?>px;transform:scale(0.4);transform-origin:0 0;">
                    <div id="certCanvas" class="cert-canvas" style="width:<?php echo (int) $cert_canvas_w; ?>px;height:<?php echo (int) $cert_canvas_h; ?>px;font-size:<?php echo (int) $cert_font_base; ?>px;">
                        <img class="cert-bg-img" src="<?php echo htmlspecialchars($cert_template_path); ?>" alt="" crossorigin="anonymous">
                        <div class="cert-overlay">
                            <div class="cert-logo-block" id="cert_logo_block" style="<?php echo $defaults['logo_show'] && $logo_exists ? '' : 'display:none;'; ?>">
                                <img id="cert_logo_img" src="<?php echo $logo_exists ? htmlspecialchars($logo_path) : ''; ?>" alt="Logo">
                                <div class="cert-logo-tag" id="v_logo_tagline"><?php
                                    $tag_lines = preg_split('/\r\n|\r|\n/', $defaults['logo_tagline']);
                                    echo htmlspecialchars($tag_lines[0] ?? '');
                                    if (!empty($tag_lines[1])) {
                                        echo '<br>' . htmlspecialchars($tag_lines[1]);
                                    }
                                ?></div>
                            </div>

                            <header class="cert-header">
                                <h1 class="cert-uni-name" id="v_university_name"><?php echo htmlspecialchars($defaults['university_name']); ?></h1>
                                <p class="cert-uni-loc" id="v_university_location"><?php echo htmlspecialchars($defaults['university_location']); ?></p>
                                <p class="cert-uni-est" id="v_university_establishment"><?php echo htmlspecialchars($defaults['university_establishment']); ?></p>
                                <p class="cert-motto-line" id="v_university_motto"><?php echo htmlspecialchars($defaults['university_motto']); ?></p>
                            </header>

                            <div class="cert-title-block">
                                <h2 class="cert-title-main">CERTIFICATE</h2>
                                <p class="cert-title-sub">OF APPRECIATION</p>
                            </div>

                            <div class="cert-body">
                                <p class="cert-body-intro">This is to certify that</p>
                                <span class="cert-name" id="v_participant_name"><?php echo htmlspecialchars(format_cert_display_name($defaults['participant_name'])); ?></span>
                                <p class="cert-achievement-line" id="v_achievement"><?php echo cert_achievement_phrase($defaults['achievement']); ?></p>
                                <p class="cert-event-title" id="v_event_title">“<?php echo htmlspecialchars($defaults['event_title'] ?: 'Event Title Goes Here'); ?>”</p>
                                <p class="cert-organised">Organised by: <strong id="v_organised_by"><?php echo htmlspecialchars($defaults['organised_by'] ?: 'Department / Club / Cell Name'); ?></strong></p>
                                <p class="cert-appreciation">We appreciate your enthusiasm, dedication, and active participation. Your contribution reflects the spirit of innovation, learning, and leadership encouraged at Guru Nanak University.</p>
                                <p class="cert-footer-motto">KEEP LEARNING • KEEP GROWING • KEEP LEADING</p>
                            </div>

                            <div class="cert-qr-block" id="cert_qr_block">
                                <div class="cert-qr-box" id="cert_qr_box">
                                    <span class="cert-qr-placeholder" id="cert_qr_placeholder" style="<?php echo $qr_path ? 'display:none;' : ''; ?>">QR</span>
                                    <img id="cert_qr_img" class="cert-qr-img" src="<?php echo $qr_path ? htmlspecialchars($qr_path) : ''; ?>" alt="QR" style="<?php echo $qr_path ? '' : 'display:none;'; ?>">
                                </div>
                                <div class="cert-qr-meta">
                                    <div id="v_qr_scan_label"><?php echo htmlspecialchars($defaults['qr_scan_label']); ?></div>
                                    <div class="cert-id-line">Certificate ID: <span id="v_certificate_id"><?php echo htmlspecialchars($defaults['certificate_id']); ?></span></div>
                                </div>
                            </div>

                            <div class="cert-date-block" id="cert_seal_block">
                                <div class="cert-seal-wrap" id="cert_seal_wrap" style="<?php echo $defaults['seal_show'] ? '' : 'display:none;'; ?>">
                                    <img id="cert_seal_img" class="cert-seal-img" src="<?php echo htmlspecialchars($seal_path); ?>" alt="">
                                </div>
                                <div class="cert-date-row">
                                    <i class="far fa-calendar-alt cert-date-icon" aria-hidden="true"></i>
                                    <div class="cert-date-val" id="v_certificate_date"><?php echo htmlspecialchars($defaults['certificate_date']); ?></div>
                                </div>
                            </div>

                            <div class="cert-sign-block" id="cert_sign_block">
                                <div class="cert-sign-line" aria-hidden="true">&nbsp;</div>
                                <div class="cert-sign-title" id="v_signatory_title"><?php echo htmlspecialchars($defaults['signatory_title']); ?></div>
                                <div class="cert-sign-uni" id="v_signatory_footer">
                                    <div id="v_signatory_uni"><?php echo htmlspecialchars($defaults['university_name']); ?></div>
                                    <div class="cert-sign-loc" id="v_signatory_location"><?php echo htmlspecialchars($defaults['signatory_location']); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.0.13/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script>
(function () {
    const CERT_EVENTS = <?php echo json_encode($cert_events_json, JSON_UNESCAPED_UNICODE); ?>;
    const INITIAL_EVENT_ID = <?php echo (int) $defaults['event_id']; ?>;
    const INITIAL_USER_ID = <?php echo (int) $defaults['user_id']; ?>;
    const INITIAL_NAME = <?php echo json_encode($defaults['participant_name']); ?>;
    const DEFAULT_LOGO_URL = <?php echo json_encode($logo_path); ?>;
    const FALLBACK_LOGO_URL = <?php echo json_encode($default_logo_path); ?>;
    const DEFAULT_QR_URL = <?php echo json_encode($qr_path); ?>;
    const DEFAULT_SEAL_URL = <?php echo json_encode($seal_path); ?>;
    const FALLBACK_SEAL_URL = <?php echo json_encode($default_seal_path); ?>;
    const CERT_W = <?php echo (int) $cert_canvas_w; ?>;
    const CERT_H = <?php echo (int) $cert_canvas_h; ?>;

    const ACHIEVEMENT_HTML = {
        participated: 'has successfully participated in the event',
        first: 'has stood <strong>FIRST</strong> in the event',
        second: 'has stood <strong>SECOND</strong> in the event',
        third: 'has stood <strong>THIRD</strong> in the event'
    };

    function formatCertName(raw) {
        const t = (raw || '').trim();
        if (!t) return 'Participant Name';
        return t.split(/\s+/).map(function (w) {
            if (!w) return '';
            return w.charAt(0).toUpperCase() + w.slice(1).toLowerCase();
        }).join(' ');
    }

    function syncParticipantNamePreview() {
        const raw = document.getElementById('f_participant_name').value;
        const formatted = formatCertName(raw);
        const view = document.getElementById('v_participant_name');
        const mini = document.getElementById('participant_name_preview');
        if (view) view.textContent = formatted;
        if (mini) mini.textContent = formatted;
    }

    function fitCertPreview() {
        const viewport = document.getElementById('previewViewport');
        const scaler = document.getElementById('previewScaler');
        const zoom = document.getElementById('previewZoom');
        const canvas = document.getElementById('certCanvas');
        if (!viewport || !scaler || !canvas) return;

        const vr = viewport.getBoundingClientRect();
        const pad = 24;
        const availW = Math.max(100, vr.width - pad);
        const availH = Math.max(100, vr.height - pad);

        let scale = Math.min(availW / CERT_W, availH / CERT_H);
        if (!isFinite(scale) || scale <= 0) {
            scale = 0.35;
        }
        // Never upscale above 100%; always shrink to fit the visible panel.
        scale = Math.min(scale, 1);

        const w = Math.max(1, Math.round(CERT_W * scale));
        const h = Math.max(1, Math.round(CERT_H * scale));

        scaler.style.width = w + 'px';
        scaler.style.height = h + 'px';
        scaler.style.overflow = 'hidden';
        scaler.style.position = 'relative';

        // Prefer scaling #previewZoom (export leaves #certCanvas unscaled).
        if (zoom) {
            zoom.style.position = 'absolute';
            zoom.style.top = '0';
            zoom.style.left = '0';
            zoom.style.width = CERT_W + 'px';
            zoom.style.height = CERT_H + 'px';
            zoom.style.transformOrigin = '0 0';
            zoom.style.transform = 'scale(' + scale + ')';
            canvas.style.transform = 'none';
            canvas.style.zoom = '';
        } else {
            // Fallback if zoom wrapper missing
            canvas.style.transformOrigin = '0 0';
            canvas.style.transform = 'scale(' + scale + ')';
        }

        canvas.dataset.previewScale = String(scale);

        const hint = document.getElementById('previewFitHint');
        if (hint) {
            hint.textContent = 'Fits screen · ' + Math.round(scale * 100) + '%';
        }
    }

    const $eventSelect = $('#f_event_select');
    const $recipientSelect = $('#f_recipient_select');
    let staffCacheKey = '';
    let staffLoading = false;

    function getStaffRole() {
        const r = document.querySelector('input[name="cert_staff_role"]:checked');
        return r ? r.value : 'participant';
    }

    function setStaffRole(role) {
        document.getElementById('f_type').value = role;
        const isVol = role === 'volunteer';
        const lbl = document.getElementById('lbl_recipient_select');
        const lblMan = document.getElementById('lbl_participant_manual');
        const t = isVol ? 'Volunteer' : 'Participant';
        if (lbl) lbl.textContent = t;
        if (lblMan) lblMan.textContent = (isVol ? 'Volunteer' : 'Participant') + ' name (manual)';
    }

    function updateLinkHint() {
        const hint = document.getElementById('cert_link_hint');
        if (!hint) return;
        const eid = parseInt(document.getElementById('f_event_id').value, 10) || 0;
        const uid = parseInt(document.getElementById('f_user_id').value, 10) || 0;
        const role = getStaffRole();
        if (eid > 0 && uid > 0) {
            hint.style.display = '';
            hint.textContent = 'Save to app user: Event #' + eid + ', User #' + uid + ' (' + role + ')';
        } else if (eid > 0 || uid > 0) {
            hint.style.display = '';
            hint.textContent = 'Save to user needs both event and user from the lists (or turn off manual entry).';
        } else {
            hint.style.display = 'none';
            hint.textContent = '';
        }
    }

    function applyAchievementFromPosition(pos) {
        const sel = document.getElementById('f_achievement');
        if (!sel || pos < 1 || pos > 3) return;
        const map = { 1: 'first', 2: 'second', 3: 'third' };
        if (map[pos]) {
            sel.value = map[pos];
            syncAchievement();
        }
    }

    function applyEventMeta(eventId) {
        const ev = CERT_EVENTS.find(function (e) { return e.id === eventId; });
        if (!ev) return;
        const titleEl = document.getElementById('f_event_title');
        if (titleEl && !document.getElementById('f_manual_event').checked) {
            titleEl.value = ev.title;
            syncField('event_title');
        }
        const orgEl = document.getElementById('f_organised_by');
        if (orgEl && ev.organised_by && !orgEl.value.trim()) {
            orgEl.value = ev.organised_by;
            syncField('organised_by');
        } else if (orgEl && ev.organised_by) {
            orgEl.value = ev.organised_by;
            syncField('organised_by');
        }
    }

    function applyEventFromSelect() {
        const manual = document.getElementById('f_manual_event').checked;
        const hid = document.getElementById('f_event_id');
        if (manual) {
            if (hid) hid.value = '0';
            updateLinkHint();
            updateSaveButton();
            return;
        }
        const val = parseInt($eventSelect.val(), 10) || 0;
        if (hid) hid.value = String(val);
        const opt = $eventSelect.find('option:selected');
        const title = opt.data('title') || opt.attr('data-title') || '';
        const organised = opt.data('organised') || opt.attr('data-organised') || '';
        const titleEl = document.getElementById('f_event_title');
        if (titleEl && title) {
            titleEl.value = title;
            syncField('event_title');
        }
        const orgEl = document.getElementById('f_organised_by');
        if (orgEl && organised) {
            orgEl.value = organised;
            syncField('organised_by');
        }
        applyEventMeta(val);
        updateLinkHint();
        updateSaveButton();
        if (val > 0) loadStaffForEvent(val);
    }

    function fillRecipientOptions(items, selectedUserId) {
        const placeholder = getStaffRole() === 'volunteer' ? 'Search volunteers…' : 'Search participants…';
        $recipientSelect.empty().append(new Option('', '', false, false));
        items.forEach(function (row) {
            const opt = new Option(row.text, String(row.id), false, false);
            opt.setAttribute('data-name', row.full_name);
            opt.setAttribute('data-position', String(row.position || 0));
            $recipientSelect.append(opt);
        });
        if (selectedUserId > 0) {
            $recipientSelect.val(String(selectedUserId));
        }
        $recipientSelect.trigger('change.select2');
    }

    function loadStaffForEvent(eventId, selectedUserId) {
        const uid = selectedUserId || (parseInt($recipientSelect.val(), 10) || 0);
        const role = getStaffRole();
        const key = eventId + ':' + role;
        if (!eventId) {
            fillRecipientOptions([], 0);
            return Promise.resolve();
        }
        if (staffCacheKey === key && $recipientSelect.find('option').length > 1) {
            if (uid > 0) $recipientSelect.val(String(uid)).trigger('change.select2');
            return Promise.resolve();
        }
        staffLoading = true;
        $recipientSelect.prop('disabled', true);
        return fetch('certificate_generator_lookup.php?action=staff&event_id=' + eventId + '&type=' + encodeURIComponent(role), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                staffLoading = false;
                $recipientSelect.prop('disabled', false);
                if (data.status !== 'success') {
                    fillRecipientOptions([], 0);
                    return;
                }
                staffCacheKey = key;
                fillRecipientOptions(data.results || [], uid);
                if (uid > 0) applyRecipientFromSelect();
            })
            .catch(function () {
                staffLoading = false;
                $recipientSelect.prop('disabled', false);
                fillRecipientOptions([], 0);
            });
    }

    function applyRecipientFromSelect() {
        const manual = document.getElementById('f_manual_recipient').checked;
        const hid = document.getElementById('f_user_id');
        if (manual) {
            if (hid) hid.value = '0';
            syncParticipantNamePreview();
            updateLinkHint();
            updateSaveButton();
            return;
        }
        const val = parseInt($recipientSelect.val(), 10) || 0;
        const opt = $recipientSelect.find('option:selected');
        const name = opt.data('name') || opt.attr('data-name') || opt.text() || '';
        const pos = parseInt(opt.data('position') || opt.attr('data-position') || '0', 10) || 0;
        if (hid) hid.value = String(val);
        const nameEl = document.getElementById('f_participant_name');
        if (nameEl && name) {
            nameEl.value = name;
            syncParticipantNamePreview();
        }
        if (pos > 0) applyAchievementFromPosition(pos);
        updateLinkHint();
        updateSaveButton();
    }

    function toggleManualEvent() {
        const manual = document.getElementById('f_manual_event').checked;
        document.getElementById('wrap_event_select').style.display = manual ? 'none' : '';
        document.getElementById('wrap_event_manual').style.display = manual ? '' : 'none';
        if (manual) {
            $eventSelect.val(null).trigger('change.select2');
            document.getElementById('f_event_id').value = '0';
            syncField('event_title');
        } else {
            applyEventFromSelect();
        }
        updateLinkHint();
        updateSaveButton();
        persistTemplate();
    }

    function toggleManualRecipient() {
        const manual = document.getElementById('f_manual_recipient').checked;
        document.getElementById('wrap_recipient_select').style.display = manual ? 'none' : '';
        document.getElementById('wrap_recipient_manual').style.display = manual ? '' : 'none';
        if (manual) {
            $recipientSelect.val(null).trigger('change.select2');
            document.getElementById('f_user_id').value = '0';
            syncParticipantNamePreview();
        } else {
            applyRecipientFromSelect();
        }
        updateLinkHint();
        updateSaveButton();
        persistTemplate();
    }

    function initCertSelect2() {
        const s2opts = { theme: 'bootstrap-5', width: '100%', allowClear: true };
        $eventSelect.select2(Object.assign({}, s2opts, {
            placeholder: $eventSelect.data('placeholder') || 'Search past events…',
            ajax: {
                url: 'certificate_generator_lookup.php',
                dataType: 'json',
                delay: 250,
                credentials: 'same-origin',
                data: function (params) {
                    return { action: 'events', q: params.term || '' };
                },
                processResults: function (data) {
                    const rows = (data && data.results) ? data.results : [];
                    return {
                        results: rows.map(function (e) {
                            return {
                                id: e.id,
                                text: e.text,
                                title: e.title,
                                organised_by: e.organised_by
                            };
                        })
                    };
                }
            }
        }));
        $recipientSelect.select2(Object.assign({}, s2opts, {
            placeholder: function () {
                return getStaffRole() === 'volunteer' ? 'Search volunteers…' : 'Search participants…';
            }
        }));
        $eventSelect.on('select2:select', function (e) {
            const d = e.params.data;
            if (d && d.id) {
                const exists = $eventSelect.find('option[value="' + d.id + '"]').length;
                if (!exists) {
                    const opt = new Option(d.text, d.id, true, true);
                    opt.setAttribute('data-title', d.title || '');
                    opt.setAttribute('data-organised', d.organised_by || '');
                    $eventSelect.append(opt);
                }
            }
            applyEventFromSelect();
            persistTemplate();
        });
        $eventSelect.on('select2:clear change', function () {
            if (!$eventSelect.val()) {
                document.getElementById('f_event_id').value = '0';
                fillRecipientOptions([], 0);
                staffCacheKey = '';
                updateLinkHint();
                updateSaveButton();
            }
        });
        $recipientSelect.on('select2:select change', function () {
            if (!staffLoading) applyRecipientFromSelect();
            persistTemplate();
        });
    }

    document.querySelectorAll('input[name="cert_staff_role"]').forEach(function (el) {
        el.addEventListener('change', function () {
            setStaffRole(this.value);
            staffCacheKey = '';
            const eid = parseInt(document.getElementById('f_event_id').value, 10) || 0;
            if (eid > 0 && !document.getElementById('f_manual_event').checked) {
                loadStaffForEvent(eid, parseInt(document.getElementById('f_user_id').value, 10) || 0);
            }
            persistTemplate();
        });
    });
    document.getElementById('f_manual_event').addEventListener('change', toggleManualEvent);
    document.getElementById('f_manual_recipient').addEventListener('change', toggleManualRecipient);

    const fields = {
        university_name: ['f_university_name', 'v_university_name'],
        university_location: ['f_university_location', 'v_university_location'],
        university_establishment: ['f_university_establishment', 'v_university_establishment'],
        university_motto: ['f_university_motto', 'v_university_motto'],
        participant_name: ['f_participant_name', 'v_participant_name'],
        event_title: ['f_event_title', 'v_event_title'],
        organised_by: ['f_organised_by', 'v_organised_by'],
        signatory_title: ['f_signatory_title', 'v_signatory_title'],
        signatory_location: ['f_signatory_location', 'v_signatory_location'],
        certificate_date: ['f_certificate_date', 'v_certificate_date'],
        certificate_id: ['f_certificate_id', 'v_certificate_id']
    };

    function syncField(key) {
        const [fid, vid] = fields[key];
        const input = document.getElementById(fid);
        const view = document.getElementById(vid);
        if (!input || !view) return;
        let val = input.value.trim();
        if (key === 'event_title') {
            view.textContent = val ? ('\u201C' + val + '\u201D') : '\u201CEvent Title Goes Here\u201D';
        } else if (key === 'participant_name') {
            syncParticipantNamePreview();
            return;
        } else {
            view.textContent = val;
        }
        if (key === 'university_name') {
            const uni = document.getElementById('v_signatory_uni');
            if (uni) uni.textContent = val;
        }
        updateSaveButton();
    }

    function syncAchievement() {
        const sel = document.getElementById('f_achievement');
        const view = document.getElementById('v_achievement');
        if (sel && view) view.innerHTML = ACHIEVEMENT_HTML[sel.value] || ACHIEVEMENT_HTML.participated;
    }

    function syncLogoTagline() {
        const raw = (document.getElementById('f_logo_tagline').value || '').trim();
        const view = document.getElementById('v_logo_tagline');
        if (!view) return;
        const lines = raw.split(/\r?\n/).map(s => s.trim()).filter(Boolean);
        view.innerHTML = lines.length ? lines.map(l => escapeHtml(l)).join('<br>') : '';
    }

    function escapeHtml(s) {
        const d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    function applyLogoVisibility() {
        const show = document.getElementById('f_logo_show').checked;
        const block = document.getElementById('cert_logo_block');
        const src = (document.getElementById('cert_logo_img').getAttribute('src') || '').trim();
        if (block) block.style.display = (show && src) ? '' : 'none';
    }

    function setLogoSrc(url) {
        const img = document.getElementById('cert_logo_img');
        const preview = document.getElementById('logoFormPreview');
        const urlInput = document.getElementById('f_logo_url');
        if (!img) return;
        const u = (url || '').trim();
        if (u) {
            img.src = u;
            img.style.display = '';
            img.onload = function () { fitCertPreview(); };
            if (preview) { preview.src = u; preview.style.display = ''; }
            if (urlInput && urlInput.value.trim() !== u.split('?')[0]) urlInput.value = u.split('?')[0];
            fitCertPreview();
        } else {
            img.removeAttribute('src');
            img.style.display = 'none';
            if (preview) preview.style.display = 'none';
        }
        applyLogoVisibility();
        persistTemplate();
        fitCertPreview();
    }

    document.getElementById('f_logo_show').addEventListener('change', function () {
        applyLogoVisibility();
        persistTemplate();
    });

    document.getElementById('f_logo_tagline').addEventListener('input', function () {
        syncLogoTagline();
        persistTemplate();
    });

    document.getElementById('f_logo_url').addEventListener('change', function () {
        setLogoSrc(this.value.trim());
    });
    document.getElementById('f_logo_url').addEventListener('input', function () {
        clearTimeout(window._logoUrlDebounce);
        window._logoUrlDebounce = setTimeout(() => setLogoSrc(this.value.trim()), 400);
    });

    document.getElementById('f_logo_file').addEventListener('change', function () {
        const file = this.files && this.files[0];
        if (!file) return;
        if (file.size > 2 * 1024 * 1024) {
            Swal.fire('Too large', 'Logo must be 2 MB or less.', 'warning');
            this.value = '';
            return;
        }
        const reader = new FileReader();
        reader.onload = function () {
            setLogoSrc(reader.result);
            document.getElementById('f_logo_url').value = '';
        };
        reader.readAsDataURL(file);
    });

    document.getElementById('btnLogoReset').addEventListener('click', function () {
        document.getElementById('f_logo_file').value = '';
        document.getElementById('f_logo_url').value = FALLBACK_LOGO_URL;
        setLogoSrc(FALLBACK_LOGO_URL);
        document.getElementById('f_logo_tagline').value = "EMPOWERING YOUTH\nTRANSFORMING LIVES";
        document.getElementById('f_logo_show').checked = true;
        syncLogoTagline();
        localStorage.removeItem(LOGO_STORAGE_KEY);
    });

    function applyQrVisibility() {
        const show = document.getElementById('f_qr_show').checked;
        const src = (document.getElementById('cert_qr_img').getAttribute('src') || '').trim();
        const hasImg = !!src;
        const qrVisible = show && hasImg;
        const block = document.getElementById('cert_qr_block');
        if (block) block.style.opacity = '1';
        const box = document.getElementById('cert_qr_box');
        if (box) box.style.display = qrVisible ? '' : 'none';
        const label = document.getElementById('v_qr_scan_label');
        if (label) label.style.display = qrVisible ? '' : 'none';
        return hasImg;
    }

    function setQrSrc(url) {
        const img = document.getElementById('cert_qr_img');
        const placeholder = document.getElementById('cert_qr_placeholder');
        const preview = document.getElementById('qrFormPreview');
        const previewWrap = document.getElementById('qrFormPreviewWrap');
        const urlInput = document.getElementById('f_qr_url');
        if (!img) return;
        const u = (url || '').trim();
        if (u) {
            img.src = u;
            img.style.display = 'block';
            img.onload = function () { fitCertPreview(); };
            if (placeholder) placeholder.style.display = 'none';
            if (preview) { preview.src = u; previewWrap && (previewWrap.style.display = ''); }
            if (urlInput && urlInput.value.trim() !== u.split('?')[0]) urlInput.value = u.split('?')[0];
            fitCertPreview();
        } else {
            img.removeAttribute('src');
            img.style.display = 'none';
            if (placeholder) placeholder.style.display = '';
            if (previewWrap) previewWrap.style.display = 'none';
            if (urlInput) urlInput.value = '';
        }
        applyQrVisibility();
        persistTemplate();
        fitCertPreview();
    }

    function syncQrLabel() {
        const val = (document.getElementById('f_qr_scan_label').value || '').trim();
        const view = document.getElementById('v_qr_scan_label');
        if (view) view.textContent = val || 'Scan to Verify';
    }

    document.getElementById('f_qr_show').addEventListener('change', function () {
        applyQrVisibility();
        persistTemplate();
    });

    document.getElementById('f_qr_scan_label').addEventListener('input', function () {
        syncQrLabel();
        persistTemplate();
    });

    document.getElementById('f_qr_url').addEventListener('change', function () {
        setQrSrc(this.value.trim());
    });
    document.getElementById('f_qr_url').addEventListener('input', function () {
        clearTimeout(window._qrUrlDebounce);
        window._qrUrlDebounce = setTimeout(() => setQrSrc(this.value.trim()), 400);
    });

    document.getElementById('f_qr_file').addEventListener('change', function () {
        const file = this.files && this.files[0];
        if (!file) return;
        if (file.size > 2 * 1024 * 1024) {
            Swal.fire('Too large', 'QR image must be 2 MB or less.', 'warning');
            this.value = '';
            return;
        }
        const reader = new FileReader();
        reader.onload = function () {
            setQrSrc(reader.result);
            document.getElementById('f_qr_url').value = '';
        };
        reader.readAsDataURL(file);
    });

    document.getElementById('btnQrReset').addEventListener('click', function () {
        document.getElementById('f_qr_file').value = '';
        setQrSrc('');
        document.getElementById('f_qr_scan_label').value = 'Scan to Verify';
        document.getElementById('f_qr_show').checked = true;
        syncQrLabel();
        localStorage.removeItem(QR_STORAGE_KEY);
    });

    document.getElementById('btnQrSaveDefault').addEventListener('click', function () {
        const fileInput = document.getElementById('f_qr_file');
        if (!fileInput.files || !fileInput.files[0]) {
            Swal.fire('No file', 'Choose a QR code image file to upload as the shared default.', 'warning');
            return;
        }
        const fd = new FormData();
        fd.append('qr', fileInput.files[0]);
        fetch('certificate_qr_upload.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    setQrSrc(data.qr_url);
                    document.getElementById('f_qr_url').value = data.qr_url.split('?')[0];
                    Swal.fire('Saved', data.message, 'success');
                } else {
                    Swal.fire('Error', data.message || 'Upload failed', 'error');
                }
            })
            .catch(e => Swal.fire('Error', e.message || 'Upload failed', 'error'));
    });

    function applySealVisibility() {
        const show = document.getElementById('f_seal_show').checked;
        const sealPart = document.getElementById('cert_seal_wrap');
        const img = document.getElementById('cert_seal_img');
        const has = img && (img.getAttribute('src') || '').trim();
        if (sealPart) sealPart.style.display = (show && has) ? '' : 'none';
    }

    function setSealSrc(url) {
        const img = document.getElementById('cert_seal_img');
        const preview = document.getElementById('sealFormPreview');
        const urlInput = document.getElementById('f_seal_url');
        if (!img) return;
        const u = (url || '').trim();
        if (u) {
            img.src = u;
            img.style.display = 'block';
            img.onload = function () { fitCertPreview(); };
            if (preview) preview.src = u;
            if (urlInput && urlInput.value.trim() !== u.split('?')[0]) urlInput.value = u.split('?')[0];
            fitCertPreview();
        } else {
            img.removeAttribute('src');
            img.style.display = 'none';
        }
        applySealVisibility();
        persistTemplate();
        fitCertPreview();
    }

    document.getElementById('f_seal_show').addEventListener('change', function () {
        applySealVisibility();
        persistTemplate();
    });

    document.getElementById('f_seal_url').addEventListener('change', function () { setSealSrc(this.value.trim()); });
    document.getElementById('f_seal_url').addEventListener('input', function () {
        clearTimeout(window._sealUrlDebounce);
        window._sealUrlDebounce = setTimeout(() => setSealSrc(this.value.trim()), 400);
    });

    document.getElementById('f_seal_file').addEventListener('change', function () {
        const file = this.files && this.files[0];
        if (!file) return;
        if (file.size > 2 * 1024 * 1024) {
            Swal.fire('Too large', 'Seal image must be 2 MB or less.', 'warning');
            this.value = '';
            return;
        }
        const reader = new FileReader();
        reader.onload = function () {
            setSealSrc(reader.result);
            document.getElementById('f_seal_url').value = '';
        };
        reader.readAsDataURL(file);
    });

    document.getElementById('btnSealReset').addEventListener('click', function () {
        document.getElementById('f_seal_file').value = '';
        document.getElementById('f_seal_url').value = FALLBACK_SEAL_URL;
        setSealSrc(FALLBACK_SEAL_URL);
        document.getElementById('f_seal_show').checked = true;
        localStorage.removeItem(SEAL_STORAGE_KEY);
    });

    document.getElementById('btnSealSaveDefault').addEventListener('click', function () {
        const fileInput = document.getElementById('f_seal_file');
        if (!fileInput.files || !fileInput.files[0]) {
            Swal.fire('No file', 'Choose a seal image file to upload as the shared default.', 'warning');
            return;
        }
        const fd = new FormData();
        fd.append('seal', fileInput.files[0]);
        fetch('certificate_seal_upload.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    setSealSrc(data.seal_url);
                    document.getElementById('f_seal_url').value = data.seal_url.split('?')[0];
                    Swal.fire('Saved', data.message, 'success');
                } else {
                    Swal.fire('Error', data.message || 'Upload failed', 'error');
                }
            })
            .catch(e => Swal.fire('Error', e.message || 'Upload failed', 'error'));
    });

    document.getElementById('btnLogoSaveDefault').addEventListener('click', function () {
        const fileInput = document.getElementById('f_logo_file');
        const urlVal = document.getElementById('f_logo_url').value.trim();
        const fd = new FormData();
        if (fileInput.files && fileInput.files[0]) {
            fd.append('logo', fileInput.files[0]);
        } else if (urlVal.startsWith('data:image/')) {
            Swal.fire('Tip', 'Use “Upload logo” with an image file to save as the shared default, or upload from your computer.', 'info');
            return;
        } else {
            Swal.fire('No file', 'Choose an image file to upload as the default logo.', 'warning');
            return;
        }
        fetch('certificate_logo_upload.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    setLogoSrc(data.logo_url);
                    document.getElementById('f_logo_url').value = data.logo_url.split('?')[0];
                    Swal.fire('Saved', data.message, 'success');
                } else {
                    Swal.fire('Error', data.message || 'Upload failed', 'error');
                }
            })
            .catch(e => Swal.fire('Error', e.message || 'Upload failed', 'error'));
    });

    document.querySelectorAll('.cert-field').forEach(el => {
        el.addEventListener('input', function () {
            const id = this.id.replace('f_', '');
            if (id === 'achievement') syncAchievement();
            else syncField(id);
        });
        el.addEventListener('change', function () {
            const id = this.id.replace('f_', '');
            if (id === 'achievement') syncAchievement();
            else syncField(id);
        });
    });

    document.getElementById('f_achievement').addEventListener('change', syncAchievement);

    function updateSaveButton() {
        const eid = parseInt(document.getElementById('f_event_id').value, 10) || 0;
        const uid = parseInt(document.getElementById('f_user_id').value, 10) || 0;
        document.getElementById('btnSaveToUser').disabled = !(eid > 0 && uid > 0);
    }

    function waitCertImages() {
        const canvas = document.getElementById('certCanvas');
        if (!canvas) return Promise.resolve();
        const imgs = canvas.querySelectorAll('img');
        const pending = [];
        imgs.forEach(function (img) {
            if (!img.getAttribute('src')) return;
            if (img.complete && img.naturalWidth > 0) return;
            // Resolve on load/error AND after a timeout so a slow or broken image
            // can never leave the Download/Save button stuck in a loading state.
            pending.push(new Promise(function (resolve) {
                let done = false;
                const finish = function () { if (!done) { done = true; resolve(); } };
                img.onload = finish;
                img.onerror = finish;
                setTimeout(finish, 8000);
            }));
        });
        return pending.length ? Promise.all(pending) : Promise.resolve();
    }

    async function renderCanvas() {
        applyQrVisibility();
        const canvas = document.getElementById('certCanvas');
        const zoom = document.getElementById('previewZoom');
        const prevZoomTransform = zoom ? zoom.style.transform : '';
        const prevTransform = canvas.style.transform;
        const prevOverflow = canvas.style.overflow;
        // Export at full size: clear preview zoom on wrapper + canvas.
        if (zoom) zoom.style.transform = 'none';
        canvas.style.transform = 'none';
        canvas.style.overflow = 'visible';
        await waitCertImages();
        const out = await html2canvas(canvas, {
            scale: 2,
            useCORS: true,
            allowTaint: false,
            backgroundColor: '#faf8f4',
            logging: false,
            width: CERT_W,
            height: CERT_H,
            windowWidth: CERT_W,
            windowHeight: CERT_H,
            x: 0,
            y: 0,
            scrollX: 0,
            scrollY: 0,
            onclone: function (doc) {
                const clone = doc.getElementById('certCanvas');
                if (clone) {
                    clone.style.transform = 'none';
                    clone.style.overflow = 'visible';
                    clone.style.position = 'relative';
                }
                const z = doc.getElementById('previewZoom');
                if (z) z.style.transform = 'none';
                const sign = doc.getElementById('cert_sign_block');
                if (sign) {
                    sign.style.display = 'block';
                    sign.style.visibility = 'visible';
                    sign.style.opacity = '1';
                    sign.style.zIndex = '5';
                    sign.style.bottom = '10.5%';
                    sign.style.right = '6.5%';
                    sign.style.width = '24%';
                    sign.style.color = '#1a2d5a';
                }
                const show = doc.getElementById('f_qr_show');
                const qrImg = doc.getElementById('cert_qr_img');
                const qrOk = show && show.checked && qrImg && (qrImg.getAttribute('src') || '').trim();
                const box = doc.getElementById('cert_qr_box');
                const label = doc.getElementById('v_qr_scan_label');
                if (box) box.style.display = qrOk ? '' : 'none';
                if (label) label.style.display = qrOk ? '' : 'none';
            }
        });
        if (zoom) zoom.style.transform = prevZoomTransform;
        canvas.style.transform = prevTransform;
        canvas.style.overflow = prevOverflow || '';
        return out;
    }

    document.getElementById('btnPrint').addEventListener('click', function () {
        window.print();
    });

    document.getElementById('btnDownloadPng').addEventListener('click', async function () {
        const btn = this;
        btn.disabled = true;
        try {
            const canvas = await renderCanvas();
            const a = document.createElement('a');
            a.download = 'certificate_' + (document.getElementById('f_certificate_id').value || 'export').replace(/[^\w\-/]/g, '_') + '.png';
            a.href = canvas.toDataURL('image/png');
            a.click();
        } catch (e) {
            Swal.fire('Error', e.message || 'Could not export image', 'error');
        }
        btn.disabled = false;
    });

    document.getElementById('btnSaveToUser').addEventListener('click', async function () {
        const eventId = parseInt(document.getElementById('f_event_id').value, 10) || 0;
        const userId = parseInt(document.getElementById('f_user_id').value, 10) || 0;
        const type = document.getElementById('f_type').value;
        if (eventId <= 0 || userId <= 0) {
            Swal.fire('Missing link', 'Select a past event and enter the app user ID.', 'warning');
            return;
        }
        const btn = this;
        btn.disabled = true;
        try {
            const canvas = await renderCanvas();
            const dataUrl = canvas.toDataURL('image/png');
            const fd = new FormData();
            fd.append('event_id', String(eventId));
            fd.append('user_id', String(userId));
            fd.append('type', type);
            fd.append('image_data', dataUrl);
            const res = await fetch('save_generated_certificate.php', { method: 'POST', body: fd, credentials: 'same-origin' });
            const data = await res.json();
            if (data.status === 'success') {
                Swal.fire('Saved', data.message, 'success');
            } else {
                Swal.fire('Error', data.message || 'Save failed', 'error');
            }
        } catch (e) {
            Swal.fire('Error', e.message || 'Save failed', 'error');
        }
        btn.disabled = false;
        updateSaveButton();
    });

    // Persist template defaults in browser for next visit
    const STORAGE_KEY = 'gnu_cert_template_v1';
    const LOGO_STORAGE_KEY = 'gnu_cert_logo_v1';
    const SEAL_STORAGE_KEY = 'gnu_cert_seal_v1';
    const QR_STORAGE_KEY = 'gnu_cert_qr_v1';

    function persistTemplate() {
        const o = {};
        document.querySelectorAll('.cert-field').forEach(f => { o[f.id] = f.value; });
        o.f_logo_show = document.getElementById('f_logo_show').checked;
        o.f_logo_url = document.getElementById('f_logo_url').value.trim();
        o.f_logo_tagline = document.getElementById('f_logo_tagline').value;
        o.f_qr_show = document.getElementById('f_qr_show').checked;
        o.f_qr_url = document.getElementById('f_qr_url').value.trim();
        o.f_qr_scan_label = document.getElementById('f_qr_scan_label').value;
        o.f_seal_show = document.getElementById('f_seal_show').checked;
        o.f_seal_url = document.getElementById('f_seal_url').value.trim();
        o.f_manual_event = document.getElementById('f_manual_event').checked;
        o.f_manual_recipient = document.getElementById('f_manual_recipient').checked;
        o.f_event_select = $eventSelect.val() || '';
        o.f_recipient_select = $recipientSelect.val() || '';
        o.cert_staff_role = getStaffRole();
        localStorage.setItem(STORAGE_KEY, JSON.stringify(o));
        const img = document.getElementById('cert_logo_img');
        const src = img ? (img.getAttribute('src') || '') : '';
        if (src.startsWith('data:image/') && src.length < 900000) {
            try { localStorage.setItem(LOGO_STORAGE_KEY, src); } catch (e) { /* quota */ }
        }
        const qrImg = document.getElementById('cert_qr_img');
        const qrSrc = qrImg ? (qrImg.getAttribute('src') || '') : '';
        if (qrSrc.startsWith('data:image/') && qrSrc.length < 900000) {
            try { localStorage.setItem(QR_STORAGE_KEY, qrSrc); } catch (e) { /* quota */ }
        }
        const sealImg = document.getElementById('cert_seal_img');
        const sealSrc = sealImg ? (sealImg.getAttribute('src') || '') : '';
        if (sealSrc.startsWith('data:image/') && sealSrc.length < 900000) {
            try { localStorage.setItem(SEAL_STORAGE_KEY, sealSrc); } catch (e) { /* quota */ }
        }
    }

    function loadSavedTemplate() {
        if (window.location.search.includes('event_id')) return;
        let o = null;
        try {
            const saved = localStorage.getItem(STORAGE_KEY);
            if (saved) {
                o = JSON.parse(saved);
                document.querySelectorAll('.cert-field').forEach(f => {
                    if (o[f.id] !== undefined) f.value = o[f.id];
                });
                if (o.f_logo_show !== undefined) document.getElementById('f_logo_show').checked = !!o.f_logo_show;
                if (o.f_logo_tagline !== undefined) document.getElementById('f_logo_tagline').value = o.f_logo_tagline;
                if (o.f_qr_show !== undefined) document.getElementById('f_qr_show').checked = !!o.f_qr_show;
                if (o.f_qr_scan_label !== undefined) document.getElementById('f_qr_scan_label').value = o.f_qr_scan_label;
                if (o.f_seal_show !== undefined) document.getElementById('f_seal_show').checked = !!o.f_seal_show;
                if (o.f_manual_event !== undefined) document.getElementById('f_manual_event').checked = !!o.f_manual_event;
                if (o.f_manual_recipient !== undefined) document.getElementById('f_manual_recipient').checked = !!o.f_manual_recipient;
                if (o.cert_staff_role) {
                    const radio = document.querySelector('input[name="cert_staff_role"][value="' + o.cert_staff_role + '"]');
                    if (radio) radio.checked = true;
                }
            }
            const logoData = localStorage.getItem(LOGO_STORAGE_KEY);
            if (logoData) {
                setLogoSrc(logoData);
            } else if (o && o.f_logo_url) {
                setLogoSrc(o.f_logo_url);
            }
            const qrData = localStorage.getItem(QR_STORAGE_KEY);
            if (qrData) {
                setQrSrc(qrData);
            } else if (o && o.f_qr_url) {
                setQrSrc(o.f_qr_url);
            } else if (DEFAULT_QR_URL) {
                setQrSrc(DEFAULT_QR_URL);
            }
            const sealData = localStorage.getItem(SEAL_STORAGE_KEY);
            if (sealData) {
                setSealSrc(sealData);
            } else if (o && o.f_seal_url) {
                setSealSrc(o.f_seal_url);
            } else if (DEFAULT_SEAL_URL) {
                setSealSrc(DEFAULT_SEAL_URL);
            }
        } catch (e) { /* ignore */ }
    }

    document.querySelectorAll('.cert-field').forEach(el => {
        el.addEventListener('change', persistTemplate);
        el.addEventListener('input', persistTemplate);
    });

    initCertSelect2();
    setStaffRole(getStaffRole());

    loadSavedTemplate();
    toggleManualEvent();
    toggleManualRecipient();

    if (INITIAL_EVENT_ID > 0 && !document.getElementById('f_manual_event').checked) {
        if (!$eventSelect.find('option[value="' + INITIAL_EVENT_ID + '"]').length) {
            const ev0 = CERT_EVENTS.find(function (e) { return e.id === INITIAL_EVENT_ID; });
            if (ev0) {
                const opt = new Option(ev0.text, ev0.id, true, true);
                opt.setAttribute('data-title', ev0.title);
                opt.setAttribute('data-organised', ev0.organised_by || '');
                $eventSelect.append(opt);
            }
        }
        $eventSelect.val(String(INITIAL_EVENT_ID)).trigger('change.select2');
        document.getElementById('f_event_id').value = String(INITIAL_EVENT_ID);
        applyEventMeta(INITIAL_EVENT_ID);
        loadStaffForEvent(INITIAL_EVENT_ID, INITIAL_USER_ID).then(function () {
            if (INITIAL_USER_ID > 0 && !document.getElementById('f_manual_recipient').checked) {
                $recipientSelect.val(String(INITIAL_USER_ID)).trigger('change.select2');
                applyRecipientFromSelect();
            } else if (INITIAL_NAME && !INITIAL_USER_ID) {
                document.getElementById('f_manual_recipient').checked = true;
                toggleManualRecipient();
                document.getElementById('f_participant_name').value = INITIAL_NAME;
                syncParticipantNamePreview();
            }
            updateLinkHint();
        });
    } else if (INITIAL_NAME) {
        document.getElementById('f_manual_recipient').checked = true;
        toggleManualRecipient();
        syncParticipantNamePreview();
    } else if (INITIAL_EVENT_ID === 0) {
        try {
            const saved = localStorage.getItem(STORAGE_KEY);
            if (saved) {
                const o = JSON.parse(saved);
                if (o.f_event_select && !document.getElementById('f_manual_event').checked) {
                    $eventSelect.val(String(o.f_event_select)).trigger('change.select2');
                    applyEventFromSelect();
                    const eid = parseInt(o.f_event_select, 10) || 0;
                    if (eid > 0) {
                        loadStaffForEvent(eid, parseInt(o.f_recipient_select, 10) || 0).then(function () {
                            if (o.f_recipient_select && !document.getElementById('f_manual_recipient').checked) {
                                $recipientSelect.val(String(o.f_recipient_select)).trigger('change.select2');
                                applyRecipientFromSelect();
                            }
                            updateLinkHint();
                        });
                    }
                }
            }
        } catch (e) { /* ignore */ }
    }

    document.querySelectorAll('.cert-field').forEach(el => {
        const id = el.id.replace('f_', '');
        if (id === 'achievement') syncAchievement();
        else syncField(id);
    });
    syncLogoTagline();
    syncQrLabel();
    if (!document.getElementById('cert_logo_img').getAttribute('src')) {
        setLogoSrc(DEFAULT_LOGO_URL);
    } else {
        applyLogoVisibility();
    }
    if (!(document.getElementById('cert_qr_img').getAttribute('src') || '').trim()) {
        if (DEFAULT_QR_URL) setQrSrc(DEFAULT_QR_URL);
    }
    applyQrVisibility();
    if (!(document.getElementById('cert_seal_img').getAttribute('src') || '').trim()) {
        setSealSrc(DEFAULT_SEAL_URL);
    } else {
        applySealVisibility();
    }
    syncAchievement();
    syncParticipantNamePreview();
    fitCertPreview();
    requestAnimationFrame(function () {
        fitCertPreview();
        requestAnimationFrame(fitCertPreview);
    });
    window.addEventListener('load', fitCertPreview);
    window.addEventListener('resize', fitCertPreview);
    if (typeof ResizeObserver !== 'undefined') {
        let fitScheduled = false;
        const scheduleFit = function () {
            if (fitScheduled) return;
            fitScheduled = true;
            requestAnimationFrame(function () {
                fitScheduled = false;
                fitCertPreview();
            });
        };
        const ro = new ResizeObserver(scheduleFit);
        const vp = document.getElementById('previewViewport');
        if (vp) ro.observe(vp);
        const col = document.querySelector('.cert-gen-preview-col');
        if (col) ro.observe(col);
        const shell = document.querySelector('.cert-gen-shell');
        if (shell) ro.observe(shell);
    }
    // After template/bg image loads, re-fit once more.
    const bg = document.querySelector('#certCanvas .cert-bg-img');
    if (bg) {
        if (bg.complete) {
            setTimeout(fitCertPreview, 50);
        } else {
            bg.addEventListener('load', function () { fitCertPreview(); });
        }
    }
    updateLinkHint();
    updateSaveButton();
})();
</script>
</body>
</html>
