<?php
session_start();

if (!isset($_SESSION['username']) || $_SESSION['role'] !== 'teknisi') {
    header("Location: ../auth/login.php");
    exit();
}

require_once '../includes/koneksi.php';

define('DEFAULT_LIMIT_MOBILE', 20);
define('DEFAULT_LIMIT_DESKTOP', 50);

function getSearchParameters() {
    return [
        'keyword' => isset($_GET['search']) ? trim($_GET['search']) : '',
        'page' => isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1,
        'per_page' => isset($_GET['per_page']) ? intval($_GET['per_page']) : DEFAULT_LIMIT_DESKTOP,
        'filter_date_from' => isset($_GET['date_from']) ? trim($_GET['date_from']) : '',
        'filter_date_to' => isset($_GET['date_to']) ? trim($_GET['date_to']) : ''
    ];
}

function buildInspectionQuery($conn, $params) {
    $sql = "
        SELECT 
            i.id_inspeksi,
            i.tanggal,
            i.kesimpulan,
            u.nama_lengkap AS nama_teknisi,
            p.nama_lengkap AS nama_customer,
            k.merk,
            k.nomor_polisi,
            k.tahun_produksi,
            o.id_order
        FROM inspeksi i
        JOIN order_inspeksi o ON i.id_order = o.id_order
        JOIN kendaraan k ON o.id_mobil = k.id_mobil
        JOIN users u ON i.id_teknisi = u.id_user
        JOIN users p ON o.id_pelanggan = p.id_user
        WHERE o.status = 'Selesai'
    ";

    if (!empty($params['keyword'])) {
        $safe = $conn->real_escape_string($params['keyword']);
        $sql .= " AND (
            u.nama_lengkap LIKE '%$safe%' OR 
            p.nama_lengkap LIKE '%$safe%' OR 
            k.merk LIKE '%$safe%' OR
            k.nomor_polisi LIKE '%$safe%' OR
            i.tanggal LIKE '%$safe%'
        )";
    }
        if (!empty($params['filter_date_from'])) {
        $dateFrom = $conn->real_escape_string($params['filter_date_from']);
        $sql .= " AND DATE(i.tanggal) >= '$dateFrom'";
    }
    
    if (!empty($params['filter_date_to'])) {
        $dateTo = $conn->real_escape_string($params['filter_date_to']);
        $sql .= " AND DATE(i.tanggal) <= '$dateTo'";
    }
    
    $sql .= " ORDER BY i.tanggal DESC";

    $per_page = intval($params['per_page']);
    $offset = ($params['page'] - 1) * $per_page;
    $sql .= " LIMIT $per_page OFFSET $offset";
    
    return $sql;
}

function formatInspectionDate($date) {
    if (empty($date)) return 'N/A';
    
    $timestamp = strtotime($date);
    $months = [
        1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr',
        5 => 'Mei', 6 => 'Jun', 7 => 'Jul', 8 => 'Agu',
        9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des'
    ];
    
    $day = date('d', $timestamp);
    $month = $months[(int)date('n', $timestamp)];
    $year = date('Y', $timestamp);
    
    return "$day $month $year";
}

function truncateText($text, $maxLength = 100) {
    if (mb_strlen($text) <= $maxLength) {
        return $text;
    }
    return mb_substr($text, 0, $maxLength) . '...';
}

