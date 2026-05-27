<?php
session_start();
require_once '../includes/koneksi.php';
date_default_timezone_set('Asia/Jakarta');

if (!isset($_SESSION['username']) || $_SESSION['role'] !== 'teknisi') {
  header("Location: ../auth/login.php");
  exit;
}
$tglInspeksi = $orderData['tanggal_inspeksi'] ?? null;
$formatTanggal = '-';
if (!empty($tglInspeksi)) {
  $timestamp = strtotime($tglInspeksi);

  // Array nama hari dan bulan dalam bahasa Indonesia
  $hariIndo = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
  $bulanIndo = [
    1 => 'Januari',
    'Februari',
    'Maret',
    'April',
    'Mei',
    'Juni',
    'Juli',
    'Agustus',
    'September',
    'Oktober',
    'November',
    'Desember'
  ];

  $hari = $hariIndo[date('w', $timestamp)];
  $tgl  = date('j', $timestamp);
  $bln  = $bulanIndo[(int)date('n', $timestamp)];
  $thn  = date('Y', $timestamp);

  $formatTanggal = "$hari, $tgl $bln $thn";
}
$id_inspeksi = isset($_GET['id_inspeksi']) ? intval($_GET['id_inspeksi']) : 0;
if ($id_inspeksi <= 0) die("ID inspeksi tidak valid.");

