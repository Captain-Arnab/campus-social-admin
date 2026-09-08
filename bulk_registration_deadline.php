<?php
/**
 * Bulk Registration Deadline — set registration_deadline on events where it is still NULL.
 * Batch save uses one CASE UPDATE + one multi-row INSERT (single transaction).
 */
session_start();
include 'db.php';
require_once __DIR__ . '/admin_priv.php';
require_once __DIR__ . '/event_date_range_schema.php';

if (!isset($_SESSION['admin']) && !isset($_SESSION['subadmin'])) {
    header('Location: index.php');
    exit();
}
require_priv('events');

$user_type = $_SESSION['user_type'] ?? (isset($_SESSION['admin']) ? 'admin' : 'subadmin');
$username = isset($_SESSION['admin']) ? (string) $_SESSION['admin'] : (string) ($_SESSION['subadmin'] ?? '');

$flash_ok = '';
$flash_err = '';
$flash_warn = '';

/**
 * Normalize HTML datetime-local / free-text into MySQL datetime, or null if invalid.
 */
function brd_normalize_deadline(?string $raw): ?string
{
    $raw = trim(str_replace('T', ' ', (string) $raw));
    if ($raw === '') {
        return null;
    }
    if (strlen($raw) === 16) {
        $raw .= ':00';
    }
    $ts = strtotime($raw);
    if ($ts === false) {
        return null;
    }
    return date('Y-m-d H:i:s', $ts);
}

/**
 * Batch-set registration_deadline for selected events in ONE UPDATE + ONE log INSERT.
 *
 * @param array<int,string> $idToDeadline map event_id => 'Y-m-d H:i:s'
 * @return array{ok:bool,saved:int,message:string,warnings:string[]}
 */
function brd_batch_save(mysqli $conn, array $idToDeadline, string $adminType, string $adminUser): array
{
    if (!schema_events_has_registration_deadline($conn)) {
        return ['ok' => false, 'saved' => 0, 'message' => 'registration_deadline column is missing — run migrations first.', 'warnings' => []];
    }
    if ($idToDeadline === []) {
        return ['ok' => false, 'saved' => 0, 'message' => 'No events selected.', 'warnings' => []];
    }

    $ids = [];
    $caseParts = [];
    $warnings = [];
    foreach ($idToDeadline as $eid => $deadline) {
        $eid = (int) $eid;
        $deadline = brd_normalize_deadline($deadline);
        if ($eid <= 0 || $deadline === null) {
            continue;
        }
        $ids[] = $eid;
        $caseParts[$eid] = $deadline;
    }
    if ($ids === []) {
        return ['ok' => false, 'saved' => 0, 'message' => 'No valid datetimes to save.', 'warnings' => []];
    }

    // Soft warnings: deadline after event_date
    $idList = implode(',', $ids);
    $evRes = $conn->query("SELECT id, title, event_date FROM events WHERE id IN ($idList)");
    $titles = [];
    if ($evRes) {
        while ($row = $evRes->fetch_assoc()) {
            $eid = (int) $row['id'];
            $titles[$eid] = (string) $row['title'];
            if (isset($caseParts[$eid]) && !empty($row['event_date'])) {
                if (strtotime($caseParts[$eid]) > strtotime((string) $row['event_date'])) {
                    $warnings[] = '#' . $eid . ' "' . $row['title'] . '": deadline is after event start';
                }
            }
        }
    }

    $conn->begin_transaction();
    try {
        $caseSql = 'CASE id';
        foreach ($caseParts as $eid => $deadline) {
            $caseSql .= ' WHEN ' . (int) $eid . " THEN '" . $conn->real_escape_string($deadline) . "'";
        }
        $caseSql .= ' END';

        $sql = "UPDATE events
                SET registration_deadline = $caseSql
                WHERE id IN ($idList)
                  AND (registration_deadline IS NULL OR registration_deadline = '0000-00-00 00:00:00')";
        if (!$conn->query($sql)) {
            throw new RuntimeException($conn->error ?: 'Batch UPDATE failed');
        }
        $saved = (int) $conn->affected_rows;

        // Audit: one multi-row INSERT into existing event_status_log
        $logValues = [];
        $atype = $conn->real_escape_string($adminType);
        $auser = $conn->real_escape_string($adminUser);
        foreach ($caseParts as $eid => $deadline) {
            $remarks = $conn->real_escape_string(
                'registration_deadline set to ' . $deadline . ' via bulk admin tool'
            );
            $logValues[] = '(' . (int) $eid . ", '$atype', '$auser', 'NULL', '" . $conn->real_escape_string($deadline) . "', '$remarks')";
        }
        if ($logValues !== []) {
            $logSql = 'INSERT INTO event_status_log (event_id, admin_type, admin_username, old_status, new_status, remarks) VALUES '
                . implode(',', $logValues);
            if (!$conn->query($logSql)) {
                throw new RuntimeException($conn->error ?: 'Audit log INSERT failed');
            }
        }

        $conn->commit();
        return [
            'ok' => true,
            'saved' => $saved > 0 ? count($caseParts) : count($caseParts),
            'message' => 'Saved registration deadline for ' . count($caseParts) . ' event(s).',
            'warnings' => $warnings,
        ];
    } catch (Throwable $e) {
        $conn->rollback();
        return ['ok' => false, 'saved' => 0, 'message' => $e->getMessage(), 'warnings' => []];
    }
}

