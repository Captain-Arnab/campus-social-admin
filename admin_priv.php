<?php
/**
 * Sub-admin privilege checks. Main admin bypasses all checks.
 * Session must be started; used after login check.
 */

function is_main_admin(): bool
{
    return !empty($_SESSION['admin']);
}

/** @return list<string>|null null means full access (main admin) */
function get_subadmin_privilege_list(): ?array
{
    if (is_main_admin()) {
        return null;
    }
    $p = $_SESSION['subadmin_privileges'] ?? [];
    return is_array($p) ? $p : [];
}

function has_priv(string $key): bool
{
    if (is_main_admin()) {
        return true;
    }
    $p = get_subadmin_privilege_list();
    return $p !== null && in_array($key, $p, true);
}

function require_priv(string $key): void
{
    if (has_priv($key)) {
        return;
    }
    header('Location: dashboard.php?forbidden=1');
    exit();
}

/** Privilege keys used across the admin panel (for documentation / manage UI). */
function subadmin_privilege_definitions(): array
{
    return [
        'dashboard' => 'Dashboard (overview & pending summary)',
        'events' => 'Events list, event details, editors & winners',
        'approve_events' => 'Approve, reject, hold events & pending edits',
        'manage_users' => 'Manage app users',
        'certificates' => 'Certificates',
        'reports' => 'Download reports',
        'notification_schedule' => 'Celebration / notification days — cron calendar (add, view & remove)',
    ];
}