$stmt = $conn->prepare("
  SELECT oi.id_order, u.nama_lengkap, t.nama_lengkap AS nama_teknisi,
         k.merk, k.model, k.nomor_polisi, k.alamat AS alamat_mobil, 
         k.tahun_produksi, k.link_gmaps,
         i.id_inspeksi, i.tanggal_inspeksi, i.nilai_akhir, i.nilai_huruf, i.kesimpulan
  FROM inspeksi i
  JOIN order_inspeksi oi ON oi.id_order = i.id_order
  JOIN users u ON oi.id_pelanggan = u.id_user
  JOIN kendaraan k ON oi.id_mobil = k.id_mobil
  JOIN users t ON i.id_teknisi = t.id_user
  WHERE i.id_inspeksi = ?
  LIMIT 1
");
$stmt->bind_param("i", $id_inspeksi);
$stmt->execute();
$orderData = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$orderData) die("Data inspeksi tidak ditemukan.");
$standarMap = [];
$resStandar = $conn->query("
    SELECT id_standar,id_kriteria,komponen,tipe_input,nilai_batas,opsi_pilihan,keterangan
    FROM standar_komponen
    ORDER BY id_kriteria, komponen,
             CASE WHEN tipe_input='angka' THEN COALESCE(nilai_batas,9999999) END ASC
");
while ($r = $resStandar->fetch_assoc()) {
  $idk = (int)$r['id_kriteria'];
  $komp = trim((string)$r['komponen']);
  if ($komp === '') continue;
  if (!isset($standarMap[$idk][$komp])) {
    $standarMap[$idk][$komp] = ['tipe' => $r['tipe_input'], 'angka' => [], 'opsi' => []];
  }
  if ($r['tipe_input'] === 'angka') {
    $standarMap[$idk][$komp]['angka'][] = ['batas' => $r['nilai_batas'] !== null ? (float)$r['nilai_batas'] : null, 'hasil' => $r['keterangan']];
  } elseif ($r['tipe_input'] === 'pilihan') {
    $ops = preg_split('/[,\|]/', (string)$r['opsi_pilihan']);
    foreach ($ops as $o) {
      $k = strtolower(trim($o));
      if ($k !== '') $standarMap[$idk][$komp]['opsi'][$k] = $r['keterangan'];
    }
  }
}

function tentukanHasil(array $row, array $map)
{
  $idk = (int)($row['id_kriteria'] ?? 0);
  $komp = trim((string)($row['komponen'] ?? ''));
  if ($idk <= 0 || $komp === '') return null;
  if (!isset($map[$idk][$komp])) return null;
  $std = $map[$idk][$komp];
  $tipe = $std['tipe'];

  if ($tipe === 'pilihan') {
    $opsi = null;
    if (isset($row['hasil_lapangan']) && $row['hasil_lapangan'] !== '') $opsi = strtolower(trim($row['hasil_lapangan']));
    elseif (isset($row['status']) && $row['status'] !== '') $opsi = strtolower(trim($row['status']));
    elseif (!empty($row['catatan']) && preg_match('/Pilihan:\s*([^|]+)/i', $row['catatan'], $m)) $opsi = strtolower(trim($m[1]));
    return ($opsi !== null && isset($std['opsi'][$opsi])) ? $std['opsi'][$opsi] : null;
  }

  if ($tipe === 'angka') {
    $nilai = null;
    if (isset($row['nilai']) && $row['nilai'] !== '') $nilai = (float)$row['nilai'];
    elseif (isset($row['hasil_lapangan']) && $row['hasil_lapangan'] !== '' && is_numeric(str_replace(',', '.', $row['hasil_lapangan']))) {
      $nilai = (float)str_replace(',', '.', $row['hasil_lapangan']);
    }
    if ($nilai === null) return null;
    usort(
      $std['angka'],
      fn($a, $b) => ($a['batas'] === null ? 1 : ($b['batas'] === null ? -1 : ($a['batas'] <=> $b['batas'])))
    );
    foreach ($std['angka'] as $e) {
      if ($e['batas'] !== null && $nilai <= $e['batas']) return $e['hasil'];
    }
    for ($i = count($std['angka']) - 1; $i >= 0; $i--) {
      if ($std['angka'][$i]['batas'] !== null) return $std['angka'][$i]['hasil'];
    }
  }
  return null;
}

$kategoriList = [];
$resKat = $conn->query("SELECT nama_kategori FROM kategori_inspeksi ORDER BY nama_kategori");
while ($r = $resKat->fetch_assoc()) $kategoriList[$r['nama_kategori']] = [];

$stmtDet = $conn->prepare("
    SELECT COALESCE(ki.nama_kategori,'Lain-lain') AS kategori,
           d.id_detail,d.id_kriteria,d.komponen,d.hasil_lapangan,d.nilai,d.status,d.catatan
    FROM detail_inspeksi d
    LEFT JOIN kriteria_inspeksi kri ON d.id_kriteria=kri.id_kriteria
    LEFT JOIN kategori_inspeksi ki ON kri.id_kategori=ki.id_kategori
    WHERE d.id_inspeksi=?
    ORDER BY kategori,d.komponen,d.id_detail
");
$stmtDet->bind_param("i", $id_inspeksi);
$stmtDet->execute();
$resDet = $stmtDet->get_result();
while ($row = $resDet->fetch_assoc()) {
  $row['hasil'] = tentukanHasil($row, $standarMap);
  $cat = $row['kategori'] ?? 'Lain-lain';
  if (!isset($kategoriList[$cat])) $kategoriList[$cat] = [];
  $kategoriList[$cat][] = $row;
}
$stmtDet->close();

$estimasiData = [];
$resEst = $conn->prepare("SELECT pekerjaan, biaya FROM estimasi_perbaikan WHERE id_inspeksi=?");
$resEst->bind_param("i", $id_inspeksi);
$resEst->execute();
$estimasiData = $resEst->get_result()->fetch_all(MYSQLI_ASSOC);
$resEst->close();

$scanObdData = [];
$stmtObd = $conn->prepare("
    SELECT id_scan, kode_trouble, indikasi_error, catatan
    FROM hasil_scan_obd
    WHERE id_inspeksi = ?
    ORDER BY id_scan ASC
");
$stmtObd->bind_param("i", $id_inspeksi);
$stmtObd->execute();
$scanObdData = $stmtObd->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtObd->close();

$stmtFoto = $conn->prepare("SELECT path_file,keterangan FROM bukti_foto WHERE id_inspeksi=?");
$stmtFoto->bind_param("i", $id_inspeksi);
$stmtFoto->execute();
$fotoData = $stmtFoto->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtFoto->close();

$tglInspeksi = $orderData['tanggal_inspeksi'] ?? null;
$formatTanggal = '-';
if (!empty($tglInspeksi)) {
  $timestamp = strtotime($tglInspeksi);

  $hariIndo = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
  $bulanIndo = [
    1 => 'Januari',
    'Februari',
    'Maret',
    'April',
    'Mei',
    'Juni',
    'Juli',
    'Agustus',
    'September',
    'Oktober',
    'November',
    'Desember'
  ];

  $hari = $hariIndo[date('w', $timestamp)];
  $tgl  = date('j', $timestamp);
  $bln  = $bulanIndo[(int)date('n', $timestamp)];
  $thn  = date('Y', $timestamp);

  $formatTanggal = "$hari, $tgl $bln $thn";
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
  <meta charset="utf-8">
  <title>Detail Inspeksi #<?= htmlspecialchars($id_inspeksi) ?></title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-100 p-6">
  <div class="max-w-5xl mx-auto bg-white p-6 rounded shadow">
    <h1 class="text-2xl font-bold text-indigo-600 mb-4">Detail Inspeksi</h1>

    <?php if (!empty($fotoData)): ?>
      <div class="flex flex-wrap justify-center gap-4 mb-6">
        <?php foreach ($fotoData as $f):
          $pf = $f['path_file'] ?? '';
          if ($pf === '') continue;
          $img = (strpos($pf, '/') === false) ? '../uploads/foto_mobil/' . $pf : '../' . ltrim($pf, '/');
        ?>
          <div class="border rounded p-2 flex flex-col items-center">
            <img src="<?= htmlspecialchars($img) ?>"
              alt="Foto"
              class="mx-auto w-auto h-48 object-contain">
            <p class="text-sm mt-2 font-semibold text-gray-700 text-center">
              <?= htmlspecialchars($orderData['merk'] . ' ' . $orderData['model'] . ' (' . ($orderData['tahun_produksi'] ?? '-') . ')') ?>
            </p>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <div>
        <p><strong>Pelanggan:</strong> <?= htmlspecialchars($orderData['nama_lengkap']) ?></p>
        <p><strong>Nama Inspektor:</strong> <?= htmlspecialchars($orderData['nama_teknisi']) ?></p>
        <p><strong>Tanggal Inspeksi:</strong> <?= htmlspecialchars($formatTanggal) ?></p>
      </div>
      <div>
        <p><strong>Kendaraan:</strong> <?= htmlspecialchars(($orderData['merk'] ?? '') . ' ' . ($orderData['model'] ?? '') . ' ' . ($orderData['tahun_produksi'] ?? '')) ?></p>
        <p><strong>Plat:</strong> <?= htmlspecialchars($orderData['nomor_polisi'] ?? '-') ?></p>
        <p>
          <strong>Alamat Mobil:</strong>
          <?= htmlspecialchars($orderData['alamat_mobil'] ?? '-') ?>
          <?php if (!empty($orderData['link_gmaps'])): ?>
            <a href="<?= htmlspecialchars($orderData['link_gmaps']) ?>"
              target="_blank"
              class="text-blue-600 hover:text-blue-800 ml-2 inline-flex items-center"
              title="Lihat di Google Maps">
              <svg xmlns="http://www.w3.org/2000/svg"
                class="h-5 w-5"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor">
                <path stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M12 11c1.657 0 3-1.343 3-3S13.657 5 12 5 9 6.343 9 8s1.343 3 3 3z" />
                <path stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M12 22s8-4.5 8-12a8 8 0 10-16 0c0 7.5 8 12 8 12z" />
              </svg>
            </a>
          <?php endif; ?>
        </p>
      </div>
    </div>

    <div class="mt-4">
      <p><strong>Nilai Akhir:</strong> <?= htmlspecialchars($orderData['nilai_akhir'] ?? '-') ?> (<?= htmlspecialchars($orderData['nilai_huruf'] ?? '-') ?>)</p>
      <p><strong>Kesimpulan:</strong> <?= htmlspecialchars($orderData['kesimpulan'] ?? '-') ?></p>
    </div>

    <h2 class="mt-6 text-lg font-semibold text-indigo-600">Detail Penilaian</h2>

    <?php foreach ($kategoriList as $kategori => $rows): ?>
      <h3 class="mt-4 font-bold text-indigo-500"><?= htmlspecialchars($kategori) ?></h3>
      <div class="overflow-x-auto">
        <table class="w-full text-sm border mt-2">
          <thead class="bg-gray-100">
            <tr>
              <th class="border px-2 py-1 text-left">Komponen</th>
              <th class="border px-2 py-1 text-left">Input Inspektor</th>
              <th class="border px-2 py-1 text-left">Keterangan</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r):
              if (isset($r['hasil_lapangan']) && $r['hasil_lapangan'] !== '') $inputDisplay = $r['hasil_lapangan'];
              elseif (isset($r['nilai']) && $r['nilai'] !== '') $inputDisplay = $r['nilai'];
              elseif (isset($r['status']) && $r['status'] !== '') $inputDisplay = $r['status'];
              elseif (isset($r['catatan']) && $r['catatan'] !== '') $inputDisplay = $r['catatan'];
              else $inputDisplay = '-';

              $hasilAuto = $r['hasil'] ?? '-';
              $catatanBersih = preg_replace([
                '/Pilihan:\s*[^|]+(\s*\|\s*)?/i',
                '/Nilai ukur:\s*[^|]+(\s*\|\s*)?/i'
              ], '', $r['catatan'] ?? '');
              $catatanBersih = trim($catatanBersih);

              $keterangan = $hasilAuto;
              if ($catatanBersih !== '') {
                if ($keterangan === '-' || $keterangan === null || $keterangan === '') {
                  $keterangan = $catatanBersih;
                } else {
                  $keterangan .= " (" . $catatanBersih . ")";
                }
              }
            ?>
              <tr>
                <td class="border px-2 py-1"><?= htmlspecialchars($r['komponen'] ?? '-') ?></td>
                <td class="border px-2 py-1"><?= htmlspecialchars($inputDisplay) ?></td>
                <td class="border px-2 py-1"><?= htmlspecialchars($keterangan) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endforeach; ?>

    <h2 class="mt-6 text-lg font-semibold text-indigo-600">Hasil Scan OBD</h2>
    <?php if (!empty($scanObdData)): ?>
      <div class="overflow-x-auto">
        <table class="w-full text-sm border mt-2">
          <thead class="bg-gray-100">
            <tr>
              <th class="border px-2 py-1">Kode Trouble</th>
              <th class="border px-2 py-1">Indikasi Error</th>
              <th class="border px-2 py-1">Catatan</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($scanObdData as $s): ?>
              <tr>
                <td class="border px-2 py-1"><?= htmlspecialchars($s['kode_trouble'] ?? '-') ?></td>
                <td class="border px-2 py-1"><?= htmlspecialchars($s['indikasi_error'] ?? '-') ?></td>
                <td class="border px-2 py-1"><?= htmlspecialchars($s['catatan'] ?? '-') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <p class="text-sm text-gray-500">Tidak ada hasil scan OBD yang tercatat.</p>
    <?php endif; ?>

    <h2 class="mt-6 text-lg font-semibold text-indigo-600">Estimasi Perbaikan</h2>
    <?php if (!empty($estimasiData)):
      $total = 0; ?>
      <ul class="list-decimal ml-6">
        <?php foreach ($estimasiData as $est): $total += (float)($est['biaya'] ?? 0); ?>
          <li><?= htmlspecialchars($est['pekerjaan'] ?? '-') ?> — Rp <?= number_format((float)($est['biaya'] ?? 0), 0, ',', '.') ?></li>
        <?php endforeach; ?>
      </ul>
      <p class="font-bold mt-2">Total: Rp <?= number_format($total, 0, ',', '.') ?></p>
    <?php else: ?>
      <p class="text-sm text-gray-500">Tidak ada estimasi perbaikan.</p>
    <?php endif; ?>

    <div class="mt-6">
      <a href="history_inspeksi.php" class="bg-gray-300 hover:bg-gray-400 text-black px-4 py-2 rounded">Kembali</a>
    </div>
  </div>
</body>

</html>