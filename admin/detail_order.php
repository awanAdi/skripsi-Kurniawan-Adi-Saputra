<?php
session_start();
require_once '../includes/koneksi.php';

if (!isset($_SESSION['username']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit;
}
$id_order = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id_order <= 0) {
    die("ID order tidak valid.");
}
$stmt = $conn->prepare("
    SELECT oi.id_order, u.nama_lengkap, 
       k.merk, k.model, k.tahun_produksi, k.nomor_polisi, k.alamat AS alamat_mobil,
       k.link_gmaps,
       i.id_inspeksi, i.tanggal AS tanggal_inspeksi, 
       i.nilai_akhir, i.nilai_huruf, i.kesimpulan
    FROM order_inspeksi oi
    JOIN users u ON oi.id_pelanggan = u.id_user
    JOIN kendaraan k ON oi.id_mobil = k.id_mobil
    LEFT JOIN inspeksi i ON oi.id_order = i.id_order
    WHERE oi.id_order = ?
    LIMIT 1
");
$stmt->bind_param("i", $id_order);
$stmt->execute();
$orderData = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$orderData) {
    die("Data order tidak ditemukan.");
}
if (empty($orderData['id_inspeksi'])) {
    die("Order ini belum memiliki data inspeksi.");
}
$standarMap = [];
$sqlStandar = "
    SELECT id_kriteria, komponen, tipe_input, nilai_batas, opsi_pilihan, keterangan
    FROM standar_komponen
    ORDER BY id_kriteria, komponen, 
             CASE WHEN tipe_input='angka' THEN nilai_batas END ASC
";
$resStandar = $conn->query($sqlStandar);
while ($r = $resStandar->fetch_assoc()) {
    $idk  = (int)$r['id_kriteria'];
    $komp = trim((string)$r['komponen']);
    $tipe = trim((string)$r['tipe_input']);
    if (!isset($standarMap[$idk])) $standarMap[$idk] = [];
    if (!isset($standarMap[$idk][$komp])) {
        $standarMap[$idk][$komp] = ['tipe' => $tipe, 'angka' => [], 'opsi' => []];
    }

    if ($tipe === 'angka') {
        $batas = is_null($r['nilai_batas']) ? null : (float)$r['nilai_batas'];
        $standarMap[$idk][$komp]['angka'][] = ['batas' => $batas, 'hasil' => $r['keterangan']];
    } elseif ($tipe === 'pilihan') {
        $opsRaw = (string)$r['opsi_pilihan'];
        if ($opsRaw !== '') {
            $parts = preg_split('/[,\|]/', $opsRaw);
            foreach ($parts as $p) {
                $key = strtolower(trim($p));
                if ($key === '') continue;
                $standarMap[$idk][$komp]['opsi'][$key] = $r['keterangan'];
            }
        }
    }
}
function tentukanHasil(array $row, array $standarMap)
{
    $idk = (int)$row['id_kriteria'];
    $komp = trim((string)$row['komponen']);
    if (!isset($standarMap[$idk][$komp])) {
        return null; // standar tidak ditemukan
    }
    $std = $standarMap[$idk][$komp];
    $tipe = $std['tipe'];

    if ($tipe === 'pilihan') {
        $opsi = null;
        if (!empty($row['status'])) {
            $opsi = strtolower(trim($row['status']));
        }
        if (!$opsi && !empty($row['catatan']) && preg_match('/Pilihan:\s*([^|]+)/i', $row['catatan'], $m)) {
            $opsi = strtolower(trim($m[1]));
        }
        if ($opsi && isset($std['opsi'][$opsi])) {
            return $std['opsi'][$opsi];
        }
        return null;
    }

    if ($tipe === 'angka') {
        $nilai = $row['nilai'] === null || $row['nilai'] === '' ? null : (float)$row['nilai'];
        if ($nilai === null) return null;

        usort($std['angka'], function ($a, $b) {
            $ba = $a['batas'];
            $bb = $b['batas'];
            if ($ba === null && $bb === null) return 0;
            if ($ba === null) return 1;
            if ($bb === null) return -1;
            return $ba <=> $bb;
        });

        foreach ($std['angka'] as $ent) {
            if ($ent['batas'] === null) continue;
            if ($nilai <= (float)$ent['batas']) {
                return $ent['hasil'];
            }
        }
        for ($i = count($std['angka']) - 1; $i >= 0; $i--) {
            if ($std['angka'][$i]['batas'] !== null) {
                return $std['angka'][$i]['hasil'];
            }
        }
        return null;
    }
    return null;
}

