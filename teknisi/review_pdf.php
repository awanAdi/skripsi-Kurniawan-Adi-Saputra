<?php
session_start();
require_once '../includes/koneksi.php';

if (!isset($_SESSION['username'], $_SESSION['role']) || $_SESSION['role'] !== 'teknisi') {
    header("Location: ../auth/login.php");
    exit();
}

function getInspectionId() {
    if (isset($_POST['id'])) {
        return intval($_POST['id']);
    } elseif (isset($_GET['id'])) {
        return intval($_GET['id']);
    }
    return 0;
}

function getOrderData($conn, $id_inspeksi) {
    $stmt = $conn->prepare("
        SELECT oi.id_order, u.nama_lengkap, 
               k.merk, k.model, k.tahun_produksi, k.nomor_polisi, k.alamat AS alamat_mobil,
               k.link_gmaps,
               i.id_inspeksi, i.tanggal AS tanggal_inspeksi, 
               i.nilai_akhir, i.nilai_huruf, i.kesimpulan
        FROM order_inspeksi oi
        JOIN users u ON oi.id_pelanggan = u.id_user
        JOIN kendaraan k ON oi.id_mobil = k.id_mobil
        JOIN inspeksi i ON oi.id_order = i.id_order
        WHERE i.id_inspeksi = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $id_inspeksi);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    return $result;
}

