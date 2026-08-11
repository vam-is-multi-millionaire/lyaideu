<?php

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();

define('DATA_FILE', __DIR__ . '/data.json');

function load_data(): array {
    return json_decode(file_get_contents(DATA_FILE), true) ?: ['dishes'=>[],'hotels'=>[],'contacts'=>[],'users'=>[]];
}
function save_data(array $data): void {
    file_put_contents(DATA_FILE, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
}
function flash(string $type, string $msg): void {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}
function redirect(string $to): void { header("Location: $to"); exit; }
function sanitize($s) { return htmlspecialchars(strip_tags(trim($s))); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('login.php');

$action = trim($_POST['action'] ?? '');

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
        redirect('login.php?tab=signup');
    }

    // Check if email or phone already registered
    $data = load_data();
    foreach ($data['users'] ?? [] as $u) {
        if ($u['email'] === $email) {
            flash('error', 'This email is already registered. Please login instead.');
            redirect('login.php?tab=signup');
        }
        if ($u['phone'] === $phone) {
            flash('error', 'This contact number is already registered. Please login instead.');
            redirect('login.php?tab=signup');
        }
    }

    // All good — create the user!
    $data['users'][] = [
        'id'      => time(),
        'name'    => $name,
        'email'   => $email,
        'phone'   => $phone,
        'dob'     => $dob,
        'pass'    => password_hash($pass, PASSWORD_DEFAULT),
        'created' => date('Y-m-d H:i'),
    ];
    save_data($data);

    unset($_SESSION['old']);
    session_regenerate_id(true);
    $_SESSION['user'] = ['id' => end($data['users'])['id'], 'name' => $name, 'email' => $email, 'phone' => $phone, 'dob' => $dob];
    flash('success', 'Welcome to LyaiDeu, ' . htmlspecialchars($name) . '! 🎉');
    redirect('index.php');
}

/* ===================== LOGIN ===================== */
if ($action === 'login') {
    $username = trim($_POST['username'] ?? '');
    $pass     = $_POST['password'] ?? '';

    if ($username === '' || $pass === '') {
        flash('error', 'Please enter your username and password.');
        redirect('login.php');
    }

    $data = load_data();
    foreach ($data['users'] ?? [] as $u) {
        // Username can be EITHER the full name OR the phone number
        $matchName  = (strcasecmp($u['name'], $username) === 0);
        $matchPhone = ($u['phone'] === $username);
        $matchEmail = ($u['email'] === strtolower($username));

        if (($matchName || $matchPhone || $matchEmail) && password_verify($pass, $u['pass'])) {
            session_regenerate_id(true);
            $_SESSION['user'] = [
                'id'    => $u['id'],
                'name'  => $u['name'],
                'email' => $u['email'],
                'phone' => $u['phone'],
                'dob'   => $u['dob'],
            ];
            redirect('index.php');
        }
    }

    flash('error', 'Invalid username or password. Please try again or sign up.');
    redirect('login.php');
}

redirect('login.php');