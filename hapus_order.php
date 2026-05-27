<?php
session_start();
// Cek autentikasi admin
if (!isset($_SESSION['username']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit();
}
require_once '../includes/koneksi.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id_order'])) {
    $id_order = (int)$_POST['id_order'];

    $conn->query("DELETE FROM inspeksi WHERE id_order = $id_order");

    $conn->query("DELETE FROM order_inspeksi WHERE id_order = $id_order");

    header("Location: history.php?hapus=sukses");
    exit();
}

header("Location: history.php?hapus=gagal");
exit();
