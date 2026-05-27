<?php
session_start();
if (!isset($_SESSION['username']) || $_SESSION['role'] !== 'teknisi') {
    header("Location: ../auth/login.php");
    exit();
}

require_once '../includes/koneksi.php';
date_default_timezone_set('Asia/Jakarta');

$urutanKategori = [
    'Eksterior'         => 1,
    'Mesin'             => 2,
    'Kelistrikan'       => 3,
    'Interior'          => 4,
    'Rangka & Kaki-Kaki' => 5,
    'Dokumen & Kunci'   => 6,
];

function normalize_name($s) {
    $s = (string)$s;
    $s = trim(preg_replace('/\s+/', ' ', $s));
    return mb_strtolower($s, 'UTF-8');
}

function updateOrderStatus($conn, $id_order) {
    if ($id_order <= 0) return;
    
    $stmt = $conn->prepare("UPDATE order_inspeksi SET status='Diproses' WHERE id_order=? AND status='Disetujui'");
    $stmt->bind_param("i", $id_order);
    $stmt->execute();
    $stmt->close();
}

function getVehicleInfo($conn, $id_order) {
    $defaultInfo = ['merk' => 'Nama Mobil', 'tahun_produksi' => 'Tahun Produksi'];
    
    if ($id_order <= 0) return $defaultInfo;
    
    $stmt = $conn->prepare("
        SELECT k.merk, k.tahun_produksi 
        FROM order_inspeksi o 
        JOIN kendaraan k ON o.id_mobil = k.id_mobil 
        WHERE o.id_order = ?
    ");
    $stmt->bind_param("i", $id_order);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $row = $result->fetch_assoc()) {
        $stmt->close();
        return $row;
    }
    
    $stmt->close();
    return $defaultInfo;
}

function getInspectionData($conn) {
    $sql = "
        SELECT 
            kat.id_kategori, kat.nama_kategori,
            kri.id_kriteria, kri.deskripsi AS nama_kriteria,
            sk.id_standar, sk.komponen, sk.tipe_input, sk.nilai_batas, 
            sk.opsi_pilihan, sk.keterangan, sk.deskripsi
        FROM kategori_inspeksi kat
        JOIN kriteria_inspeksi kri ON kri.id_kategori = kat.id_kategori
        LEFT JOIN standar_komponen sk ON sk.id_kriteria = kri.id_kriteria
        ORDER BY kat.id_kategori, sk.komponen, kri.id_kriteria, sk.id_standar
    ";
    return $conn->query($sql);
}

function getComponentOrdering($conn) {
    $urutanMapByName = [];
    $urutanMapByStandar = [];
    
    $hasIdStandarInUrutan = false;
    $check = $conn->query("SHOW COLUMNS FROM urutan_komponen LIKE 'id_standar'");
    if ($check && $check->num_rows > 0) {
        $hasIdStandarInUrutan = true;
    }
    
    $resUr = $conn->query("SELECT * FROM urutan_komponen");
    while ($u = $resUr->fetch_assoc()) {
        if (!empty($u['nama_komponen'])) {
            $urutanMapByName[$u['nama_komponen']] = (int)($u['urutan'] ?? 999);
        }
        if ($hasIdStandarInUrutan && isset($u['id_standar'])) {
            $urutanMapByStandar[(int)$u['id_standar']] = (int)($u['urutan'] ?? 999);
        }
    }
    
    $urutanMapByNameNorm = [];
    foreach ($urutanMapByName as $rawName => $val) {
        $k = normalize_name($rawName);
        if (!isset($urutanMapByNameNorm[$k]) || $val < $urutanMapByNameNorm[$k]) {
            $urutanMapByNameNorm[$k] = (int)$val;
        }
    }
    
    return [
        'hasIdStandarInUrutan' => $hasIdStandarInUrutan,
        'byName' => $urutanMapByName,
        'byStandar' => $urutanMapByStandar,
        'byNameNorm' => $urutanMapByNameNorm
    ];
}

