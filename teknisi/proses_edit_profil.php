<?php
session_start();
require_once '../includes/koneksi.php';

header('Content-Type: application/json');

if (!isset($_SESSION['username'], $_SESSION['role']) || $_SESSION['role'] !== 'teknisi') {
    echo json_encode(['status' => 'error', 'message' => 'Akses ditolak!']);
    exit();
}

$data = json_decode(file_get_contents("php://input"), true);
$nama_lengkap = trim($data['nama_lengkap'] ?? '');
$email = trim($data['email'] ?? '');
$no_hp = trim($data['no_hp'] ?? '');

if (empty($nama_lengkap) || empty($email) || empty($no_hp)) {
    echo json_encode(['status' => 'error', 'message' => 'Semua field wajib diisi!']);
    exit();
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['status' => 'error', 'message' => 'Format email tidak valid!']);
    exit();
}

if (!preg_match('/^[0-9]{10,15}$/', $no_hp)) {
    echo json_encode(['status' => 'error', 'message' => 'Nomor HP harus berupa angka 10-15 digit!']);
    exit();
}

$link_gmaps = trim($data['link_gmaps'] ?? '');

if (!empty($link_gmaps) && !preg_match('/^https:\/\/(www\.)?google\.[a-z.]+\/maps/i', $link_gmaps)) {
    echo json_encode(['status' => 'error', 'message' => 'Link Maps tidak valid!']);
    exit();
}

$stmt = $conn->prepare("UPDATE users 
    SET nama_lengkap = ?, email = ?, no_hp = ?, link_gmaps = ? 
    WHERE username = ?");
$stmt->bind_param("sssss", $nama_lengkap, $email, $no_hp, $link_gmaps, $_SESSION['username']);

if ($stmt->execute()) {
    echo json_encode(['status' => 'success', 'message' => 'Profil berhasil diperbarui.']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Gagal memperbarui profil.']);
}
$stmt->close();
$conn->close();
