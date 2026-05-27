<?php
session_start();
require_once '../includes/koneksi.php';

header('Content-Type: application/json');

if (!isset($_SESSION['username'], $_SESSION['role']) || $_SESSION['role'] !== 'teknisi') {
    echo json_encode(['status' => 'error', 'message' => 'Akses ditolak!']);
    exit();
}

$data = json_decode(file_get_contents("php://input"), true);
$password_lama = $data['password_lama'] ?? '';
$password_baru = $data['password_baru'] ?? '';

// Validasi field kosong
if (empty($password_lama) || empty($password_baru)) {
    echo json_encode(['status' => 'error', 'message' => 'Semua field wajib diisi!']);
    exit();
}

// Validasi panjang password baru (minimal 8 karakter, ada huruf & angka)
if (
    strlen($password_baru) < 8 ||
    !preg_match('/[A-Za-z]/', $password_baru) ||
    !preg_match('/[0-9]/', $password_baru)
) {
    echo json_encode(['status' => 'error', 'message' => 'Password baru minimal 8 karakter dan harus mengandung huruf serta angka!']);
    exit();
}

// Ambil password lama dari DB
$stmt = $conn->prepare("SELECT password FROM users WHERE username = ?");
$stmt->bind_param("s", $_SESSION['username']);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    echo json_encode(['status' => 'error', 'message' => 'User tidak ditemukan!']);
    exit();
}

if (!password_verify($password_lama, $user['password'])) {
    echo json_encode(['status' => 'error', 'message' => 'Password lama salah!']);
    exit();
}

$new_hash = password_hash($password_baru, PASSWORD_DEFAULT);

$update = $conn->prepare("UPDATE users SET password = ? WHERE username = ?");
$update->bind_param("ss", $new_hash, $_SESSION['username']);

if ($update->execute()) {
    echo json_encode(['status' => 'success', 'message' => 'Password berhasil diubah.']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Gagal mengubah password.']);
}
$update->close();
$conn->close();
