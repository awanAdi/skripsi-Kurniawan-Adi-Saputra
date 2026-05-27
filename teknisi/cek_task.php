<?php
session_start();

if (!isset($_SESSION['username']) || $_SESSION['role'] !== 'teknisi') {
    header("Location: ../auth/login.php");
    exit();
}

require_once '../includes/koneksi.php';

function getTechnicianInfo($conn, $id_teknisi) {
    $stmt = $conn->prepare("SELECT username, nama_lengkap FROM users WHERE id_user = ?");
    $stmt->bind_param("i", $id_teknisi);
    $stmt->execute();
    $result = $stmt->get_result();
    $teknisi = $result->fetch_assoc();
    $stmt->close();
    
    return [
        'username' => $teknisi['username'] ?? 'Teknisi',
        'nama_lengkap' => $teknisi['nama_lengkap'] ?? 'Teknisi'
    ];
}

function getOrdersForTechnician($conn, $id_teknisi) {
    $sql = "SELECT o.id_order, o.status, o.id_teknisi, o.tanggal_order,
                   u.nama_lengkap AS nama_customer, 
                   k.merk, k.nomor_polisi, k.tahun_produksi 
            FROM order_inspeksi o
            JOIN kendaraan k ON o.id_mobil = k.id_mobil
            JOIN users u ON o.id_pelanggan = u.id_user
            WHERE (
                (o.status = 'Disetujui' AND (o.id_teknisi IS NULL OR o.id_teknisi = ?))
                OR (o.status = 'Diproses' AND o.id_teknisi = ?)
            )
            ORDER BY o.id_order DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $id_teknisi, $id_teknisi);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $orders = [];
    while ($row = $result->fetch_assoc()) {
        $orders[] = $row;
    }
    
    $stmt->close();
    return $orders;
}

function getStatusStyling($status) {
    $styles = [
        'Menunggu' => [
            'badge' => 'bg-yellow-100 text-yellow-800 border border-yellow-300',
            'icon' => '⏳'
        ],
        'Disetujui' => [
            'badge' => 'bg-green-100 text-green-800 border border-green-300',
            'icon' => '✅'
        ],
        'Diproses' => [
            'badge' => 'bg-blue-100 text-blue-800 border border-blue-300',
            'icon' => '🔧'
        ],
        'Selesai' => [
            'badge' => 'bg-gray-100 text-gray-800 border border-gray-300',
            'icon' => '✓'
        ]
    ];
    
    return $styles[$status] ?? [
        'badge' => 'bg-gray-100 text-gray-700 border border-gray-300',
        'icon' => '•'
    ];
}

function getButtonState($order, $id_teknisi) {
    $state = [
        'disabled' => true,
        'label' => 'Mulai Inspeksi',
        'tooltip' => 'Menunggu persetujuan admin atau bukan teknisi yang ditugaskan'
    ];
    
    if ($order['status'] === 'Disetujui' && $order['id_teknisi'] == $id_teknisi) {
        $state['disabled'] = false;
        $state['label'] = 'Mulai Inspeksi';
        $state['tooltip'] = '';
    }
    
    if ($order['status'] === 'Diproses' && $order['id_teknisi'] == $id_teknisi) {
        $state['disabled'] = false;
        $state['label'] = 'Lanjutkan Inspeksi';
        $state['tooltip'] = '';
    }
    
    return $state;
}

function formatOrderDate($date) {
    if (empty($date)) return 'N/A';
    
    $timestamp = strtotime($date);
    return date('d M Y, H:i', $timestamp);
}

$id_teknisi = $_SESSION['id_user'];
$teknisiInfo = getTechnicianInfo($conn, $id_teknisi);
$orders = getOrdersForTechnician($conn, $id_teknisi);

$conn->close();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Task Inspeksi Teknisi</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        /* Smooth transitions */
        * {
            transition: background-color 0.2s ease, transform 0.2s ease;
        }
        
        /* Card hover effect */
        .order-card {
            transition: all 0.3s ease;
        }
        
        .order-card:hover {
            transform: translateY(-2px);
        }
        
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
        
        .order-card {
            animation: fadeIn 0.4s ease-out backwards;
        }
        
        .order-card:nth-child(1) { animation-delay: 0.05s; }
        .order-card:nth-child(2) { animation-delay: 0.1s; }
        .order-card:nth-child(3) { animation-delay: 0.15s; }
        .order-card:nth-child(4) { animation-delay: 0.2s; }
        .order-card:nth-child(5) { animation-delay: 0.25s; }
        .order-card:nth-child(6) { animation-delay: 0.3s; }
    </style>
</head>

