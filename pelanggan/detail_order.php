<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../includes/koneksi.php';
date_default_timezone_set('Asia/Jakarta');

if (empty($_SESSION['username']) || ($_SESSION['role'] ?? '') !== 'pelanggan') {
    header("Location: ../auth/login.php");
    exit;
}

$id_user = (int)($_SESSION['id_user'] ?? 0);

$id_order = 0;
if (isset($_GET['id'])) {
    $id_order = (int)$_GET['id'];
}

if ($id_order <= 0) {
    $_SESSION['flash_error'] = "ID order tidak valid.";
    header("Location: pelanggan_dashboard.php");
    exit;
}

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$stmt = $conn->prepare("
    SELECT oi.id_order, oi.tanggal_order, oi.status AS status_order,
           u.nama_lengkap, 
           k.merk, k.model, k.tahun_produksi, k.nomor_polisi, k.alamat AS alamat_mobil,
           k.link_gmaps,
           i.id_inspeksi, i.tanggal AS tanggal_inspeksi, 
           i.nilai_akhir, i.nilai_huruf, i.kesimpulan
    FROM order_inspeksi oi
    JOIN users u ON oi.id_pelanggan = u.id_user
    JOIN kendaraan k ON oi.id_mobil = k.id_mobil
    LEFT JOIN inspeksi i ON oi.id_order = i.id_order
    WHERE oi.id_order = ? AND oi.id_pelanggan = ?
    LIMIT 1
");
$stmt->bind_param("ii", $id_order, $id_user);
$stmt->execute();
$orderData = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$orderData) {
    $_SESSION['flash_error'] = "Order tidak ditemukan atau Anda tidak memiliki akses.";
    header("Location: pelanggan_dashboard.php");
    exit;
}

$kategoriList = [];
$scanData = [];
$estimasiData = [];
$fotoData = [];

