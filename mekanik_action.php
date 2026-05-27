<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['username']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Akses ditolak.']);
    exit();
}
require_once '../includes/koneksi.php';

function post($k)
{
    return $_POST[$k] ?? null;
}
function jsonResponse($arr)
{
    echo json_encode($arr);
    exit();
}
if (!isset($_POST['action'])) jsonResponse(['success' => false, 'message' => 'Action tidak ditemukan.']);
$action = $_POST['action'];

// CSRF
$csrf = post('csrf_token') ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
    jsonResponse(['success' => false, 'message' => 'Token CSRF tidak valid.']);
}

if ($action === 'add') {
    $username = trim(post('username') ?? '');
    $password_raw = trim(post('password') ?? '');
    $confirm = trim(post('confirm_password') ?? '');
    $nama = trim(post('nama_lengkap') ?? '');
    $no_hp = trim(post('no_hp') ?? '');
    $role = post('role') ?? '';

    // basic validation (same rules)
    if ($username === '' || $password_raw === '' || $confirm === '' || $nama === '' || $no_hp === '' || $role === '') {
        jsonResponse(['success' => false, 'message' => 'Semua field wajib diisi.']);
    }
    if ($password_raw !== $confirm) jsonResponse(['success' => false, 'message' => 'Konfirmasi password tidak cocok.']);
    if (!preg_match('/^[A-Za-z0-9_]{4,20}$/', $username)) jsonResponse(['success' => false, 'message' => 'Username tidak valid.']);
    if (strlen($password_raw) < 6) jsonResponse(['success' => false, 'message' => 'Password minimal 6 karakter.']);
    if (!preg_match('/^[0-9]{10,15}$/', $no_hp)) jsonResponse(['success' => false, 'message' => 'No HP tidak valid.']);
    if (!in_array($role, ['admin', 'teknisi'])) jsonResponse(['success' => false, 'message' => 'Role tidak valid.']);

    // unique checks
    $stmt = $conn->prepare("SELECT id_user, username, no_hp FROM users WHERE username=? OR no_hp=?");
    $stmt->bind_param("ss", $username, $no_hp);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        if ($r['username'] === $username) jsonResponse(['success' => false, 'message' => 'Username sudah digunakan.']);
        if ($r['no_hp'] === $no_hp) jsonResponse(['success' => false, 'message' => 'No HP sudah terdaftar.']);
    }
    $stmt->close();

    $pw = password_hash($password_raw, PASSWORD_DEFAULT);
    $ins = $conn->prepare("INSERT INTO users (username, password, nama_lengkap, no_hp, role, status_aktif) VALUES (?, ?, ?, ?, ?, 1)");
    $ins->bind_param("sssss", $username, $pw, $nama, $no_hp, $role);
    if ($ins->execute()) {
        $id = $ins->insert_id;
        $ins->close();
        jsonResponse(['success' => true, 'message' => 'Akun berhasil ditambahkan.', 'user' => [
            'id_user' => (int)$id,
            'username' => $username,
            'nama_lengkap' => $nama,
            'no_hp' => $no_hp,
            'role' => $role
        ]]);
    } else {
        $ins->close();
        jsonResponse(['success' => false, 'message' => 'Gagal menyimpan data.']);
    }
}

if ($action === 'edit') {
    $id = (int)(post('id') ?? 0);
    $username = trim(post('username') ?? '');
    $nama = trim(post('nama_lengkap') ?? '');
    $no_hp = trim(post('no_hp') ?? '');

    if ($id <= 0) jsonResponse(['success' => false, 'message' => 'ID tidak valid.']);
    if ($username === '' || $nama === '' || $no_hp === '') jsonResponse(['success' => false, 'message' => 'Semua field wajib diisi.']);
    if (!preg_match('/^[A-Za-z0-9_]{4,20}$/', $username)) jsonResponse(['success' => false, 'message' => 'Username tidak valid.']);
    if (!preg_match('/^[0-9]{10,15}$/', $no_hp)) jsonResponse(['success' => false, 'message' => 'No HP tidak valid.']);

    // uniqueness check excluding current
    $stmt = $conn->prepare("SELECT id_user, username, no_hp FROM users WHERE (username=? OR no_hp=?) AND id_user<>?");
    $stmt->bind_param("ssi", $username, $no_hp, $id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        if ($r['username'] === $username) jsonResponse(['success' => false, 'message' => 'Username sudah digunakan oleh akun lain.']);
        if ($r['no_hp'] === $no_hp) jsonResponse(['success' => false, 'message' => 'No HP sudah digunakan oleh akun lain.']);
    }
    $stmt->close();

    $upd = $conn->prepare("UPDATE users SET username=?, nama_lengkap=?, no_hp=? WHERE id_user=?");
    $upd->bind_param("sssi", $username, $nama, $no_hp, $id);
    if ($upd->execute()) {
        $upd->close();
        jsonResponse(['success' => true, 'message' => 'Data akun berhasil diperbarui.', 'user' => [
            'id_user' => $id,
            'username' => $username,
            'nama_lengkap' => $nama,
            'no_hp' => $no_hp
        ]]);
    } else {
        $upd->close();
        jsonResponse(['success' => false, 'message' => 'Gagal memperbarui data.']);
    }
}

if ($action === 'reset_password') {
    $id = (int)(post('id') ?? 0);
    $new = trim(post('new_password') ?? '');
    if ($id <= 0) jsonResponse(['success' => false, 'message' => 'ID tidak valid.']);
    if (strlen($new) < 6) jsonResponse(['success' => false, 'message' => 'Password minimal 6 karakter.']);
    $pw = password_hash($new, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("UPDATE users SET password=? WHERE id_user=?");
    $stmt->bind_param("si", $pw, $id);
    if ($stmt->execute()) {
        $stmt->close();
        jsonResponse(['success' => true, 'message' => 'Password berhasil direset.']);
    } else {
        $stmt->close();
        jsonResponse(['success' => false, 'message' => 'Gagal mereset password.']);
    }
}

if ($action === 'delete' || $action === 'hapus') {
    $id = (int)(post('id') ?? 0);
    if ($id <= 0) jsonResponse(['success' => false, 'message' => 'ID tidak valid.']);
    // prevent deleting self
    $stmtSel = $conn->prepare("SELECT username FROM users WHERE id_user=?");
    $stmtSel->bind_param("i", $id);
    $stmtSel->execute();
    $stmtSel->bind_result($target_username);
    $f = $stmtSel->fetch();
    $stmtSel->close();
    if (!$f) jsonResponse(['success' => false, 'message' => 'Akun tidak ditemukan.']);
    if ($target_username === $_SESSION['username']) jsonResponse(['success' => false, 'message' => 'Tidak bisa menghapus akun yang sedang digunakan.']);
    $del = $conn->prepare("DELETE FROM users WHERE id_user=?");
    $del->bind_param("i", $id);
    if ($del->execute()) {
        $del->close();
        jsonResponse(['success' => true, 'message' => 'Akun berhasil dihapus.', 'id' => $id]);
    } else {
        $del->close();
        jsonResponse(['success' => false, 'message' => 'Gagal menghapus akun.']);
    }
}

jsonResponse(['success' => false, 'message' => 'Action tidak dikenali.']);