function groupInspectionData($result, $ordering) {
    $grouped = [];
    
    while ($r = $result->fetch_assoc()) {
        $idKategori = (int)$r['id_kategori'];
        $idKriteria = (int)$r['id_kriteria'];
        $komponenNama = $r['komponen'] ?? '';
        
        // Initialize category if not exists
        if (!isset($grouped[$idKategori])) {
            $grouped[$idKategori] = [
                'nama' => $r['nama_kategori'],
                'components' => []
            ];
        }
        
        if (!isset($grouped[$idKategori]['components'][$komponenNama])) {
            $urutan_effective = 999;
            
            if (!empty($r['id_standar']) && isset($ordering['byStandar'][(int)$r['id_standar']])) {
                $urutan_effective = (int)$ordering['byStandar'][(int)$r['id_standar']];
            } elseif (isset($ordering['byName'][$komponenNama])) {
                $urutan_effective = (int)$ordering['byName'][$komponenNama];
            } else {
                $normKey = normalize_name($komponenNama);
                if (isset($ordering['byNameNorm'][$normKey])) {
                    $urutan_effective = (int)$ordering['byNameNorm'][$normKey];
                }
            }
            
            $grouped[$idKategori]['components'][$komponenNama] = [
                'nama' => $komponenNama,
                'tipe_input' => $r['tipe_input'],
                'urutan_effective' => $urutan_effective,
                'kriteria' => []
            ];
        }
        
        if (!empty($r['id_standar'])) {
            $comp = &$grouped[$idKategori]['components'][$komponenNama];
            if (!isset($comp['kriteria'][$idKriteria])) {
                $comp['kriteria'][$idKriteria] = [
                    'id_kriteria' => $idKriteria,
                    'nama' => $r['nama_kriteria'],
                    'standards' => []
                ];
            }
            $comp['kriteria'][$idKriteria]['standards'][] = [
                'id_standar'  => $r['id_standar'],
                'nilai_batas' => $r['nilai_batas'],
                'opsi'        => $r['opsi_pilihan'],
                'keterangan'  => $r['keterangan']
            ];
            unset($comp);
        }
    }
    
    return $grouped;
}

function sortComponentsByOrdering(&$grouped) {
    foreach ($grouped as $idKat => &$katData) {
        if (empty($katData['components'])) continue;
        
        $list = array_values($katData['components']);
        usort($list, function ($a, $b) {
            if ($a['urutan_effective'] === $b['urutan_effective']) {
                return strcmp($a['nama'], $b['nama']);
            }
            return $a['urutan_effective'] <=> $b['urutan_effective'];
        });
        
        $newComps = [];
        foreach ($list as $c) {
            $newComps[$c['nama']] = $c;
        }
        $katData['components'] = $newComps;
    }
    unset($katData);
}

function sortCategoriesByCustomOrder($grouped, $urutanKategori, $conn) {
    $nameToId = [];
    $resCats = $conn->query("SELECT id_kategori, nama_kategori FROM kategori_inspeksi");
    if ($resCats) {
        while ($rk = $resCats->fetch_assoc()) {
            $norm = normalize_name($rk['nama_kategori'] ?? '');
            if ($norm !== '') {
                $nameToId[$norm] = (int)$rk['id_kategori'];
            }
        }
    }
    
    $urutanById = [];
    foreach ($urutanKategori as $name => $pos) {
        $norm = normalize_name($name);
        if (isset($nameToId[$norm])) {
            $urutanById[$nameToId[$norm]] = (int)$pos;
        }
    }
    
    $katKeys = array_keys($grouped);
    usort($katKeys, function ($a, $b) use ($grouped, $urutanById) {
        $posA = $urutanById[$a] ?? 999;
        $posB = $urutanById[$b] ?? 999;
        if ($posA === $posB) {
            $nameA = $grouped[$a]['nama'] ?? '';
            $nameB = $grouped[$b]['nama'] ?? '';
            return strcmp($nameA, $nameB);
        }
        return $posA <=> $posB;
    });
    
    $katOrdered = [];
    foreach ($katKeys as $k) {
        $katOrdered[$k] = $grouped[$k];
    }
    
    return $katOrdered;
}