$kategoriList = [];
$resKat = $conn->query("SELECT id_kategori, nama_kategori FROM kategori_inspeksi ORDER BY nama_kategori ASC");
while ($r = $resKat->fetch_assoc()) {
    $kategoriList[$r['nama_kategori']] = [];
}

$stmtDet = $conn->prepare("
    SELECT ki.nama_kategori AS kategori, 
       d.id_kriteria,
       d.komponen, 
       d.nilai,
       d.status,
       d.catatan,
       d.hasil_lapangan
    FROM detail_inspeksi d
    JOIN kriteria_inspeksi kri ON d.id_kriteria = kri.id_kriteria
    JOIN kategori_inspeksi ki ON kri.id_kategori = ki.id_kategori
    WHERE d.id_inspeksi = ?
    ORDER BY ki.nama_kategori, d.komponen
");
$stmtDet->bind_param("i", $orderData['id_inspeksi']);
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
$cols = [];
$q = $conn->prepare("
    SELECT COLUMN_NAME
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'estimasi_perbaikan'
");
$q->execute();
$resCols = $q->get_result();
while ($c = $resCols->fetch_assoc()) {
    $cols[] = $c['COLUMN_NAME'];
}
$q->close();

$candidates = ['pekerjaan', 'hal', 'uraian', 'deskripsi', 'keterangan', 'item', 'nama'];
$chosen = null;
foreach ($candidates as $cand) {
    if (in_array($cand, $cols, true)) {
        $chosen = $cand;
        break;
    }
}

$stmtScan = $conn->prepare("
    SELECT kode_trouble, indikasi_error, catatan, tanggal_scan
    FROM hasil_scan_obd
    WHERE id_inspeksi = ?
");
$stmtScan->bind_param("i", $orderData['id_inspeksi']);
$stmtScan->execute();
$scanData = $stmtScan->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtScan->close();

if ($chosen === null && !empty($cols)) $chosen = $cols[0];
if ($chosen !== null) {
    $colEsc = '`' . str_replace('`', '``', $chosen) . '`';
    $sqlEstimasi = "SELECT {$colEsc} AS pekerjaan, `biaya` FROM `estimasi_perbaikan` WHERE `id_inspeksi` = ?";
    $stmtEstimasi = $conn->prepare($sqlEstimasi);
    $stmtEstimasi->bind_param("i", $orderData['id_inspeksi']);
    $stmtEstimasi->execute();
    $estimasiData = $stmtEstimasi->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmtEstimasi->close();
}
$stmtFoto = $conn->prepare("
    SELECT path_file, keterangan
    FROM bukti_foto
    WHERE id_inspeksi = ?
");
$stmtFoto->bind_param("i", $orderData['id_inspeksi']);
$stmtFoto->execute();
$fotoData = $stmtFoto->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtFoto->close();
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Detail Order</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-100 p-6">
    <div class="max-w-4xl mx-auto bg-white p-6 rounded shadow">
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
                            class="mx-auto w-auto h-48 object-contain cursor-pointer"
                            onclick="openImageModal('<?= htmlspecialchars($img) ?>')">
                        <p class="text-sm mt-2 font-semibold text-gray-700 text-center">
                            <?= htmlspecialchars($orderData['merk'] . ' ' . $orderData['model'] . ' (' . ($orderData['tahun_produksi'] ?? '-') . ')') ?>
                        </p>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <h2 class="text-xl font-bold mb-4 text-indigo-600">Detail Order</h2>
        <p><strong>Pelanggan:</strong> <?= htmlspecialchars($orderData['nama_lengkap']) ?></p>
        <p><strong>Kendaraan:</strong>
            <?= htmlspecialchars(
                $orderData['merk'] . " " .
                    $orderData['model'] . " " .
                    ($orderData['tahun_produksi'] ?? '-') .
                    " (" . $orderData['nomor_polisi'] . ")"
            ) ?>
        </p>

        <p><strong>Alamat Mobil:</strong> <?= htmlspecialchars($orderData['alamat_mobil']) ?></p>
        <?php if (!empty($orderData['link_gmaps'])): ?>
            <p><strong>Link Lokasi:</strong>
                <a href="<?= htmlspecialchars($orderData['link_gmaps']) ?>"
                    target="_blank"
                    class="text-blue-600 underline">Lihat di Google Maps</a>
            </p>
        <?php endif; ?>
        <p><strong>Tanggal Inspeksi:</strong> <?= htmlspecialchars($orderData['tanggal_inspeksi']) ?></p>
        <p><strong>Nilai Akhir:</strong> <?= htmlspecialchars($orderData['nilai_akhir'] ?? '-') ?> (<?= htmlspecialchars($orderData['nilai_huruf'] ?? '-') ?>)</p>
        <p><strong>Kesimpulan:</strong> <?= htmlspecialchars($orderData['kesimpulan'] ?? '-') ?></p>

        <h3 class="mt-6 font-semibold text-lg">Detail Penilaian (berdasarkan Standar):</h3>
        <?php foreach ($kategoriList as $kategori => $rows): ?>
            <h4 class="mt-4 font-bold text-indigo-500"><?= htmlspecialchars($kategori) ?></h4>
            <?php if (!empty($rows)): ?>
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
                            <?php foreach ($rows as $r): ?>
                                <?php
                                if (!empty($r['hasil_lapangan'])) {
                                    $inputInspektor = $r['hasil_lapangan'];
                                } elseif ($r['nilai'] !== null && $r['nilai'] !== '') {
                                    $inputInspektor = $r['nilai'];
                                } elseif (!empty($r['status'])) {
                                    $inputInspektor = $r['status'];
                                } elseif (!empty($r['catatan'])) {
                                    $inputInspektor = $r['catatan'];
                                } else {
                                    $inputInspektor = '-';
                                }
                                $hasilOtomatis = $r['hasil'] ? $r['hasil'] : '-';
                                $catatanBersih = preg_replace([
                                    '/Pilihan:\s*[^|]+/i',
                                    '/Nilai ukur:\s*[^|]+/i'
                                ], '', $r['catatan'] ?? '');
                                $catatanBersih = trim($catatanBersih);
                                $keterangan = $hasilOtomatis;
                                if (!empty($catatanBersih) && $catatanBersih !== (string)$inputInspektor) {
                                    if ($keterangan === '-') $keterangan = $catatanBersih;
                                    else $keterangan .= " (" . $catatanBersih . ")";
                                }
                                ?>
                                <tr>
                                    <td class="border px-2 py-1"><?= htmlspecialchars($r['komponen']) ?></td>
                                    <td class="border px-2 py-1"><?= htmlspecialchars($inputInspektor) ?></td>
                                    <td class="border px-2 py-1"><?= htmlspecialchars($keterangan) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="text-sm text-gray-500">Belum ada data untuk kategori ini.</p>
            <?php endif; ?>
        <?php endforeach; ?>

        <h3 class="mt-6 font-semibold">Hasil Scan OBD:</h3>
        <?php if (!empty($scanData)): ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm border mt-2">
                    <thead class="bg-gray-100">
                        <tr>
                            <th class="border px-2 py-1">Kode Trouble</th>
                            <th class="border px-2 py-1">Indikasi Error</th>
                            <th class="border px-2 py-1">Catatan</th>
                            <th class="border px-2 py-1">Tanggal Scan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($scanData as $s): ?>
                            <tr>
                                <td class="border px-2 py-1"><?= htmlspecialchars($s['kode_trouble']) ?></td>
                                <td class="border px-2 py-1"><?= htmlspecialchars($s['indikasi_error']) ?></td>
                                <td class="border px-2 py-1"><?= htmlspecialchars($s['catatan'] ?? '-') ?></td>
                                <td class="border px-2 py-1"><?= htmlspecialchars($s['tanggal_scan']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="text-sm text-gray-600">Tidak ada data hasil scan OBD.</p>
        <?php endif; ?>

        <h3 class="mt-6 font-semibold">Estimasi Perbaikan:</h3>
        <?php if (!empty($estimasiData)): ?>
            <ul class="list-decimal ml-6">
                <?php
                $totalBiaya = 0;
                foreach ($estimasiData as $est):
                    $totalBiaya += (float)$est['biaya'];
                ?>
                    <li><?= htmlspecialchars($est['pekerjaan']) ?> - Rp <?= number_format($est['biaya'], 0, ',', '.') ?></li>
                <?php endforeach; ?>
            </ul>
            <p class="font-bold mt-2">Total: Rp <?= number_format($totalBiaya, 0, ',', '.') ?></p>
        <?php else: ?>
            <p class="text-sm text-gray-600">Tidak ada estimasi perbaikan.</p>
        <?php endif; ?>
        <div class="mt-6 flex gap-2">
            <a href="history.php" class="bg-gray-300 hover:bg-gray-400 text-black px-4 py-2 rounded">Kembali</a>
            <a href="cetak_detail_order.php?id=<?= $id_order ?>"
                target="_blank"
                class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded">
                Download PDF
            </a>
        </div>
    </div>
    <div id="imageModal" class="fixed inset-0 bg-black bg-opacity-80 hidden items-center justify-center z-50">
        <span class="absolute top-4 right-6 text-white text-3xl cursor-pointer" onclick="closeImageModal()">&times;</span>
        <img id="modalImage" class="max-w-full max-h-full transform transition-transform duration-300 cursor-grab">
    </div>

    <script>
        let scale = 1;
        let isDragging = false;
        let startX, startY, imgX = 0,
            imgY = 0;

        function openImageModal(src) {
            const modal = document.getElementById('imageModal');
            const img = document.getElementById('modalImage');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            img.src = src;

            // reset zoom dan posisi
            scale = 1;
            imgX = 0;
            imgY = 0;
            img.style.transform = `translate(0px, 0px) scale(1)`;
        }

        function closeImageModal() {
            const modal = document.getElementById('imageModal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        // Zoom menggunakan scroll wheel
        document.getElementById('modalImage').addEventListener('wheel', e => {
            e.preventDefault();
            scale += e.deltaY * -0.001;
            scale = Math.min(Math.max(1, scale), 4);
            e.target.style.transform = `translate(${imgX}px, ${imgY}px) scale(${scale})`;
        });

        // Geser pakai mouse (tombol kiri)
        const imgEl = document.getElementById('modalImage');
        imgEl.addEventListener('mousedown', e => {
            if (e.button !== 0) return; // hanya tombol kiri
            isDragging = true;
            imgEl.style.cursor = 'grabbing';
            startX = e.clientX - imgX;
            startY = e.clientY - imgY;
        });
        window.addEventListener('mouseup', () => {
            isDragging = false;
            imgEl.style.cursor = 'grab';
        });
        window.addEventListener('mousemove', e => {
            if (isDragging) {
                imgX = e.clientX - startX;
                imgY = e.clientY - startY;
                imgEl.style.transform = `translate(${imgX}px, ${imgY}px) scale(${scale})`;
            }
        });

        // Klik kanan untuk close modal
        imgEl.addEventListener('contextmenu', e => {
            e.preventDefault();
            closeImageModal();
        });

        // Geser pakai sentuhan (HP)
        imgEl.addEventListener('touchstart', e => {
            isDragging = true;
            startX = e.touches[0].clientX - imgX;
            startY = e.touches[0].clientY - imgY;
        });
        imgEl.addEventListener('touchend', () => isDragging = false);
        imgEl.addEventListener('touchmove', e => {
            if (isDragging) {
                imgX = e.touches[0].clientX - startX;
                imgY = e.touches[0].clientY - startY;
                imgEl.style.transform = `translate(${imgX}px, ${imgY}px) scale(${scale})`;
            }
        });
    </script>
</body>

</html>
