<?php
session_start();
if (!isset($_SESSION['username']) || $_SESSION['role'] !== 'teknisi') {
  header("Location: ../auth/login.php");
  exit();
}
require_once '../includes/koneksi.php';

$sql = "
    SELECT 
        o.id_order,
        u.nama_lengkap AS nama_customer,
        k.merk,
        k.nomor_polisi,
        k.tahun_produksi,
        i.id_inspeksi
    FROM order_inspeksi o
    JOIN kendaraan k ON o.id_mobil = k.id_mobil
    JOIN users u ON o.id_pelanggan = u.id_user
    JOIN inspeksi i ON i.id_order = o.id_order
    WHERE o.status = 'Selesai'
    ORDER BY o.id_order DESC
";
$data = $conn->query($sql);
?>

<!DOCTYPE html>
<html lang="id">

<head>
  <meta charset="UTF-8" />
  <title>Order Selesai - Teknisi</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-100 p-6 min-h-screen">
  <div class="max-w-6xl mx-auto">
    <div class="flex justify-between items-center mb-6">
      <h1 class="text-2xl font-bold text-green-700">Orderan Selesai</h1>
      <a href="teknisi_dashboard.php" class="bg-gray-200 px-4 py-2 rounded text-gray-700 hover:bg-gray-300">← Kembali</a>
    </div>

    <?php if ($data->num_rows > 0): ?>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <?php while ($row = $data->fetch_assoc()): ?>
          <a href="hasil_inspeksi.php?id=<?= $row['id_inspeksi'] ?>" class="block bg-white border border-gray-200 p-6 rounded-lg shadow hover:shadow-lg transition hover:bg-gray-50">
            <div class="mb-2">
              <p class="text-sm text-gray-400">#Order ID: <?= $row['id_order'] ?></p>
              <h2 class="text-lg font-semibold text-gray-800"><?= htmlspecialchars($row['merk']) ?> (<?= $row['nomor_polisi'] ?>)</h2>
              <p class="text-sm">Customer: <strong><?= htmlspecialchars($row['nama_customer']) ?></strong></p>
              <p class="text-sm text-gray-500">Tahun: <?= $row['tahun_produksi'] ?></p>
            </div>
            <span class="inline-block px-3 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-700">Selesai</span>
          </a>
        <?php endwhile; ?>
      </div>
    <?php else: ?>
      <p class="text-gray-600">Belum ada order yang selesai.</p>
    <?php endif; ?>
  </div>
</body>

</html>