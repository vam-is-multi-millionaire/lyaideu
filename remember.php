<?php
/**
 * LyaiDeu persistent login ("stay logged in") helper.
 *
 * Keeps user / admin / vendor / rider logged in across browser restarts
 * until they press Logout. Uses long-lived HttpOnly cookies backed by a
 * `remember_tokens` DB table (selector + sha256 validator hash).
 *
 * No UI / CSS / ?v= changes here. Safe to include from any page.
 */

if (!defined('LYAIDEU_REMEMBER_DAYS')) {
    define('LYAIDEU_REMEMBER_DAYS', 30);
}
if (!defined('LYAIDEU_REMEMBER_LIFETIME')) {
    define('LYAIDEU_REMEMBER_LIFETIME', 30 * 24 * 60 * 60); // 2592000
}

function lyaideu_remember_lifetime(): int {
    return (int)LYAIDEU_REMEMBER_LIFETIME;
}

function lyaideu_remember_is_https(): bool {
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        return true;
    }
    if (isset($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443') {
        return true;
    }
    return false;
}

/** Persistent session cookie params. Call BEFORE session_start(). */
function lyaideu_session_cookie_params(): array {
    return [
        'lifetime' => lyaideu_remember_lifetime(),
        'path' => '/',
        'domain' => '',
        'secure' => lyaideu_remember_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function lyaideu_remember_cookie_name(string $role): string {
    $role = strtolower(trim($role));
    if (!in_array($role, ['user', 'admin', 'vendor', 'rider'], true)) {
        $role = 'user';
    }
    return 'lyaideu_rem_' . $role;
}

function lyaideu_remember_pdo(): ?PDO {
    try {
        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            return $GLOBALS['pdo'];
        }
        if (function_exists('lyaideu_load_pdo')) {
            $pdo = lyaideu_load_pdo();
            if ($pdo instanceof PDO) {
                return $pdo;
            }
        }
    } catch (Throwable $e) {
        return null;
    }
    return null;
}

function lyaideu_ensure_remember_table(?PDO $pdo = null): bool {
    if (!$pdo instanceof PDO) {
        $pdo = lyaideu_remember_pdo();
    }
    if (!$pdo instanceof PDO) {
        return false;
    }
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS remember_tokens (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                role VARCHAR(10) NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                selector VARCHAR(32) NOT NULL,
                validator_hash CHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_remember_selector (selector),
                KEY idx_remember_role_user (role, user_id),
                KEY idx_remember_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function lyaideu_remember_set_cookie(string $role, string $value, int $expiresTs): void {
    $name = lyaideu_remember_cookie_name($role);
    // Don't send cookies if headers already sent (e.g. included late).
    if (headers_sent()) {
        return;
    }
    if (PHP_VERSION_ID >= 70300) {
        setcookie($name, $value, [
            'expires' => $expiresTs,
            'path' => '/',
            'domain' => '',
            'secure' => lyaideu_remember_is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        // Fallback for very old PHP (no SameSite support).
        setcookie($name, $value, $expiresTs, '/', '', lyaideu_remember_is_https(), true);
    }
    // Make it visible to current request too.
    $_COOKIE[$name] = $value;
}

function lyaideu_remember_clear_cookie(string $role): void {
    $name = lyaideu_remember_cookie_name($role);
    if (headers_sent()) {
        unset($_COOKIE[$name]);
        return;
    }
    if (PHP_VERSION_ID >= 70300) {
        setcookie($name, '', [
            'expires' => time() - 86400,
            'path' => '/',
            'domain' => '',
            'secure' => lyaideu_remember_is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        setcookie($name, '', time() - 86400, '/', '', lyaideu_remember_is_https(), true);
    }
    unset($_COOKIE[$name]);
}

function lyaideu_remember_purge_expired(?PDO $pdo = null): void {
    if (!$pdo instanceof PDO) {
        $pdo = lyaideu_remember_pdo();
    }
    if (!$pdo instanceof PDO) {
        return;
    }
    try {
        $pdo->exec("DELETE FROM remember_tokens WHERE expires_at < NOW()");
    } catch (Throwable $e) {
        // ignore
    }
}

/**
 * Issue a new persistent token for (role, user_id) and set the cookie.
 * Call right after a successful explicit login / signup.
 */
function lyaideu_remember_issue(string $role, int $userId): bool {
    $role = strtolower(trim($role));
    if (!in_array($role, ['user', 'admin', 'vendor', 'rider'], true) || $userId <= 0) {
        return false;
    }
    $pdo = lyaideu_remember_pdo();
    if (!$pdo instanceof PDO) {
        return false;
    }
    if (!lyaideu_ensure_remember_table($pdo)) {
        return false;
    }
    try {
        // One active token per device is enough; keep table small by
        // removing this user's expired rows on each new login.
        $del = $pdo->prepare('DELETE FROM remember_tokens WHERE role = ? AND (user_id = ? AND expires_at < NOW())');
        $del->execute([$role, $userId]);
    } catch (Throwable $e) {
        // ignore
    }
    try {
        $selector = bin2hex(random_bytes(12));   // 24 hex chars
        $validator = bin2hex(random_bytes(32));  // 64 hex chars
        $hash = hash('sha256', $validator);
        $lifetime = lyaideu_remember_lifetime();
        $expiresTs = time() + $lifetime;
        $expiresSql = date('Y-m-d H:i:s', $expiresTs);
        $nowSql = date('Y-m-d H:i:s');
        $ins = $pdo->prepare(
            'INSERT INTO remember_tokens (role, user_id, selector, validator_hash, expires_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([$role, $userId, $selector, $hash, $expiresSql, $nowSql]);
        lyaideu_remember_set_cookie($role, $selector . ':' . $validator, $expiresTs);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** Delete the token matching the current cookie (single-device logout). */
function lyaideu_remember_forget(string $role): void {
    $role = strtolower(trim($role));
    if (!in_array($role, ['user', 'admin', 'vendor', 'rider'], true)) {
        return;
    }
    $name = lyaideu_remember_cookie_name($role);
    $raw = isset($_COOKIE[$name]) ? (string)$_COOKIE[$name] : '';
    $selector = '';
    if (preg_match('/^([a-f0-9]{24}):([a-f0-9]{64})$/', $raw, $m)) {
        $selector = $m[1];
    }
    if ($selector !== '') {
        try {
            $pdo = lyaideu_remember_pdo();
            if ($pdo instanceof PDO && lyaideu_ensure_remember_table($pdo)) {
                $st = $pdo->prepare('DELETE FROM remember_tokens WHERE selector = ? AND role = ? LIMIT 1');
                // LIMIT in DELETE with prepare works on MySQL; wrap in try.
                try {
                    $st->execute([$selector, $role]);
                } catch (Throwable $e2) {
                    $st2 = $pdo->prepare('DELETE FROM remember_tokens WHERE selector = ? AND role = ?');
                    $st2->execute([$selector, $role]);
                }
            }
        } catch (Throwable $e) {
            // ignore
        }
    }
    lyaideu_remember_clear_cookie($role);
}

/** Delete ALL persistent tokens for (role, user_id) — password change / block. */
function lyaideu_remember_forget_all(string $role, int $userId): void {
    $role = strtolower(trim($role));
    if (!in_array($role, ['user', 'admin', 'vendor', 'rider'], true) || $userId <= 0) {
        return;
    }
    try {
        $pdo = lyaideu_remember_pdo();
        if ($pdo instanceof PDO && lyaideu_ensure_remember_table($pdo)) {
            $st = $pdo->prepare('DELETE FROM remember_tokens WHERE role = ? AND user_id = ?');
            $st->execute([$role, $userId]);
        }
    } catch (Throwable $e) {
        // ignore
    }
}

function lyaideu_remember_parse_cookie(string $role): ?array {
    $name = lyaideu_remember_cookie_name($role);
    $raw = isset($_COOKIE[$name]) ? trim((string)$_COOKIE[$name]) : '';
    if (!preg_match('/^([a-f0-9]{24}):([a-f0-9]{64})$/', $raw, $m)) {
        return null;
    }
    return [$m[1], $m[2]];
}

/* ---------------- restore helpers (one per role) ---------------- */

function lyaideu_remember_try_user(): bool {
    if (!empty($_SESSION['user']['id'])) {
        return true;
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }
    $parts = lyaideu_remember_parse_cookie('user');
    if (!$parts) {
        return false;
    }
    [$selector, $validator] = $parts;
    try {
        $pdo = lyaideu_remember_pdo();
        if (!$pdo instanceof PDO) {
            return false;
        }
        if (!lyaideu_ensure_remember_table($pdo)) {
            return false;
        }
        $st = $pdo->prepare("SELECT id, user_id, validator_hash, expires_at FROM remember_tokens WHERE selector = ? AND role = 'user' LIMIT 1");
        $st->execute([$selector]);
        $tok = $st->fetch(PDO::FETCH_ASSOC);
        if (!$tok || (string)$tok['expires_at'] < date('Y-m-d H:i:s')) {
            if ($tok) {
                try { $pdo->prepare('DELETE FROM remember_tokens WHERE selector = ?')->execute([$selector]); } catch (Throwable $e) {}
            }
            lyaideu_remember_clear_cookie('user');
            return false;
        }
        if (!hash_equals((string)$tok['validator_hash'], hash('sha256', $validator))) {
            try { $pdo->prepare('DELETE FROM remember_tokens WHERE selector = ?')->execute([$selector]); } catch (Throwable $e) {}
            lyaideu_remember_clear_cookie('user');
            return false;
        }
        $uid = (int)$tok['user_id'];
        if ($uid <= 0) {
            return false;
        }
        // Load fresh profile; respect block flag when the column exists.
        $u = false;
        try {
            $q = $pdo->prepare('SELECT id, name, email, phone, dob, avatar, address, kyc_status, is_blocked FROM users WHERE id = ? LIMIT 1');
            $q->execute([$uid]);
            $u = $q->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            try {
                $q = $pdo->prepare('SELECT id, name, email, phone, dob, avatar, address, kyc_status FROM users WHERE id = ? LIMIT 1');
                $q->execute([$uid]);
                $u = $q->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable $e2) {
                $u = false;
            }
        }
        if (!$u) {
            try { $pdo->prepare('DELETE FROM remember_tokens WHERE selector = ?')->execute([$selector]); } catch (Throwable $e) {}
            lyaideu_remember_clear_cookie('user');
            return false;
        }
        if ((int)($u['is_blocked'] ?? 0) === 1) {
            try { $pdo->prepare('DELETE FROM remember_tokens WHERE role = ? AND user_id = ?')->execute(['user', $uid]); } catch (Throwable $e) {}
            lyaideu_remember_clear_cookie('user');
            return false;
        }
        $_SESSION['user'] = [
            'id' => (int)$u['id'],
            'name' => (string)$u['name'],
            'email' => (string)$u['email'],
            'phone' => (string)($u['phone'] ?? ''),
            'dob' => (string)($u['dob'] ?? ''),
            'avatar' => (string)($u['avatar'] ?? ''),
            'address' => (string)($u['address'] ?? ''),
            'kyc_status' => (string)($u['kyc_status'] ?? 'none'),
        ];
        // Rotate validator so a stolen cookie has a short window.
        try { $pdo->prepare('DELETE FROM remember_tokens WHERE selector = ?')->execute([$selector]); } catch (Throwable $e) {}
        lyaideu_remember_issue('user', $uid);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function lyaideu_remember_try_admin(): bool {
    if (!empty($_SESSION['is_admin']) && !empty($_SESSION['admin_id'])) {
        return true;
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }
    $parts = lyaideu_remember_parse_cookie('admin');
    if (!$parts) {
        return false;
    }
    [$selector, $validator] = $parts;
    try {
        $pdo = lyaideu_remember_pdo();
        if (!$pdo instanceof PDO) {
            return false;
        }
        if (!lyaideu_ensure_remember_table($pdo)) {
            return false;
        }
        $st = $pdo->prepare("SELECT id, user_id, validator_hash, expires_at FROM remember_tokens WHERE selector = ? AND role = 'admin' LIMIT 1");
        $st->execute([$selector]);
        $tok = $st->fetch(PDO::FETCH_ASSOC);
        if (!$tok || (string)$tok['expires_at'] < date('Y-m-d H:i:s')) {
            if ($tok) {
                try { $pdo->prepare('DELETE FROM remember_tokens WHERE selector = ?')->execute([$selector]); } catch (Throwable $e) {}
            }
            lyaideu_remember_clear_cookie('admin');
            return false;
        }
        if (!hash_equals((string)$tok['validator_hash'], hash('sha256', $validator))) {
            try { $pdo->prepare('DELETE FROM remember_tokens WHERE selector = ?')->execute([$selector]); } catch (Throwable $e) {}
            lyaideu_remember_clear_cookie('admin');
            return false;
        }
        $aid = (int)$tok['user_id'];
        if ($aid <= 0) {
            return false;
        }
        if (function_exists('lyaideu_ensure_admin_users_tables')) {
            try { lyaideu_ensure_admin_users_tables(); } catch (Throwable $e) {}
        }
        try {
            $q = $pdo->prepare('SELECT id, username, name, role, is_active FROM admin_users WHERE id = ? LIMIT 1');
            $q->execute([$aid]);
            $row = $q->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $row = false;
        }
        if (!$row || (int)$row['is_active'] !== 1) {
            try { $pdo->prepare('DELETE FROM remember_tokens WHERE role = ? AND user_id = ?')->execute(['admin', $aid]); } catch (Throwable $e) {}
            lyaideu_remember_clear_cookie('admin');
            return false;
        }
        $_SESSION['is_admin'] = true;
        $_SESSION['admin_id'] = (int)$row['id'];
        $_SESSION['admin_role'] = (string)$row['role'];
        $_SESSION['admin_name'] = (string)$row['name'];
        if (empty($_SESSION['csrf_admin'])) {
            try { $_SESSION['csrf_admin'] = bin2hex(random_bytes(32)); } catch (Throwable $e) {}
        }
        try { $pdo->prepare('DELETE FROM remember_tokens WHERE selector = ?')->execute([$selector]); } catch (Throwable $e) {}
        lyaideu_remember_issue('admin', $aid);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function lyaideu_remember_try_delivery(string $role): bool {
    $role = strtolower(trim($role));
    if (!in_array($role, ['vendor', 'rider'], true)) {
        return false;
    }
    if (!empty($_SESSION['delivery_role']) && $_SESSION['delivery_role'] === $role && !empty($_SESSION['delivery_user']['id'])) {
        return true;
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }
    $parts = lyaideu_remember_parse_cookie($role);
    if (!$parts) {
        return false;
    }
    [$selector, $validator] = $parts;
    try {
        $pdo = lyaideu_remember_pdo();
        if (!$pdo instanceof PDO) {
            return false;
        }
        if (!lyaideu_ensure_remember_table($pdo)) {
            return false;
        }
        $st = $pdo->prepare('SELECT id, user_id, validator_hash, expires_at FROM remember_tokens WHERE selector = ? AND role = ? LIMIT 1');
        $st->execute([$selector, $role]);
        $tok = $st->fetch(PDO::FETCH_ASSOC);
        if (!$tok || (string)$tok['expires_at'] < date('Y-m-d H:i:s')) {
            if ($tok) {
                try { $pdo->prepare('DELETE FROM remember_tokens WHERE selector = ?')->execute([$selector]); } catch (Throwable $e) {}
            }
            lyaideu_remember_clear_cookie($role);
            return false;
        }
        if (!hash_equals((string)$tok['validator_hash'], hash('sha256', $validator))) {
            try { $pdo->prepare('DELETE FROM remember_tokens WHERE selector = ?')->execute([$selector]); } catch (Throwable $e) {}
            lyaideu_remember_clear_cookie($role);
            return false;
        }
        $id = (int)$tok['user_id'];
        if ($id <= 0) {
            return false;
        }
        if ($role === 'vendor') {
            try {
                $q = $pdo->prepare('SELECT id, name, email, phone, is_active FROM vendors WHERE id = ? LIMIT 1');
                $q->execute([$id]);
                $u = $q->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                $u = false;
            }
            if (!$u || !(int)$u['is_active']) {
                try { $pdo->prepare('DELETE FROM remember_tokens WHERE role = ? AND user_id = ?')->execute([$role, $id]); } catch (Throwable $e) {}
                lyaideu_remember_clear_cookie($role);
                return false;
            }
            $_SESSION['delivery_role'] = 'vendor';
            $_SESSION['delivery_user'] = [
                'id' => (int)$u['id'],
                'name' => (string)$u['name'],
                'email' => (string)$u['email'],
                'phone' => (string)$u['phone'],
                'vehicle' => '',
                'avatar' => '',
            ];
        } else {
            try {
                $q = $pdo->prepare('SELECT id, name, email, phone, vehicle, avatar, is_active FROM riders WHERE id = ? LIMIT 1');
                $q->execute([$id]);
                $u = $q->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                $u = false;
            }
            if (!$u || !(int)$u['is_active']) {
                try { $pdo->prepare('DELETE FROM remember_tokens WHERE role = ? AND user_id = ?')->execute([$role, $id]); } catch (Throwable $e) {}
                lyaideu_remember_clear_cookie($role);
                return false;
            }
            $_SESSION['delivery_role'] = 'rider';
            $_SESSION['delivery_user'] = [
                'id' => (int)$u['id'],
                'name' => (string)$u['name'],
                'email' => (string)$u['email'],
                'phone' => (string)$u['phone'],
                'vehicle' => (string)($u['vehicle'] ?? ''),
                'avatar' => (string)($u['avatar'] ?? ''),
            ];
        }
        if (empty($_SESSION['csrf_delivery'])) {
            try { $_SESSION['csrf_delivery'] = bin2hex(random_bytes(32)); } catch (Throwable $e) {}
        }
        try { $pdo->prepare('DELETE FROM remember_tokens WHERE selector = ?')->execute([$selector]); } catch (Throwable $e) {}
        lyaideu_remember_issue($role, $id);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Best-effort auto restore for the current session cookie.
 * Safe to call on any page after session_start() + DB available.
 */
function lyaideu_remember_auto_restore(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    try {
        $name = session_name();
    } catch (Throwable $e) {
        $name = '';
    }
    try {
        if ($name === 'LYAIDEU_VENDOR') {
            lyaideu_remember_try_delivery('vendor');
            return;
        }
        if ($name === 'LYAIDEU_RIDER') {
            lyaideu_remember_try_delivery('rider');
            return;
        }
        // Default PHPSESSID is shared by storefront user + admin panel.
        lyaideu_remember_try_user();
        lyaideu_remember_try_admin();
        // If a logged-in user was blocked/deleted since login, drop the
        // session now so blocked accounts cannot keep ordering.
        try { lyaideu_remember_validate_active_user(); } catch (Throwable $e) {}
    } catch (Throwable $e) {
        // Never break the page because of remember-me.
    }
}

/**
 * Re-validates an already-active user session (blocked/deleted -> logout).
 * Only runs for logged-in users; guests cost zero queries.
 */
function lyaideu_remember_validate_active_user(): void {
    if (empty($_SESSION['user']['id'])) {
        return;
    }
    $uid = (int)$_SESSION['user']['id'];
    if ($uid <= 0) {
        return;
    }
    try {
        $pdo = lyaideu_remember_pdo();
        if (!$pdo instanceof PDO) {
            return;
        }
        $ok = false;
        $blocked = 0;
        try {
            $q = $pdo->prepare('SELECT is_blocked FROM users WHERE id = ? LIMIT 1');
            $q->execute([$uid]);
            $row = $q->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $ok = true;
                $blocked = (int)($row['is_blocked'] ?? 0);
            }
        } catch (Throwable $e) {
            try {
                $q = $pdo->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
                $q->execute([$uid]);
                $ok = (bool)$q->fetchColumn();
            } catch (Throwable $e2) {
                return; // DB hiccup: keep session, don't log out.
            }
        }
        if (!$ok || $blocked === 1) {
            unset($_SESSION['user']);
            try { lyaideu_remember_forget('user'); } catch (Throwable $e) {}
            if (!$ok) {
                try { lyaideu_remember_forget_all('user', $uid); } catch (Throwable $e) {}
            } else {
                try { lyaideu_remember_forget_all('user', $uid); } catch (Throwable $e) {}
            }
        }
    } catch (Throwable $e) {
        // ignore
    }
}
