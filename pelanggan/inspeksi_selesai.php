<?php
// Session & security
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
  ini_set('session.cookie_secure', 1);
}

session_start();
require_once '../includes/koneksi.php';
date_default_timezone_set("Asia/Jakarta");

if (!isset($_SESSION['username']) || $_SESSION['role'] !== 'pelanggan') {
  header("Location: ../auth/login.php");
  exit();
}

$id_user   = (int) $_SESSION['id_user'];
$nama_user = $_SESSION['username'];

$stmt = $conn->prepare("SELECT o.id_order, o.tanggal_order, o.status, 
       k.nomor_polisi, k.merk, k.model, k.tahun_produksi, k.alamat, k.link_gmaps
       FROM order_inspeksi o
       JOIN kendaraan k ON o.id_mobil = k.id_mobil
       WHERE o.id_pelanggan=? AND o.status='Selesai'
       ORDER BY o.tanggal_order DESC");
$stmt->bind_param("i", $id_user);
$stmt->execute();
$result = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="id">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Order Selesai - Rtech Indonesia</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
  <link rel="icon" type="image/x-icon" href="../favicon.ico">
  <link rel="shortcut icon" type="image/x-icon" href="../favicon.ico">
  <style>
    body {
      font-family: 'Inter', sans-serif;
      padding-bottom: 70px;
    }
  </style>
</head>

<body class="bg-gray-50 text-gray-800">

  <header class="bg-indigo-600 text-white p-4 shadow-md flex justify-between items-center">
    <h1 class="text-lg font-semibold">Rtech Indonesia</h1>
    <a href="pelanggan_dashboard.php" class="text-sm bg-white text-indigo-700 px-3 py-1 rounded hover:bg-gray-100">
      ⬅️ Dashboard
    </a>
  </header>

  <div class="max-w-7xl mx-auto p-4">
    <h2 class="text-xl font-bold text-indigo-700 mb-4">✅ Order Selesai</h2>

    <?php if ($result->num_rows > 0): ?>
      <div class="block md:hidden space-y-3">
        <?php while ($row = $result->fetch_assoc()): ?>
          <div class="bg-white rounded shadow p-4">
            <p class="text-sm text-gray-400"><?= date("d M Y", strtotime($row['tanggal_order'])) ?></p>
            <h3 class="text-lg font-bold text-indigo-700"><?= htmlspecialchars($row['nomor_polisi']) ?></h3>
            <p class="text-gray-600"><?= htmlspecialchars($row['merk'] . ' ' . $row['model']) ?> - <?= $row['tahun_produksi'] ?></p>
            <p class="text-gray-600 text-sm">
              <?= htmlspecialchars($row['alamat']) ?>
              <?php if (!empty($row['link_gmaps'])): ?>
                <a href="<?= htmlspecialchars($row['link_gmaps']) ?>" target="_blank" rel="noopener"
                  class="ml-1 text-blue-600 hover:underline">Maps</a>
              <?php endif; ?>
            </p>

            <div class="flex items-center justify-between mt-2">
              <span class="px-2 py-1 rounded text-sm font-medium bg-green-100 text-green-700">
                <?= htmlspecialchars($row['status']) ?>
              </span>
              <a href="../admin/cetak_detail_order.php?id=<?= urlencode($row['id_order']) ?>"
                class="bg-red-600 text-white px-3 py-1 rounded-md text-sm font-medium hover:bg-red-700 active:scale-95 transition"
                title="Download Laporan PDF">
                📄 PDF
              </a>
            </div>
          </div>
        <?php endwhile; ?>
      </div>

      <div class="hidden md:block bg-white shadow rounded-lg overflow-x-auto">
        <table class="w-full text-sm text-left">
          <thead class="text-xs uppercase bg-gray-100">
            <tr>
              <th class="px-4 py-2">Tanggal</th>
              <th class="px-4 py-2">No. Polisi</th>
              <th class="px-4 py-2">Merk</th>
              <th class="px-4 py-2">Model</th>
              <th class="px-4 py-2">Tahun</th>
              <th class="px-4 py-2">Alamat</th>
              <th class="px-4 py-2">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <?php mysqli_data_seek($result, 0);
            while ($row = $result->fetch_assoc()): ?>
              <tr class="border-b hover:bg-gray-50">
                <td class="px-4 py-2"><?= date("d M Y", strtotime($row['tanggal_order'])) ?></td>
                <td class="px-4 py-2"><?= htmlspecialchars($row['nomor_polisi']) ?></td>
                <td class="px-4 py-2"><?= htmlspecialchars($row['merk']) ?></td>
                <td class="px-4 py-2"><?= htmlspecialchars($row['model']) ?></td>
                <td class="px-4 py-2"><?= htmlspecialchars($row['tahun_produksi']) ?></td>
                <td class="px-4 py-2 text-gray-600 text-sm">
                  <?= htmlspecialchars($row['alamat']) ?>
                  <?php if (!empty($row['link_gmaps'])): ?>
                    <a href="<?= htmlspecialchars($row['link_gmaps']) ?>" target="_blank" rel="noopener"
                      class="inline-block ml-1 text-blue-600 hover:underline">Maps</a>
                  <?php endif; ?>
                </td>
                <td class="px-4 py-2">
                  <a href="../admin/cetak_detail_order.php?id=<?= urlencode($row['id_order']) ?>"
                    class="inline-block bg-red-600 text-white text-xs font-medium px-3 py-1 rounded-md hover:bg-red-700 active:scale-95 transition"
                    title="Download Laporan PDF">
                    📄 PDF
                  </a>
                </td>
              </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <div class="bg-white p-6 text-center text-gray-500 rounded shadow">Belum ada order inspeksi yang selesai.</div>
    <?php endif; ?>
  </div>

  <nav class="fixed bottom-0 inset-x-0 bg-white shadow-inner border-t flex justify-around items-center py-2 z-50 md:hidden">
    <a href="pelanggan_dashboard.php" class="flex flex-col items-center text-xs text-gray-600 hover:text-indigo-600">
      🏠 <span>Dashboard</span>
    </a>
    <a href="buat_order.php" class="flex flex-col items-center text-xs text-gray-600 hover:text-indigo-600">
      ➕ <span>Order</span>
    </a>
    <a href="profil_pelanggan.php" class="flex flex-col items-center text-xs text-gray-600 hover:text-indigo-600">
      👤 <span>Profil</span>
    </a>
  </nav>
</body>

</html>