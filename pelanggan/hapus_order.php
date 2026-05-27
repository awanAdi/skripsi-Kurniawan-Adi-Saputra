<?php

declare(strict_types=1);
session_start();
require_once '../includes/koneksi.php';

header('Content-Type: application/json');

if (
    ($_SESSION['role'] ?? '') !== 'pelanggan' ||
    !isset($_SESSION['id_user'])
) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Akses ditolak']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metode tidak valid']);
    exit;
}

if (
    empty($_POST['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])
) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Token tidak valid']);
    exit;
}

$id_order = (int)($_POST['id_order'] ?? 0);
$id_user  = (int)$_SESSION['id_user'];

$sql = "
  DELETE FROM order_inspeksi
  WHERE id_order = ?
    AND id_pelanggan = ?
    AND status = 'Gagal'
  LIMIT 1
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Query gagal']);
    exit;
}

$stmt->bind_param('ii', $id_order, $id_user);
$stmt->execute();

if ($stmt->affected_rows === 1) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Order tidak ditemukan atau tidak bisa dihapus']);
}

$stmt->close();
