<?php
session_start();
include 'db.php';
require_once __DIR__ . '/admin_priv.php';
require_once __DIR__ . '/event_date_range_schema.php';

if (!isset($_SESSION['admin']) && !isset($_SESSION['subadmin'])) {
    header('Location: index.php');
    exit();
}
require_priv('dashboard');

$user_type = $_SESSION['user_type'] ?? 'admin';
$username = isset($_SESSION['admin']) ? $_SESSION['admin'] : $_SESSION['subadmin'];

$total_events = $conn->query('SELECT * FROM events')->num_rows;
$pending_events = $conn->query("SELECT * FROM events WHERE status='pending'")->num_rows;
$hold_events = $conn->query("SELECT * FROM events WHERE status='hold'")->num_rows;
$active_events_sql = "SELECT COUNT(*) AS c FROM events WHERE status = 'approved'
    AND (" . events_sql_not_past_naked($conn) . ')';
$active_events_row = $conn->query($active_events_sql);
$live_events = $active_events_row ? (int) $active_events_row->fetch_assoc()['c'] : 0;

$pending_requests = $conn->query("
    SELECT e.*, u.full_name as organizer_name
    FROM events e
    JOIN users u ON e.organizer_id = u.id
    WHERE e.status='pending'
    ORDER BY e.created_at DESC
");

$hold_requests = $conn->query("
    SELECT e.*, u.full_name as organizer_name
    FROM events e
    JOIN users u ON e.organizer_id = u.id
    WHERE e.status='hold'
    ORDER BY e.created_at DESC
");

$app_users_count_row = $conn->query('SELECT COUNT(*) AS c FROM users');
$app_users_count = $app_users_count_row ? (int) $app_users_count_row->fetch_assoc()['c'] : 0;

function dashboard_event_poster_src(?string $banners_json): ?string
{
    $banners = json_decode((string) ($banners_json ?? '[]'), true);
    if (!is_array($banners) || empty($banners[0])) {
        return null;
    }
    $raw = trim((string) $banners[0]);
    if ($raw === '') {
        return null;
    }
    if (preg_match('#^https?://#i', $raw)) {
        return $raw;
    }
    $fn = basename(str_replace('\\', '/', $raw));
    if ($fn === '' || $fn === '.' || $fn === '..') {
        return null;
    }
    return 'uploads/events/' . $fn;
}

function dashboard_event_when(array $row): string
{
    $start = date('M d, Y', strtotime($row['event_date']));
    $end = $row['event_end_date'] ?? null;
    if (!empty($end) && $end !== '0000-00-00 00:00:00') {
        return $start . ' → ' . date('M d, Y', strtotime($end));
    }
    return $start;
}

/**
 * @param 'pending'|'hold' $kind
 */
function dashboard_render_event_card(array $row, string $kind): void
{
    $poster = dashboard_event_poster_src($row['banners'] ?? '[]');
    $isHold = ($kind === 'hold');
    $primaryHref = 'event_details.php?id=' . (int) $row['id'];
    $editHref = 'edit_event.php?id=' . (int) $row['id'];
    $primaryLabel = $isHold ? 'Manage' : 'Review';
    $primaryIcon = $isHold ? 'fa-sliders' : 'fa-arrow-right';
    ?>
    <article class="event-queue-card <?php echo $isHold ? 'is-hold' : ''; ?>">
        <div class="event-poster">
            <?php if ($poster): ?>
                <img src="<?php echo htmlspecialchars($poster, ENT_QUOTES, 'UTF-8'); ?>"
                     alt=""
                     loading="lazy"
                     onerror="this.remove(); this.parentElement.classList.add('is-empty');">
            <?php else: ?>
                <div class="event-poster-fallback"><i class="fas fa-image"></i></div>
            <?php endif; ?>
        </div>
        <div class="event-copy min-w-0">
            <div class="event-meta-row">
                <span class="cat-chip <?php echo $isHold ? 'hold' : ''; ?>"><?php echo htmlspecialchars((string) $row['category']); ?></span>
                <?php if ($isHold): ?><span class="status-dot">On hold</span><?php endif; ?>
            </div>
            <h3 class="event-title"><?php echo htmlspecialchars((string) $row['title']); ?></h3>
            <p class="event-sub">
                <span><i class="fas fa-user"></i><?php echo htmlspecialchars((string) $row['organizer_name']); ?></span>
                <span class="sep">·</span>
                <span><i class="far fa-calendar"></i>
                    <?php
                    if ($isHold && !empty($row['reschedule_date'])) {
                        echo 'Reschedule ' . htmlspecialchars(date('M d, Y', strtotime($row['reschedule_date'])));
                    } elseif ($isHold) {
                        echo 'Date TBD';
                    } else {
                        echo htmlspecialchars(dashboard_event_when($row));
                    }
                    ?>
                </span>
            </p>
            <?php if ($isHold && !empty($row['hold_reason'])): ?>
                <p class="hold-note"><i class="fas fa-info-circle"></i><?php echo htmlspecialchars((string) $row['hold_reason']); ?></p>
            <?php endif; ?>
        </div>
        <div class="event-actions">
            <?php if (has_priv('events')): ?>
                <a href="<?php echo htmlspecialchars($primaryHref); ?>" class="btn-primary-action <?php echo $isHold ? 'hold' : ''; ?>">
                    <?php echo $primaryLabel; ?> <i class="fas <?php echo $primaryIcon; ?>"></i>
                </a>
                <a href="<?php echo htmlspecialchars($editHref); ?>" class="btn-ghost-action" title="Edit event">
                    <i class="fas fa-pen"></i>
                </a>
            <?php elseif (has_priv('approve_events')): ?>
                <span class="text-muted small">Needs “events” privilege</span>
            <?php endif; ?>
        </div>
    </article>
    <?php
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Dashboard | MiCampus</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&family=Fraunces:opsz,wght@9..144,600;9..144,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        :root {
            --brand: #FF5F15;
            --brand-deep: #e04e0b;
            --ink: #1c1917;
            --muted: #78716c;
            --line: #e7e5e4;
            --surface: #ffffff;
            --canvas: #f5f2ee;
            --hold: #c2410c;
            --shadow: 0 1px 0 rgba(28, 25, 23, 0.04), 0 12px 32px rgba(28, 25, 23, 0.06);
        }

        body {
            font-family: 'DM Sans', system-ui, sans-serif;
            background:
                radial-gradient(1200px 500px at 10% -10%, rgba(255, 95, 21, 0.08), transparent 55%),
                radial-gradient(900px 420px at 100% 0%, rgba(28, 25, 23, 0.05), transparent 50%),
                var(--canvas);
            color: var(--ink);
        }
        .main-content {
            margin-left: 280px;
            padding: 36px 40px 56px;
            box-sizing: border-box;
            width: 100%;
            max-width: 100%;
        }
        @media (max-width: 991px) {
            .main-content { margin-left: 0; padding: 16px 14px 40px; }
        }

        .dash-hero {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: flex-end;
            gap: 16px;
            margin-bottom: 28px;
        }
        .dash-kicker {
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--brand);
            margin-bottom: 6px;
        }
        .dash-title {
            font-family: 'Fraunces', Georgia, serif;
            font-size: clamp(1.6rem, 2.4vw, 2.1rem);
            font-weight: 700;
            letter-spacing: -0.02em;
            margin: 0;
            line-height: 1.15;
        }
        .dash-sub {
            margin: 6px 0 0;
            color: var(--muted);
            font-size: 0.92rem;
        }
        .btn-site {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 16px;
            border-radius: 999px;
            border: 1px solid var(--line);
            background: var(--surface);
            color: var(--ink);
            text-decoration: none;
            font-weight: 600;
            font-size: 0.85rem;
            box-shadow: 0 1px 0 rgba(0,0,0,0.03);
        }
        .btn-site:hover { border-color: #d6d3d1; color: var(--ink); background: #fff; }

        .stat-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
            margin-bottom: 36px;
        }
        @media (max-width: 1199.98px) { .stat-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 575.98px) { .stat-grid { grid-template-columns: 1fr; } }

        .stat-tile {
            display: block;
            text-decoration: none;
            color: inherit;
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 18px;
            padding: 18px 18px 16px;
            box-shadow: var(--shadow);
            transition: transform 0.2s ease, border-color 0.2s ease;
            position: relative;
            overflow: hidden;
            min-height: 112px;
        }
        .stat-tile:hover { transform: translateY(-2px); border-color: #d6d3d1; color: inherit; }
        .stat-tile .label {
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: var(--muted);
        }
        .stat-tile .value {
            font-family: 'Fraunces', Georgia, serif;
            font-size: 2.2rem;
            font-weight: 700;
            margin-top: 10px;
            line-height: 1;
            letter-spacing: -0.03em;
        }
        .stat-tile .accent {
            position: absolute;
            left: 0; top: 14px; bottom: 14px;
            width: 3px;
            border-radius: 999px;
            background: var(--brand);
        }
        .stat-tile.hold .accent { background: var(--hold); }
        .stat-tile.live .accent { background: #292524; }
        .stat-tile.users .accent { background: #0f766e; }
        .stat-tile.static { cursor: default; }
        .stat-tile.static:hover { transform: none; }

        .queue-head {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 12px;
            margin: 8px 0 14px;
        }
        .queue-head h2 {
            font-family: 'Fraunces', Georgia, serif;
            font-size: 1.25rem;
            font-weight: 700;
            margin: 0;
            letter-spacing: -0.02em;
        }
        .queue-head .count {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--muted);
        }
        .queue-block + .queue-block { margin-top: 34px; }

        .event-queue-card {
            display: grid;
            grid-template-columns: 72px minmax(0, 1fr) auto;
            gap: 16px;
            align-items: center;
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 18px;
            padding: 12px 14px;
            margin-bottom: 10px;
            box-shadow: var(--shadow);
            transition: border-color 0.2s ease, transform 0.2s ease;
        }
        .event-queue-card:hover { border-color: #d6d3d1; transform: translateY(-1px); }
        .event-queue-card.is-hold { border-left: 3px solid var(--hold); }
        @media (max-width: 767.98px) {
            .event-queue-card {
                grid-template-columns: 64px minmax(0, 1fr);
                grid-template-areas:
                    "poster copy"
                    "actions actions";
            }
            .event-poster { grid-area: poster; }
            .event-copy { grid-area: copy; }
            .event-actions { grid-area: actions; justify-content: flex-start; }
        }

        .event-poster {
            width: 72px;
            height: 92px;
            border-radius: 12px;
            overflow: hidden;
            background: #ebe7e2;
            border: 1px solid var(--line);
            flex-shrink: 0;
            position: relative;
        }
        .event-poster img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .event-poster.is-empty,
        .event-poster:not(:has(img)) .event-poster-fallback { display: flex; }
        .event-poster-fallback {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #a8a29e;
            background: linear-gradient(160deg, #fafaf9, #e7e5e4);
        }

        .event-meta-row { display: flex; align-items: center; gap: 8px; margin-bottom: 4px; flex-wrap: wrap; }
        .cat-chip {
            display: inline-flex;
            align-items: center;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 0.65rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            background: rgba(255, 95, 21, 0.1);
            color: var(--brand-deep);
        }
        .cat-chip.hold { background: rgba(194, 65, 12, 0.1); color: var(--hold); }
        .status-dot {
            font-size: 0.68rem;
            font-weight: 700;
            color: var(--hold);
        }
        .event-title {
            font-size: 1rem;
            font-weight: 700;
            margin: 0 0 4px;
            line-height: 1.3;
            letter-spacing: -0.01em;
        }
        .event-sub {
            margin: 0;
            color: var(--muted);
            font-size: 0.8rem;
            display: flex;
            flex-wrap: wrap;
            gap: 4px 6px;
            align-items: center;
        }
        .event-sub i { margin-right: 5px; opacity: 0.75; }
        .event-sub .sep { opacity: 0.45; }
        .hold-note {
            margin: 8px 0 0;
            font-size: 0.75rem;
            color: #9a3412;
            background: #fff7ed;
            border: 1px solid #ffedd5;
            border-radius: 8px;
            padding: 6px 8px;
            display: inline-flex;
            gap: 6px;
            align-items: flex-start;
            max-width: 100%;
        }

        .event-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-shrink: 0;
        }
        .btn-primary-action {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--brand);
            color: #fff;
            text-decoration: none;
            font-weight: 700;
            font-size: 0.82rem;
            padding: 10px 14px;
            border-radius: 12px;
            border: none;
        }
        .btn-primary-action:hover { background: var(--brand-deep); color: #fff; }
        .btn-primary-action.hold { background: var(--hold); }
        .btn-primary-action.hold:hover { background: #9a3412; color: #fff; }
        .btn-ghost-action {
            width: 40px;
            height: 40px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            border: 1px solid var(--line);
            color: var(--ink);
            background: #fff;
            text-decoration: none;
        }
        .btn-ghost-action:hover { background: #fafaf9; color: var(--ink); border-color: #d6d3d1; }

        .empty-panel {
            text-align: center;
            padding: 36px 20px;
            background: var(--surface);
            border: 1px dashed #d6d3d1;
            border-radius: 18px;
            color: var(--muted);
        }
        .empty-panel i { font-size: 1.6rem; margin-bottom: 10px; opacity: 0.45; display: block; }
    </style>
</head>
<body>

    <?php include 'sidebar.php'; ?>

    <div class="main-content">
        <div class="dash-hero">
            <div>
                <div class="dash-kicker">Operations</div>
                <h1 class="dash-title">What needs attention</h1>
                <p class="dash-sub">Pending reviews, holds, and live campus activity at a glance.</p>
            </div>
            <a href="https://micampus.co.in/" target="_blank" rel="noopener noreferrer" class="btn-site">
                <i class="fas fa-globe"></i> MiCampus website
                <i class="fas fa-arrow-up-right-from-square small opacity-60"></i>
            </a>
        </div>

        <div class="stat-grid">
            <?php if (has_priv('events')): ?>
            <a href="events.php?view=pending" class="stat-tile" title="View pending approval events">
                <span class="accent"></span>
                <div class="label">Needs review</div>
                <div class="value"><?php echo (int) $pending_events; ?></div>
            </a>
            <?php else: ?>
            <div class="stat-tile static">
                <span class="accent"></span>
                <div class="label">Needs review</div>
                <div class="value"><?php echo (int) $pending_events; ?></div>
            </div>
            <?php endif; ?>

            <?php if (has_priv('events')): ?>
            <a href="events.php?view=hold" class="stat-tile hold" title="View events on hold">
                <span class="accent"></span>
                <div class="label">On hold</div>
                <div class="value"><?php echo (int) $hold_events; ?></div>
            </a>
            <?php else: ?>
            <div class="stat-tile hold static">
                <span class="accent"></span>
                <div class="label">On hold</div>
                <div class="value"><?php echo (int) $hold_events; ?></div>
            </div>
            <?php endif; ?>

            <?php if (has_priv('events')): ?>
            <a href="events.php?view=live" class="stat-tile live" title="View live / upcoming events">
                <span class="accent"></span>
                <div class="label">Active events</div>
                <div class="value"><?php echo (int) $live_events; ?></div>
            </a>
            <?php else: ?>
            <div class="stat-tile live static">
                <span class="accent"></span>
                <div class="label">Active events</div>
                <div class="value"><?php echo (int) $live_events; ?></div>
            </div>
            <?php endif; ?>

            <?php if (has_priv('manage_users')): ?>
            <a href="users.php" class="stat-tile users" title="Manage app users">
                <span class="accent"></span>
                <div class="label">App users</div>
                <div class="value"><?php echo (int) $app_users_count; ?></div>
            </a>
            <?php else: ?>
            <div class="stat-tile users static">
                <span class="accent"></span>
                <div class="label">App users</div>
                <div class="value"><?php echo (int) $app_users_count; ?></div>
            </div>
            <?php endif; ?>
        </div>

        <section class="queue-block">
            <div class="queue-head">
                <h2>Pending approvals</h2>
                <span class="count"><?php echo (int) $pending_requests->num_rows; ?> waiting</span>
            </div>
            <?php if ($pending_requests->num_rows > 0): ?>
                <?php while ($row = $pending_requests->fetch_assoc()): ?>
                    <?php dashboard_render_event_card($row, 'pending'); ?>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="empty-panel">
                    <i class="fas fa-check"></i>
                    All caught up — no pending events.
                </div>
            <?php endif; ?>
        </section>

        <section class="queue-block">
            <div class="queue-head">
                <h2>Events on hold</h2>
                <span class="count"><?php echo (int) $hold_requests->num_rows; ?> paused</span>
            </div>
            <?php if ($hold_requests->num_rows > 0): ?>
                <?php while ($row = $hold_requests->fetch_assoc()): ?>
                    <?php dashboard_render_event_card($row, 'hold'); ?>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="empty-panel">
                    <i class="fas fa-pause"></i>
                    Nothing on hold right now.
                </div>
            <?php endif; ?>
        </section>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        (function showMsgAlert() {
            const params = new URLSearchParams(window.location.search);
            const msg = params.get('msg');
            if (params.get('forbidden') === '1') {
                Swal.fire({ icon: 'warning', title: 'Access denied', text: 'You do not have permission for that action.', confirmButtonColor: '#FF5F15' });
                window.history.replaceState({}, document.title, window.location.pathname);
            }
            const messages = {
                approve: { title: 'Event approved', text: 'The event has been approved and is now live.', icon: 'success' },
                reject: { title: 'Event rejected', text: 'The event was rejected, removed from the system, and the organizer was notified with your reason.', icon: 'info' },
                hold: { title: 'Event on hold', text: 'The event has been put on hold.', icon: 'warning' },
                reschedule: { title: 'Event rescheduled', text: 'The event has been rescheduled.', icon: 'success' },
                error: { title: 'Error', text: 'Something went wrong. Please try again.', icon: 'error' },
                welcome: { title: 'Welcome', text: 'You are logged in successfully.', icon: 'success' }
            };
            if (msg && messages[msg]) {
                Swal.fire(messages[msg].title, messages[msg].text, messages[msg].icon).then(() => {
                    window.history.replaceState({}, '', 'dashboard.php');
                });
            }
        })();
    </script>
</body>
</html>