if (!empty($orderData['id_inspeksi'])) {
    $id_inspeksi = (int)$orderData['id_inspeksi'];

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

    if (!function_exists('tentukanHasil')) {
        function tentukanHasil(array $row, array $standarMap)
        {
            $idk = (int)$row['id_kriteria'];
            $komp = trim((string)$row['komponen']);
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
    }

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
    $stmtDet->bind_param("i", $id_inspeksi);
    $stmtDet->execute();
    $resDet = $stmtDet->get_result();

    while ($row = $resDet->fetch_assoc()) {
        $hasilSistem = tentukanHasil($row, $standarMap);

        $hasilLapangan = trim((string)($row['hasil_lapangan'] ?? ''));
        $nilaiAngka    = trim((string)($row['nilai'] ?? ''));
        $statusPilihan = trim((string)($row['status'] ?? ''));
        $catatanMentah = trim((string)($row['catatan'] ?? ''));

        if ($hasilLapangan !== '') {
            $inputInspektor = $hasilLapangan;
        } elseif ($nilaiAngka !== '') {
            $inputInspektor = $nilaiAngka;
        } elseif ($statusPilihan !== '') {
            $inputInspektor = $statusPilihan;
        } elseif ($catatanMentah !== '') {
            $inputInspektor = $catatanMentah;
        } else {
            $inputInspektor = '-';
        }

        $catatanBersih = preg_replace([
            '/Pilihan:\s*[^|]+/i',
            '/Nilai ukur:\s*[^|]+/i'
        ], '', $catatanMentah);
        $catatanBersih = trim($catatanBersih);

        if ($catatanBersih !== '') {
            $keteranganFinal = $catatanBersih;
        } elseif (!empty($hasilSistem)) {
            $keteranganFinal = $hasilSistem;
        } else {
            $keteranganFinal = '-';
        }

        $row['_input']      = $inputInspektor;
        $row['_keterangan'] = $keteranganFinal;

        $cat = $row['kategori'] ?? 'Lain-lain';
        if (!isset($kategoriList[$cat])) {
            $kategoriList[$cat] = [];
        }
        $kategoriList[$cat][] = $row;
    }

    $stmtDet->close();

    $stmtScan = $conn->prepare("
        SELECT kode_trouble, indikasi_error, catatan, tanggal_scan
        FROM hasil_scan_obd
        WHERE id_inspeksi = ?
    ");
    $stmtScan->bind_param("i", $id_inspeksi);
    $stmtScan->execute();
    $scanData = $stmtScan->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmtScan->close();

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
    if ($chosen === null && !empty($cols)) $chosen = $cols[0];

    if ($chosen !== null) {
        $colEsc = '`' . str_replace('`', '``', $chosen) . '`';
        $sqlEstimasi = "SELECT {$colEsc} AS pekerjaan, `biaya` FROM `estimasi_perbaikan` WHERE `id_inspeksi` = ?";
        $stmtEstimasi = $conn->prepare($sqlEstimasi);
        $stmtEstimasi->bind_param("i", $id_inspeksi);
        $stmtEstimasi->execute();
        $estimasiData = $stmtEstimasi->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmtEstimasi->close();
    }

    $stmtFoto = $conn->prepare("
        SELECT path_file, keterangan
        FROM bukti_foto
        WHERE id_inspeksi = ?
    ");
    $stmtFoto->bind_param("i", $id_inspeksi);
    $stmtFoto->execute();
    $fotoData = $stmtFoto->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmtFoto->close();
}

$statusConfig = [
    'Pending' => ['class' => 'bg-gray-500/15 text-gray-400 border-gray-500/30', 'icon' => '⏱️'],
    'Diproses' => ['class' => 'bg-blue-500/15 text-blue-400 border-blue-500/30', 'icon' => '⏳'],
    'Selesai' => ['class' => 'bg-green-500/15 text-green-400 border-green-500/30', 'icon' => '✅'],
    'Disetujui' => ['class' => 'bg-yellow-500/15 text-yellow-400 border-yellow-500/30', 'icon' => '✓'],
    'Gagal' => ['class' => 'bg-red-500/15 text-red-400 border-red-500/30', 'icon' => '❌']
];
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detail Order Inspeksi — RTECH</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" type="image/x-icon" href="../favicon.ico">
    <style>
        :root {
            --bg: #0a0e1a;
            --bg-secondary: #0f1423;
            --card: #151b2e;
            --card-hover: #1a2235;
            --muted: #94a3b8;
            --text: #f1f5f9;
            --brand: #FF7A2D;
            --brand-dark: #D35400;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background: linear-gradient(135deg, var(--bg) 0%, var(--bg-secondary) 100%);
            color: var(--text);
            min-height: 100vh;
        }

        .glass-card {
            background: rgba(21, 27, 46, 0.7);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .glass-card:hover {
            border-color: rgba(255, 255, 255, 0.12);
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .animate-fade-in-up {
            animation: fadeInUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }

        .status-badge {
            padding: 0.375rem 0.75rem;
            border-radius: 9999px;
            font-weight: 600;
            font-size: 0.75rem;
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            text-transform: uppercase;
            letter-spacing: 0.025em;
            border: 1px solid;
        }

        .section-divider {
            height: 1px;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.1), transparent);
            margin: 2rem 0;
        }

        .info-row {
            display: grid;
            grid-template-columns: 140px 1fr;
            gap: 1rem;
            padding: 0.75rem 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        }

        .info-row:last-child {
            border-bottom: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--brand) 0%, var(--brand-dark) 100%);
            color: white;
            font-weight: 600;
            padding: 0.75rem 1.5rem;
            border-radius: 0.75rem;
            transition: all 0.3s ease;
            box-shadow: 0 4px 14px rgba(255, 122, 45, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(255, 122, 45, 0.4);
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: var(--text);
            font-weight: 500;
            padding: 0.75rem 1.5rem;
            border-radius: 0.75rem;
            transition: all 0.3s ease;
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.1);
            border-color: rgba(255, 255, 255, 0.2);
        }

        .photo-card {
            position: relative;
            overflow: hidden;
            border-radius: 1rem;
            border: 1px solid rgba(255, 255, 255, 0.08);
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .photo-card:hover {
            transform: scale(1.05);
            border-color: var(--brand);
            box-shadow: 0 8px 30px rgba(255, 122, 45, 0.3);
        }

        .photo-card img {
            width: 100%;
            height: 200px;
            object-fit: cover;
        }

        table {
            border-collapse: separate;
            border-spacing: 0;
        }

        table thead {
            background: rgba(255, 122, 45, 0.1);
        }

        table th {
            padding: 0.875rem 1rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.875rem;
            border-bottom: 2px solid rgba(255, 122, 45, 0.3);
            color: var(--brand);
        }

        table td {
            padding: 0.875rem 1rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            font-size: 0.875rem;
        }

        table tbody tr:hover {
            background: rgba(255, 255, 255, 0.02);
        }

        .grade-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 4rem;
            height: 4rem;
            border-radius: 1rem;
            font-size: 2rem;
            font-weight: 800;
            background: linear-gradient(135deg, var(--brand), var(--brand-dark));
            color: white;
            box-shadow: 0 8px 20px rgba(255, 122, 45, 0.4);
        }
    </style>
</head>

<body>

    <header class="sticky top-0 z-40 bg-black/20 backdrop-blur-xl border-b border-white/5">
        <div class="max-w-6xl mx-auto px-4 py-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <div class="glass-card rounded-xl px-4 py-2">
                        <h1 class="text-[color:var(--brand)] font-extrabold text-xl tracking-tight cursor-pointer"
                            onclick="window.location.href='pelanggan_dashboard.php'">
                            Rtech Indonesia
                        </h1>
                    </div>
                </div>
                <a href="pelanggan_dashboard.php" class="btn-secondary flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                    </svg>
                    <span class="hidden sm:inline">Kembali</span>
                </a>
            </div>
        </div>
    </header>

    <main class="max-w-6xl mx-auto px-4 py-8 space-y-6">

        <!-- Order Header Card -->
        <div class="glass-card rounded-2xl p-6 animate-fade-in-up">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
                <div>
                    <h1 class="text-3xl font-bold bg-gradient-to-r from-[color:var(--brand)] to-[color:var(--brand-dark)] bg-clip-text text-transparent mb-2">
                        Detail Order Inspeksi
                    </h1>
                    <p class="text-[color:var(--muted)]">Order #<?= e((string)$orderData['id_order']) ?></p>
                </div>
                <div>
                    <?php
                    $status = $orderData['status_order'] ?? 'Pending';
                    $statusInfo = $statusConfig[$status] ?? $statusConfig['Pending'];
                    ?>
                    <span class="status-badge <?= $statusInfo['class'] ?>">
                        <span><?= $statusInfo['icon'] ?></span>
                        <span><?= e($status) ?></span>
                    </span>
                </div>
            </div>

            <div class="grid md:grid-cols-2 gap-6">
                <div>
                    <h3 class="text-sm font-semibold text-[color:var(--brand)] mb-3 uppercase tracking-wide">Informasi Kendaraan</h3>
                    <div class="space-y-2">
                        <div class="info-row">
                            <span class="text-[color:var(--muted)] text-sm">Merk</span>
                            <span class="font-semibold"><?= e($orderData['merk']) ?></span>
                        </div>
                        <div class="info-row">
                            <span class="text-[color:var(--muted)] text-sm">Model</span>
                            <span class="font-semibold"><?= e($orderData['model']) ?></span>
                        </div>
                        <div class="info-row">
                            <span class="text-[color:var(--muted)] text-sm">Tahun</span>
                            <span class="font-semibold"><?= e($orderData['tahun_produksi'] ?? '-') ?></span>
                        </div>
                        <div class="info-row">
                            <span class="text-[color:var(--muted)] text-sm">Nomor Polisi</span>
                            <span class="font-semibold"><?= e($orderData['nomor_polisi']) ?></span>
                        </div>
                    </div>
                </div>

                <div>
                    <h3 class="text-sm font-semibold text-[color:var(--brand)] mb-3 uppercase tracking-wide">Informasi Lokasi</h3>
                    <div class="space-y-2">
                        <div class="info-row">
                            <span class="text-[color:var(--muted)] text-sm">Alamat</span>
                            <span class="font-semibold"><?= e($orderData['alamat_mobil']) ?></span>
                        </div>
                        <?php if (!empty($orderData['link_gmaps'])): ?>
                            <div class="info-row">
                                <span class="text-[color:var(--muted)] text-sm">Google Maps</span>
                                <a href="<?= e($orderData['link_gmaps']) ?>" target="_blank"
                                    class="text-[color:var(--brand)] hover:text-[color:var(--brand-dark)] transition flex items-center gap-1">
                                    <span>Lihat Lokasi</span>
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                                    </svg>
                                </a>
                            </div>
                        <?php endif; ?>
                        <div class="info-row">
                            <span class="text-[color:var(--muted)] text-sm">Tanggal Order</span>
                            <span class="font-semibold"><?= e(date('d M Y', strtotime($orderData['tanggal_order']))) ?></span>
                        </div>
                        <?php if (!empty($orderData['tanggal_inspeksi'])): ?>
                            <div class="info-row">
                                <span class="text-[color:var(--muted)] text-sm">Tanggal Inspeksi</span>
                                <span class="font-semibold"><?= e(date('d M Y', strtotime($orderData['tanggal_inspeksi']))) ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!empty($orderData['id_inspeksi'])): ?>
            <div class="glass-card rounded-2xl p-6 animate-fade-in-up">
                <div class="flex flex-col md:flex-row items-start md:items-center justify-between gap-6">
                    <div class="flex-1">
                        <h2 class="text-2xl font-bold mb-3">Hasil Inspeksi</h2>
                        <div class="space-y-2">
                            <div class="flex items-center gap-3">
                                <span class="text-[color:var(--muted)]">Nilai Akhir:</span>
                                <span class="text-2xl font-bold text-[color:var(--brand)]">
                                    <?= !empty($orderData['nilai_akhir']) ? number_format((float)$orderData['nilai_akhir'], 2) : '-' ?>
                                </span>
                            </div>
                            <div class="flex items-baseline gap-3">
                                <span class="text-[color:var(--muted)]">Kesimpulan:</span>
                                <p class="text-lg font-semibold">
                                    <?php
                                    $kesimpulan = !empty($orderData['kesimpulan']) ? $orderData['kesimpulan'] : '';

                                    if ($kesimpulan === 'lainnya' || $kesimpulan === '') {
                                        echo '<span class="text-yellow-400">⚠️ Kesimpulan belum diisi dengan lengkap</span>';
                                    } else {
                                        echo e($kesimpulan);
                                    }
                                    ?>
                                </p>
                            </div>
                        </div>
                    </div>
                    <div class="grade-badge">
                        <?= !empty($orderData['nilai_huruf']) ? e($orderData['nilai_huruf']) : '?' ?>
                    </div>
                </div>
            </div>

            <div class="glass-card rounded-2xl p-6 animate-fade-in-up">
                <div class="flex flex-col sm:flex-row items-center justify-between gap-4">
                    <div class="flex items-center gap-4">
                        <div class="w-14 h-14 rounded-xl bg-gradient-to-br from-red-500/20 to-red-600/10 flex items-center justify-center">
                            <svg class="w-8 h-8 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-lg font-semibold mb-1">Laporan Inspeksi PDF</h3>
                            <p class="text-sm text-[color:var(--muted)]">Download laporan inspeksi lengkap dengan semua detail pemeriksaan</p>
                        </div>
                    </div>
                    <form action="../admin/cetak_detail_order.php" method="post" target="_blank">
                        <input type="hidden" name="id" value="<?= e((string)$id_order) ?>">
                        <button type="submit" class="btn-primary flex items-center gap-2 whitespace-nowrap">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                            <span>Download PDF</span>
                        </button>
                    </form>
                </div>
            </div>

            <?php if (!empty($fotoData)): ?>
                <div class="glass-card rounded-2xl p-6 animate-fade-in-up">
                    <h2 class="text-xl font-bold mb-4 flex items-center gap-2">
                        <span>📸</span>
                        <span>Foto Kendaraan</span>
                    </h2>
                    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                        <?php foreach ($fotoData as $f):
                            $pf = $f['path_file'] ?? '';
                            if ($pf === '') continue;
                            $img = (strpos($pf, '/') === false) ? '../uploads/foto_mobil/' . $pf : '../' . ltrim($pf, '/');
                        ?>
                            <div class="photo-card" onclick="openImageModal('<?= e($img) ?>')">
                                <img src="<?= e($img) ?>" alt="Foto Kendaraan" loading="lazy">
                                <?php if (!empty($f['keterangan'])): ?>
                                    <div class="absolute bottom-0 left-0 right-0 bg-black/70 backdrop-blur-sm p-2 text-xs text-center">
                                        <?= e($f['keterangan']) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Detail Penilaian Komponen (FIXED) -->
            <div class="glass-card rounded-2xl p-6 animate-fade-in-up">
                <h2 class="text-xl font-bold mb-4 flex items-center gap-2">
                    <span>📋</span>
                    <span>Detail Penilaian Komponen</span>
                </h2>

                <?php foreach ($kategoriList as $kategori => $rows): ?>
                    <?php
                    $hasVisible = false;
                    foreach ($rows as $r) {
                        if (
                            trim((string)($r['_input'] ?? '')) !== '-' ||
                            trim((string)($r['_keterangan'] ?? '')) !== '-'
                        ) {
                            $hasVisible = true;
                            break;
                        }
                    }
                    if (!$hasVisible) continue;
                    ?>

                    <div class="mb-6 last:mb-0">
                        <h3 class="text-lg font-semibold text-[color:var(--brand)] mb-3 flex items-center gap-2">
                            <span>▸</span>
                            <span><?= e($kategori) ?></span>
                        </h3>
                        <div class="overflow-x-auto">
                            <table class="w-full">
                                <thead>
                                    <tr>
                                        <th class="rounded-tl-lg">Komponen</th>
                                        <th>Kondisi</th>
                                        <th class="rounded-tr-lg">Keterangan</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($rows as $r): ?>
                                        <tr>
                                            <td class="font-medium"><?= e($r['komponen']) ?></td>
                                            <td>
                                                <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-semibold
                                                        bg-blue-500/10 text-blue-400 border border-blue-500/30">
                                                    <?= e($r['_input']) ?>
                                                </span>
                                            </td>
                                            <td class="text-[color:var(--muted)]">
                                                <?= e($r['_keterangan']) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- OBD Scan Results -->
            <?php if (!empty($scanData)): ?>
                <div class="glass-card rounded-2xl p-6 animate-fade-in-up">
                    <h2 class="text-xl font-bold mb-4 flex items-center gap-2">
                        <span>🔧</span>
                        <span>Hasil Scan OBD (Diagnosa Elektronik)</span>
                    </h2>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead>
                                <tr>
                                    <th class="rounded-tl-lg">Kode Error</th>
                                    <th>Indikasi</th>
                                    <th>Catatan</th>
                                    <th class="rounded-tr-lg">Tanggal Scan</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($scanData as $s): ?>
                                    <tr>
                                        <td>
                                            <span class="inline-flex items-center gap-1 px-3 py-1 rounded-lg text-xs font-mono font-bold bg-red-500/10 text-red-400 border border-red-500/30">
                                                <?= e($s['kode_trouble']) ?>
                                            </span>
                                        </td>
                                        <td class="font-medium"><?= e($s['indikasi_error']) ?></td>
                                        <td class="text-[color:var(--muted)]"><?= e($s['catatan'] ?? '-') ?></td>
                                        <td class="text-[color:var(--muted)]"><?= e(date('d M Y', strtotime($s['tanggal_scan']))) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Estimasi Perbaikan -->
            <?php if (!empty($estimasiData) && count($estimasiData) > 0): ?>
                <div class="glass-card rounded-2xl p-6 animate-fade-in-up">
                    <h2 class="text-xl font-bold mb-4 flex items-center gap-2">
                        <span>💰</span>
                        <span>Estimasi Biaya Perbaikan</span>
                    </h2>
                    <div class="space-y-3">
                        <?php
                        $totalBiaya = 0;
                        foreach ($estimasiData as $i => $est):
                            $biaya = isset($est['biaya']) ? (float)$est['biaya'] : 0;
                            $totalBiaya += $biaya;
                        ?>
                            <div class="flex items-center justify-between p-4 rounded-lg bg-white/5 border border-white/10">
                                <div class="flex items-start gap-3 flex-1">
                                    <span class="flex-shrink-0 w-6 h-6 rounded-full bg-[color:var(--brand)]/20 text-[color:var(--brand)] text-xs font-bold flex items-center justify-center">
                                        <?= $i + 1 ?>
                                    </span>
                                    <span class="font-medium"><?= e($est['pekerjaan']) ?></span>
                                </div>
                                <span class="font-bold text-[color:var(--brand)] text-lg whitespace-nowrap ml-4">
                                    Rp <?= number_format($biaya, 0, ',', '.') ?>
                                </span>
                            </div>
                        <?php endforeach; ?>

                        <div class="section-divider"></div>

                        <div class="flex items-center justify-between p-5 rounded-xl bg-gradient-to-r from-[color:var(--brand)]/20 to-[color:var(--brand-dark)]/10 border border-[color:var(--brand)]/30">
                            <span class="text-lg font-bold">Total Estimasi</span>
                            <span class="text-2xl font-extrabold text-[color:var(--brand)]">
                                Rp <?= number_format($totalBiaya, 0, ',', '.') ?>
                            </span>
                        </div>
                    </div>
                    <p class="text-xs text-[color:var(--muted)] mt-4 italic">
                        * Estimasi biaya dapat berubah tergantung kondisi aktual dan ketersediaan suku cadang
                    </p>
                </div>
            <?php endif; ?>

        <?php else: ?>
            <?php
            $status = $orderData['status_order'] ?? 'Pending';

            $ui = [
                'icon'  => '⏱️',
                'title' => 'Menunggu Konfirmasi',
                'desc'  => 'Order Anda telah diterima dan sedang menunggu penjadwalan inspeksi.'
            ];

            if ($status === 'Diproses') {
                $ui = [
                    'icon'  => '⏳',
                    'title' => 'Inspeksi Sedang Dilakukan',
                    'desc'  => 'Teknisi sedang melakukan pemeriksaan kendaraan Anda.'
                ];
            } elseif ($status === 'Selesai') {
                $ui = [
                    'icon'  => '⚠️',
                    'title' => 'Menunggu Finalisasi Data',
                    'desc'  => 'Inspeksi telah selesai, namun data hasil belum tersedia.'
                ];
            }
            ?>

            <div class="glass-card rounded-2xl p-12 text-center animate-fade-in-up">
                <div class="text-6xl mb-4"><?= $ui['icon'] ?></div>
                <h3 class="text-xl font-semibold mb-2"><?= e($ui['title']) ?></h3>
                <p class="text-[color:var(--muted)]"><?= e($ui['desc']) ?></p>
            </div>

            <?php endif; ?>

        <div class="flex justify-center">
            <a href="pelanggan_dashboard.php" class="btn-secondary flex items-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                </svg>
                <span>Kembali ke Dashboard</span>
            </a>
        </div>

    </main>

    <div id="imageModal" class="fixed inset-0 bg-black/90 hidden items-center justify-center z-50 p-4">
        <button class="absolute top-6 right-6 text-white text-4xl hover:text-[color:var(--brand)] transition z-10"
            onclick="closeImageModal()">
            ×
        </button>
        <img id="modalImage" class="max-w-full max-h-full object-contain cursor-grab" alt="Preview">
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

            scale = 1;
            imgX = 0;
            imgY = 0;
            img.style.transform = 'translate(0px, 0px) scale(1)';
        }

        function closeImageModal() {
            const modal = document.getElementById('imageModal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        const imgEl = document.getElementById('modalImage');

        // Zoom with mouse wheel
        imgEl.addEventListener('wheel', e => {
            e.preventDefault();
            scale += e.deltaY * -0.001;
            scale = Math.min(Math.max(1, scale), 4);
            imgEl.style.transform = `translate(${imgX}px, ${imgY}px) scale(${scale})`;
        });

        // Drag functionality
        imgEl.addEventListener('mousedown', e => {
            if (e.button !== 0) return;
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

        // Close on right click
        imgEl.addEventListener('contextmenu', e => {
            e.preventDefault();
            closeImageModal();
        });

        // Touch support
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

        // Close modal on background click
        document.getElementById('imageModal').addEventListener('click', function(e) {
            if (e.target === this) closeImageModal();
        });

        // ESC key to close
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeImageModal();
        });
    </script>

</body>

</html>