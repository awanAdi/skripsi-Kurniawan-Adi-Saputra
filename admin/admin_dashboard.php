<?php
require_once '../includes/session.php';

if (empty($_SESSION['username']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit;
}

require_once '../includes/koneksi.php';

function e(string $val): string
{
    return htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function formatTanggalIndonesia(?string $tanggal): string
{
    if (!$tanggal) return '-';
    $bulan = [
        1 => 'Januari',
        2 => 'Februari',
        3 => 'Maret',
        4 => 'April',
        5 => 'Mei',
        6 => 'Juni',
        7 => 'Juli',
        8 => 'Agustus',
        9 => 'September',
        10 => 'Oktober',
        11 => 'November',
        12 => 'Desember'
    ];
    $ts = strtotime($tanggal);
    if ($ts === false) return '-';
    $day = date('j', $ts);
    $month = (int)date('n', $ts);
    $year = date('Y', $ts);
    return "{$day} {$bulan[$month]} {$year}";
}

function fetchKategoriKriteria(mysqli $conn): array
{
    $sql = "
        SELECT c.nama_kategori, COUNT(DISTINCT sc.komponen) AS total
        FROM standar_komponen sc
        JOIN kriteria_inspeksi ki ON sc.id_kriteria = ki.id_kriteria
        JOIN kategori_inspeksi c ON ki.id_kategori = c.id_kategori
        GROUP BY c.id_kategori, c.nama_kategori
        ORDER BY c.nama_kategori ASC
    ";
    $res = $conn->query($sql);
    $out = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $out[] = $r;
        }
        $res->free();
    }
    return $out;
}

function getStatusCounts(mysqli $conn): array
{
    $defaults = [
        'Menunggu' => 0,
        'Diproses' => 0,
        'Selesai'  => 0
    ];
    $sql = "SELECT status, COUNT(*) AS total FROM order_inspeksi GROUP BY status";
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $status = $row['status'] ?? '';
            if ($status !== '') $defaults[$status] = (int)$row['total'];
        }
        $res->free();
    }
    return $defaults;
}

function getTotals(mysqli $conn): array
{
    $totals = [
        'orders' => 0,
        'teknisi' => 0,
        'kriteria' => 0
    ];
    if ($r = $conn->query("SELECT COUNT(*) AS total FROM order_inspeksi")->fetch_assoc()) {
        $totals['orders'] = (int)$r['total'];
    }
    if ($r = $conn->query("SELECT COUNT(*) AS total FROM users WHERE role = 'teknisi'")->fetch_assoc()) {
        $totals['teknisi'] = (int)$r['total'];
    }
    if ($r = $conn->query("SELECT COUNT(DISTINCT komponen) AS total FROM standar_komponen")->fetch_assoc()) {
        $totals['kriteria'] = (int)$r['total'];
    }
    return $totals;
}

function getOrders(mysqli $conn, string $search, int $offset, int $limit, int &$totalRows): array
{
    $orders = [];
    $totalRows = 0;

    if ($search !== '') {
        $countSql = "
            SELECT COUNT(*) AS total
            FROM order_inspeksi o
            JOIN users pelanggan ON o.id_pelanggan = pelanggan.id_user
            WHERE pelanggan.nama_lengkap LIKE ?
        ";
        $stmt = $conn->prepare($countSql);
        $like = "%{$search}%";
        $stmt->bind_param("s", $like);
        $stmt->execute();
        $cntRes = $stmt->get_result()->fetch_assoc();
        $totalRows = (int)($cntRes['total'] ?? 0);
        $stmt->close();

        $sql = "
            SELECT 
                o.id_order,
                pelanggan.nama_lengkap AS nama_customer,
                k.merk,
                o.tanggal_order,
                i.tanggal AS tanggal_dikerjakan,
                o.status,
                teknisi.nama_lengkap AS teknisi_nama
            FROM order_inspeksi o
            JOIN users pelanggan ON o.id_pelanggan = pelanggan.id_user
            JOIN kendaraan k ON o.id_mobil = k.id_mobil
            LEFT JOIN inspeksi i ON i.id_order = o.id_order
            LEFT JOIN users teknisi ON o.id_teknisi = teknisi.id_user
            WHERE pelanggan.nama_lengkap LIKE ?
            ORDER BY o.id_order DESC
            LIMIT ?, ?
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sii", $like, $offset, $limit);
    } else {
        // no search
        $countSql = "
            SELECT COUNT(*) AS total
            FROM order_inspeksi o
        ";
        $cntRes = $conn->query($countSql)->fetch_assoc();
        $totalRows = (int)($cntRes['total'] ?? 0);

        $sql = "
            SELECT 
                o.id_order,
                pelanggan.nama_lengkap AS nama_customer,
                k.merk,
                o.tanggal_order,
                i.tanggal AS tanggal_dikerjakan,
                o.status,
                teknisi.nama_lengkap AS teknisi_nama
            FROM order_inspeksi o
            JOIN users pelanggan ON o.id_pelanggan = pelanggan.id_user
            JOIN kendaraan k ON o.id_mobil = k.id_mobil
            LEFT JOIN inspeksi i ON i.id_order = o.id_order
            LEFT JOIN users teknisi ON o.id_teknisi = teknisi.id_user
            ORDER BY o.id_order DESC
            LIMIT ?, ?
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $offset, $limit);
    }

    if ($stmt) {
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $orders[] = $row;
        }
        $res->free();
        $stmt->close();
    }

    return $orders;
}