function generatePagination($currentPage, $totalPages, $params) {
    if ($totalPages <= 1) return '';
    
    $html = '<div class="flex flex-wrap justify-center items-center gap-2">';
    
    if ($currentPage > 1) {
        $prevParams = $params;
        $prevParams['page'] = $currentPage - 1;
        $html .= '<a href="?' . http_build_query($prevParams) . '" class="px-4 py-2 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 text-sm font-medium transition">
            ← Prev
        </a>';
    }
    
    $start = max(1, $currentPage - 2);
    $end = min($totalPages, $currentPage + 2);
    
    if ($start > 1) {
        $firstParams = $params;
        $firstParams['page'] = 1;
        $html .= '<a href="?' . http_build_query($firstParams) . '" class="px-4 py-2 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 text-sm font-medium transition">1</a>';
        if ($start > 2) {
            $html .= '<span class="px-2 text-gray-500">...</span>';
        }
    }
    
    for ($i = $start; $i <= $end; $i++) {
        $pageParams = $params;
        $pageParams['page'] = $i;
        
        if ($i == $currentPage) {
            $html .= '<span class="px-4 py-2 bg-indigo-600 text-white border border-indigo-600 rounded-lg text-sm font-bold">' . $i . '</span>';
        } else {
            $html .= '<a href="?' . http_build_query($pageParams) . '" class="px-4 py-2 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 text-sm font-medium transition">' . $i . '</a>';
        }
    }
    
    if ($end < $totalPages) {
        if ($end < $totalPages - 1) {
            $html .= '<span class="px-2 text-gray-500">...</span>';
        }
        $lastParams = $params;
        $lastParams['page'] = $totalPages;
        $html .= '<a href="?' . http_build_query($lastParams) . '" class="px-4 py-2 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 text-sm font-medium transition">' . $totalPages . '</a>';
    }
    
    if ($currentPage < $totalPages) {
        $nextParams = $params;
        $nextParams['page'] = $currentPage + 1;
        $html .= '<a href="?' . http_build_query($nextParams) . '" class="px-4 py-2 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 text-sm font-medium transition">
            Next →
        </a>';
    }
    
    $html .= '</div>';
    return $html;
}

function getTotalInspectionCount($conn, $params) {
    $sql = "
        SELECT COUNT(*) as total
        FROM inspeksi i
        JOIN order_inspeksi o ON i.id_order = o.id_order
        JOIN kendaraan k ON o.id_mobil = k.id_mobil
        JOIN users u ON i.id_teknisi = u.id_user
        JOIN users p ON o.id_pelanggan = p.id_user
        WHERE o.status = 'Selesai'
    ";
    
    if (!empty($params['keyword'])) {
        $safe = $conn->real_escape_string($params['keyword']);
        $sql .= " AND (
            u.nama_lengkap LIKE '%$safe%' OR 
            p.nama_lengkap LIKE '%$safe%' OR 
            k.merk LIKE '%$safe%' OR
            k.nomor_polisi LIKE '%$safe%' OR
            i.tanggal LIKE '%$safe%'
        )";
    }
    
    if (!empty($params['filter_date_from'])) {
        $dateFrom = $conn->real_escape_string($params['filter_date_from']);
        $sql .= " AND DATE(i.tanggal) >= '$dateFrom'";
    }
    
    if (!empty($params['filter_date_to'])) {
        $dateTo = $conn->real_escape_string($params['filter_date_to']);
        $sql .= " AND DATE(i.tanggal) <= '$dateTo'";
    }
    
    $result = $conn->query($sql);
    $row = $result->fetch_assoc();
    return (int)$row['total'];
}

$params = getSearchParameters();
$sql = buildInspectionQuery($conn, $params);
$data = $conn->query($sql);
$totalCount = getTotalInspectionCount($conn, $params);

$totalPages = ceil($totalCount / $params['per_page']);
$currentPage = $params['page'];

