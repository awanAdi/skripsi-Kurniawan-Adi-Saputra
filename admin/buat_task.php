<?php
session_start();
require_once '../includes/koneksi.php';

if (!isset($_SESSION['username']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit();
}

$limit = 10;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $limit;

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['assign_task'])) {
    $id_order = intval($_POST['id_order']);
    $id_teknisi = intval($_POST['assign_task']);

    $valid = $conn->prepare("SELECT COUNT(*) FROM users WHERE id_user = ? AND role = 'teknisi'");
    $valid->bind_param("i", $id_teknisi);
    $valid->execute();
    $is_valid = $valid->get_result()->fetch_row()[0];

    if ($id_order && $is_valid) {
        $stmt = $conn->prepare("UPDATE order_inspeksi SET id_teknisi=?, status='Disetujui' WHERE id_order=? AND status='Menunggu'");
        $stmt->bind_param("ii", $id_teknisi, $id_order);
        $stmt->execute();

        header("Location: buat_task.php?status=" . ($stmt->affected_rows > 0 ? "success" : "failed"));
        exit();
    }
}
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['delete_order'])) {
    $id_order = intval($_POST['id_order']);

    $check = $conn->prepare("SELECT COUNT(*) FROM order_inspeksi WHERE id_order = ? AND status = 'Menunggu' AND id_teknisi IS NULL");
    $check->bind_param("i", $id_order);
    $check->execute();
    $valid_delete = $check->get_result()->fetch_row()[0];

    if ($valid_delete) {
        $update = $conn->prepare("
        UPDATE order_inspeksi 
        SET status = 'Gagal',
            deleted_at = NOW()
        WHERE id_order = ?
    ");
        $update->bind_param("i", $id_order);
        $update->execute();

        header("Location: buat_task.php?delete_status=" . ($update->affected_rows > 0 ? "success" : "failed"));
        exit();
    }
}

$search = isset($_GET['search']) ? "%" . $_GET['search'] . "%" : "%%";
$status_filter = $_GET['status_filter'] ?? '';
$status_condition = $status_filter ? "AND o.status = ?" : "";

$sql = "
    SELECT 
        o.id_order,
        o.id_teknisi,
        k.nomor_polisi,
        CONCAT(k.merk, ' ', k.model) AS kendaraan,
        k.tahun_produksi,
        o.tanggal_order,
        o.status, 
        k.link_gmaps,
        u.nama_lengkap AS nama_customer,
        u.no_hp AS no_hp_customer,
        t.username AS nama_teknisi,
        (SELECT COUNT(*) FROM order_inspeksi oi WHERE oi.id_teknisi = o.id_teknisi AND oi.status = 'Selesai') AS total_dikerjakan
    FROM order_inspeksi o
    JOIN kendaraan k ON o.id_mobil = k.id_mobil
    JOIN users u ON k.id_pelanggan = u.id_user
    LEFT JOIN users t ON o.id_teknisi = t.id_user
    WHERE (k.nomor_polisi LIKE ? OR u.nama_lengkap LIKE ?) 
        AND k.link_gmaps IS NOT NULL 
        AND o.deleted_at IS NULL
        AND o.status IN ('Menunggu', 'Disetujui') 
        $status_condition
    ORDER BY o.tanggal_order DESC
    LIMIT ? OFFSET ?
";

if ($status_filter) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sssii", $search, $search, $status_filter, $limit, $offset);
} else {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssii", $search, $search, $limit, $offset);
}

$stmt->execute();
$order = $stmt->get_result();

function wa_number(string $no): string {
    $no = preg_replace('/[^0-9]/', '', $no); // hapus karakter non-angka
    if (str_starts_with($no, '0')) {
        $no = '62' . substr($no, 1);
    }
    if (!str_starts_with($no, '62')) {
        $no = '62' . $no;
    }
    return $no;
}