function pageUrl(string $search, int $page): string
{
    $params = [];
    if ($search !== '') $params['search'] = $search;
    if ($page > 1) $params['page'] = $page;
    return '?' . http_build_query($params);
}

$kategori_kriteria = fetchKategoriKriteria($conn);

$limit = 10;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

$search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';
$search = mb_substr($search, 0, 255); // limit length for safety

$totalRows = 0;
$order_result = getOrders($conn, $search, $offset, $limit, $totalRows);

$totalPages = $totalRows > 0 ? (int)ceil($totalRows / $limit) : 1;

$total_keseluruhan = 0;
if (!empty($kategori_kriteria)) {
    foreach ($kategori_kriteria as $r) {
        $total_keseluruhan += isset($r['total']) ? (int)$r['total'] : 0;
    }
}

$totals = getTotals($conn);
$status_counts = getStatusCounts($conn);

date_default_timezone_set('Asia/Jakarta');
$hari_indo = [
    'Sunday' => 'Minggu',
    'Monday' => 'Senin',
    'Tuesday' => 'Selasa',
    'Wednesday' => 'Rabu',
    'Thursday' => 'Kamis',
    'Friday' => 'Jumat',
    'Saturday' => 'Sabtu'
];
$bulan_indo = [
    'January' => 'Januari',
    'February' => 'Februari',
    'March' => 'Maret',
    'April' => 'April',
    'May' => 'Mei',
    'June' => 'Juni',
    'July' => 'Juli',
    'August' => 'Agustus',
    'September' => 'September',
    'October' => 'Oktober',
    'November' => 'November',
    'December' => 'Desember'
];
$hari_en = date("l");
$bulan_en = date("F");
$hari = $hari_indo[$hari_en] ?? $hari_en;
$bulan = $bulan_indo[$bulan_en] ?? $bulan_en;
$tanggal = date("j");
$tahun = date("Y");
$jam = date("H:i:s");
$waktu_indo = "$hari, $tanggal $bulan $tahun | $jam";

