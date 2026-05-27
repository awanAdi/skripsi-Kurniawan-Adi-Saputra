<?php

declare(strict_types=1);
session_start();
require_once '../includes/koneksi.php';

header('Content-Type: application/json');

function json_exit(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

if (
    $_SERVER['REQUEST_METHOD'] !== 'POST' ||
    !isset($_SESSION['id_user'], $_SESSION['role']) ||
    $_SESSION['role'] !== 'pelanggan'
) {
    json_exit(['status' => 'error', 'message' => 'Akses ditolak']);
}

if (
    empty($_POST['csrf_token']) ||
    empty($_SESSION['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
) {
    json_exit(['status' => 'error', 'message' => ' Permintaan tidak dapat diproses. Silakan muat ulang halaman.']);
}

/* ================= INPUT ================= */
$id_user  = (int) $_SESSION['id_user'];
$username = trim($_POST['username'] ?? '');
$nama     = trim($_POST['nama_lengkap'] ?? '');
$no_hp    = trim($_POST['no_hp'] ?? '');
$email    = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';
$confirm  = $_POST['confirm_password'] ?? '';

$errors = [];

/* ================= VALIDASI ================= */
if ($username === '' || strlen($username) < 3) {
    $errors['username'] = 'Username minimal 3 karakter.';
}
if ($nama === '' || strlen($nama) < 3 || strlen($nama) > 100) {
    $errors['nama_lengkap'] = 'Nama lengkap harus 3–100 karakter.';
}
if (!preg_match('/^[0-9]{10,15}$/', $no_hp)) {
    $errors['no_hp'] = 'Nomor HP harus 10–15 digit.';
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = 'Format email tidak valid.';
}

if ($password !== '') {
    if (strlen($password) < 6) {
        $errors['password'] = 'Password minimal 6 karakter.';
    } elseif ($password !== $confirm) {
        $errors['password'] = 'Password dan konfirmasi tidak cocok.';
    }
}

if ($errors) {
    json_exit([
        'status'  => 'error',
        'message' => 'Validasi gagal.',
        'errors'  => $errors
    ]);
}

/* ================= CEK UNIK (USERNAME, EMAIL, HP) ================= */
$stmt = $conn->prepare("
    SELECT 
        SUM(username = ?) AS u,
        SUM(email = ?) AS e,
        SUM(no_hp = ?) AS h
    FROM users
    WHERE id_user <> ?
");
$stmt->bind_param('sssi', $username, $email, $no_hp, $id_user);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($result['u'] > 0) $errors['username'] = 'Username sudah digunakan.';
if ($result['e'] > 0) $errors['email']    = 'Email sudah digunakan.';
if ($result['h'] > 0) $errors['no_hp']    = 'Nomor HP sudah digunakan.';

if ($errors) {
    json_exit([
        'status'  => 'error',
        'message' => 'Data sudah terdaftar.',
        'errors'  => $errors
    ]);
}

if ($password !== '') {
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("
        UPDATE users 
        SET username=?, nama_lengkap=?, no_hp=?, email=?, password=?
        WHERE id_user=?
    ");
    $stmt->bind_param('sssssi', $username, $nama, $no_hp, $email, $hash, $id_user);
} else {
    $stmt = $conn->prepare("
        UPDATE users 
        SET username=?, nama_lengkap=?, no_hp=?, email=?
        WHERE id_user=?
    ");
    $stmt->bind_param('ssssi', $username, $nama, $no_hp, $email, $id_user);
}

if (!$stmt->execute()) {
    error_log('update_profil.php DB error: ' . $stmt->error);
    json_exit(['status' => 'error', 'message' => 'Gagal menyimpan perubahan.']);
}
$stmt->close();

$_SESSION['username'] = $username;

$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

json_exit([
    'status'      => 'success',
    'message'     => 'Profil berhasil diperbarui.',
    'username'    => $username,
    'new_csrf'    => $_SESSION['csrf_token']
]);
