<?php

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();

require_once __DIR__ . '/db.php';

function flash(string $type, string $msg): void {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}
function redirect(string $to): void { header("Location: $to"); exit; }
function sanitize($s) { return htmlspecialchars(strip_tags(trim($s))); }
function safe_next(string $next): string {
    $next = trim((string)$next);
    if ($next === '' || $next === 'login.php' || str_starts_with($next, '//') || strpos($next, ':') !== false || strpos($next, '..') !== false) return 'index.php';
    if (!preg_match('#^[A-Za-z0-9_\-.?&=]+$#', $next)) return 'index.php';
    return $next;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('login.php');

$action = trim($_POST['action'] ?? '');
$next   = safe_next($_POST['next'] ?? '');
$nextQS = ($next !== 'index.php') ? '&next=' . urlencode($next) : '';

/* ===================== SIGN UP ===================== */
if ($action === 'signup') {
    $name    = trim($_POST['name']    ?? '');
    $email   = strtolower(trim($_POST['email'] ?? ''));
    $phone   = preg_replace('/[^0-9]/', '', $_POST['phone'] ?? '');
    $dob     = trim($_POST['dob'] ?? '');
    $pass    = $_POST['password'] ?? '';
    $confirm = $_POST['confirm']  ?? '';

    $_SESSION['old'] = ['name'=>$name,'email'=>$email,'phone'=>$phone,'dob'=>$dob];

    $errors = [];

    // 1. Full name (min 3 chars, only letters/spaces)
    if (mb_strlen($name) < 3 || !preg_match('/^[\p{L}\s\'.]+$/u', $name))
        $errors[] = 'Full name must be at least 3 letters (only letters and spaces).';

    // 2. Email must end with @gmail.com
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !str_ends_with($email, '@gmail.com'))
        $errors[] = 'Email must be a valid address ending in @gmail.com';

    // 3. Phone exactly 10 digits, starts with 97 or 98
    if (!preg_match('/^9[78]\d{8}$/', $phone))
        $errors[] = 'Contact number must be exactly 10 digits and start with 97 or 98.';

    // 4. DOB: between 10 and 80 years old
    if ($dob) {
        $today = new DateTimeImmutable('today');
        $birth = DateTimeImmutable::createFromFormat('!Y-m-d', $dob);
        $validDob = $birth && $birth->format('Y-m-d') === $dob;

        if (!$validDob) {
            $errors[] = 'Please select a valid date of birth.';
        } elseif ($birth > $today) {
            $errors[] = 'Date of birth cannot be in the future.';
        } else {
            $age = $today->diff($birth)->y;
            if ($age < 10)  $errors[] = "You must be at least 10 years old (you are $age).";
            if ($age > 80)  $errors[] = "Age must be 80 or younger (you are $age).";
        }
    } else {
        $errors[] = 'Please select your date of birth.';
    }

    // 5. Password rules: 8+ chars, 1 capital, 1 symbol, 1 number
    if (strlen($pass) < 8)                        $errors[] = 'Password must be at least 8 characters.';
    elseif (!preg_match('/[A-Z]/', $pass))        $errors[] = 'Password must contain at least 1 capital letter.';
    elseif (!preg_match('/[0-9]/', $pass))        $errors[] = 'Password must contain at least 1 number.';
    elseif (!preg_match('/[^A-Za-z0-9]/', $pass)) $errors[] = 'Password must contain at least 1 symbol (e.g. @, #, $).';
    else {
        // 6. Password must NOT contain the user's name (any part) or phone number
        $nameParts = array_filter(explode(' ', strtolower($name)), fn($p) => strlen($p) >= 3);
        $passLow = strtolower($pass);
        foreach ($nameParts as $part) {
            if (strpos($passLow, $part) !== false) {
                $errors[] = "Password must NOT contain your name ('" . htmlspecialchars($part) . "').";
                break;
            }
        }
        if ($phone && strpos($pass, $phone) !== false)
            $errors[] = 'Password must NOT contain your contact number.';
    }

    // 7. Confirm password must match
    if ($pass !== $confirm) $errors[] = 'New password and confirm password do not match.';

    if ($errors) {
        flash('error', implode('<br>', $errors));
        redirect('login.php?tab=signup' . $nextQS);
    }

    try {
        $check = $pdo->prepare('SELECT email, phone FROM users WHERE email = :email OR phone = :phone LIMIT 1');
        $check->execute([':email' => $email, ':phone' => $phone]);
        $existing = $check->fetch();

        if ($existing && $existing['email'] === $email) {
            flash('error', 'This email is already registered. Please login instead.');
            redirect('login.php?tab=signup' . $nextQS);
        }
        if ($existing && $existing['phone'] === $phone) {
            flash('error', 'This contact number is already registered. Please login instead.');
            redirect('login.php?tab=signup' . $nextQS);
        }

        $insert = $pdo->prepare(
            'INSERT INTO users (name, email, phone, dob, pass, created_at)
             VALUES (:name, :email, :phone, :dob, :pass, :created_at)'
        );
        $insert->execute([
            ':name' => $name,
            ':email' => $email,
            ':phone' => $phone,
            ':dob' => $dob,
            ':pass' => password_hash($pass, PASSWORD_DEFAULT),
            ':created_at' => date('Y-m-d H:i:s'),
        ]);

        $userId = (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        flash('error', 'Could not create your account right now. Please try again.');
        redirect('login.php?tab=signup' . $nextQS);
    }

    unset($_SESSION['old']);
    session_regenerate_id(true);
    $_SESSION['user'] = ['id' => $userId, 'name' => $name, 'email' => $email, 'phone' => $phone, 'dob' => $dob];
    flash('success', 'Welcome to LyaiDeu, ' . htmlspecialchars($name) . '!');
    redirect(safe_next($_POST['next'] ?? ''));
}

/* ===================== LOGIN ===================== */
if ($action === 'login') {
    $username = trim($_POST['username'] ?? '');
    $pass     = $_POST['password'] ?? '';

    if ($username === '' || $pass === '') {
        flash('error', 'Please enter your username and password.');
        redirect('login.php' . $nextQS);
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT id, name, email, phone, dob, pass
             FROM users
             WHERE LOWER(name) = LOWER(:name_login)
                OR phone = :phone_login
                OR email = :email_login
             LIMIT 1'
        );
        $stmt->execute([
            ':name_login' => $username,
            ':phone_login' => $username,
            ':email_login' => strtolower($username),
        ]);
        $u = $stmt->fetch();

        if ($u && password_verify($pass, $u['pass'])) {
            session_regenerate_id(true);
            $_SESSION['user'] = [
                'id'    => $u['id'],
                'name'  => $u['name'],
                'email' => $u['email'],
                'phone' => $u['phone'],
                'dob'   => $u['dob'],
            ];
            redirect(safe_next($_POST['next'] ?? ''));
        }
    } catch (Throwable $e) {
        flash('error', 'Could not log you in right now. Please try again.');
        redirect('login.php' . $nextQS);
    }

    flash('error', 'Invalid username or password. Please try again or sign up.');
    redirect('login.php' . $nextQS);
}

redirect('login.php' . $nextQS);