$nama_user = $_SESSION['username'] ?? 'User';
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <title>Admin Dashboard</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    <link rel="icon" href="../favicon.ico" type="image/x-icon">
    <style>
        #orderStatusCount.fade-out,
        #kategoriKriteriaCount.fade-out {
            opacity: 0;
        }

        #orderStatusCount.fade-in,
        #kategoriKriteriaCount.fade-in {
            opacity: 1;
            transition: opacity .5s ease;
        }

        .card-shadow {
            box-shadow: 0 6px 18px rgba(15, 23, 42, 0.06);
        }

        .truncate-name {
            max-width: 10rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .mobile-drawer {
            transition: transform .25s ease;
        }

        .focus-ring:focus {
            outline: 2px solid rgba(99, 102, 241, 0.5);
            outline-offset: 2px;
        }

        .page-btn {
            padding: .5rem .75rem;
        }
    </style>
</head>

<body class="bg-gray-100 font-sans text-gray-800 antialiased">
    <div class="min-h-screen">
        <header class="bg-white shadow-md p-3 flex items-center justify-between md:hidden">
            <div class="flex items-center gap-3">
                <button id="mobileMenuBtn" aria-controls="mobileDrawer" aria-expanded="false" aria-label="Buka menu" class="p-2 rounded focus-ring">
                    <i class="fas fa-bars text-xl text-indigo-600"></i>
                </button>
                <div class="text-lg font-semibold text-indigo-600">Admin Panel</div>
            </div>
            <div class="flex items-center gap-3">
                <div class="text-sm text-gray-700 font-medium">👋 <?= e($nama_user) ?></div>
                <button id="mobileLogoutBtn" aria-controls="logoutModal" class="p-2 rounded focus-ring bg-red-600 text-white text-sm">Logout</button>
            </div>
        </header>

        <div id="mobileDrawer" class="fixed inset-y-0 left-0 w-64 bg-white z-50 transform -translate-x-full mobile-drawer md:hidden" aria-hidden="true">
            <div class="p-5 border-b">
                <div class="text-xl font-bold text-indigo-600">Menu</div>
            </div>
            <nav class="p-4 space-y-2">
                <a href="admin_dashboard.php" class="flex items-center p-2 text-gray-700 hover:bg-indigo-50 rounded">
                    <i class="fas fa-home w-6"></i><span>Dashboard</span>
                </a>
                <a href="buat_task.php" class="flex items-center p-2 text-gray-700 hover:bg-indigo-50 rounded">
                    <i class="fas fa-tasks w-6"></i><span>Orders</span>
                </a>
                <a href="mekanik.php" class="flex items-center p-2 text-gray-700 hover:bg-indigo-50 rounded">
                    <i class="fas fa-users-cog w-6"></i><span>Mechanics</span>
                </a>
                <a href="standar_inspeksi.php" class="flex items-center p-2 text-gray-700 hover:bg-indigo-50 rounded">
                    <i class="fas fa-car w-6"></i><span>Standar Inspeksi</span>
                </a>
                <a href="pelanggan.php" class="flex items-center p-2 text-gray-700 hover:bg-indigo-50 rounded">
                    <i class="fas fa-user-friends w-6"></i><span>Manajemen Pelanggan</span>
                </a>
            </nav>
            <div class="p-4 border-t mt-auto">
                <a href="../auth/logout.php" class="block text-center bg-red-600 text-white px-4 py-2 rounded">Logout</a>
            </div>
        </div>

        <aside class="hidden md:flex w-64 bg-white shadow-lg fixed inset-y-0 left-0 flex-col justify-between z-40">
            <div>
                <div class="p-6 text-center">
                    <h2 class="text-2xl font-bold text-indigo-600">Admin Panel</h2>
                </div>
                <nav class="px-4 space-y-4">
                    <a href="admin_dashboard.php" class="flex items-center p-2 text-gray-700 hover:bg-indigo-100 rounded">
                        <i class="fas fa-home w-6"></i><span>Dashboard</span>
                    </a>
                    <a href="buat_task.php" class="flex items-center p-2 text-gray-700 hover:bg-indigo-100 rounded">
                        <i class="fas fa-tasks w-6"></i><span>Orders</span>
                    </a>
                    <a href="mekanik.php" class="flex items-center p-2 text-gray-700 hover:bg-indigo-100 rounded">
                        <i class="fas fa-users-cog w-6"></i><span>Mechanics</span>
                    </a>
                    <a href="standar_inspeksi.php" class="flex items-center p-2 text-gray-700 hover:bg-indigo-100 rounded">
                        <i class="fas fa-car w-6"></i><span>Standar Inspeksi</span>
                    </a>
                    <a href="pelanggan.php" class="flex items-center p-2 text-gray-700 hover:bg-indigo-100 rounded">
                        <i class="fas fa-user-friends w-6"></i><span>Manajemen Pelanggan</span>
                    </a>
                </nav>
            </div>
            <div class="p-4 border-t border-gray-200">
                <button id="logoutBtn" class="bg-red-600 text-white px-4 py-2 rounded hover:bg-red-700 w-full">Logout</button>
            </div>
        </aside>

        <main class="flex-1 p-4 md:p-8 md:ml-64">
            <header class="flex flex-wrap md:flex-nowrap items-center justify-between gap-4 mb-4">
                <div class="flex items-center gap-4">
                    <h1 class="text-2xl md:text-3xl font-semibold text-indigo-600">Dashboard</h1>
                    <div class="hidden md:block text-sm text-gray-600">
                        🕒 <?= e("$hari, $tanggal $bulan $tahun") ?> |
                        <span id="jamSekarang"></span>
                    </div>
                </div>

                <div class="w-full md:w-auto">
                    <form method="GET" class="flex items-center gap-2 w-full">
                        <input type="text" name="search" placeholder="Cari nama customer..." value="<?= e($search) ?>" class="px-3 py-2 border rounded w-full md:w-64 focus-ring" aria-label="Cari nama customer">
                        <div class="flex gap-2">
                            <button type="submit" class="bg-indigo-500 text-white px-4 py-2 rounded hover:bg-indigo-600">Cari</button>
                            <a href="history.php" class="hidden md:inline-block bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded text-sm shadow">📄 Histori Order</a>
                        </div>
                    </form>
                </div>
            </header>

            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4 mb-6">
                <div class="bg-white p-4 rounded-xl card-shadow hover:shadow-lg transition">
                    <h3 class="text-sm font-medium text-gray-600">Total Orders</h3>
                    <p class="text-lg md:text-2xl font-bold text-indigo-600" id="orderStatusCount">
                        Menunggu Disetujui: <?= e((string)($status_counts['Menunggu'] ?? 0)) ?>
                    </p>
                </div>

                <div class="bg-white p-4 rounded-xl card-shadow hover:shadow-lg transition">
                    <h3 class="text-sm font-medium text-gray-600">Jumlah Teknisi</h3>
                    <p class="text-lg md:text-2xl font-bold text-indigo-600"><?= e((string)($totals['teknisi'] ?? 0)) ?></p>
                </div>

                <div class="bg-white p-4 rounded-xl card-shadow hover:shadow-lg transition">
                    <h3 class="text-sm font-medium text-gray-600">Kriteria per Kategori</h3>
                    <p class="text-lg md:text-2xl font-bold text-indigo-600" id="kategoriKriteriaCount">
                        <?= e($kategori_kriteria[0]['nama_kategori'] ?? '-') ?>: <?= e((string)($kategori_kriteria[0]['total'] ?? 0)) ?>
                    </p>
                    <p class="text-sm text-gray-600 mt-2">
                        Total keseluruhan komponen:
                        <span class="text-lg font-bold text-indigo-600"><?= e((string)$total_keseluruhan) ?></span>
                    </p>
                </div>
            </div>

            <section class="bg-white p-4 rounded-xl card-shadow">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-xl font-semibold text-gray-700">Orders</h2>
                    <div class="flex items-center gap-2">
                        <a href="history.php" class="inline-flex items-center gap-2 md:hidden bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 rounded text-sm">📄 Histori</a>
                        <a href="buat_task.php" class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-2 rounded text-sm">+ Buat Order</a>
                    </div>
                </div>

                <div class="overflow-x-auto hidden md:block">
                    <table class="min-w-full divide-y divide-gray-200 text-sm text-left">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3">Customer</th>
                                <th class="px-4 py-3">Tanggal Order</th>
                                <th class="px-4 py-3">Tanggal Dikerjakan</th>
                                <th class="px-4 py-3">Merk</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3">Teknisi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($order_result)): ?>
                                <?php foreach ($order_result as $row): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3"><?= e($row['nama_customer'] ?? '-') ?></td>
                                        <td class="px-4 py-3"><?= e(formatTanggalIndonesia($row['tanggal_order'] ?? null)) ?></td>
                                        <td class="px-4 py-3"><?= $row['tanggal_dikerjakan'] ? e(formatTanggalIndonesia($row['tanggal_dikerjakan'])) : '-' ?></td>
                                        <td class="px-4 py-3"><?= e($row['merk'] ?? '-') ?></td>
                                        <td class="px-4 py-3">
                                            <?php
                                            $status = $row['status'] ?? '';
                                            $badgeClass = match ($status) {
                                                'Selesai'   => 'bg-green-100 text-green-700',
                                                'Disetujui' => 'bg-blue-300 text-blue-800',
                                                'Diproses'  => 'bg-yellow-100 text-yellow-800',
                                                'Menunggu'  => 'bg-gray-100 text-gray-600',
                                                'Gagal'     => 'bg-red-100 text-red-600',
                                                default     => 'bg-gray-200 text-gray-700'
                                            };
                                            ?>
                                            <span class="px-2 py-1 rounded-full text-xs font-semibold <?= e($badgeClass) ?>">
                                                <?= e($status ?: '-') ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-3"><?= e($row['teknisi_nama'] ?? '-') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center py-4 text-gray-500 italic">Tidak ada data order ditemukan</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Card list for small screens -->
                <div class="md:hidden space-y-3">
                    <?php if (!empty($order_result)): ?>
                        <?php foreach ($order_result as $row): ?>
                            <?php
                            $status = $row['status'] ?? '';
                            $badgeClass = match ($status) {
                                'Selesai'   => 'bg-green-100 text-green-700',
                                'Disetujui' => 'bg-blue-300 text-blue-800',
                                'Diproses'  => 'bg-yellow-100 text-yellow-800',
                                'Menunggu'  => 'bg-gray-100 text-gray-600',
                                'Gagal'     => 'bg-red-100 text-red-600',
                                default     => 'bg-gray-200 text-gray-700'
                            };
                            ?>
                            <article class="bg-white p-3 rounded-lg shadow-sm border">
                                <div class="flex justify-between items-start gap-2">
                                    <div class="flex-1 min-w-0">
                                        <div class="text-sm font-semibold text-gray-800 truncate"><?= e($row['nama_customer'] ?? '-') ?></div>
                                        <div class="text-xs text-gray-500 mt-1">
                                            <span class="block">Order: <?= e(formatTanggalIndonesia($row['tanggal_order'] ?? null)) ?></span>
                                            <span class="block">Dikerjakan: <?= $row['tanggal_dikerjakan'] ? e(formatTanggalIndonesia($row['tanggal_dikerjakan'])) : '-' ?></span>
                                        </div>
                                    </div>
                                    <div class="text-right flex-shrink-0">
                                        <div class="text-sm text-gray-500"><?= e($row['merk'] ?? '-') ?></div>
                                        <div class="mt-2">
                                            <span class="inline-block px-2 py-1 rounded-full text-xs font-semibold <?= e($badgeClass) ?>">
                                                <?= e($status ?: '-') ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <div class="mt-3 text-xs text-gray-600">Teknisi: <span class="font-medium"><?= e($row['teknisi_nama'] ?? '-') ?></span></div>
                            </article>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center py-4 text-gray-500 italic">Tidak ada data order ditemukan</div>
                    <?php endif; ?>
                </div>

                <!-- Pagination -->
                <div class="mt-4 flex flex-col sm:flex-row justify-between items-center gap-3">
                    <div class="text-sm text-gray-600">Halaman <?= e((string)$page) ?> dari <?= e((string)$totalPages) ?> (Total <?= e((string)$totalRows) ?> data)</div>
                    <div class="flex gap-2 flex-wrap">
                        <?php if ($page > 1): ?>
                            <a href="<?= e(pageUrl($search, $page - 1)) ?>" class="page-btn rounded bg-gray-200 hover:bg-gray-300 text-sm">← Prev</a>
                        <?php endif; ?>

                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                            <a href="<?= e(pageUrl($search, $i)) ?>" class="page-btn rounded <?= $i == $page ? 'bg-indigo-500 text-white' : 'bg-gray-200 hover:bg-gray-300' ?> text-sm">
                                <?= e((string)$i) ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($page < $totalPages): ?>
                            <a href="<?= e(pageUrl($search, $page + 1)) ?>" class="page-btn rounded bg-gray-200 hover:bg-gray-300 text-sm">Next →</a>
                        <?php endif; ?>
                    </div>
                </div>
            </section>
        </main>
    </div>

    <!-- Logout modal simplified: controlled from JS -->
    <div id="logoutModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
        <div class="bg-white p-6 rounded shadow-lg w-full max-w-sm text-center">
            <h2 class="text-lg font-semibold mb-4 text-gray-800">Konfirmasi Logout</h2>
            <p class="text-sm text-gray-600 mb-6">Apakah Anda yakin ingin logout dari sistem?</p>
            <div class="flex justify-center gap-4">
                <button id="cancelLogoutBtn" class="px-4 py-2 bg-gray-200 text-gray-700 rounded hover:bg-gray-300">Batal</button>
                <a id="confirmLogoutLink" href="../auth/logout.php" class="px-4 py-2 bg-red-600 text-white rounded hover:bg-red-700">Logout</a>
            </div>
        </div>
    </div>

    <script>
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        const mobileDrawer = document.getElementById('mobileDrawer');

        function openMobileDrawer() {
            mobileDrawer.style.transform = 'translateX(0)';
            mobileDrawer.setAttribute('aria-hidden', 'false');
            mobileMenuBtn.setAttribute('aria-expanded', 'true');
            document.body.style.overflow = 'hidden';
        }

        function closeMobileDrawer() {
            mobileDrawer.style.transform = 'translateX(-100%)';
            mobileDrawer.setAttribute('aria-hidden', 'true');
            mobileMenuBtn.setAttribute('aria-expanded', 'false');
            document.body.style.overflow = '';
        }
        mobileMenuBtn && mobileMenuBtn.addEventListener('click', function() {
            const expanded = this.getAttribute('aria-expanded') === 'true';
            if (expanded) closeMobileDrawer();
            else openMobileDrawer();
        });

        document.addEventListener('click', function(e) {
            if (!mobileDrawer.contains(e.target) && !mobileMenuBtn.contains(e.target) && window.innerWidth < 768) {
                closeMobileDrawer();
            }
        });

        const logoutBtn = document.getElementById('logoutBtn');
        const mobileLogoutBtn = document.getElementById('mobileLogoutBtn');
        const logoutModal = document.getElementById('logoutModal');
        const cancelLogoutBtn = document.getElementById('cancelLogoutBtn');

        function openLogoutModal() {
            logoutModal.classList.remove('hidden');
            logoutModal.classList.add('flex');
        }

        function closeLogoutModal() {
            logoutModal.classList.add('hidden');
            logoutModal.classList.remove('flex');
        }
        logoutBtn && logoutBtn.addEventListener('click', openLogoutModal);
        mobileLogoutBtn && mobileLogoutBtn.addEventListener('click', openLogoutModal);
        cancelLogoutBtn && cancelLogoutBtn.addEventListener('click', closeLogoutModal);

        const statusData = [{
                label: "Menunggu Disetujui",
                value: <?= json_encode($status_counts['Menunggu']) ?>
            },
            {
                label: "Proses Pengerjaan",
                value: <?= json_encode($status_counts['Diproses']) ?>
            },
            {
                label: "Selesai",
                value: <?= json_encode($status_counts['Selesai']) ?>
            }
        ];
        let index = 0;
        const el = document.getElementById('orderStatusCount');

        function animateNumberWithFade(label, value) {
            if (!el) return;
            el.classList.remove("fade-in");
            el.classList.add("fade-out");
            setTimeout(() => {
                el.textContent = `${label}: ${value}`;
                el.classList.remove("fade-out");
                el.classList.add("fade-in");
            }, 400);
        }
        if (statusData.length) {
            setInterval(() => {
                const {
                    label,
                    value
                } = statusData[index];
                animateNumberWithFade(label, value);
                index = (index + 1) % statusData.length;
            }, 3500);
        }

        const kategoriData = <?= json_encode($kategori_kriteria) ?>;
        let kategoriIndex = 0;
        const kategoriEl = document.getElementById('kategoriKriteriaCount');

        function animateKategori(label, value) {
            if (!kategoriEl) return;
            kategoriEl.classList.remove("fade-in");
            kategoriEl.classList.add("fade-out");
            setTimeout(() => {
                kategoriEl.textContent = `${label}: ${value}`;
                kategoriEl.classList.remove("fade-out");
                kategoriEl.classList.add("fade-in");
            }, 400);
        }
        if (Array.isArray(kategoriData) && kategoriData.length) {
            setInterval(() => {
                const {
                    nama_kategori,
                    total
                } = kategoriData[kategoriIndex];
                animateKategori(nama_kategori, total);
                kategoriIndex = (kategoriIndex + 1) % kategoriData.length;
            }, 3500);
        }

        function updateJam() {
            const now = new Date();
            const jam = String(now.getHours()).padStart(2, '0');
            const menit = String(now.getMinutes()).padStart(2, '0');
            const detik = String(now.getSeconds()).padStart(2, '0');

            const jamElem = document.getElementById('jamSekarang');
            if (jamElem) {
                jamElem.textContent = `${jam}:${menit}:${detik}`;
            }
        }

        updateJam();
        setInterval(updateJam, 1000);

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeMobileDrawer();
                closeLogoutModal();
            }
        });

        mobileDrawer.addEventListener('touchmove', function(e) {
            e.stopPropagation();
        }, {
            passive: true
        });
    </script>
</body>

</html>