// ——— POST handlers ———
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'save_selected');

    if ($action === 'save_row') {
        $eid = (int) ($_POST['event_id'] ?? 0);
        $raw = '';
        if (isset($_POST['deadline']) && is_array($_POST['deadline'])) {
            $raw = (string) ($_POST['deadline'][$eid] ?? '');
        } else {
            $raw = (string) ($_POST['deadline'] ?? '');
        }
        $result = brd_batch_save($conn, [$eid => $raw], $user_type, $username);
        if ($result['ok']) {
            $flash_ok = $result['message'];
            if ($result['warnings'] !== []) {
                $flash_warn = implode('; ', $result['warnings']);
            }
        } else {
            $flash_err = $result['message'];
        }
    } elseif ($action === 'save_selected') {
        $selected = $_POST['selected'] ?? [];
        if (!is_array($selected)) {
            $selected = [];
        }
        $deadlines = $_POST['deadline'] ?? [];
        if (!is_array($deadlines)) {
            $deadlines = [];
        }
        $map = [];
        foreach ($selected as $eidRaw) {
            $eid = (int) $eidRaw;
            if ($eid <= 0) {
                continue;
            }
            $map[$eid] = (string) ($deadlines[$eid] ?? '');
        }
        $result = brd_batch_save($conn, $map, $user_type, $username);
        if ($result['ok']) {
            $flash_ok = $result['message'];
            if ($result['warnings'] !== []) {
                $flash_warn = implode('; ', $result['warnings']);
            }
        } else {
            $flash_err = $result['message'];
        }
    }
}

// ——— Load events still missing a deadline ———
$events = [];
$count_missing = 0;
if (schema_events_has_registration_deadline($conn)) {
    $sql = "SELECT e.id, e.title, e.event_date, e.event_end_date, e.status, e.registration_deadline,
                   u.full_name AS host_name
            FROM events e
            JOIN users u ON u.id = e.organizer_id
            WHERE e.registration_deadline IS NULL
               OR e.registration_deadline = '0000-00-00 00:00:00'
            ORDER BY e.event_date ASC, e.id ASC";
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $events[] = $row;
        }
    }
    $count_missing = count($events);
} else {
    $flash_err = 'registration_deadline column is missing. Run migrations/2026_batch_features.sql first.';
}

