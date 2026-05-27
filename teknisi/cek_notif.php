<?php
session_start();
require_once '../includes/koneksi.php';

if (!isset($_SESSION['username']) || $_SESSION['role'] !== 'teknisi') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$stmt = $conn->prepare("SELECT COUNT(*) AS jumlah FROM order_inspeksi WHERE id_teknisi = ? AND status = 'Menunggu'");
$stmt->bind_param("i", $id_teknisi);
$stmt->execute();
$result = $stmt->get_result();
$jumlah = $result->fetch_assoc()['jumlah'] ?? 0;

echo json_encode(['jumlah' => $jumlah]);
