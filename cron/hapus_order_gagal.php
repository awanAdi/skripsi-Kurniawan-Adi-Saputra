<?php
require_once __DIR__ . '/../includes/koneksi.php';

$conn->query("
    DELETE FROM order_inspeksi
    WHERE status = 'Gagal'
      AND deleted_at < NOW() - INTERVAL 10 DAY
");
