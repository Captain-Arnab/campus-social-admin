<?php

/** Match login verification: bcrypt hashes or legacy plaintext. */
function portal_verify_password($stored, $input): bool
{
    if ($stored === null || $stored === '') {
        return false;
    }
    if (is_string($stored) && strncmp($stored, '$2y$', 4) === 0) {
        return password_verify((string) $input, $stored);
    }
    return hash_equals((string) $stored, (string) $input);
}