$count_sql = "SELECT COUNT(*) FROM order_inspeksi o 
    JOIN kendaraan k ON o.id_mobil = k.id_mobil 
    JOIN users u ON k.id_pelanggan = u.id_user 
    WHERE (k.nomor_polisi LIKE '$search' OR u.nama_lengkap LIKE '$search') 
        AND k.link_gmaps IS NOT NULL 
        AND o.deleted_at IS NULL
        AND o.status IN ('Menunggu','Disetujui') ";
if ($status_filter) {
    $count_sql .= " AND o.status = '" . $conn->real_escape_string($status_filter) . "'";
}
$total_data = $conn->query($count_sql)->fetch_row()[0];
$total_pages = ceil($total_data / $limit);

$teknisi = $conn->query("SELECT id_user, username, nama_lengkap, link_gmaps 
                         FROM users WHERE role = 'teknisi'");
$teknisi_list = $teknisi->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Penugasan Teknisi - Rtech</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>

<body class="bg-gray-100 min-h-screen p-4">
    <?php include 'admin_sidebar.php'; ?>
    <div class="max-w-6xl mx-auto bg-white p-6 rounded shadow">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-6">
            <h1 class="text-2xl font-bold text-indigo-700">📋 Penugasan Teknisi</h1>
            <div class="flex gap-2 flex-wrap mt-4">
                <a href="history.php"
                    class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded text-sm shadow">
                    📄 Histori Order
                </a>
                <a href="buat_order_manual.php"
                    class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded text-sm shadow">
                    ➕ Buat Order Baru
                </a>
                <a href="admin_dashboard.php"
                    class="bg-gray-100 hover:bg-gray-200 text-gray-800 px-4 py-2 rounded text-sm border border-gray-300 shadow">
                    ← Dashboard
                </a>
            </div>
        </div>

        <form method="GET" class="flex flex-col md:flex-row gap-3 mb-6">
            <input type="text" name="search" placeholder="🔍 Cari No Polisi / Customer..." value="<?= isset($_GET['search']) ? htmlspecialchars($_GET['search']) : '' ?>" class="border border-gray-300 p-2 rounded w-full md:w-1/3 focus:ring-indigo-300">
            <select name="status_filter" class="border border-gray-300 p-2 rounded w-full md:w-1/5 focus:ring-indigo-300">
                <option value="">📂 Filter Status</option>
                <option value="Menunggu" <?= ($status_filter === 'Menunggu') ? 'selected' : '' ?>>⏳ Menunggu</option>
                <option value="Disetujui" <?= ($status_filter === 'Disetujui') ? 'selected' : '' ?>>✅ Disetujui</option>
            </select>
            <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded text-sm">Terapkan Filter</button>
        </form>

        <?php if (isset($_GET['status'])): ?>
            <div class="mb-4 p-3 rounded text-sm <?= $_GET['status'] === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' ?>">
                <?= $_GET['status'] === 'success' ? '✅ Tugas berhasil di-assign.' : '❌ Gagal assign tugas. Coba lagi.' ?>
            </div>
        <?php endif; ?>
        <?php if (isset($_GET['delete_status'])): ?>
            <div id="deleteAlert" class="mb-4 p-3 rounded text-sm 
            <?= $_GET['delete_status'] === 'success' ? 'bg-green-100 text-green-700' : ($_GET['delete_status'] === 'invalid' ? 'bg-yellow-100 text-yellow-700' : 'bg-red-100 text-red-700') ?>">
                <?php
                if ($_GET['delete_status'] === 'success') echo '✅ Order berhasil dibatalkan.';
                elseif ($_GET['delete_status'] === 'invalid') echo '⚠ Order tidak dapat dihapus.';
                else echo '❌ Gagal menghapus order.';
                ?>
            </div>
        <?php endif; ?>

        <div class="overflow-x-auto hidden md:block">
            <table class="w-full table-auto text-sm">
                <thead class="bg-indigo-50 text-gray-700">
                    <tr>
                        <th class="p-2">Tanggal Order</th>
                        <th class="p-2">No Polisi</th>
                        <th class="p-2">Kendaraan</th>
                        <th class="p-2">Pelanggan</th>
                        <th class="p-2">Lokasi</th>
                        <th class="p-2">Status</th>
                        <th class="p-2">Teknisi</th>
                        <th class="p-2">Assign</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($order->num_rows > 0): ?>
                        <?php while ($row = $order->fetch_assoc()): ?>
                            <tr class="border-t hover:bg-gray-50">
                                <td class="p-2"><?= date('d M Y', strtotime($row['tanggal_order'])) ?></td>
                                <td class="p-2 font-semibold"><?= htmlspecialchars($row['nomor_polisi']) ?></td>
                                <td class="p-2"><?= htmlspecialchars($row['kendaraan']) ?> (<?= $row['tahun_produksi'] ?>)</td>
                                <td class="p-2">
                                <div class="font-medium text-gray-800">
                                        <?= htmlspecialchars($row['nama_customer']) ?>
                                    </div>
                                    <?php if (!empty($row['no_hp_customer'])): ?>
                                        <?php $wa = wa_number($row['no_hp_customer']); ?>
                                        <div class="text-xs text-green-600">
                                            📞 
                                            <a href="https://wa.me/<?= $wa ?>" 
                                               target="_blank"
                                               class="hover:underline font-medium">
                                               <?= htmlspecialchars($row['no_hp_customer']) ?>
                                            </a>
                                        </div>
                                    <?php else: ?>
                                        <div class="text-xs text-gray-400">📞 -</div>
                                    <?php endif; ?>
                                </td>
                                <td class="p-2"><a href="<?= htmlspecialchars($row['link_gmaps']) ?>" target="_blank" class="text-blue-600 hover:underline">📍 Lihat</a></td>
                                <td class="p-2 text-center">
                                    <span class="inline-block px-2 py-1 text-xs font-semibold rounded-full <?= $row['status'] === 'Menunggu' ? 'bg-yellow-100 text-yellow-800' : 'bg-green-100 text-green-700' ?>">
                                        <?= $row['status'] ?>
                                    </span>
                                </td>
                                <td class="p-2"><?= $row['nama_teknisi'] ?? '<span class="text-gray-400 italic">Belum</span>' ?></td>
                                <td class="p-2">
                                    <?php if ($row['status'] === 'Menunggu'): ?>
                                        <button type="button"
                                            onclick="openAssignModal(<?= $row['id_order'] ?>, '<?= htmlspecialchars($row['link_gmaps']) ?>')"
                                            class="bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-1 rounded text-sm w-full">
                                            Pilih Teknisi
                                        </button>
                                        <!-- Tombol Hapus -->
                                        <button type="button"
                                            onclick="openDeleteModal(<?= $row['id_order'] ?>)"
                                            class="bg-red-600 hover:bg-red-700 text-white px-3 py-1 rounded text-sm w-full mt-2">
                                            Hapus
                                        </button>
                                    <?php else: ?>
                                        <span class="text-xs text-gray-400">✔ Sudah</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" class="text-center p-4 text-gray-500">Tidak ada data ditemukan.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="block md:hidden mt-4">
            <?php if ($order->num_rows > 0): ?>
                <?php foreach ($order as $row): ?>
                    <div class="bg-white border rounded-2xl p-5 mb-4 shadow-md space-y-2">
                        <p class="text-base text-gray-600 font-medium">
                            📅 <?= date('d M Y', strtotime($row['tanggal_order'])) ?>
                        </p>
                        <p class="text-lg font-semibold text-gray-800 leading-snug">
                            <?= htmlspecialchars($row['nomor_polisi']) ?> -
                            <?= htmlspecialchars($row['kendaraan']) ?> (<?= $row['tahun_produksi'] ?>)
                        </p>
                        <p class="text-base">
                            👤 <span class="font-medium"><?= htmlspecialchars($row['nama_customer']) ?></span><br>
                            <?php if (!empty($row['no_hp_customer'])): ?>
                                <?php $wa = wa_number($row['no_hp_customer']); ?>
                                <span class="text-sm text-green-600">
                                    📞 
                                    <a href="https://wa.me/<?= $wa ?>" target="_blank" class="underline">
                                        <?= htmlspecialchars($row['no_hp_customer']) ?>
                                    </a>
                                </span>
                            <?php endif; ?>
                        </p>
                        <p class="text-base">
                            Status:
                            <span class="inline-block px-3 py-1 text-sm rounded-full font-semibold 
                        <?= $row['status'] === 'Menunggu' ? 'bg-yellow-100 text-yellow-800' : 'bg-green-100 text-green-700' ?>">
                                <?= $row['status'] ?>
                            </span>
                        </p>
                        <p class="text-base mt-2">
                            📍 <a href="<?= htmlspecialchars($row['link_gmaps']) ?>" target="_blank" class="text-blue-600 underline">Lihat Lokasi</a>
                        </p>
                        <div class="flex flex-col sm:flex-row gap-3 mt-4">
                            <?php if ($row['status'] === 'Menunggu'): ?>
                                <button type="button"
                                    onclick='openAssignModal(<?= $row['id_order'] ?>, <?= json_encode($row['link_gmaps']) ?>)'
                                    class="flex-1 bg-indigo-600 hover:bg-indigo-700 text-white py-3 rounded-xl text-base font-semibold shadow">
                                    Pilih Teknisi
                                </button>
                                <button type="button" onclick="openDeleteModal(<?= $row['id_order'] ?>)"
                                    class="flex-1 bg-purple-600 hover:bg-purple-700 text-white py-3 rounded-xl text-base font-semibold shadow">
                                    Hapus
                                </button>
                            <?php else: ?>
                                <span class="text-sm text-gray-500 italic">✔ Sudah diassign</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="text-center text-gray-500">Tidak ada data ditemukan.</p>
            <?php endif; ?>
        </div>
        <div class="mt-6 flex justify-center text-sm text-gray-700 gap-1">
            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <a href="?page=<?= $i ?><?= $status_filter ? '&status_filter=' . $status_filter : '' ?>" class="px-3 py-1 rounded border <?= $i == $page ? 'bg-indigo-600 text-white border-indigo-600' : 'hover:bg-gray-200 border-gray-300' ?>">
                    <?= $i ?>
                </a>
            <?php endfor; ?>
        </div>
    </div>
    <div id="assignModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden z-50">
        <div class="bg-white rounded-lg p-6 w-96 shadow-lg max-h-[80vh] overflow-y-auto">
            <h2 class="text-lg font-semibold text-gray-800 mb-4">Pilih Teknisi</h2>
            <form method="post" id="assignForm">
                <input type="hidden" name="id_order" id="assignOrderId">
                <input type="hidden" id="assignOrderLink">
                <table class="w-full text-sm border">
                    <thead>
                        <tr class="bg-gray-100">
                            <th class="p-2 border">Username</th>
                            <th class="p-2 border">Nama</th>
                            <th class="p-2 border">Pilih Teknisi</th>
                            <th class="p-2 border">Rute</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($teknisi_list as $t): ?>
                            <tr class="border-t hover:bg-gray-50">
                                <td class="p-1 border"><?= htmlspecialchars($t['username']) ?></td>
                                <td class="p-1 border"><?= htmlspecialchars($t['nama_lengkap']) ?></td>
                                <td class="p-1 border text-center">
                                    <button type="submit" name="assign_task" value="<?= $t['id_user'] ?>"
                                        class="bg-indigo-600 hover:bg-indigo-700 text-white px-2 py-1 rounded text-xs">
                                        Tugaskan
                                    </button>
                                </td>
                                <td class="p-1 border text-center" data-teknisi-link="<?= htmlspecialchars($t['link_gmaps'] ?? '') ?>">
                                    <span class="text-gray-400">-</span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </form>
            <div class="mt-4 text-right">
                <button onclick="closeAssignModal()" class="px-4 py-2 bg-gray-200 text-gray-800 rounded hover:bg-gray-300">
                    Tutup
                </button>
            </div>
        </div>
    </div>


    <!-- Modal Hapus -->
    <div id="deleteModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden">
        <div class="bg-white rounded-lg p-6 w-80 shadow-lg">
            <h2 class="text-lg font-semibold text-gray-800 mb-4">Konfirmasi Hapus</h2>
            <p class="text-sm text-gray-600 mb-6">Apakah Anda yakin ingin menghapus order ini? Tindakan ini tidak dapat dibatalkan.</p>

            <form method="post">
                <input type="hidden" name="id_order" id="deleteOrderId">
                <div class="flex justify-end gap-2">
                    <button type="button" onclick="closeDeleteModal()"
                        class="px-4 py-2 bg-gray-200 text-gray-800 rounded hover:bg-gray-300">
                        Batal
                    </button>
                    <button type="submit" name="delete_order"
                        class="px-4 py-2 bg-purple-600 text-white rounded hover:bg-purple-700">
                        Ya, Hapus
                    </button>
                </div>
            </form>
        </div>
    </div>
    <script>
        let currentOrderId = null;

        function closeAssignModal() {
            document.getElementById('assignModal').classList.add('hidden');
        }

        function openDeleteModal(orderId) {
            document.getElementById('deleteModal').classList.remove('hidden');
            document.getElementById('deleteOrderId').value = orderId;
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').classList.add('hidden');
        }
    </script>
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            const alertBox = document.getElementById('deleteAlert');
            if (alertBox) {
                setTimeout(() => {
                    alertBox.style.transition = "opacity 0.5s ease";
                    alertBox.style.opacity = "0";
                    setTimeout(() => alertBox.remove(), 500); // Hapus dari DOM
                }, 4000); // 4 detik
            }
        });

        function generateRuteLinks() {
            const orderLink = document.getElementById('assignOrderLink').value;
            // Ambil koordinat dari link order
            let orderMatch = orderLink.match(/@(-?\d+\.\d+),(-?\d+\.\d+)/);
            if (!orderMatch) {
                // fallback jika link pakai "q=" (format share lokasi)
                orderMatch = orderLink.match(/q=(-?\d+\.\d+),(-?\d+\.\d+)/);
            }
            if (!orderMatch) return;

            const destLat = orderMatch[1];
            const destLng = orderMatch[2];

            document.querySelectorAll("[data-teknisi-link]").forEach(el => {
                const teknisiLink = el.getAttribute("data-teknisi-link") || "";
                let match = teknisiLink.match(/@(-?\d+\.\d+),(-?\d+\.\d+)/);
                if (!match) {
                    // fallback kalau teknisi pakai link share lokasi
                    match = teknisiLink.match(/q=(-?\d+\.\d+),(-?\d+\.\d+)/);
                }
                if (match) {
                    const originLat = match[1];
                    const originLng = match[2];
                    const mapsUrl = `https://www.google.com/maps/dir/?api=1&origin=${originLat},${originLng}&destination=${destLat},${destLng}&travelmode=driving`;
                    el.innerHTML = `<a href="${mapsUrl}" target="_blank" class="text-blue-600 hover:underline">🚗</a>`;
                } else {
                    el.innerHTML = `<span class="text-gray-400">-</span>`;
                }
            });
        }

        function openAssignModal(orderId, orderLink) {
            currentOrderId = orderId;
            document.getElementById('assignOrderId').value = orderId;
            document.getElementById('assignOrderLink').value = orderLink;
            document.getElementById('assignModal').classList.remove('hidden');
            generateRuteLinks();
        }
    </script>

</body>

</html>