function brd_dt_local(?string $mysqlDt): string
{
    if ($mysqlDt === null || $mysqlDt === '' || $mysqlDt === '0000-00-00 00:00:00') {
        return '';
    }
    $ts = strtotime($mysqlDt);
    return $ts ? date('Y-m-d\TH:i', $ts) : '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Bulk Registration Deadlines | Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --brand-color: #FF5F15; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: #f8f9fd; color: #2d3436; }
        .main-content { margin-left: 280px; padding: 20px 24px; box-sizing: border-box; max-width: 100%; }
        @media (max-width: 991px) { .main-content { margin-left: 0; padding: 12px; } }
        .card-panel { background: #fff; border-radius: 16px; border: 1px solid #f0f0f0; box-shadow: 0 4px 20px rgba(0,0,0,0.03); }
        .btn-brand { background: var(--brand-color); color: #fff; border: none; border-radius: 12px; font-weight: 700; }
        .btn-brand:hover { background: #e04e0b; color: #fff; }
        .table thead th { font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.04em; color: #95a5a6; font-weight: 800; border-bottom-width: 1px; }
        .deadline-warn { color: #d97706; cursor: help; }
        .status-chip { font-size: 0.7rem; font-weight: 700; padding: 0.2rem 0.55rem; border-radius: 999px; background: #f1f5f9; color: #475569; }
        .bulk-bar { background: #fff7ed; border: 1px solid #fed7aa; border-radius: 14px; }
    </style>
</head>
<body>
<?php include 'sidebar.php'; ?>

<div class="main-content">
    <div class="mb-3 d-flex flex-wrap justify-content-between align-items-start gap-2">
        <div>
            <h4 class="fw-bold m-0">Bulk Registration Deadlines</h4>
            <p class="text-muted small mb-0">
                Only events with <code>registration_deadline</code> still unset.
                Registration stays open indefinitely until a deadline is saved.
            </p>
        </div>
        <a href="events.php?view=live" class="btn btn-outline-secondary btn-sm rounded-3">
            <i class="fas fa-arrow-left me-1"></i>Events
        </a>
    </div>

    <div class="alert alert-secondary rounded-3 py-2 mb-3">
        <strong><?php echo (int) $count_missing; ?></strong> event<?php echo $count_missing === 1 ? '' : 's'; ?> without a registration deadline.
    </div>

    <?php if ($flash_ok !== ''): ?>
        <div class="alert alert-success rounded-3 py-2"><?php echo htmlspecialchars($flash_ok); ?></div>
    <?php endif; ?>
    <?php if ($flash_warn !== ''): ?>
        <div class="alert alert-warning rounded-3 py-2"><i class="fas fa-exclamation-triangle me-1"></i><?php echo htmlspecialchars($flash_warn); ?></div>
    <?php endif; ?>
    <?php if ($flash_err !== ''): ?>
        <div class="alert alert-danger rounded-3 py-2"><?php echo htmlspecialchars($flash_err); ?></div>
    <?php endif; ?>

    <?php if ($count_missing === 0 && schema_events_has_registration_deadline($conn)): ?>
        <div class="card-panel p-4 text-center text-muted">
            <i class="fas fa-check-circle text-success mb-2" style="font-size:1.5rem;"></i>
            <div class="fw-semibold">All events have a registration deadline set.</div>
        </div>
    <?php elseif ($count_missing > 0): ?>

    <form method="post" id="bulkDeadlineForm">
        <input type="hidden" name="action" id="formAction" value="save_selected">

        <div class="bulk-bar p-3 mb-3">
            <div class="row g-3 align-items-end">
                <div class="col-lg-5">
                    <label class="form-label small fw-bold mb-1">Set all selected to fixed datetime</label>
                    <div class="input-group">
                        <input type="datetime-local" class="form-control" id="bulkFixedDt">
                        <button type="button" class="btn btn-outline-dark" id="btnApplyFixed">Apply to selected</button>
                    </div>
                </div>
                <div class="col-lg-5">
                    <label class="form-label small fw-bold mb-1">Set all selected to X days before event date</label>
                    <div class="input-group">
                        <input type="number" class="form-control" id="bulkDaysBefore" min="0" max="365" value="2" style="max-width:100px;">
                        <span class="input-group-text">days before</span>
                        <button type="button" class="btn btn-outline-dark" id="btnApplyRelative">Apply to selected</button>
                    </div>
                </div>
                <div class="col-lg-2">
                    <button type="submit" class="btn btn-brand w-100" id="btnSaveSelected">
                        <i class="fas fa-save me-1"></i>Save Selected
                    </button>
                </div>
            </div>
            <div class="small text-muted mt-2">
                Bulk buttons only fill the row inputs — click <strong>Save Selected</strong> to commit in one batch update.
            </div>
        </div>

        <div class="card-panel">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="width:42px;">
                                <input type="checkbox" class="form-check-input" id="selectAll" title="Select all">
                            </th>
                            <th>Event Name</th>
                            <th>Event Date</th>
                            <th>Host</th>
                            <th>Current Status</th>
                            <th style="min-width:220px;">Set Registration Closing Date &amp; Time</th>
                            <th class="text-end">Row</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($events as $ev):
                            $eid = (int) $ev['id'];
                            $eventDate = (string) ($ev['event_date'] ?? '');
                            $eventPast = $eventDate !== '' && strtotime($eventDate) <= time();
                        ?>
                        <tr data-event-id="<?php echo $eid; ?>" data-event-date="<?php echo htmlspecialchars(brd_dt_local($eventDate)); ?>">
                            <td>
                                <input type="checkbox" class="form-check-input row-check" name="selected[]" value="<?php echo $eid; ?>">
                            </td>
                            <td>
                                <a href="event_details.php?id=<?php echo $eid; ?>" class="fw-semibold text-decoration-none text-dark">
                                    <?php echo htmlspecialchars($ev['title']); ?>
                                </a>
                                <div class="small text-muted">#<?php echo $eid; ?> · <?php echo htmlspecialchars(strtoupper((string) $ev['status'])); ?></div>
                            </td>
                            <td class="text-nowrap small">
                                <?php echo $eventDate ? htmlspecialchars(date('M d, Y h:i A', strtotime($eventDate))) : '—'; ?>
                            </td>
                            <td class="small"><?php echo htmlspecialchars($ev['host_name'] ?? '—'); ?></td>
                            <td>
                                <span class="status-chip" title="No registration_deadline stored; open/closed is not derived from event_date anymore.">
                                    no deadline set<?php echo $eventPast ? ' · event started' : ''; ?>
                                </span>
                            </td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <input type="datetime-local"
                                           class="form-control form-control-sm deadline-input"
                                           name="deadline[<?php echo $eid; ?>]"
                                           data-event-date="<?php echo htmlspecialchars(brd_dt_local($eventDate)); ?>">
                                    <span class="deadline-warn d-none" title="Warning: this deadline is after the event start date.">
                                        <i class="fas fa-exclamation-triangle"></i>
                                    </span>
                                </div>
                            </td>
                            <td class="text-end">
                                <button type="button"
                                        class="btn btn-sm btn-outline-primary rounded-3"
                                        onclick="saveSingleRow(<?php echo $eid; ?>)">
                                    Save
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </form>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    const selectAll = document.getElementById('selectAll');
    const checks = () => Array.from(document.querySelectorAll('.row-check'));

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            checks().forEach(function (cb) { cb.checked = selectAll.checked; });
        });
    }

    function selectedRows() {
        return checks().filter(function (cb) { return cb.checked; }).map(function (cb) {
            return cb.closest('tr');
        });
    }

    function warnIfAfterEvent(input) {
        const wrap = input.closest('td');
        const icon = wrap ? wrap.querySelector('.deadline-warn') : null;
        if (!icon) return;
        const val = (input.value || '').trim();
        const evDt = (input.getAttribute('data-event-date') || '').trim();
        if (!val || !evDt) {
            icon.classList.add('d-none');
            return;
        }
        // Compare as local datetime strings (YYYY-MM-DDTHH:mm)
        if (val > evDt) {
            icon.classList.remove('d-none');
        } else {
            icon.classList.add('d-none');
        }
    }

    document.querySelectorAll('.deadline-input').forEach(function (inp) {
        inp.addEventListener('change', function () { warnIfAfterEvent(inp); });
        inp.addEventListener('input', function () { warnIfAfterEvent(inp); });
    });

    const btnFixed = document.getElementById('btnApplyFixed');
    if (btnFixed) {
        btnFixed.addEventListener('click', function () {
            const dt = (document.getElementById('bulkFixedDt').value || '').trim();
            if (!dt) {
                alert('Pick a datetime first.');
                return;
            }
            const rows = selectedRows();
            if (!rows.length) {
                alert('Select at least one event.');
                return;
            }
            rows.forEach(function (tr) {
                const inp = tr.querySelector('.deadline-input');
                if (inp) {
                    inp.value = dt;
                    warnIfAfterEvent(inp);
                }
            });
        });
    }

    const btnRel = document.getElementById('btnApplyRelative');
    if (btnRel) {
        btnRel.addEventListener('click', function () {
            const days = parseInt(document.getElementById('bulkDaysBefore').value, 10);
            if (isNaN(days) || days < 0) {
                alert('Enter a valid number of days (0 or more).');
                return;
            }
            const rows = selectedRows();
            if (!rows.length) {
                alert('Select at least one event.');
                return;
            }
            rows.forEach(function (tr) {
                const evLocal = tr.getAttribute('data-event-date') || '';
                const inp = tr.querySelector('.deadline-input');
                if (!inp || !evLocal) return;
                // Parse as local time
                const parts = evLocal.match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/);
                if (!parts) return;
                const d = new Date(
                    parseInt(parts[1], 10),
                    parseInt(parts[2], 10) - 1,
                    parseInt(parts[3], 10),
                    parseInt(parts[4], 10),
                    parseInt(parts[5], 10),
                    0
                );
                d.setDate(d.getDate() - days);
                const pad = function (n) { return String(n).padStart(2, '0'); };
                inp.value = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate())
                    + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
                warnIfAfterEvent(inp);
            });
        });
    }

    const form = document.getElementById('bulkDeadlineForm');
    if (form) {
        form.addEventListener('submit', function (e) {
            const actionField = document.getElementById('formAction');
            if (actionField && actionField.value === 'save_selected') {
                const rows = selectedRows();
                if (!rows.length) {
                    e.preventDefault();
                    alert('Select at least one event to save.');
                    return;
                }
                let missing = 0;
                rows.forEach(function (tr) {
                    const inp = tr.querySelector('.deadline-input');
                    if (!inp || !(inp.value || '').trim()) missing++;
                });
                if (missing > 0) {
                    e.preventDefault();
                    alert(missing + ' selected row(s) are missing a closing date/time.');
                    return;
                }
            }
        });
    }

    window.saveSingleRow = function (eid) {
        const tr = document.querySelector('tr[data-event-id="' + eid + '"]');
        if (!tr || !form) return;
        const inp = tr.querySelector('.deadline-input');
        if (!inp || !(inp.value || '').trim()) {
            alert('Pick a closing date/time for this row first.');
            return;
        }
        // Clear other selection state; post only this row via dedicated fields
        let eventIdField = form.querySelector('input[name="event_id"][data-single="1"]');
        if (!eventIdField) {
            eventIdField = document.createElement('input');
            eventIdField.type = 'hidden';
            eventIdField.name = 'event_id';
            eventIdField.setAttribute('data-single', '1');
            form.appendChild(eventIdField);
        }
        eventIdField.value = String(eid);

        const actionField = document.getElementById('formAction');
        if (actionField) actionField.value = 'save_row';

        // Ensure this row's deadline[id] is filled (already is) and submit
        form.submit();
    };

    // Reset action when using main Save Selected
    const btnSave = document.getElementById('btnSaveSelected');
    if (btnSave) {
        btnSave.addEventListener('click', function () {
            const actionField = document.getElementById('formAction');
            if (actionField) actionField.value = 'save_selected';
            const single = form.querySelector('input[name="event_id"][data-single="1"]');
            if (single) single.remove();
        });
    }
})();
</script>
</body>
</html>
