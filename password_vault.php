<?php
/**
 * Reversible storage of user (student/faculty) passwords for the admin "view password" feature.
 *
 * users.password           — bcrypt hash, the ONLY value used for login verification.
 * users.password_encrypted — AES-256-GCM ciphertext of the same plaintext, admin-reveal only.
 *
 * Both columns must be written together on every password set (registration / reset). If the
 * ciphertext cannot be produced (key missing, etc.) password_encrypted is set to NULL so it is
 * never out of sync with the hash.
 *
 * Key (32 random bytes, base64) — never commit it:
 *   env USER_PASSWORD_ENC_KEY, or api/password_vault_config.local.php (gitignored, see .example).
 * Losing or changing the key makes every stored ciphertext unrecoverable.
 */

const PASSWORD_VAULT_CIPHER = 'aes-256-gcm';
const PASSWORD_VAULT_VERSION = 'v1';
const PASSWORD_VAULT_COLUMN_MAX = 255;

function password_vault_key(): ?string
{
    static $key = false;
    if ($key !== false) {
        return $key;
    }
    $raw = getenv('USER_PASSWORD_ENC_KEY') ?: '';
    if ($raw === '') {
        $local = __DIR__ . '/api/password_vault_config.local.php';
        if (is_readable($local)) {
            $cfg = include $local;
            if (is_array($cfg) && isset($cfg['key']) && is_string($cfg['key'])) {
                $raw = $cfg['key'];
            }
        }
    }
    $raw = trim($raw);
    if (strncmp($raw, 'base64:', 7) === 0) {
        $raw = substr($raw, 7);
    }
    $bin = $raw !== '' ? base64_decode($raw, true) : false;
    if ($bin === false || strlen($bin) !== 32) {
        if ($raw !== '') {
            error_log('[password_vault] USER_PASSWORD_ENC_KEY is set but is not 32 bytes of base64');
        }
        $key = null;
        return null;
    }
    $key = $bin;
    return $key;
}

function password_vault_is_configured(): bool
{
    return password_vault_key() !== null && function_exists('openssl_encrypt');
}

function password_vault_aad(int $userId): string
{
    return 'micampus-user-password:' . PASSWORD_VAULT_VERSION . ':' . $userId;
}

/** @return string|null ciphertext for users.password_encrypted, or null if it cannot be produced */
function password_vault_encrypt_for_user(int $userId, string $plain): ?string
{
    if ($userId <= 0 || !password_vault_is_configured()) {
        return null;
    }
    try {
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plain, PASSWORD_VAULT_CIPHER, password_vault_key(), OPENSSL_RAW_DATA, $iv, $tag, password_vault_aad($userId), 16);
        if ($ct === false) {
            return null;
        }
        $out = PASSWORD_VAULT_VERSION . ':' . base64_encode($iv . $tag . $ct);
        return strlen($out) <= PASSWORD_VAULT_COLUMN_MAX ? $out : null;
    } catch (Throwable $e) {
        error_log('[password_vault] encrypt failed: ' . $e->getMessage());
        return null;
    }
}

/** @return string|null plaintext, or null if the blob is invalid / key wrong / tampered */
function password_vault_decrypt_for_user(int $userId, string $blob): ?string
{
    if ($userId <= 0 || !password_vault_is_configured()) {
        return null;
    }
    $prefix = PASSWORD_VAULT_VERSION . ':';
    if (strncmp($blob, $prefix, strlen($prefix)) !== 0) {
        return null;
    }
    $bin = base64_decode(substr($blob, strlen($prefix)), true);
    if ($bin === false || strlen($bin) < 28) {
        return null;
    }
    $iv = substr($bin, 0, 12);
    $tag = substr($bin, 12, 16);
    $ct = substr($bin, 28);
    $plain = openssl_decrypt($ct, PASSWORD_VAULT_CIPHER, password_vault_key(), OPENSSL_RAW_DATA, $iv, $tag, password_vault_aad($userId));
    return $plain === false ? null : $plain;
}

/** Adds users.password_encrypted and the password_view_log table if missing. */
function password_vault_ensure_schema(mysqli $conn): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    $col = @$conn->query("SHOW COLUMNS FROM users LIKE 'password_encrypted'");
    if (!$col) {
        $ok = false;
        return $ok;
    }
    if ($col->num_rows === 0) {
        $added = @$conn->query(
            "ALTER TABLE `users` ADD COLUMN `password_encrypted` varchar(255) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL
             COMMENT 'AES-256-GCM ciphertext for admin reveal; NULL = not captured. Login uses `password` only.' AFTER `password`"
        );
        if (!$added) {
            error_log('[password_vault] could not add users.password_encrypted: ' . $conn->error);
            $ok = false;
            return $ok;
        }
    }
    $ok = (bool) @$conn->query(
        "CREATE TABLE IF NOT EXISTS `password_view_log` (
          `id` int NOT NULL AUTO_INCREMENT,
          `admin_id` int NOT NULL COMMENT 'admins.id or subadmins.id depending on admin_type',
          `admin_type` enum('admin','subadmin') NOT NULL DEFAULT 'admin',
          `target_user_id` int NOT NULL COMMENT 'users.id whose password was revealed',
          `viewed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `ip_address` varchar(45) DEFAULT NULL,
          PRIMARY KEY (`id`),
          KEY `idx_pvl_admin` (`admin_type`, `admin_id`),
          KEY `idx_pvl_target` (`target_user_id`),
          KEY `idx_pvl_viewed_at` (`viewed_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        COMMENT='Audit: every successful admin reveal of a user password'"
    );
    return $ok;
}

/**
 * Set a user's password: bcrypt hash + ciphertext in a single UPDATE so they cannot drift.
 * Login behaviour is unchanged if the vault is unavailable (ciphertext becomes NULL).
 */
function user_password_update(mysqli $conn, int $userId, string $plain): bool
{
    $hash = password_hash($plain, PASSWORD_DEFAULT);
    if (password_vault_ensure_schema($conn)) {
        $enc = password_vault_encrypt_for_user($userId, $plain);
        $stmt = $conn->prepare('UPDATE users SET password = ?, password_encrypted = ? WHERE id = ?');
        $stmt->bind_param('ssi', $hash, $enc, $userId);
    } else {
        $stmt = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
        $stmt->bind_param('si', $hash, $userId);
    }
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

/** Registration: the hash is already inserted; store the matching ciphertext (or NULL). */
function password_vault_store_for_new_user(mysqli $conn, int $userId, string $plain): void
{
    try {
        if (!password_vault_ensure_schema($conn)) {
            return;
        }
        $enc = password_vault_encrypt_for_user($userId, $plain);
        $stmt = $conn->prepare('UPDATE users SET password_encrypted = ? WHERE id = ?');
        $stmt->bind_param('si', $enc, $userId);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        error_log('[password_vault] store for new user ' . $userId . ' failed: ' . $e->getMessage());
    }
}