function buildSteps($grouped) {
    $steps = ['Foto Mobil'];
    foreach ($grouped as $idKat => $data) {
        $steps[] = $idKat;
    }
    $steps[] = 'Scan OBD';
    $steps[] = 'Estimasi Perbaikan';
    $steps[] = 'Tinjau Hasil';
    return $steps;
}

$id_order = isset($_GET['id_order']) ? intval($_GET['id_order']) : 0;

updateOrderStatus($conn, $id_order);

$vehicleInfo = getVehicleInfo($conn, $id_order);

$inspectionResult = getInspectionData($conn);
$componentOrdering = getComponentOrdering($conn);

$grouped = groupInspectionData($inspectionResult, $componentOrdering);
sortComponentsByOrdering($grouped);
$grouped = sortCategoriesByCustomOrder($grouped, $urutanKategori, $conn);

$steps = buildSteps($grouped);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Form Inspeksi</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .fade-in {
            animation: fadeIn 0.35s ease-in-out;
        }
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(6px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        @media (max-width: 640px) {
            .table-responsive {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
            .table-responsive table {
                min-width: 600px;
            }
        }
    </style>
</head>

<body class="bg-gray-50 min-h-screen">
    <div class="container mx-auto px-3 sm:px-4 md:px-6 py-4 max-w-4xl">
        <div class="bg-white rounded-lg shadow-md overflow-hidden">
            <!-- Header -->
            <div class="bg-gradient-to-r from-indigo-600 to-indigo-700 text-white p-4 sm:p-6">
                <h2 class="text-xl sm:text-2xl font-bold mb-2">
                    Form Inspeksi RTECH Indonesia
                </h2>
                <p class="text-indigo-100 text-sm sm:text-base">
                    <?= htmlspecialchars($vehicleInfo['merk']) ?> - <?= htmlspecialchars($vehicleInfo['tahun_produksi']) ?>
                </p>
            </div>

            <!-- Form Content -->
            <div class="p-4 sm:p-6">
                <form id="formInspeksi" method="post" action="process_final.php" enctype="multipart/form-data">
                    <input type="hidden" name="id_order" value="<?= $id_order ?>">

                    <?php foreach ($steps as $i => $step): ?>
                        <div class="step hidden" data-step="<?= $i ?>">
                            <?php if ($i == 0): ?>
                                <!-- Foto Mobil -->
                                <h3 class="text-lg sm:text-xl font-semibold mb-4 text-gray-800">Upload Foto Mobil</h3>
                                <div class="space-y-3">
                                    <input type="file" 
                                           name="foto_mobil" 
                                           accept="image/*" 
                                           capture="environment" 
                                           required
                                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent input-review text-sm sm:text-base" 
                                           data-max-size="52428800" 
                                           data-review-label="Foto Mobil">
                                    <p class="text-xs sm:text-sm text-gray-500">*Format JPG/PNG/JPEG (Max 50MB)</p>
                                </div>

                            <?php elseif ($step === 'Scan OBD'): ?>
                                <!-- Scan OBD -->
                                <h3 class="text-lg sm:text-xl font-semibold mb-4 text-gray-800">Hasil Scan OBD</h3>
                                <div class="table-responsive mb-4">
                                    <table class="w-full border border-gray-300 text-xs sm:text-sm">
                                        <thead class="bg-gray-100">
                                            <tr>
                                                <th class="border border-gray-300 px-2 py-2 text-center w-12">No</th>
                                                <th class="border border-gray-300 px-2 py-2">Kode Trouble</th>
                                                <th class="border border-gray-300 px-2 py-2">Indikasi Error</th>
                                                <th class="border border-gray-300 px-2 py-2">Catatan</th>
                                                <th class="border border-gray-300 px-2 py-2 text-center w-20">Aksi</th>
                                            </tr>
                                        </thead>
                                        <tbody id="obd-body">
                                            <tr>
                                                <td class="border border-gray-300 px-2 py-2 text-center">1</td>
                                                <td class="border border-gray-300 px-2 py-1">
                                                    <input type="text" name="scan_obd[0][kode]" class="w-full border-none focus:ring-0 input-review" data-review-label="Kode Trouble 1">
                                                </td>
                                                <td class="border border-gray-300 px-2 py-1">
                                                    <input type="text" name="scan_obd[0][error]" class="w-full border-none focus:ring-0 input-review" data-review-label="Indikasi Error 1">
                                                </td>
                                                <td class="border border-gray-300 px-2 py-1">
                                                    <input type="text" name="scan_obd[0][catatan]" class="w-full border-none focus:ring-0 input-review" data-review-label="Catatan OBD 1">
                                                </td>
                                                <td class="border border-gray-300 px-2 py-2 text-center">
                                                    <button type="button" onclick="hapusBarisObd(this)" class="bg-red-500 hover:bg-red-600 text-white px-2 py-1 rounded text-xs sm:text-sm">
                                                        Hapus
                                                    </button>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <button type="button" onclick="tambahBarisObd()" class="w-full sm:w-auto bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm sm:text-base transition">
                                    + Tambah Kode Trouble
                                </button>

                            <?php elseif ($step === 'Estimasi Perbaikan'): ?>
                                <!-- Estimasi Perbaikan -->
                                <h3 class="text-lg sm:text-xl font-semibold mb-4 text-gray-800">Estimasi Perbaikan</h3>
                                <div class="table-responsive mb-4">
                                    <table class="w-full border border-gray-300 text-xs sm:text-sm">
                                        <thead class="bg-gray-100">
                                            <tr>
                                                <th class="border border-gray-300 px-2 py-2 text-center w-12">No</th>
                                                <th class="border border-gray-300 px-2 py-2">Hal yang Diservis</th>
                                                <th class="border border-gray-300 px-2 py-2">Biaya (Rp)</th>
                                                <th class="border border-gray-300 px-2 py-2 text-center w-20">Aksi</th>
                                            </tr>
                                        </thead>
                                        <tbody id="estimasi-body">
                                            <tr>
                                                <td class="border border-gray-300 px-2 py-2 text-center">1</td>
                                                <td class="border border-gray-300 px-2 py-1">
                                                    <input type="text" name="servis[0][hal]" class="w-full border-none focus:ring-0 input-review" data-review-label="Servis 1">
                                                </td>
                                                <td class="border border-gray-300 px-2 py-1">
                                                    <input type="number" name="servis[0][biaya]" class="w-full border-none focus:ring-0 biaya-input input-review" min="0" data-review-label="Biaya Servis 1">
                                                </td>
                                                <td class="border border-gray-300 px-2 py-2 text-center">
                                                    <button type="button" onclick="hapusBarisEstimasi(this)" class="bg-red-500 hover:bg-red-600 text-white px-2 py-1 rounded text-xs sm:text-sm">
                                                        Hapus
                                                    </button>
                                                </td>
                                            </tr>
                                        </tbody>
                                        <tfoot>
                                            <tr class="bg-gray-50 font-semibold">
                                                <td colspan="2" class="border border-gray-300 px-2 py-2 text-right">Total Estimasi</td>
                                                <td class="border border-gray-300 px-2 py-2" id="total-estimasi">Rp 0</td>
                                                <td class="border border-gray-300"></td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                                <button type="button" onclick="tambahBarisEstimasi()" class="w-full sm:w-auto bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm sm:text-base transition">
                                    + Tambah Estimasi
                                </button>

                            <?php elseif ($step === 'Tinjau Hasil'): ?>
                                <!-- Tinjau Hasil -->
                                <h3 class="text-lg sm:text-xl font-semibold mb-4 text-gray-800">Tinjau Hasil Inspeksi</h3>
                                <div id="tinjau-hasil" class="text-gray-700 text-sm mb-6 p-4 bg-gray-50 rounded-lg"></div>
                                
                                <div class="space-y-3">
                                    <select id="kesimpulan" 
                                            name="kesimpulan" 
                                            onchange="toggleKesimpulan(this)"
                                            class="w-full border border-gray-300 p-3 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent input-review text-sm sm:text-base"
                                            data-review-label="Kesimpulan" 
                                            required>
                                        <option value="">-- Pilih kesimpulan --</option>
                                        <option value="Mobil Dalam Keadaan yang Layak Pakai">Mobil Dalam Keadaan yang Layak Pakai</option>
                                        <option value="Mobil memiliki banyak part yang Tidak Layak">Mobil memiliki banyak part yang Tidak Layak</option>
                                        <option value="Banyak Bagian yang Harus di Service">Banyak Bagian yang Harus di Service/Perbaiki</option>
                                        <option value="Banyak Bagian yang Harus di Replace">Banyak Bagian yang Harus di Replace/Ganti</option>
                                        <option value="lainnya">Lainnya...</option>
                                    </select>
                                    
                                    <input type="text" 
                                           id="customKesimpulan" 
                                           name="kesimpulan_custom"
                                           class="w-full border border-gray-300 p-3 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent hidden input-review text-sm sm:text-base"
                                           data-review-label="Kesimpulan (Custom)"
                                           placeholder="Tulis kesimpulan sendiri">
                                    
                                    <textarea name="catatan_kesimpulan"
                                              rows="3"
                                              class="w-full border border-gray-300 p-3 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent input-review text-sm sm:text-base"
                                              data-review-label="Catatan Kesimpulan"
                                              placeholder="Catatan tambahan (opsional)"></textarea>
                                </div>

                            <?php else: ?>
                                <!-- Category Steps -->
                                <?php
                                if (is_int($step) && isset($grouped[$step])):
                                    $id_kategori = $step;
                                    $namaKategori = $grouped[$step]['nama'];
                                ?>
                                    <h3 class="text-lg sm:text-xl font-semibold mb-4 text-gray-800 pb-2 border-b-2 border-indigo-600">
                                        <?= htmlspecialchars($namaKategori) ?>
                                    </h3>

                                    <?php if (!empty($grouped[$step]['components']) && is_array($grouped[$step]['components'])): ?>
                                        <?php foreach ($grouped[$step]['components'] as $comp): ?>
                                            <?php $compName = $comp['nama'] ?? ''; ?>
                                            
                                            <div class="mb-6">
                                                <h4 class="text-base sm:text-lg font-semibold mb-3 text-indigo-700">
                                                    <?= htmlspecialchars($compName) ?>
                                                </h4>

                                                <?php if (!empty($comp['kriteria']) && is_array($comp['kriteria'])): ?>
                                                    <?php foreach ($comp['kriteria'] as $kri): ?>
                                                        <?php
                                                        $kriNama = $kri['nama'] ?? '';
                                                        $standards = $kri['standards'] ?? [];
                                                        ?>
                                                        
                                                        <div class="mb-4 p-3 sm:p-4 border border-gray-200 rounded-lg shadow-sm bg-white hover:shadow-md transition">
                                                            <div class="text-sm sm:text-base text-gray-700 mb-3">
                                                                <strong>Sub-kriteria:</strong> <?= htmlspecialchars($kriNama) ?>
                                                            </div>

                                                            <?php if ($comp['tipe_input'] === 'angka' && !empty($standards)): ?>
                                                                <div class="text-xs sm:text-sm text-gray-600 bg-blue-50 p-3 border border-blue-200 rounded-lg mb-3">
                                                                    <strong>Standar:</strong><br>
                                                                    <?php foreach ($standards as $s): ?>
                                                                        <div class="mt-1">≤ <?= htmlspecialchars($s['nilai_batas']) ?> → <?= htmlspecialchars($s['keterangan']) ?></div>
                                                                    <?php endforeach; ?>
                                                                </div>
                                                            <?php elseif ($comp['tipe_input'] === 'angka'): ?>
                                                                <div class="text-xs sm:text-sm text-gray-500 italic mb-3">
                                                                    Tidak ada data standar untuk sub-kriteria ini.
                                                                </div>
                                                            <?php endif; ?>

                                                            <?php
                                                            $firstStdId = null;
                                                            if (!empty($standards) && isset($standards[0]['id_standar'])) {
                                                                $firstStdId = (int)$standards[0]['id_standar'];
                                                            }

                                                            if ($comp['tipe_input'] === 'angka'):
                                                                $inputName = $firstStdId !== null 
                                                                    ? 'input[' . $firstStdId . ']' 
                                                                    : 'input[' . preg_replace('/[^a-z0-9_]/', '_', mb_strtolower($compName . '_' . $kriNama, 'UTF-8')) . ']';
                                                            ?>
                                                                <input type="number" 
                                                                       name="<?= $inputName ?>" 
                                                                       step="0.01" 
                                                                       min="0"
                                                                       class="w-full border border-gray-300 p-2 sm:p-3 rounded-lg input-review focus:ring-2 focus:ring-indigo-500 focus:border-transparent text-sm sm:text-base"
                                                                       data-review-label="<?= htmlspecialchars($compName . ' - ' . $kriNama) ?>"
                                                                       placeholder="Masukkan nilai ukur">

                                                            <?php elseif ($comp['tipe_input'] === 'pilihan'): ?>
                                                                <?php
                                                                $opsiSudahAda = [];
                                                                $opsiListFinal = [];
                                                                foreach ($standards as $s) {
                                                                    if (!empty($s['opsi'])) {
                                                                        $ops = preg_split('/[,|]/', $s['opsi']);
                                                                        foreach ($ops as $op) {
                                                                            $op = trim($op);
                                                                            if ($op === '' || in_array($op, $opsiSudahAda, true)) continue;
                                                                            $opsiSudahAda[] = $op;
                                                                            $opsiListFinal[] = $op;
                                                                        }
                                                                    }
                                                                }
                                                                $selectName = 'input[' . htmlspecialchars($compName, ENT_QUOTES, 'UTF-8') . ']';
                                                                ?>
                                                                
                                                                <?php if (!empty($opsiListFinal)): ?>
                                                                    <select name="<?= $selectName ?>" 
                                                                            class="w-full border border-gray-300 p-2 sm:p-3 rounded-lg input-review focus:ring-2 focus:ring-indigo-500 focus:border-transparent text-sm sm:text-base"
                                                                            data-review-label="<?= htmlspecialchars($compName . ' - ' . $kriNama) ?>">
                                                                        <option value="">-- Pilih kondisi --</option>
                                                                        <?php foreach ($opsiListFinal as $op): ?>
                                                                            <option value="<?= htmlspecialchars($op) ?>"><?= htmlspecialchars($op) ?></option>
                                                                        <?php endforeach; ?>
                                                                    </select>
                                                                <?php else: ?>
                                                                    <p class="text-gray-500 text-xs sm:text-sm italic">
                                                                        Tidak ada opsi tersedia untuk komponen ini.
                                                                    </p>
                                                                <?php endif; ?>

                                                            <?php else: ?>
                                                                <?php
                                                                $inputName = $firstStdId !== null 
                                                                    ? 'input[' . $firstStdId . ']' 
                                                                    : 'input[' . preg_replace('/[^a-z0-9_]/', '_', mb_strtolower($compName . '_' . $kriNama, 'UTF-8')) . ']';
                                                                ?>
                                                                <input type="text" 
                                                                       name="<?= $inputName ?>"
                                                                       class="w-full border border-gray-300 p-2 sm:p-3 rounded-lg input-review focus:ring-2 focus:ring-indigo-500 focus:border-transparent text-sm sm:text-base"
                                                                       data-review-label="<?= htmlspecialchars($compName . ' - ' . $kriNama) ?>"
                                                                       placeholder="Masukkan hasil">
                                                            <?php endif; ?>

                                                            <textarea name="<?= ($firstStdId !== null) ? 'catatan[' . $firstStdId . ']' : 'catatan[' . htmlspecialchars(preg_replace('/[^a-z0-9_]/', '_', mb_strtolower($compName . '_' . $kriNama, 'UTF-8')), ENT_QUOTES, 'UTF-8') . ']' ?>"
                                                                      rows="2"
                                                                      class="w-full border border-gray-300 mt-3 p-2 sm:p-3 rounded-lg text-xs sm:text-sm text-gray-700 focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                                                                      placeholder="Catatan tambahan (opsional)"></textarea>
                                                        </div>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>

                                        <div class="border-t-2 border-gray-200 pt-4 mt-6">
                                            <h4 class="text-base sm:text-lg font-semibold mb-3 text-gray-800">
                                                Penilaian Kategori (1-100)
                                            </h4>
                                            <input type="number"
                                                   name="nilai_kategori[<?= (int)$id_kategori ?>][nilai]"
                                                   class="w-full border border-gray-300 p-2 sm:p-3 rounded-lg input-review focus:ring-2 focus:ring-indigo-500 focus:border-transparent text-sm sm:text-base"
                                                   data-review-label="Nilai Kategori <?= htmlspecialchars($namaKategori) ?>"
                                                   min="1"
                                                   max="100"
                                                   placeholder="Masukkan skor 1–100"
                                                   required>
                                        </div>
                                    <?php else: ?>
                                        <p class="text-sm text-gray-500 italic">Tidak ada komponen pada kategori ini.</p>
                                    <?php endif; ?>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>

                    <!-- Navigation Buttons -->
                    <div class="flex flex-col sm:flex-row justify-center items-center gap-2 sm:gap-3 mt-6 pt-4 border-t border-gray-200">
                        <button type="button"
                                onclick="confirmBack()"
                                class="w-full sm:w-auto bg-yellow-400 hover:bg-yellow-500 text-gray-800 px-4 sm:px-6 py-2 sm:py-3 rounded-lg font-medium transition text-sm sm:text-base order-1 sm:order-1">
                            ← Kembali Ke Dashboard
                        </button>
                        <button type="button" 
                                id="prevBtn" 
                                onclick="nextPrev(-1)"
                                class="w-full sm:w-auto bg-gray-300 hover:bg-gray-400 text-gray-800 px-4 sm:px-6 py-2 sm:py-3 rounded-lg font-medium hidden transition text-sm sm:text-base order-2 sm:order-2">
                            ← Sebelumnya
                        </button>
                        <button type="button" 
                                id="nextBtn" 
                                onclick="nextPrev(1)"
                                class="w-full sm:w-auto bg-blue-600 hover:bg-blue-700 text-white px-4 sm:px-6 py-2 sm:py-3 rounded-lg font-medium transition text-sm sm:text-base order-3 sm:order-3">
                            Lanjut →
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div id="confirmModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden z-50 px-4">
        <div class="bg-white rounded-lg shadow-xl max-w-sm w-full p-6 text-center">
            <h3 class="text-lg sm:text-xl font-semibold mb-4 text-gray-800">Konfirmasi</h3>
            <p class="text-gray-600 mb-6 text-sm sm:text-base">
                Apakah Anda yakin ingin kembali ke dashboard? Perubahan pada form ini belum tersimpan.
            </p>
            <div class="flex flex-col sm:flex-row justify-center gap-3">
                <button onclick="goBack()"
                        class="w-full sm:w-auto bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg font-medium transition">
                    Ya, kembali
                </button>
                <button onclick="closeModal()"
                        class="w-full sm:w-auto bg-gray-300 hover:bg-gray-400 text-gray-800 px-4 py-2 rounded-lg font-medium transition">
                    Batal
                </button>
            </div>
        </div>
    </div>

    <div id="loadingOverlay" class="fixed inset-0 bg-black bg-opacity-60 flex items-center justify-center hidden z-[60]">
        <div class="bg-white rounded-2xl shadow-2xl p-8 text-center max-w-sm mx-4">
            <!-- Spinner -->
            <div class="flex justify-center mb-4">
                <div class="relative">
                    <div class="w-16 h-16 border-4 border-indigo-200 rounded-full"></div>
                    <div class="w-16 h-16 border-4 border-indigo-600 rounded-full border-t-transparent animate-spin absolute top-0 left-0"></div>
                </div>
            </div>
            
            <h3 class="text-xl font-bold text-gray-800 mb-2">
                Menyimpan Data...
            </h3>
            <p class="text-sm text-gray-600 mb-4">
                Mohon tunggu, hasil inspeksi sedang diproses
            </p>
            
            <div class="w-full bg-gray-200 rounded-full h-2 overflow-hidden">
                <div class="bg-indigo-600 h-full rounded-full animate-pulse" style="width: 70%"></div>
            </div>
            
            <p class="text-xs text-gray-500 mt-4">
                ⚠️ Jangan tutup halaman ini
            </p>
        </div>
    </div>

    <script src="form.js"></script>
</body>
</html>