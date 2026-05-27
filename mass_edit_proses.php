<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['username']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['status' => 'error', 'message' => 'Akses ditolak. Silakan login sebagai admin.']);
    exit;
}

require_once '../includes/koneksi.php';

if (!isset($_POST['id_standar']) || !is_array($_POST['id_standar'])) {
    echo json_encode(['status' => 'error', 'message' => 'Data tidak diterima.']);
    exit;
}

$ids = $_POST['id_standar'];
$komponen = $_POST['komponen'] ?? [];
$tipe_input = $_POST['tipe_input'] ?? [];
$nilai_batas = $_POST['nilai_batas'] ?? [];
$opsi_pilihan = $_POST['opsi_pilihan'] ?? [];
$keterangan = $_POST['keterangan'] ?? [];

$count = count($ids);
if (!($count === count($komponen) && $count === count($tipe_input) && $count === count($nilai_batas) && $count === count($opsi_pilihan) && $count === count($keterangan))) {
    echo json_encode(['status' => 'error', 'message' => 'Jumlah data tidak konsisten.']);
    exit;
}

$conn->begin_transaction();
try {
    $stmt = $conn->prepare("
        UPDATE standar_komponen
        SET komponen = ?,
            tipe_input = ?,
            nilai_batas = (CASE WHEN TRIM(?) = '' THEN NULL ELSE ? END),
            opsi_pilihan = (CASE WHEN TRIM(?) = '' THEN NULL ELSE ? END),
            keterangan = (CASE WHEN TRIM(?) = '' THEN NULL ELSE ? END)
        WHERE id_standar = ?
    ");

    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }

    for ($i = 0; $i < $count; $i++) {
        $id = (int)$ids[$i];
        $komp = trim($komponen[$i]);
        $komp = preg_replace('/\s+/', ' ', $komp);
        $tipe = trim($tipe_input[$i]);
        $nilai_raw = (string)($nilai_batas[$i] ?? '');
        $opsi = (string)($opsi_pilihan[$i] ?? '');
        $ket = (string)($keterangan[$i] ?? '');

        $bind = $stmt->bind_param(
            "ssssssssi",
            $komp,
            $tipe,
            $nilai_raw,
            $nilai_raw,
            $opsi,
            $opsi,
            $ket,
            $ket,
            $id
        );

        if ($bind === false) {
            throw new Exception("Bind param failed: " . $stmt->error);
        }

        if (!$stmt->execute()) {
            throw new Exception("Gagal update ID $id: " . $stmt->error);
        }
    }

    $conn->commit();
    echo json_encode(['status' => 'ok']);
    exit;
} catch (Exception $e) {
    $conn->rollback();
    error_log("mass_edit_proses error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}
