<?php
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();
if (!isset($_SESSION['user'])) { header('Location: login.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: contact.php'); exit; }
if (!hash_equals($_SESSION['csrf_contact'] ?? '', $_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    exit('Invalid security token. Please go back and try again.');
}

require_once __DIR__ . '/site_config.php';
require_once __DIR__ . '/db.php';

function clean_text($v): string { return trim(strip_tags((string)$v)); }
function clean_phone($v): string { return preg_replace('/[^0-9+]/', '', (string)$v); }
function clean_email($v): string { return strtolower(trim((string)$v)); }
function flash_contact(string $type, string $msg): void {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
    header('Location: contact.php');
    exit;
}

$user = $_SESSION['user'];
$name = clean_text($_POST['name'] ?? '');
$email = clean_email($_POST['email'] ?? '');
$phone = clean_phone($_POST['phone'] ?? '');
$subject = clean_text($_POST['subject'] ?? '');
$body = clean_text($_POST['message'] ?? '');

if ($name === '') { $name = (string)($user['name'] ?? ''); }
if ($email === '') { $email = (string)($user['email'] ?? ''); }
if ($phone === '') { $phone = (string)($user['phone'] ?? ''); }
if ($name === '') { flash_contact('error', 'Please enter your name.'); }
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    flash_contact('error', 'Please enter a valid email address.');
}
if (mb_strlen($body) < 10) {
    flash_contact('error', 'Your message must be at least 10 characters long.');
}
if (!lyaideu_ensure_messages_table()) {
    flash_contact('error', 'Could not set up the message system. Please try again.');
}

try {
    $stmt = $pdo->prepare(
        'INSERT INTO messages (user_id, name, email, phone, subject, body, status, created_at)
         VALUES (:user_id, :name, :email, :phone, :subject, :body, \'unread\', :created_at)'
    );
    $stmt->execute([
        ':user_id' => (int)($user['id'] ?? 0) ?: null,
        ':name' => $name,
        ':email' => $email,
        ':phone' => $phone,
        ':subject' => $subject !== '' ? $subject : '(No subject)',
        ':body' => $body,
        ':created_at' => date('Y-m-d H:i:s'),
    ]);
} catch (Throwable $e) {
    flash_contact('error', 'Could not send your message. Please try again.');
}

flash_contact('success', '<i class="fa-solid fa-circle-check"></i> Message sent! Our support team will get back to you soon.');