function buildStandardMap($conn) {
    $standarMap = [];
    
    $sql = "
        SELECT id_kriteria, komponen, tipe_input, nilai_batas, opsi_pilihan, keterangan
        FROM standar_komponen
        ORDER BY id_kriteria, komponen, 
                 CASE WHEN tipe_input='angka' THEN nilai_batas END ASC
    ";
    
    $result = $conn->query($sql);
    
    while ($r = $result->fetch_assoc()) {
        $idk  = (int)$r['id_kriteria'];
        $komp = trim((string)$r['komponen']);
        $tipe = trim((string)$r['tipe_input']);
        
        // Inisialisasi struktur jika belum ada
        if (!isset($standarMap[$idk])) {
            $standarMap[$idk] = [];
        }
        
        if (!isset($standarMap[$idk][$komp])) {
            $standarMap[$idk][$komp] = [
                'tipe' => $tipe,
                'angka' => [],
                'opsi' => []
            ];
        }

        // Proses standar tipe angka
        if ($tipe === 'angka') {
            $batas = is_null($r['nilai_batas']) ? null : (float)$r['nilai_batas'];
            $standarMap[$idk][$komp]['angka'][] = [
                'batas' => $batas,
                'hasil' => $r['keterangan']
            ];
        } 
        // Proses standar tipe pilihan
        elseif ($tipe === 'pilihan') {
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
    
    return $standarMap;
}

function determineResult(array $row, array $standarMap) {
    $idk = (int)$row['id_kriteria'];
    $komp = trim((string)$row['komponen']);
    
    // Jika standar tidak ditemukan
    if (!isset($standarMap[$idk][$komp])) {
        return null;
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

        // Sort standar berdasarkan batas
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

function getInspectionDetails($conn, $id_inspeksi, $standarMap) {
    // Ambil daftar kategori
    $kategoriList = [];
    $resKat = $conn->query("SELECT id_kategori, nama_kategori FROM kategori_inspeksi ORDER BY nama_kategori ASC");
    while ($r = $resKat->fetch_assoc()) {
        $kategoriList[$r['nama_kategori']] = [];
    }
    
    // Ambil detail inspeksi
    $stmt = $conn->prepare("
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
    
    $stmt->bind_param("i", $id_inspeksi);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $row['hasil'] = determineResult($row, $standarMap);
        $cat = $row['kategori'] ?? 'Lain-lain';
        
        if (!isset($kategoriList[$cat])) {
            $kategoriList[$cat] = [];
        }
        
        $kategoriList[$cat][] = $row;
    }
    
    $stmt->close();
    
    return $kategoriList;
}

function getScanOBDData($conn, $id_inspeksi) {
    $stmt = $conn->prepare("
        SELECT kode_trouble, indikasi_error, catatan, tanggal_scan
        FROM hasil_scan_obd
        WHERE id_inspeksi = ?
    ");
    $stmt->bind_param("i", $id_inspeksi);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return $result;
}

function detectPekerjaanColumn($conn) {
    $cols = [];
    $q = $conn->prepare("
        SELECT COLUMN_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'estimasi_perbaikan'
    ");
    $q->execute();
    $result = $q->get_result();
    
    while ($c = $result->fetch_assoc()) {
        $cols[] = $c['COLUMN_NAME'];
    }
    $q->close();

    $candidates = ['pekerjaan', 'hal', 'uraian', 'deskripsi', 'keterangan', 'item', 'nama'];
    
    foreach ($candidates as $cand) {
        if (in_array($cand, $cols, true)) {
            return $cand;
        }
    }
    return !empty($cols) ? $cols[0] : null;
}

function getEstimasiData($conn, $id_inspeksi) {
    $chosen = detectPekerjaanColumn($conn);
    
    if ($chosen === null) {
        return [];
    }
    
    $colEsc = '`' . str_replace('`', '``', $chosen) . '`';
    $sql = "SELECT {$colEsc} AS pekerjaan, `biaya` FROM `estimasi_perbaikan` WHERE `id_inspeksi` = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id_inspeksi);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return $result;
}

function getFotoData($conn, $id_inspeksi) {
    $stmt = $conn->prepare("
        SELECT path_file, keterangan
        FROM bukti_foto
        WHERE id_inspeksi = ?
    ");
    $stmt->bind_param("i", $id_inspeksi);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return $result;
}

function formatTanggalInspeksi($tanggal) {
    if (empty($tanggal)) return '-';
    
    $timestamp = strtotime($tanggal);
    $months = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
    ];
    
    $day = date('d', $timestamp);
    $month = $months[(int)date('n', $timestamp)];
    $year = date('Y', $timestamp);
    
    return "$day $month $year";
}

function hitungTotalEstimasi($estimasiData) {
    $total = 0;
    foreach ($estimasiData as $est) {
        $total += (float)($est['biaya'] ?? 0);
    }
    return $total;
}

$id_inspeksi = getInspectionId();

if ($id_inspeksi <= 0) {
    die("ID inspeksi tidak valid.");
}

$orderData = getOrderData($conn, $id_inspeksi);

if (!$orderData) {
    die("Data order / inspeksi tidak ditemukan.");
}

$id_order = (int)$orderData['id_order'];

if (empty($orderData['id_inspeksi'])) {
    die("Order ini belum memiliki data inspeksi.");
}

$standarMap = buildStandardMap($conn);
$kategoriList = getInspectionDetails($conn, $orderData['id_inspeksi'], $standarMap);
$scanData = getScanOBDData($conn, $orderData['id_inspeksi']);
$estimasiData = getEstimasiData($conn, $orderData['id_inspeksi']);
$fotoData = getFotoData($conn, $orderData['id_inspeksi']);

$conn->close();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detail Inspeksi - <?= htmlspecialchars($orderData['merk']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .fade-in {
            animation: fadeIn 0.4s ease-out;
        }
        
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        
        ::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 10px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: #555;
        }
        
        @media (max-width: 640px) {
            .table-scroll {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
        }
        
        @media print {
            body {
                background: white;
            }
            .no-print {
                display: none !important;
            }
        }
    </style>
</head>

<body class="bg-gradient-to-br from-gray-50 to-gray-100 min-h-screen">
    <div class="container mx-auto px-3 sm:px-4 md:px-6 py-4 sm:py-6 max-w-6xl">
        
        <!-- Header Card -->
        <div class="bg-white rounded-xl shadow-md p-4 sm:p-6 mb-6 fade-in">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-4">
                <div>
                    <h1 class="text-xl sm:text-2xl lg:text-3xl font-bold text-gray-800 mb-2">
                        📋 Detail Hasil Inspeksi
                    </h1>
                    <p class="text-sm sm:text-base text-gray-600">
                        ID Inspeksi: <span class="font-semibold text-indigo-600">#<?= $orderData['id_inspeksi'] ?></span>
                    </p>
                </div>
                
                <div class="flex flex-col sm:flex-row gap-2 w-full md:w-auto">
                    <a href="history_inspeksi.php" 
                       class="no-print flex items-center justify-center gap-2 bg-gray-200 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-300 text-sm font-medium transition">
                        <span>←</span>
                        <span>Kembali</span>
                    </a>
                    
                    <form action="../admin/cetak_detail_order.php" method="post" target="_blank" class="w-full sm:w-auto">
                        <input type="hidden" name="id" value="<?= $id_order ?>">
                        <button type="submit" 
                                class="no-print w-full flex items-center justify-center gap-2 bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 text-sm font-medium transition shadow-sm hover:shadow-md">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M19 8H5c-1.66 0-3 1.34-3 3v6h4v4h12v-4h4v-6c0-1.66-1.34-3-3-3zm-3 11H8v-5h8v5zm3-7c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1zm-1-9H6v4h12V3z"/>
                            </svg>
                            <span>Download PDF</span>
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Foto Kendaraan -->
        <?php if (!empty($fotoData)): ?>
            <div class="bg-white rounded-xl shadow-md p-4 sm:p-6 mb-6 fade-in">
                <h2 class="text-lg sm:text-xl font-bold text-gray-800 mb-4">📸 Foto Kendaraan</h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php foreach ($fotoData as $f):
                        $pf = $f['path_file'] ?? '';
                        if ($pf === '') continue;
                        $img = (strpos($pf, '/') === false) ? '../uploads/foto_mobil/' . $pf : '../' . ltrim($pf, '/');
                    ?>
                        <div class="border-2 border-gray-200 rounded-lg p-3 hover:border-indigo-400 transition cursor-pointer"
                             onclick="openImageModal('<?= htmlspecialchars($img) ?>')">
                            <img src="<?= htmlspecialchars($img) ?>"
                                 alt="Foto Kendaraan"
                                 class="w-full h-48 object-cover rounded-lg mb-2">
                            <p class="text-sm font-semibold text-gray-700 text-center">
                                <?= htmlspecialchars($orderData['merk'] . ' ' . $orderData['model'] . ' (' . ($orderData['tahun_produksi'] ?? '-') . ')') ?>
                            </p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Informasi Kendaraan & Pelanggan -->
        <div class="bg-white rounded-xl shadow-md p-4 sm:p-6 mb-6 fade-in">
            <h2 class="text-lg sm:text-xl font-bold text-gray-800 mb-4 pb-2 border-b-2 border-indigo-600">
                🚗 Informasi Kendaraan & Pelanggan
            </h2>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="space-y-3">
                    <div>
                        <p class="text-sm text-gray-600">Pelanggan</p>
                        <p class="font-semibold text-gray-800"><?= htmlspecialchars($orderData['nama_lengkap']) ?></p>
                    </div>
                    
                    <div>
                        <p class="text-sm text-gray-600">Kendaraan</p>
                        <p class="font-semibold text-gray-800">
                            <?= htmlspecialchars($orderData['merk'] . " " . $orderData['model'] . " " . ($orderData['tahun_produksi'] ?? '-')) ?>
                        </p>
                    </div>
                    
                    <div>
                        <p class="text-sm text-gray-600">Nomor Polisi</p>
                        <p class="font-semibold text-gray-800"><?= htmlspecialchars($orderData['nomor_polisi']) ?></p>
                    </div>
                </div>
                
                <div class="space-y-3">
                    <div>
                        <p class="text-sm text-gray-600">Tanggal Inspeksi</p>
                        <p class="font-semibold text-gray-800"><?= formatTanggalInspeksi($orderData['tanggal_inspeksi']) ?></p>
                    </div>
                    
                    <div>
                        <p class="text-sm text-gray-600">Nilai Akhir</p>
                        <p class="font-semibold text-gray-800">
                            <?= htmlspecialchars($orderData['nilai_akhir'] ?? '-') ?> 
                            <span class="text-indigo-600">(<?= htmlspecialchars($orderData['nilai_huruf'] ?? '-') ?>)</span>
                        </p>
                    </div>
                    
                    <div>
                        <p class="text-sm text-gray-600">Alamat Mobil</p>
                        <p class="font-semibold text-gray-800"><?= htmlspecialchars($orderData['alamat_mobil']) ?></p>
                        <?php if (!empty($orderData['link_gmaps'])): ?>
                            <a href="<?= htmlspecialchars($orderData['link_gmaps']) ?>"
                               target="_blank"
                               class="text-sm text-blue-600 hover:text-blue-800 underline inline-flex items-center gap-1 mt-1">
                                <span>📍</span>
                                <span>Lihat di Google Maps</span>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Kesimpulan -->
            <div class="mt-4 p-4 bg-gradient-to-r from-indigo-50 to-blue-50 rounded-lg border-l-4 border-indigo-600">
                <p class="text-sm font-medium text-gray-600 mb-1">Kesimpulan:</p>
                <p class="text-base text-gray-800 italic"><?= htmlspecialchars($orderData['kesimpulan'] ?? '-') ?></p>
            </div>
        </div>

        <!-- Detail Penilaian -->
        <div class="bg-white rounded-xl shadow-md p-4 sm:p-6 mb-6 fade-in">
            <h2 class="text-lg sm:text-xl font-bold text-gray-800 mb-4 pb-2 border-b-2 border-indigo-600">
                ⭐ Detail Penilaian (Berdasarkan Standar)
            </h2>
            
            <?php foreach ($kategoriList as $kategori => $rows): ?>
                <div class="mb-6">
                    <h3 class="text-base sm:text-lg font-bold text-indigo-700 mb-3 flex items-center gap-2">
                        <span>📌</span>
                        <span><?= htmlspecialchars($kategori) ?></span>
                    </h3>
                    
                    <?php if (!empty($rows)): ?>
                        <div class="table-scroll">
                            <table class="w-full text-xs sm:text-sm border border-gray-300 rounded-lg overflow-hidden">
                                <thead class="bg-gradient-to-r from-gray-100 to-gray-200">
                                    <tr>
                                        <th class="border border-gray-300 px-3 py-2 text-left font-semibold">Komponen</th>
                                        <th class="border border-gray-300 px-3 py-2 text-left font-semibold">Hasil Lapangan</th>
                                        <th class="border border-gray-300 px-3 py-2 text-left font-semibold">Keterangan</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($rows as $r): 
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
                                            if ($keterangan === '-') {
                                                $keterangan = $catatanBersih;
                                            } else {
                                                $keterangan .= " (" . $catatanBersih . ")";
                                            }
                                        }
                                    ?>
                                        <tr class="hover:bg-gray-50 transition">
                                            <td class="border border-gray-300 px-3 py-2"><?= htmlspecialchars($r['komponen']) ?></td>
                                            <td class="border border-gray-300 px-3 py-2"><?= htmlspecialchars($inputInspektor) ?></td>
                                            <td class="border border-gray-300 px-3 py-2"><?= htmlspecialchars($keterangan) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-sm text-gray-500 italic">Belum ada data untuk kategori ini.</p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Hasil Scan OBD -->
        <div class="bg-white rounded-xl shadow-md p-4 sm:p-6 mb-6 fade-in">
            <h2 class="text-lg sm:text-xl font-bold text-gray-800 mb-4 pb-2 border-b-2 border-indigo-600">
                🔧 Hasil Scan OBD
            </h2>
            
            <?php if (!empty($scanData)): ?>
                <div class="table-scroll">
                    <table class="w-full text-xs sm:text-sm border border-gray-300 rounded-lg overflow-hidden">
                        <thead class="bg-gradient-to-r from-gray-100 to-gray-200">
                            <tr>
                                <th class="border border-gray-300 px-3 py-2 text-left font-semibold">Kode Trouble</th>
                                <th class="border border-gray-300 px-3 py-2 text-left font-semibold">Indikasi Error</th>
                                <th class="border border-gray-300 px-3 py-2 text-left font-semibold">Catatan</th>
                                <th class="border border-gray-300 px-3 py-2 text-left font-semibold">Tanggal Scan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($scanData as $s): ?>
                                <tr class="hover:bg-gray-50 transition">
                                    <td class="border border-gray-300 px-3 py-2 font-mono"><?= htmlspecialchars($s['kode_trouble']) ?></td>
                                    <td class="border border-gray-300 px-3 py-2"><?= htmlspecialchars($s['indikasi_error']) ?></td>
                                    <td class="border border-gray-300 px-3 py-2"><?= htmlspecialchars($s['catatan'] ?? '-') ?></td>
                                    <td class="border border-gray-300 px-3 py-2"><?= htmlspecialchars($s['tanggal_scan']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center py-8">
                    <div class="text-4xl mb-2">✓</div>
                    <p class="text-sm text-gray-600">Tidak ada kode trouble ditemukan</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Estimasi Perbaikan -->
        <div class="bg-white rounded-xl shadow-md p-4 sm:p-6 mb-6 fade-in">
            <h2 class="text-lg sm:text-xl font-bold text-gray-800 mb-4 pb-2 border-b-2 border-indigo-600">
                💰 Estimasi Perbaikan
            </h2>
            
            <?php if (!empty($estimasiData)): ?>
                <div class="space-y-2 mb-4">
                    <?php foreach ($estimasiData as $idx => $est): ?>
                        <div class="flex justify-between items-center p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition">
                            <div class="flex items-start gap-3">
                                <span class="text-sm font-semibold text-indigo-600"><?= $idx + 1 ?>.</span>
                                <span class="text-sm sm:text-base text-gray-800"><?= htmlspecialchars($est['pekerjaan']) ?></span>
                            </div>
                            <span class="text-sm sm:text-base font-semibold text-gray-800 ml-2">
                                Rp <?= number_format($est['biaya'], 0, ',', '.') ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Total -->
                <div class="border-t-2 border-gray-300 pt-4 mt-4">
                    <div class="flex justify-between items-center p-4 bg-gradient-to-r from-indigo-50 to-blue-50 rounded-lg">
                        <span class="text-base sm:text-lg font-bold text-gray-800">Total Estimasi:</span>
                        <span class="text-lg sm:text-xl font-bold text-indigo-600">
                            Rp <?= number_format(hitungTotalEstimasi($estimasiData), 0, ',', '.') ?>
                        </span>
                    </div>
                </div>
            <?php else: ?>
                <div class="text-center py-8">
                    <div class="text-4xl mb-2">💵</div>
                    <p class="text-sm text-gray-600">Tidak ada estimasi perbaikan</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Image Modal -->
    <div id="imageModal" 
         class="fixed inset-0 bg-black bg-opacity-90 hidden items-center justify-center z-50"
         onclick="closeImageModal()">
        <span class="no-print absolute top-4 right-6 text-white text-3xl sm:text-4xl cursor-pointer hover:text-gray-300 transition" 
              onclick="closeImageModal()">&times;</span>
        
        <div class="no-print absolute top-4 left-6 text-white text-sm bg-black bg-opacity-50 px-3 py-2 rounded-lg">
            <p>🖱️ Scroll untuk zoom</p>
            <p>🖐️ Drag untuk pan</p>
            <p>Klik kanan untuk tutup</p>
        </div>
        
        <img id="modalImage" 
             class="max-w-full max-h-full transform transition-transform duration-300 cursor-grab"
             onclick="event.stopPropagation()">
    </div>

    <script>
        let scale = 1;
        let isDragging = false;
        let startX, startY, imgX = 0, imgY = 0;

        function openImageModal(src) {
            const modal = document.getElementById('imageModal');
            const img = document.getElementById('modalImage');
            
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            img.src = src;

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

        document.getElementById('modalImage').addEventListener('wheel', e => {
            e.preventDefault();
            
            scale += e.deltaY * -0.001;
            scale = Math.min(Math.max(1, scale), 4); // Limit: 1x sampai 4x
            
            e.target.style.transform = `translate(${imgX}px, ${imgY}px) scale(${scale})`;
        });

        const imgEl = document.getElementById('modalImage');
        
        imgEl.addEventListener('mousedown', e => {
            if (e.button !== 0) return; // Hanya left click
            
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

        imgEl.addEventListener('contextmenu', e => {
            e.preventDefault();
            closeImageModal();
        });

        imgEl.addEventListener('touchstart', e => {
            if (e.touches.length === 1) {
                isDragging = true;
                startX = e.touches[0].clientX - imgX;
                startY = e.touches[0].clientY - imgY;
            }
        });
        
        imgEl.addEventListener('touchend', () => {
            isDragging = false;
        });
        
        imgEl.addEventListener('touchmove', e => {
            if (isDragging && e.touches.length === 1) {
                imgX = e.touches[0].clientX - startX;
                imgY = e.touches[0].clientY - startY;
                imgEl.style.transform = `translate(${imgX}px, ${imgY}px) scale(${scale})`;
            }
        });

        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') {
                closeImageModal();
            }
        });
    </script>
</body>
</html>