$inspections = [];
while ($row = $data->fetch_assoc()) {
    $inspections[] = $row;
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Riwayat Inspeksi</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        * {
            transition: background-color 0.2s ease, transform 0.2s ease;
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
        
        .inspection-card {
            animation: fadeInUp 0.4s ease-out backwards;
        }
        
        .inspection-card:nth-child(1) { animation-delay: 0.05s; }
        .inspection-card:nth-child(2) { animation-delay: 0.1s; }
        .inspection-card:nth-child(3) { animation-delay: 0.15s; }
        .inspection-card:nth-child(4) { animation-delay: 0.2s; }
        .inspection-card:nth-child(5) { animation-delay: 0.25s; }
        .inspection-card:nth-child(6) { animation-delay: 0.3s; }
        
        .inspection-card:hover {
            transform: translateY(-4px);
        }
        .pagination-container {
            scroll-behavior: smooth;
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
    <div class="container mx-auto px-3 sm:px-4 md:px-6 py-4 sm:py-6 max-w-7xl">
        <div class="mb-6 sm:mb-8">
            <div class="bg-white rounded-xl shadow-md p-4 sm:p-6">
                <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-4">
                    <div>
                        <h1 class="text-xl sm:text-2xl lg:text-3xl font-bold text-gray-800 mb-2">
                            📋 Riwayat Inspeksi
                        </h1>
                        <p class="text-sm sm:text-base text-gray-600">
                            <?php if ($totalCount > 0): ?>
                                Menampilkan <span class="font-semibold text-indigo-600"><?= (($currentPage - 1) * $params['per_page']) + 1 ?>-<?= min($currentPage * $params['per_page'], $totalCount) ?></span> 
                                dari <span class="font-semibold text-indigo-600"><?= $totalCount ?></span> inspeksi
                            <?php else: ?>
                                Tidak ada inspeksi
                            <?php endif; ?>
                            <?php if (!empty($params['keyword']) || !empty($params['filter_date_from']) || !empty($params['filter_date_to'])): ?>
                                <span class="text-gray-500">(filtered)</span>
                            <?php endif; ?>
                        </p>
                    </div>
                    
                    <a href="teknisi_dashboard.php" 
                       class="no-print flex items-center justify-center gap-2 bg-gray-200 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-300 text-sm font-medium transition w-full md:w-auto">
                        <span>←</span>
                        <span>Kembali</span>
                    </a>
                </div>
            </div>
        </div>
        <div class="no-print bg-white rounded-xl shadow-md p-4 sm:p-6 mb-6">
            <form method="get" id="searchForm">
                <input type="hidden" name="per_page" id="perPageInput" value="<?= $params['per_page'] ?>">
                
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            🔍 Pencarian
                        </label>
                        <div class="flex flex-col sm:flex-row gap-2">
                            <input type="text"
                                   name="search"
                                   placeholder="Cari nama teknisi, customer, mobil, atau no. polisi..."
                                   value="<?= htmlspecialchars($params['keyword']) ?>"
                                   class="flex-1 border border-gray-300 rounded-lg px-4 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent text-sm sm:text-base">
                            <button type="submit" 
                                    class="bg-indigo-600 text-white px-6 py-2 rounded-lg hover:bg-indigo-700 font-medium transition shadow-sm hover:shadow-md flex items-center justify-center gap-2">
                                <span>🔍</span>
                                <span>Cari</span>
                            </button>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                📅 Dari Tanggal
                            </label>
                            <input type="date"
                                   name="date_from"
                                   value="<?= htmlspecialchars($params['filter_date_from']) ?>"
                                   class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent text-sm sm:text-base">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                📅 Sampai Tanggal
                            </label>
                            <input type="date"
                                   name="date_to"
                                   value="<?= htmlspecialchars($params['filter_date_to']) ?>"
                                   class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent text-sm sm:text-base">
                        </div>
                    </div>
                    
                    <?php if (!empty($params['keyword']) || !empty($params['filter_date_from']) || !empty($params['filter_date_to'])): ?>
                        <div class="flex justify-end">
                            <a href="history_inspeksi.php" 
                               class="text-sm text-indigo-600 hover:text-indigo-800 font-medium flex items-center gap-1">
                                <span>✕</span>
                                <span>Reset Filter</span>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Results Section -->
        <?php if (count($inspections) > 0): ?>
            <div class="no-print mb-4 text-sm text-gray-600 flex justify-between items-center">
                <span>Menampilkan <?= count($inspections) ?> dari <?= $totalCount ?> hasil</span>
                <?php if (count($inspections) >= $params['limit']): ?>
                    <span class="text-yellow-600">⚠️ Hasil dibatasi. Gunakan filter untuk hasil lebih spesifik.</span>
                <?php endif; ?>
            </div>
            
            <!-- Cards Grid -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-6">
                <?php foreach ($inspections as $row): ?>
                    <div class="inspection-card bg-white rounded-xl shadow-md hover:shadow-xl border border-gray-200 overflow-hidden">
                        <div class="bg-gradient-to-r from-indigo-50 to-blue-50 px-4 sm:px-5 py-3 border-b border-gray-200">
                            <div class="flex justify-between items-start">
                                <div class="flex-1">
                                    <p class="text-xs text-gray-500 mb-1">ID: #<?= $row['id_inspeksi'] ?></p>
                                    <p class="text-xs sm:text-sm text-gray-700 font-medium">
                                        📅 <?= formatInspectionDate($row['tanggal']) ?>
                                    </p>
                                </div>
                                <span class="inline-flex items-center px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800 border border-green-300">
                                    ✓ Selesai
                                </span>
                            </div>
                        </div>
                        
                        <!-- Card Body -->
                        <div class="p-4 sm:p-5">
                            <div class="mb-4">
                                <h2 class="text-base sm:text-lg font-bold text-gray-800 mb-2 flex items-start gap-2">
                                    <span class="text-xl">🚗</span>
                                    <span class="flex-1">
                                        <?= htmlspecialchars($row['merk']) ?>
                                        <span class="block text-sm font-normal text-gray-600 mt-1">
                                            <?= htmlspecialchars($row['nomor_polisi']) ?>
                                        </span>
                                    </span>
                                </h2>
                                
                                <div class="space-y-1 text-xs sm:text-sm text-gray-600">
                                    <p class="flex items-center gap-2">
                                        <span class="font-medium min-w-20">Tahun:</span>
                                        <span class="text-gray-800"><?= htmlspecialchars($row['tahun_produksi']) ?></span>
                                    </p>
                                    <p class="flex items-center gap-2">
                                        <span class="font-medium min-w-20">Customer:</span>
                                        <span class="text-gray-800 font-semibold"><?= htmlspecialchars($row['nama_customer']) ?></span>
                                    </p>
                                    <p class="flex items-center gap-2">
                                        <span class="font-medium min-w-20">Teknisi:</span>
                                        <span class="text-gray-800"><?= htmlspecialchars($row['nama_teknisi']) ?></span>
                                    </p>
                                </div>
                            </div>
                            
                            <div class="mb-4 p-3 bg-gray-50 rounded-lg border border-gray-200">
                                <p class="text-xs font-medium text-gray-600 mb-1">Kesimpulan:</p>
                                <p class="text-xs sm:text-sm text-gray-800 italic" title="<?= htmlspecialchars($row['kesimpulan']) ?>">
                                    <?= htmlspecialchars(truncateText($row['kesimpulan'], 80)) ?>
                                </p>
                            </div>
                            
                            <div class="flex flex-col sm:flex-row gap-2">
                                <form action="review_pdf.php" method="post" class="flex-1">
                                    <input type="hidden" name="id" value="<?= $row['id_inspeksi'] ?>">
                                    <button type="submit"
                                            class="w-full bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 text-xs sm:text-sm font-medium transition shadow-sm hover:shadow-md flex items-center justify-center gap-2">
                                        <span>👁️</span>
                                        <span>Lihat Hasil</span>
                                    </button>
                                </form>
                                
                                <form action="../admin/cetak_detail_order.php" method="post" target="_blank" class="flex-1">
                                    <input type="hidden" name="id" value="<?= $row['id_order'] ?>">
                                    <input type="hidden" name="print" value="1">
                                    <button type="submit"
                                            class="w-full bg-red-600 text-white px-4 py-2 rounded-lg hover:bg-red-700 text-xs sm:text-sm font-medium transition shadow-sm hover:shadow-md flex items-center justify-center gap-2">
                                        <svg xmlns="http://www.w3.org/2000/svg" 
                                             fill="currentColor"
                                             viewBox="0 0 24 24" 
                                             class="w-4 h-4">
                                            <path d="M19 8H5c-1.66 0-3 1.34-3 3v6h4v4h12v-4h4v-6c0-1.66-1.34-3-3-3zm-3 11H8v-5h8v5zm3-7c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1zm-1-9H6v4h12V3z"/>
                                        </svg>
                                        <span>PDF</span>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <?php if (count($inspections) >= $params['limit']): ?>
                <div class="no-print mt-8 text-center">
                    <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 inline-block">
                        <p class="text-sm text-yellow-800 mb-3">
                            ⚠️ Menampilkan <?= $params['limit'] ?> hasil teratas. Masih ada lebih banyak data.
                        </p>
                        <button onclick="loadMore()" 
                                class="bg-yellow-600 text-white px-6 py-2 rounded-lg hover:bg-yellow-700 text-sm font-medium transition">
                            Muat Lebih Banyak
                        </button>
                    </div>
                </div>
            <?php endif; ?>
            
        <?php else: ?>
            <div class="bg-white rounded-xl shadow-md p-8 sm:p-12 text-center">
                <div class="text-6xl sm:text-7xl mb-4">🔍</div>
                <h3 class="text-lg sm:text-xl font-semibold text-gray-800 mb-2">
                    Tidak Ada Data
                </h3>
                <p class="text-sm sm:text-base text-gray-600 mb-6">
                    <?php if (!empty($params['keyword']) || !empty($params['filter_date_from']) || !empty($params['filter_date_to'])): ?>
                        Tidak ada inspeksi yang sesuai dengan filter Anda.
                    <?php else: ?>
                        Belum ada inspeksi yang selesai.
                    <?php endif; ?>
                </p>
                <?php if (!empty($params['keyword']) || !empty($params['filter_date_from']) || !empty($params['filter_date_to'])): ?>
                    <a href="history_inspeksi.php" 
                       class="inline-flex items-center gap-2 bg-indigo-600 text-white px-6 py-3 rounded-lg hover:bg-indigo-700 transition">
                        <span>✕</span>
                        <span>Reset Filter</span>
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <script>
        document.addEventListener("DOMContentLoaded", function() {
            const width = window.innerWidth;
            const limitInput = document.getElementById("limitInput");
            const currentLimit = parseInt(limitInput.value);
            
            const shouldBeMobile = width < 768;
            const expectedLimit = shouldBeMobile ? <?= DEFAULT_LIMIT_MOBILE ?> : <?= DEFAULT_LIMIT_DESKTOP ?>;
            
            if (currentLimit !== expectedLimit && !hasSearchParams()) {
                limitInput.value = expectedLimit;
                document.getElementById('searchForm').submit();
            }
        });

        function hasSearchParams() {
            const urlParams = new URLSearchParams(window.location.search);
            return urlParams.has('search') || urlParams.has('date_from') || urlParams.has('date_to');
        }
        
        function loadMore() {
            const limitInput = document.getElementById("limitInput");
            const currentLimit = parseInt(limitInput.value);
            limitInput.value = currentLimit + 20;
            document.getElementById('searchForm').submit();
        }

        if ('ontouchstart' in window) {
            document.addEventListener('DOMContentLoaded', function() {
                const cards = document.querySelectorAll('.inspection-card');
                
                cards.forEach(card => {
                    card.addEventListener('touchstart', function() {
                        this.style.transform = 'scale(0.98)';
                    });
                    
                    card.addEventListener('touchend', function() {
                        this.style.transform = '';
                    });
                });
            });
        }

        <?php if (isset($_GET['print'])): ?>
        window.onload = function() {
            window.print();
        };
        <?php endif; ?>
    </script>
</body>
</html>