<body class="bg-gradient-to-br from-gray-50 to-gray-100 min-h-screen">
    <div class="container mx-auto px-3 sm:px-4 md:px-6 py-4 sm:py-6 max-w-7xl">
        
        <!-- Header Section -->
        <div class="mb-6 sm:mb-8">
            <div class="bg-white rounded-xl shadow-md p-4 sm:p-6">
                <div class="flex flex-col md:flex-row md:justify-between md:items-center space-y-4 md:space-y-0">
                    <!-- Title -->
                    <div>
                        <h1 class="text-xl sm:text-2xl lg:text-3xl font-bold text-gray-800 mb-1">
                            Daftar Order Inspeksi
                        </h1>
                        <p class="text-sm sm:text-base text-gray-600">
                            Teknisi: <span class="font-semibold text-indigo-600"><?= htmlspecialchars($teknisiInfo['username']) ?></span>
                        </p>
                    </div>
                    
                    <!-- Action Buttons -->
                    <div class="flex flex-col sm:flex-row gap-2 sm:gap-3">
                        <a href="history_inspeksi.php" 
                           class="flex items-center justify-center gap-2 bg-white border-2 border-indigo-500 text-indigo-600 px-4 py-2 rounded-lg hover:bg-indigo-50 text-sm font-medium transition shadow-sm">
                            <span>📄</span>
                            <span>History Inspeksi</span>
                        </a>
                        <a href="teknisi_dashboard.php" 
                           class="flex items-center justify-center gap-2 bg-gray-200 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-300 text-sm font-medium transition">
                            <span>←</span>
                            <span>Kembali</span>
                        </a>
                    </div>
                </div>
                
                <!-- Stats Summary -->
                <div class="mt-4 pt-4 border-t border-gray-200">
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                        <div class="text-center">
                            <div class="text-2xl sm:text-3xl font-bold text-indigo-600">
                                <?= count($orders) ?>
                            </div>
                            <div class="text-xs sm:text-sm text-gray-600">Total Order</div>
                        </div>
                        <div class="text-center">
                            <div class="text-2xl sm:text-3xl font-bold text-green-600">
                                <?= count(array_filter($orders, fn($o) => $o['status'] === 'Disetujui')) ?>
                            </div>
                            <div class="text-xs sm:text-sm text-gray-600">Disetujui</div>
                        </div>
                        <div class="text-center col-span-2 sm:col-span-1">
                            <div class="text-2xl sm:text-3xl font-bold text-blue-600">
                                <?= count(array_filter($orders, fn($o) => $o['status'] === 'Diproses')) ?>
                            </div>
                            <div class="text-xs sm:text-sm text-gray-600">Sedang Diproses</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Orders Grid -->
        <?php if (count($orders) > 0): ?>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 sm:gap-6">
                <?php foreach ($orders as $order): ?>
                    <?php
                    $statusStyle = getStatusStyling($order['status']);
                    $buttonState = getButtonState($order, $id_teknisi);
                    ?>
                    
                    <div class="order-card bg-white rounded-xl shadow-md hover:shadow-xl border border-gray-200 overflow-hidden">
    
                        <div class="bg-gradient-to-r from-indigo-50 to-blue-50 px-4 sm:px-6 py-3 border-b border-gray-200">
                            <div class="flex justify-between items-center">
                                <span class="text-xs sm:text-sm text-gray-500 font-medium">
                                    Order #<?= $order['id_order'] ?>
                                </span>
                                <span class="inline-flex items-center gap-1 px-3 py-1 text-xs font-semibold rounded-full <?= $statusStyle['badge'] ?>">
                                    <span><?= $statusStyle['icon'] ?></span>
                                    <span><?= htmlspecialchars($order['status']) ?></span>
                                </span>
                            </div>
                        </div>
                        
                        <!-- Card Body -->
                        <div class="p-4 sm:p-6">
                            <div class="mb-4">
                                <h2 class="text-lg sm:text-xl font-bold text-gray-800 mb-2 flex items-start gap-2">
                                    <span class="text-2xl">🚗</span>
                                    <span>
                                        <?= htmlspecialchars($order['merk']) ?>
                                        <span class="text-sm sm:text-base font-normal text-gray-600">
                                            (<?= htmlspecialchars($order['nomor_polisi']) ?>)
                                        </span>
                                    </span>
                                </h2>
                                
                                <div class="space-y-1 text-sm sm:text-base text-gray-600 ml-10">
                                    <p>
                                        <span class="font-medium">Tahun:</span> 
                                        <span class="text-gray-800"><?= htmlspecialchars($order['tahun_produksi']) ?></span>
                                    </p>
                                    <p>
                                        <span class="font-medium">Customer:</span> 
                                        <span class="text-gray-800 font-semibold"><?= htmlspecialchars($order['nama_customer']) ?></span>
                                    </p>
                                    <?php if (!empty($order['tanggal_order'])): ?>
                                        <p>
                                            <span class="font-medium">Tanggal Order:</span> 
                                            <span class="text-gray-800"><?= formatOrderDate($order['tanggal_order']) ?></span>
                                        </p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <!-- Action Button -->
                            <div class="flex justify-end pt-4 border-t border-gray-200">
                                <?php if ($buttonState['disabled']): ?>
                                    <button class="w-full sm:w-auto bg-gray-200 text-gray-500 px-4 sm:px-6 py-2 sm:py-3 rounded-lg text-sm font-medium cursor-not-allowed flex items-center justify-center gap-2"
                                            title="<?= htmlspecialchars($buttonState['tooltip']) ?>" 
                                            disabled>
                                        <span>🔒</span>
                                        <span><?= htmlspecialchars($buttonState['label']) ?></span>
                                    </button>
                                <?php else: ?>
                                    <button onclick="openConfirmPopup(<?= $order['id_order'] ?>, '<?= htmlspecialchars($buttonState['label']) ?>')"
                                            class="w-full sm:w-auto bg-blue-600 text-white px-4 sm:px-6 py-2 sm:py-3 rounded-lg hover:bg-blue-700 text-sm font-medium transition shadow-sm hover:shadow-md flex items-center justify-center gap-2">
                                        <span><?= $buttonState['label'] === 'Lanjutkan Inspeksi' ? '▶️' : '🚀' ?></span>
                                        <span><?= htmlspecialchars($buttonState['label']) ?></span>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="bg-white rounded-xl shadow-md p-8 sm:p-12 text-center fade-in">
                <div class="text-6xl sm:text-7xl mb-4">📋</div>
                <h3 class="text-lg sm:text-xl font-semibold text-gray-800 mb-2">
                    Tidak Ada Order
                </h3>
                <p class="text-sm sm:text-base text-gray-600 mb-6">
                    Tidak ada order inspeksi yang tersedia saat ini.
                </p>
                <a href="teknisi_dashboard.php" 
                   class="inline-flex items-center gap-2 bg-indigo-600 text-white px-6 py-3 rounded-lg hover:bg-indigo-700 transition">
                    <span>←</span>
                    <span>Kembali ke Dashboard</span>
                </a>
            </div>
        <?php endif; ?>
    </div>

    <!-- Confirmation Modal -->
    <div id="confirmPopup" 
         class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50 px-4"
         onclick="closeConfirmPopup()">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-md p-6 sm:p-8 fade-in"
             onclick="event.stopPropagation()">
            <!-- Modal Header -->
            <div class="text-center mb-6">
                <div class="mx-auto w-16 h-16 bg-blue-100 rounded-full flex items-center justify-center mb-4">
                    <span class="text-3xl">❓</span>
                </div>
                <h2 class="text-xl sm:text-2xl font-bold text-gray-800 mb-2">
                    Konfirmasi Tindakan
                </h2>
                <p id="popupMessage" class="text-sm sm:text-base text-gray-600"></p>
            </div>
            
            <!-- Modal Actions -->
            <div class="flex flex-col sm:flex-row gap-3">
                <button onclick="closeConfirmPopup()" 
                        class="flex-1 px-4 py-3 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 font-medium transition">
                    Batal
                </button>
                <a id="confirmLink" 
                   href="#" 
                   class="flex-1 px-4 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-center font-medium transition shadow-sm hover:shadow-md">
                    Lanjutkan
                </a>
            </div>
        </div>
    </div>

    <script>
        function openConfirmPopup(idOrder, actionText) {
            const popup = document.getElementById('confirmPopup');
            const message = document.getElementById('popupMessage');
            const link = document.getElementById('confirmLink');
            
            message.textContent = `Yakin ingin ${actionText.toLowerCase()} untuk order #${idOrder}?`;
            link.href = `form_inspeksi.php?id_order=${idOrder}`;
            
            popup.classList.remove('hidden');
            popup.classList.add('flex');
       
            document.body.style.overflow = 'hidden';
        }

        function closeConfirmPopup() {
            const popup = document.getElementById('confirmPopup');
            popup.classList.add('hidden');
            popup.classList.remove('flex');
            
            // Restore body scroll
            document.body.style.overflow = 'auto';
        }

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeConfirmPopup();
            }
        });

        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.order-card');
            
            cards.forEach(card => {
                card.addEventListener('touchstart', function() {
                    this.style.transform = 'scale(0.98)';
                });
                
                card.addEventListener('touchend', function() {
                    this.style.transform = '';
                });
            });
        });
    </script>
</body>
</html>