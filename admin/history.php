<?php
session_start();
require_once '../includes/koneksi.php';

if (!isset($_SESSION['username']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit;
}
date_default_timezone_set('Asia/Jakarta');
$waktu_awal = time() * 1000;

function format_tanggal_indo($timestamp = null, $show_time = true)
{
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

    $timestamp = $timestamp ?? time();
    $hari = $hari_indo[date("l", $timestamp)];
    $tanggal = date("j", $timestamp);
    $bulan = $bulan_indo[date("F", $timestamp)];
    $tahun = date("Y", $timestamp);

    return $show_time ? "$hari, $tanggal $bulan $tahun"
        : "$hari, $tanggal $bulan $tahun";
}

$per_page = 10;
$page = isset($_GET['page']) ? max((int)$_GET['page'], 1) : 1;
$offset = ($page - 1) * $per_page;

$search = $_GET['search'] ?? '';
$search_query = "";
if (!empty($search)) {
    $search_query = "AND u.nama_lengkap LIKE ?";
}

// Hitung total
$count_sql = "
    SELECT COUNT(*) 
    FROM order_inspeksi oi
    JOIN users u ON oi.id_pelanggan = u.id_user
    WHERE oi.status = 'Selesai' $search_query
";
$count_stmt = $conn->prepare($count_sql);
if ($count_stmt === false) {
    die('Prepare failed: ' . htmlspecialchars($conn->error));
}
if (!empty($search)) {
    $search_term = "%$search%";
    $count_stmt->bind_param("s", $search_term);
}
$count_stmt->execute();
$count_stmt->bind_result($total_data);
$count_stmt->fetch();
$count_stmt->close();

$total_pages = ($total_data > 0) ? ceil($total_data / $per_page) : 1;

// Ambil data
$data_sql = "
    SELECT 
        oi.id_order, u.nama_lengkap, 
        k.merk, k.model, k.nomor_polisi, k.alamat,
        oi.tanggal_order, i.tanggal AS tanggal_selesai,
        oi.status, t.nama_lengkap AS nama_teknisi
    FROM order_inspeksi oi
    JOIN users u ON oi.id_pelanggan = u.id_user
    JOIN kendaraan k ON oi.id_mobil = k.id_mobil
    LEFT JOIN inspeksi i ON oi.id_order = i.id_order
    LEFT JOIN users t ON oi.id_teknisi = t.id_user
    WHERE oi.status IN ('Selesai', 'Diproses') $search_query
    ORDER BY i.tanggal DESC
    LIMIT ? OFFSET ?
";

$data_stmt = $conn->prepare($data_sql);
if ($data_stmt === false) {
    die('Prepare failed: ' . htmlspecialchars($conn->error));
}

if (!empty($search)) {
    $data_stmt->bind_param("sii", $search_term, $per_page, $offset);
} else {
    $data_stmt->bind_param("ii", $per_page, $offset);
}
$data_stmt->execute();
$data_result = $data_stmt->get_result();
$rows = $data_result->fetch_all(MYSQLI_ASSOC);
$data_stmt->close();
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Histori Order Selesai</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        /* Modal animation kelas utilitas untuk transisi */
        .modal-enter {
            opacity: 0;
            transform: translateY(8px) scale(0.98);
        }

        .modal-enter-active {
            opacity: 1;
            transform: translateY(0) scale(1);
            transition: opacity 0.18s ease, transform 0.18s ease;
        }

        .modal-exit {
            opacity: 1;
            transform: translateY(0) scale(1);
        }

        .modal-exit-active {
            opacity: 0;
            transform: translateY(8px) scale(0.98);
            transition: opacity 0.18s ease, transform 0.18s ease;
        }

        .line-clamp-2 {
            display: -webkit-box;
            -webkit-box-orient: vertical;
            -webkit-line-clamp: 2;
            overflow: hidden;
            line-clamp: 2;
        }
    </style>
</head>

<body class="bg-gray-50 min-h-screen">
    <?php include 'admin_sidebar.php'; ?>

    <?php if (isset($_GET['hapus']) && $_GET['hapus'] === 'sukses'): ?>
        <div id="notif" class="fixed top-4 inset-x-0 flex justify-center z-50 transition-transform duration-700 transform -translate-y-16">
            <div class="bg-green-500 text-white px-6 py-3 rounded shadow-md text-center font-semibold">
                Data sudah berhasil dihapus!!!
            </div>
        </div>
        <script>
            window.addEventListener('DOMContentLoaded', () => {
                const notif = document.getElementById('notif');
                notif.classList.remove('-translate-y-16');
                setTimeout(() => {
                    notif.classList.add('-translate-y-16');
                }, 3000);
            });
        </script>
    <?php endif; ?>

    <main class="p-4 sm:p-6 max-w-6xl mx-auto">
        <header class="mb-6">
            <div class="bg-white shadow rounded-md p-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div class="flex-1">
                    <h1 class="text-xl sm:text-2xl font-semibold text-indigo-600">Histori Inspeksi Selesai</h1>
                    <p class="text-sm text-gray-600 mt-1">
                        🕒 <span id="waktuRealtime" class="font-medium"></span>
                        <span class="hidden sm:inline">|</span>
                        <span class="ml-0 sm:ml-2">👋 Halo, <?= htmlspecialchars($_SESSION['username']) ?></span>
                    </p>
                </div>

                <div class="w-full sm:w-auto flex flex-col sm:flex-row items-stretch sm:items-center gap-2">
                    <form method="GET" class="flex-1 sm:flex-none w-full sm:w-auto">
                        <label for="search" class="sr-only">Cari pelanggan</label>
                        <div class="flex gap-2">
                            <input id="search" type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                                placeholder="Cari nama pelanggan..." class="px-3 py-2 border rounded-l-md w-full sm:w-64 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300">
                            <button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded-r-md text-sm hover:bg-indigo-700">Cari</button>
                        </div>
                    </form>

                    <div class="flex gap-2">
                        <button onclick="history.back()"
                            class="bg-gray-100 hover:bg-gray-200 text-gray-800 px-3 py-2 rounded border border-gray-300 shadow text-sm">
                            ← Dashboard
                        </button>
                    </div>
                </div>
            </div>
        </header>

        <div class="hidden sm:block bg-white shadow rounded-md overflow-x-auto">
            <table class="min-w-full text-sm text-left">
                <thead class="bg-indigo-50 text-indigo-800">
                    <tr>
                        <th class="p-3 border-b">No</th>
                        <th class="p-3 border-b">Pelanggan</th>
                        <th class="p-3 border-b">Mobil</th>
                        <th class="p-3 border-b">No. Polisi</th>
                        <th class="p-3 border-b">Alamat</th>
                        <th class="p-3 border-b">Tanggal Masuk</th>
                        <th class="p-3 border-b">Tanggal Selesai</th>
                        <th class="p-3 border-b">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $no = $offset + 1;
                    foreach ($rows as $row): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="p-3 border-b align-top"><?= $no++ ?></td>
                            <td class="p-3 border-b align-top"><?= htmlspecialchars($row['nama_lengkap']) ?></td>
                            <td class="p-3 border-b align-top"><?= htmlspecialchars($row['merk'] . ' ' . $row['model']) ?></td>
                            <td class="p-3 border-b align-top"><?= htmlspecialchars($row['nomor_polisi']) ?></td>
                            <td class="p-3 border-b align-top">
                                <?= htmlspecialchars($row['alamat']) ?>
                                <?php
                                $maps_query = trim($row['alamat']);
                                if (!empty($maps_query)) {
                                    $maps_url = 'https://www.google.com/maps/search/?api=1&query=' . urlencode($maps_query);
                                    echo ' <a href="' . $maps_url . '" target="_blank" rel="noopener noreferrer" class="text-indigo-600 hover:underline text-xs">Lihat di Maps</a>';
                                }
                                ?>
                            </td>
                            <td class="p-3 border-b align-top"><?= format_tanggal_indo(strtotime($row['tanggal_order'])) ?></td>
                            <td class="p-3 border-b align-top">
                                <?php if ($row['status'] === 'Diproses'): ?>
                                    <span class="text-yellow-600 font-medium text-sm">
                                        Sedang Dikerjakan Oleh <?= htmlspecialchars($row['nama_teknisi'] ?? 'Teknisi Belum Ditentukan') ?>
                                    </span>
                                <?php else: ?>
                                    <?= format_tanggal_indo(strtotime($row['tanggal_selesai'])) ?>
                                <?php endif; ?>
                            </td>
                            <td class="p-3 border-b align-top">
                                <div class="flex flex-wrap gap-2">
                                    <?php if ($row['status'] === 'Selesai'): ?>
                                        <a href="detail_order.php?id=<?= $row['id_order'] ?>"
                                            class="px-3 py-1 bg-blue-500 text-white rounded hover:bg-blue-600 text-xs">Detail</a>
                                        <a href="cetak_detail_order.php?id=<?= $row['id_order'] ?>"
                                            class="px-3 py-1 bg-green-500 text-white rounded hover:bg-green-600 text-xs btn-cetak"
                                            target="_blank" rel="noopener noreferrer">Cetak</a>
                                    <?php endif; ?>
                                    <button type="button" onclick="showDeleteModal(<?= $row['id_order'] ?>)"
                                        class="px-3 py-1 bg-red-500 text-white rounded hover:bg-red-600 text-xs">Hapus</button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (count($rows) === 0): ?>
                        <tr>
                            <td colspan="8" class="p-4 text-center text-gray-500">Data tidak ditemukan.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="sm:hidden space-y-3">
            <?php $noMobile = $offset + 1;
            foreach ($rows as $row): ?>
                <article class="bg-white rounded-md shadow p-4">
                    <div class="flex justify-between items-start gap-3">
                        <div class="flex-1 min-w-0">
                            <h3 class="font-semibold text-gray-800"><?= $noMobile++ ?>. <?= htmlspecialchars($row['nama_lengkap']) ?></h3>
                            <p class="text-sm text-gray-600 mt-1"><?= htmlspecialchars($row['merk'] . ' ' . $row['model']) ?> — <span class="font-medium"><?= htmlspecialchars($row['nomor_polisi']) ?></span></p>
                            <p class="text-sm text-gray-500 mt-2 line-clamp-2"><?= htmlspecialchars($row['alamat']) ?></p>
                            <?php
                            $maps_query = trim($row['alamat']);
                            if (!empty($maps_query)) {
                                $maps_url = 'https://www.google.com/maps/search/?api=1&query=' . urlencode($maps_query);
                                echo '<p class="mt-2"><a href="' . $maps_url . '" target="_blank" rel="noopener noreferrer" class="text-indigo-600 text-sm hover:underline">Buka di Google Maps</a></p>';
                            }
                            ?>
                        </div>
                        <div class="text-right">
                            <p class="text-xs text-gray-500">Masuk</p>
                            <p class="text-sm font-medium"><?= format_tanggal_indo(strtotime($row['tanggal_order'])) ?></p>
                        </div>
                    </div>

                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <?php if ($row['status'] === 'Selesai'): ?>
                            <a href="detail_order.php?id=<?= $row['id_order'] ?>"
                                class="flex-1 px-3 py-2 bg-blue-500 text-white rounded text-center text-sm">Detail</a>
                            <a href="cetak_detail_order.php?id=<?= $row['id_order'] ?>"
                                class="px-3 py-2 bg-green-500 text-white rounded text-sm btn-cetak" target="_blank" rel="noopener noreferrer">Cetak</a>
                        <?php else: ?>
                            <span class="px-3 py-2 bg-yellow-100 text-yellow-800 rounded text-sm">Sedang Dikerjakan</span>
                        <?php endif; ?>

                        <button type="button" onclick="showDeleteModal(<?= $row['id_order'] ?>)"
                            class="px-3 py-2 bg-red-600 text-white rounded text-sm">Hapus</button>
                    </div>
                </article>
            <?php endforeach; ?>

            <?php if (count($rows) === 0): ?>
                <div class="text-center text-gray-500 py-6">Data tidak ditemukan.</div>
            <?php endif; ?>
        </div>

        <nav class="mt-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div class="flex-1 text-sm text-gray-600">
                Menampilkan halaman <span class="font-medium"><?= $page ?></span> dari <span class="font-medium"><?= $total_pages ?></span>
            </div>
            <div class="flex gap-1 justify-center sm:justify-end flex-wrap">
                <?php
                $prev = max(1, $page - 1);
                $next = min($total_pages, $page + 1);
                ?>
                <a href="?page=<?= $prev ?>&search=<?= urlencode($search) ?>"
                    class="px-3 py-1 border rounded <?= $page == 1 ? 'bg-gray-100 text-gray-400 pointer-events-none' : 'bg-white text-gray-700 hover:bg-gray-50' ?>">Prev</a>

                <?php
                // tampilkan range halaman ringkas (maks 7)
                $start = max(1, $page - 3);
                $end = min($total_pages, $page + 3);
                for ($i = $start; $i <= $end; $i++): ?>
                    <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>"
                        class="px-3 py-1 border rounded <?= $i == $page ? 'bg-indigo-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-50' ?>">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>

                <a href="?page=<?= $next ?>&search=<?= urlencode($search) ?>"
                    class="px-3 py-1 border rounded <?= $page == $total_pages ? 'bg-gray-100 text-gray-400 pointer-events-none' : 'bg-white text-gray-700 hover:bg-gray-50' ?>">Next</a>
            </div>
        </nav>
    </main>

    <div id="deleteModal" class="fixed inset-0 bg-black bg-opacity-40 hidden items-center justify-center z-50 px-4">
        <div class="bg-white p-4 rounded-md shadow-lg w-full max-w-md" role="dialog" aria-modal="true" aria-labelledby="deleteTitle">
            <h2 id="deleteTitle" class="text-lg font-semibold text-gray-800">Konfirmasi Penghapusan</h2>
            <p class="text-sm text-gray-600 mt-1">Pastikan data yang dihapus sudah sesuai.</p>
            <p id="countdownText" class="text-sm text-red-600 font-medium my-2"></p>
            <form method="post" action="hapus_order.php" class="mt-2">
                <input type="hidden" name="id_order" id="delete_order_id">
                <div class="flex gap-3 justify-end mt-4">
                    <button type="button" onclick="hideDeleteModal()" class="px-3 py-2 bg-gray-100 text-gray-700 rounded hover:bg-gray-200">Batal</button>
                    <button type="submit" id="confirmDeleteBtn" class="px-3 py-2 bg-red-600 text-white rounded hover:bg-red-700" disabled>Hapus</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        let countdown;

        function showDeleteModal(id_order) {
            const modal = document.getElementById("deleteModal");
            const btn = document.getElementById("confirmDeleteBtn");
            const input = document.getElementById("delete_order_id");
            const timerText = document.getElementById("countdownText");

            input.value = id_order;
            btn.disabled = true;
            let seconds = 4;
            timerText.innerText = `Tombol akan aktif dalam ${seconds} detik...`;

            modal.classList.remove("hidden");
            modal.classList.add("flex", "modal-enter");
            setTimeout(() => {
                modal.classList.add("modal-enter-active");
                modal.classList.remove("modal-enter");
            }, 10);

            setTimeout(() => {
                modal.querySelector('button[type="button"]').focus();
            }, 200);

            countdown = setInterval(() => {
                seconds--;
                if (seconds > 0) {
                    timerText.innerText = `Tombol akan aktif dalam ${seconds} detik...`;
                } else {
                    clearInterval(countdown);
                    timerText.innerText = "";
                    btn.disabled = false;
                    btn.focus();
                }
            }, 1000);
        }

        function hideDeleteModal() {
            const modal = document.getElementById("deleteModal");
            modal.classList.add("modal-exit");
            setTimeout(() => {
                modal.classList.add("modal-exit-active");
                modal.classList.remove("modal-exit");
            }, 10);

            setTimeout(() => {
                modal.classList.add("hidden");
                modal.classList.remove("flex", "modal-exit-active");
            }, 220);

            clearInterval(countdown);
        }

        const hariIndo = ["Minggu", "Senin", "Selasa", "Rabu", "Kamis", "Jumat", "Sabtu"];
        const bulanIndo = ["Januari", "Februari", "Maret", "April", "Mei", "Juni", "Juli", "Agustus", "September", "Oktober", "November", "Desember"];
        let waktuServer = new Date(<?= $waktu_awal ?>);

        function updateWaktu() {
            let hari = hariIndo[waktuServer.getDay()];
            let tanggal = waktuServer.getDate();
            let bulan = bulanIndo[waktuServer.getMonth()];
            let tahun = waktuServer.getFullYear();

            let jam = String(waktuServer.getHours()).padStart(2, '0');
            let menit = String(waktuServer.getMinutes()).padStart(2, '0');
            let detik = String(waktuServer.getSeconds()).padStart(2, '0');

            document.getElementById("waktuRealtime").textContent =
                `${hari}, ${tanggal} ${bulan} ${tahun} | ${jam}:${menit}:${detik} WIB`;

            waktuServer.setSeconds(waktuServer.getSeconds() + 1);
        }

        setInterval(updateWaktu, 1000);
        updateWaktu();

        document.addEventListener('click', function(e) {
            const btn = e.target.closest('.btn-cetak');
            if (!btn) return;

            if (btn.dataset.disabled === '1') {
                e.preventDefault();
                return;
            }

            btn.dataset.disabled = '1';
            btn.classList.add('opacity-50', 'pointer-events-none');
            const oldText = btn.textContent;
            btn.textContent = 'Mencetak...';

            setTimeout(() => {
                btn.dataset.disabled = '0';
                btn.classList.remove('opacity-50', 'pointer-events-none');
                btn.textContent = oldText;
            }, 10000);
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const modal = document.getElementById('deleteModal');
                if (!modal.classList.contains('hidden')) hideDeleteModal();
            }
        });
    </script>
</body>

</html>
