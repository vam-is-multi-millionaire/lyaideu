<?php
// Perfect user logout: clears the storefront user session + persistent
// remember token, but keeps an admin session (same PHPSESSID) intact.

session_set_cookie_params([
    'lifetime' => 30 * 24 * 60 * 60,
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/remember.php';

// Always clear the persistent cookie, even if the PHP session already expired.
try { if (function_exists('lyaideu_remember_forget')) lyaideu_remember_forget('user'); } catch (Throwable $e) {}

// Clear only user keys — do NOT wipe admin keys sharing this session.
unset($_SESSION['user'], $_SESSION['old']);

// Keep CSRF + admin data; rotate the session id to prevent fixation.
try { session_regenerate_id(true); } catch (Throwable $e) {}

header('Location: login');
exit;
