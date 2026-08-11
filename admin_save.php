<?php
session_start();

if (!isset($_SESSION['is_admin'])) {
    header('Location: admin.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: admin.php');
    exit;
}

if (!hash_equals($_SESSION['csrf_admin'] ?? '', $_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    exit('Invalid security token. Please reload the admin panel and try again.');
}

const DATA_FILE = __DIR__ . '/data.json';

function clean_text($value): string {
    return trim(strip_tags((string)$value));
}

function clean_phone($value): string {
    return preg_replace('/[^0-9+]/', '', (string)$value);
}

function clean_url($value): string {
    $value = trim((string)$value);
    if ($value === '') return '';
    if (preg_match('#^(https?://|/|\./|\.\./)#i', $value)) return $value;
    return '';
}

function valid_category($value): string {
    $allowed = ['momo', 'pizza', 'chowmein', 'snacks', 'beverages', 'dinner'];
    return in_array($value, $allowed, true) ? $value : 'snacks';
}

$data = json_decode(file_get_contents(DATA_FILE), true);
if (!is_array($data)) {
    header('Location: admin.php?error=' . urlencode('Could not read data.json.'));
    exit;
}

$data += ['dishes' => [], 'hotels' => [], 'contacts' => [], 'users' => [], 'orders' => [], 'reviews' => []];

/* ===================== DISHES ===================== */
$updatedDishes = [];
$maxDishId = 0;

foreach ($data['dishes'] as $dish) {
    $maxDishId = max($maxDishId, (int)($dish['id'] ?? 0));
}

foreach (($_POST['dishes'] ?? []) as $d) {
    if (!empty($d['delete'])) continue;

    $name = clean_text($d['name'] ?? '');
    $hotel = clean_text($d['hotel'] ?? '');
    if ($name === '' || $hotel === '') continue;

    $updatedDishes[] = [
        'id' => (int)($d['id'] ?? 0),
        'name' => $name,
        'hotel' => $hotel,
        'cat' => valid_category($d['cat'] ?? 'snacks'),
        'price' => max(0, (int)($d['price'] ?? 0)),
        'phone' => clean_phone($d['phone'] ?? ''),
        'tag' => clean_text($d['tag'] ?? ''),
        'desc' => clean_text($d['desc'] ?? ''),
        'img' => clean_url($d['img'] ?? '')
    ];
}

$newDish = $_POST['new_dish'] ?? [];
if (clean_text($newDish['name'] ?? '') !== '') {
    $updatedDishes[] = [
        'id' => ++$maxDishId,
        'name' => clean_text($newDish['name'] ?? ''),
        'hotel' => clean_text($newDish['hotel'] ?? ''),
        'cat' => valid_category($newDish['cat'] ?? 'snacks'),
        'price' => max(0, (int)($newDish['price'] ?? 0)),
        'phone' => clean_phone($newDish['phone'] ?? ''),
        'tag' => clean_text($newDish['tag'] ?? ''),
        'desc' => clean_text($newDish['desc'] ?? ''),
        'img' => clean_url($newDish['img'] ?? '')
    ];
}

/* ===================== HOTELS ===================== */
$updatedHotels = [];
foreach (($_POST['hotels'] ?? []) as $h) {
    if (!empty($h['delete'])) continue;

    $name = clean_text($h['name'] ?? '');
    if ($name === '') continue;

    $updatedHotels[] = [
        'name' => $name,
        'type' => clean_text($h['type'] ?? ''),
        'phone' => clean_phone($h['phone'] ?? ''),
        'emoji' => clean_text($h['emoji'] ?? '')
    ];
}

$newHotel = $_POST['new_hotel'] ?? [];
if (clean_text($newHotel['name'] ?? '') !== '') {
    $updatedHotels[] = [
        'name' => clean_text($newHotel['name'] ?? ''),
        'type' => clean_text($newHotel['type'] ?? ''),
        'phone' => clean_phone($newHotel['phone'] ?? ''),
        'emoji' => clean_text($newHotel['emoji'] ?? '🏨')
    ];
}

/* ===================== CONTACTS ===================== */
$updatedContacts = [];
foreach (($_POST['contacts'] ?? []) as $c) {
    if (!empty($c['delete'])) continue;

    $role = clean_text($c['role'] ?? '');
    if ($role === '') continue;

    $updatedContacts[] = [
        'role' => $role,
        'person' => clean_text($c['person'] ?? ''),
        'phone' => clean_phone($c['phone'] ?? ''),
        'note' => clean_text($c['note'] ?? ''),
        'ico' => clean_text($c['ico'] ?? '📞')
    ];
}

$newContact = $_POST['new_contact'] ?? [];
if (clean_text($newContact['role'] ?? '') !== '') {
    $updatedContacts[] = [
        'role' => clean_text($newContact['role'] ?? ''),
        'person' => clean_text($newContact['person'] ?? ''),
        'phone' => clean_phone($newContact['phone'] ?? ''),
        'note' => clean_text($newContact['note'] ?? ''),
        'ico' => clean_text($newContact['ico'] ?? '📞')
    ];
}

/* Preserve users and any future top-level data instead of rebuilding the JSON from scratch. */
$data['dishes'] = $updatedDishes;
$data['hotels'] = $updatedHotels;
$data['contacts'] = $updatedContacts;
$data['users'] = $data['users'] ?? [];

$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false || file_put_contents(DATA_FILE, $json . PHP_EOL, LOCK_EX) === false) {
    header('Location: admin.php?error=' . urlencode('Could not save changes. Check file permissions.'));
    exit;
}

header('Location: admin.php?saved=1');
exit;
?>
