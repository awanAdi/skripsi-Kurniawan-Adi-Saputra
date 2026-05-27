<?php
session_start();
if (!isset($_SESSION['username']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit();
}
require_once '../includes/koneksi.php';

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

function safe_json($data)
{
    $opts = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;
    $json = json_encode($data, $opts);
    if ($json !== false) return $json;

    array_walk_recursive($data, function (&$v) {
        if (is_string($v)) {
            $v = mb_convert_encoding($v, 'UTF-8', 'auto');
        }
    });
    $json = json_encode($data, $opts);
    if ($json !== false) return $json;

    return json_encode(new stdClass(), $opts);
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!empty($_POST['mass_update'])) {
        $komponenArr = $_POST['komponen'] ?? [];
        $tipeArr = $_POST['tipe_input'] ?? [];
        $nilaiArr = $_POST['nilai_batas'] ?? [];
        $opsiArr  = $_POST['opsi_pilihan'] ?? [];
        $ketArr   = $_POST['keterangan'] ?? [];

        $stmtUp = $conn->prepare("UPDATE standar_komponen SET komponen=?, tipe_input=?, nilai_batas=?, opsi_pilihan=?, keterangan=? WHERE id_standar=?");
        if (!$stmtUp) {
            error_log("Prepare mass update failed: " . $conn->error);
        } else {
            foreach ($komponenArr as $id => $kom) {
                $id = (int)$id;
                // sanitize & normalize
                $kom_raw = preg_replace('/\s+/', ' ', trim($kom));
                if (function_exists('mb_convert_case')) {
                    $komp = mb_convert_case($kom_raw, MB_CASE_TITLE, 'UTF-8');
                } else {
                    $komp = ucwords(strtolower($kom_raw));
                }
                $t = $tipeArr[$id] ?? 'teks';
                $n_raw = $nilaiArr[$id] ?? '';
                $n_val = $n_raw !== '' ? str_replace(',', '.', $n_raw) : null;
                $o = $opsiArr[$id] ?? null;
                $k = $ketArr[$id] ?? null;

                // bind and execute
                $stmtUp->bind_param("sssssi", $komp, $t, $n_val, $o, $k, $id);
                if (!$stmtUp->execute()) {
                    error_log("Mass update failed for id {$id}: " . $stmtUp->error);
                }
            }
            $stmtUp->close();
        }

        $_SESSION['notif'] = 'Perubahan massal berhasil disimpan.';
        $redirect = 'standar_inspeksi.php';
        header("Location: " . $redirect);
        exit();
    }
    if (isset($_POST['tambah_standar'])) {
        $id_kriteria = $_POST['id_kriteria'] ?? [];
        $komponen = $_POST['komponen'] ?? [];
        $tipe_input = $_POST['tipe_input'] ?? [];
        $nilai_batas = $_POST['nilai_batas'] ?? [];
        $opsi_pilihan = $_POST['opsi_pilihan'] ?? [];
        $keterangan = $_POST['keterangan'] ?? [];

        if (count($id_kriteria) !== count($komponen)) {
            $_SESSION['notif'] = 'Data tidak valid.';
            header("Location: standar_inspeksi.php");
            exit();
        }

        $stmt = $conn->prepare("INSERT INTO standar_komponen 
            (id_kriteria, komponen, tipe_input, nilai_batas, opsi_pilihan, keterangan)
            VALUES (?, ?, ?, ?, ?, ?)");
        if (!$stmt) {
            $_SESSION['notif'] = 'Gagal menyiapkan statement: ' . $conn->error;
            header("Location: standar_inspeksi.php");
            exit();
        }

        for ($i = 0; $i < count($id_kriteria); $i++) {
            if (empty(trim($komponen[$i]))) continue;
            $idKriteria = (int)$id_kriteria[$i];
            $komp_raw = preg_replace('/\s+/', ' ', trim($komponen[$i]));
            if (function_exists('mb_convert_case')) {
                $komp = mb_convert_case($komp_raw, MB_CASE_TITLE, 'UTF-8');
            } else {
                $komp = ucwords(strtolower($komp_raw));
            }
            $tipe = $tipe_input[$i] ?? 'teks';
            $nilai_input = str_replace(',', '.', $nilai_batas[$i] ?? '');
            $nilaiBatas = $nilai_input !== '' ? $nilai_input : null;
            $opsi = !empty($opsi_pilihan[$i]) ? $opsi_pilihan[$i] : null;
            $ket = !empty($keterangan[$i]) ? $keterangan[$i] : null;

            $stmt->bind_param("isssss", $idKriteria, $komp, $tipe, $nilaiBatas, $opsi, $ket);
            if (!$stmt->execute()) {
                // optionally log error; continue saving other rows
                error_log("Insert standar_komponen failed: " . $stmt->error);
            }
        }
        $stmt->close();

        $_SESSION['notif'] = 'Data berhasil disimpan.';
        header("Location: standar_inspeksi.php");
        exit();
    }

    if (isset($_POST['edit_id'])) {
        $id = intval($_POST['edit_id']);
        $id_kriteria = (int)($_POST['edit_kriteria'] ?? 0);
        $komponen_raw = preg_replace('/\s+/', ' ', trim($_POST['edit_komponen'] ?? ''));
        if (function_exists('mb_convert_case')) {
            $komponen = mb_convert_case($komponen_raw, MB_CASE_TITLE, 'UTF-8');
        } else {
            $komponen = ucwords(strtolower($komponen_raw));
        }
        $tipe = $_POST['edit_tipe'] ?? 'teks';
        $nilai_input = str_replace(',', '.', $_POST['edit_nilai_batas'] ?? '');
        $nilai_batas = $nilai_input !== '' ? $nilai_input : null;
        $opsi = $_POST['edit_opsi'] ?: null;
        $ket = $_POST['edit_keterangan'] ?: null;

        $stmt = $conn->prepare("UPDATE standar_komponen 
        SET id_kriteria=?, komponen=?, tipe_input=?, nilai_batas=?, opsi_pilihan=?, keterangan=? 
        WHERE id_standar=?");
        if ($stmt) {
            $stmt->bind_param("isssssi", $id_kriteria, $komponen, $tipe, $nilai_batas, $opsi, $ket, $id);
            if (!$stmt->execute()) {
                // log if needed
                error_log("Update standar_komponen error: " . $stmt->error);
            }
            $stmt->close();
        } else {
            error_log("Prepare failed (update): " . $conn->error);
        }

        if (!empty($_POST['ajax_edit'])) {
            $id_safe = (int)$id;
            $q = $conn->query("
                SELECT s.*, k.deskripsi AS sub_kriteria, k.kode_kriteria, c.nama_kategori
                FROM standar_komponen s
                JOIN kriteria_inspeksi k ON s.id_kriteria = k.id_kriteria
                JOIN kategori_inspeksi c ON k.id_kategori = c.id_kategori
                WHERE s.id_standar = $id_safe
                LIMIT 1
            ");
            $row = $q ? $q->fetch_assoc() : null;
            header('Content-Type: application/json; charset=utf-8');
            echo safe_json(['status' => 'ok', 'row' => $row]);
            exit();
        }

        $_SESSION['notif'] = 'Data berhasil diperbarui.';

        $return_url = 'standar_inspeksi.php';
        $params = [];
        if (!empty($_POST['return_mode']))  $params[] = 'mode=' . urlencode($_POST['return_mode']);
        if (isset($_POST['return_search']))  $params[] = 'search=' . urlencode($_POST['return_search']);
        if (!empty($_POST['return_page']))  $params[] = 'page=' . intval($_POST['return_page']);
        if (count($params) > 0) {
            $return_url .= '?' . implode('&', $params);
        }
        header("Location: " . $return_url);
        exit();
    }
}

if (isset($_GET['hapus'])) {
    $id = (int) $_GET['hapus'];
    $conn->query("DELETE FROM standar_komponen WHERE id_standar = $id");
    $_SESSION['notif'] = "Data berhasil dihapus.";
    header("Location: standar_inspeksi.php");
    exit;
}

$limit = 20;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

$mode = $_GET['mode'] ?? 'detail';
$search = $_GET['search'] ?? '';
$where = '';
if (!empty($search)) {
    $search_safe = $conn->real_escape_string($search);
    $where = "WHERE s.komponen LIKE '%$search_safe%'";
}

$subkategori_list = [];
$subRes = $conn->query("SELECT k.id_kriteria, c.nama_kategori, k.kode_kriteria, k.deskripsi 
    FROM kriteria_inspeksi k 
    JOIN kategori_inspeksi c ON k.id_kategori = c.id_kategori
    ORDER BY c.nama_kategori, k.kode_kriteria ASC");
while ($r = $subRes->fetch_assoc()) {
    $subkategori_list[] = $r;
}

if ($mode === 'gabungan') {
    $standar_result = $conn->query("
        SELECT 
            s.komponen,
            s.tipe_input,
            GROUP_CONCAT(DISTINCT s.nilai_batas ORDER BY s.nilai_batas SEPARATOR ', ') AS nilai_batas,
            GROUP_CONCAT(DISTINCT s.opsi_pilihan ORDER BY s.opsi_pilihan SEPARATOR ', ') AS opsi_pilihan,
            GROUP_CONCAT(DISTINCT s.keterangan SEPARATOR ' / ') AS keterangan,
            k.kode_kriteria,
            k.deskripsi AS sub_kriteria,
            c.nama_kategori
        FROM standar_komponen s
        JOIN kriteria_inspeksi k ON s.id_kriteria = k.id_kriteria
        JOIN kategori_inspeksi c ON k.id_kategori = c.id_kategori
        $where
        GROUP BY s.komponen, s.tipe_input, k.id_kriteria
        ORDER BY c.nama_kategori, k.kode_kriteria, s.komponen
        LIMIT $limit OFFSET $offset
    ");
    $total_query = $conn->query("
        SELECT COUNT(DISTINCT s.komponen, s.tipe_input, s.id_kriteria) AS total
        FROM standar_komponen s
        $where
    ");
} else {
    $standar_result = $conn->query("
        SELECT s.*, k.deskripsi AS sub_kriteria, k.kode_kriteria, c.nama_kategori
        FROM standar_komponen s
        JOIN kriteria_inspeksi k ON s.id_kriteria = k.id_kriteria
        JOIN kategori_inspeksi c ON k.id_kategori = c.id_kategori
        $where
        ORDER BY c.nama_kategori, k.kode_kriteria, s.komponen
        LIMIT $limit OFFSET $offset
    ");
    $total_query = $conn->query("
        SELECT COUNT(*) AS total
        FROM standar_komponen s
        $where
    ");
}
$total_rows = (int)$total_query->fetch_assoc()['total'];
$total_pages = (int)ceil($total_rows / $limit);
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Standar Inspeksi</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Poppins', sans-serif;
            background-color: #f7f8fa;
        }

        .modal {
            background-color: rgba(0, 0, 0, 0.45);
        }

        tbody tr:nth-child(even) {
            background-color: #f9fafb;
        }

        tbody tr:hover {
            background-color: #eef2ff;
            transition: background-color 0.2s;
        }

        .btn-primary {
            background-color: #1E3A8A;
            color: white;
        }

        .btn-primary:hover {
            background-color: #1D4ED8;
        }

        .btn-success {
            background-color: #16A34A;
            color: white;
        }

        .btn-success:hover {
            background-color: #15803D;
        }

        .btn-gray {
            background-color: #E5E7EB;
            color: #374151;
        }

        .btn-gray:hover {
            background-color: #D1D5DB;
        }

        .truncate-td {
            max-width: 600px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: normal;
        }

        td[data-full]:hover::after {
            content: attr(data-full);
            display: block;
            position: absolute;
            background: white;
            color: #111827;
            border: 1px solid #e5e7eb;
            padding: 8px;
            max-width: 420px;
            white-space: normal;
            z-index: 60;
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.08);
        }

        .sticky-actions {
            position: sticky;
            bottom: 16px;
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(6px);
            padding: 8px;
            border-radius: 10px;
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.06);
            z-index: 40;
        }

        :focus {
            outline: 3px solid rgba(30, 58, 138, 0.18);
            outline-offset: 2px;
        }

        .modal {
            z-index: 9999;
        }

        .modal>* {
            z-index: 10000;
        }

        #dataTable {
            font-size: 1rem;
            line-height: 1.45;
        }

        #dataTable input,
        #dataTable textarea,
        #dataTable select {
            font-size: 0.95rem;
            line-height: 1.4;
        }

        #dataTable textarea {
            resize: vertical;
            min-height: 40px;
            /* minimal 2 baris */
            padding: 0.5rem;
        }

        #dataTable td {
            white-space: normal;
            word-break: break-word;
        }

        @media (max-width: 768px) {
            #dataTable {
                font-size: 0.95rem;
            }

            #dataTable textarea {
                min-height: 48px;
            }
        }
    </style>
</head>

<body class="text-gray-800 p-6">
    <?php if (isset($_SESSION['notif'])): ?>
        <div id="notif-alert" class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded max-w-2xl mx-auto mb-4 shadow relative">
            <?= htmlspecialchars($_SESSION['notif']) ?>
            <button aria-label="Tutup notifikasi" onclick="this.parentElement.remove()" class="absolute top-0 bottom-0 right-0 px-4 py-3 cursor-pointer text-green-700">&times;</button>
        </div>
        <script>
            setTimeout(() => {
                const notif = document.getElementById('notif-alert');
                if (notif) {
                    notif.style.opacity = '0';
                    notif.style.transition = 'opacity 0.5s ease';
                    setTimeout(() => notif.remove(), 500);
                }
            }, 3000);
        </script>
        <?php unset($_SESSION['notif']); ?>
    <?php endif; ?>

    <div class="max-w-7xl mx-auto bg-white border border-gray-200 p-8 rounded-xl shadow-md space-y-6">
        <div class="flex justify-between items-center">
            <h1 class="text-3xl font-bold text-gray-800">📋 Standar Pemeriksaan Komponen</h1>
            <div class="flex gap-2">
                <a href="admin_dashboard.php" class="btn-gray px-4 py-2 rounded shadow text-sm">← Kembali</a>
                <a href="atur_urutan.php" class="btn-primary px-4 py-2 rounded shadow text-sm">⚙️ Atur Urutan Komponen</a>
            </div>
        </div>

        <div class="bg-blue-50 border border-blue-200 text-blue-800 text-sm p-2 rounded mb-4">
            Butuh bantuan mengisi standar inspeksi?
            <button type="button" onclick="document.getElementById('sopModal').classList.remove('hidden')" aria-haspopup="dialog" class="underline font-medium hover:text-blue-900">Lihat Panduan</button>
        </div>

        <form method="post" action="standar_inspeksi.php" class="space-y-4">
            <input type="hidden" name="tambah_standar" value="1">
            <div id="form-container" class="space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-5 gap-4 bg-gray-50 p-4 rounded-lg shadow-sm relative form-row">
                    <select name="id_kriteria[]" class="border p-2 rounded-lg focus:ring-blue-300 focus:border-blue-400" required>
                        <option value="" disabled selected>Pilih Sub Kategori</option>
                        <?php foreach ($subkategori_list as $s): ?>
                            <option value="<?= (int)$s['id_kriteria'] ?>"><?= htmlspecialchars($s['nama_kategori'] . ' - ' . $s['kode_kriteria'] . ' ' . $s['deskripsi']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="komponen[]" class="border p-2 rounded-lg" placeholder="Nama Komponen" required onblur="this.value = this.value.replace(/\s+/g, ' ').trim();">
                    <select name="tipe_input[]" class="border p-2 rounded-lg tipe-input" onchange="toggleOpsi(this)" required>
                        <option value="angka">Angka</option>
                        <option value="pilihan">Pilihan</option>
                        <option value="teks">Teks</option>
                    </select>
                    <textarea name="nilai_batas[]" class="border p-2 rounded-lg resize-y nilai-batas" placeholder="Nilai Maksimum (angka)"></textarea>
                    <textarea name="opsi_pilihan[]" class="border p-2 rounded-lg resize-y hidden opsi-pilihan" placeholder="Pilihan Tersedia (jika pilihan)"></textarea>
                    <textarea name="keterangan[]" class="border p-2 rounded-lg resize-y" placeholder="Hasil Penilaian Otomatis"></textarea>
                    <button type="button" class="absolute -top-3 -right-3 bg-red-500 text-white rounded-full w-6 h-6 text-xs font-bold hover:bg-red-700" title="Hapus Baris">×</button>
                </div>
            </div>
            <div class="sticky-actions flex items-center justify-between mt-4">
                <div class="text-sm text-gray-600"></div>
                <div class="flex gap-2">
                    <button type="button" class="btn-primary px-4 py-2 rounded btn-tambah-baris">+ Tambah Baris</button>
                    <button type="submit" class="btn-success px-4 py-2 rounded">💾 Simpan</button>
                </div>
            </div>
        </form>

        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <form method="GET" class="flex gap-2 w-full md:w-2/3" aria-label="Form pencarian komponen">
                <input type="hidden" name="mode" value="<?= htmlspecialchars($mode) ?>">
                <input type="text" id="searchInput" name="search" value="<?= htmlspecialchars($_GET['search'] ?? '') ?>" placeholder="Cari Komponen..." class="border p-2 rounded-lg w-full" aria-label="Cari Komponen">
                <button type="submit" class="btn-gray px-4 py-2 rounded">Cari</button>
            </form>

            <div class="flex items-center gap-2">
                <a href="?mode=<?= $mode === 'gabungan' ? 'detail' : 'gabungan' ?>&page=<?= $page ?>&search=<?= urlencode($search) ?>" class="btn-primary px-4 py-2 rounded">
                    Tampilkan Mode <?= $mode === 'gabungan' ? 'Detail' : 'Gabungan' ?>
                </a>

                <?php if ($mode === 'detail'): ?>
                    <button id="btnMassEdit" onclick="enableMassEdit()" class="btn-gray px-4 py-2 rounded">Mass Edit</button>
                    <button id="btnSaveAll" onclick="saveMassEdit()" class="btn-success px-4 py-2 rounded hidden">💾 Simpan Semua Perubahan</button>
                <?php endif; ?>
            </div>
        </div>

        <div class="overflow-x-auto">
            <h2 class="text-lg font-semibold mb-2">Daftar Standar Komponen</h2>

            <form id="massEditForm" method="POST" action="standar_inspeksi.php">
                <input type="hidden" name="mass_update" value="1">
                <table id="dataTable" class="min-w-full text-sm border border-gray-200 bg-white shadow rounded-lg overflow-hidden">
                    <thead class="bg-gray-100 text-gray-700 font-semibold text-left">
                        <tr>
                            <th class="px-4 py-3">Kategori</th>
                            <th class="px-4 py-3">Sub Kriteria</th>
                            <th class="px-4 py-3">Komponen</th>
                            <th class="px-4 py-3">Tipe Input</th>
                            <th class="px-4 py-3">Nilai Maksimum</th>
                            <th class="px-4 py-3">Pilihan Tersedia</th>
                            <th class="px-4 py-3">Hasil Penilaian Otomatis</th>
                            <?php if ($mode === 'detail'): ?><th class="px-4 py-3">Aksi</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody class="text-gray-700 relative">
                        <?php while ($s = $standar_result->fetch_assoc()): ?>
                            <?php if ($mode === 'detail'): ?>
                                <tr data-row-id="<?= (int)$s['id_standar'] ?>">
                                <?php else: ?>
                                <tr>
                                <?php endif; ?>
                                <td class="px-4 py-3"><?= htmlspecialchars($s['nama_kategori']) ?></td>
                                <td class="px-4 py-3"><?= htmlspecialchars($s['kode_kriteria'] . ' ' . $s['sub_kriteria']) ?></td>

                                <?php if ($mode === 'detail'): ?>
                                    <td class="px-5 py-6">
                                        <!-- no more id_standar[]; we use associative names -->
                                        <input type="text" name="komponen[<?= (int)$s['id_standar'] ?>]"
                                            value="<?= htmlspecialchars($s['komponen']) ?>"
                                            class="w-full border p-1 rounded whitespace-normal break-words"
                                            disabled data-original="<?= htmlspecialchars($s['komponen']) ?>">
                                        <div style="display:none;">ID: <?= (int)$s['id_standar'] ?></div>
                                    </td>

                                    <td class="px-4 py-3">
                                        <select name="tipe_input[<?= (int)$s['id_standar'] ?>]" class="border p-1 rounded" disabled data-original="<?= htmlspecialchars($s['tipe_input']) ?>">
                                            <option value="angka" <?= $s['tipe_input'] == 'angka' ? 'selected' : '' ?>>angka</option>
                                            <option value="pilihan" <?= $s['tipe_input'] == 'pilihan' ? 'selected' : '' ?>>pilihan</option>
                                            <option value="teks" <?= $s['tipe_input'] == 'teks' ? 'selected' : '' ?>>teks</option>
                                        </select>
                                    </td>

                                    <td class="px-4 py-3">
                                        <input type="text" name="nilai_batas[<?= (int)$s['id_standar'] ?>]" value="<?= htmlspecialchars($s['nilai_batas']) ?>" class="w-full border p-1 rounded" disabled data-original="<?= htmlspecialchars($s['nilai_batas']) ?>">
                                    </td>

                                    <td class="px-4 py-3">
                                        <input type="text" name="opsi_pilihan[<?= (int)$s['id_standar'] ?>]" value="<?= htmlspecialchars($s['opsi_pilihan']) ?>" class="w-full border p-1 rounded" disabled data-original="<?= htmlspecialchars($s['opsi_pilihan']) ?>">
                                    </td>

                                    <td class="px-4 py-3">
                                        <input type="text" name="keterangan[<?= (int)$s['id_standar'] ?>]" value="<?= htmlspecialchars($s['keterangan']) ?>" class="w-full border p-1 rounded" disabled data-original="<?= htmlspecialchars($s['keterangan']) ?>">
                                    </td>

                                    <td class="px-4 py-3 flex gap-2">
                                        <a href="#" class="text-blue-600 hover:underline edit-btn"
                                            data-payload='<?= htmlspecialchars(safe_json($s), ENT_QUOTES, "UTF-8") ?>'>✏️ Edit</a>
                                        <button type="button" class="text-red-600 hover:underline delete-btn"
                                            data-delete="<?= htmlspecialchars(isset($s['id_list']) ? $s['id_list'] : $s['id_standar']) ?>">🗑️ Hapus</button>
                                    </td>
                                <?php else: ?>
                                    <td class="px-4 py-3 truncate-td" data-full="<?= htmlspecialchars($s['komponen']) ?>"><?= htmlspecialchars($s['komponen']) ?></td>
                                    <td class="px-4 py-3"><?= htmlspecialchars($s['tipe_input']) ?></td>
                                    <td class="px-4 py-3"><?= htmlspecialchars($s['nilai_batas']) ?></td>
                                    <td class="px-4 py-3 truncate-td" data-full="<?= htmlspecialchars($s['opsi_pilihan']) ?>"><?= htmlspecialchars($s['opsi_pilihan']) ?></td>
                                    <td class="px-4 py-3 truncate-td" data-full="<?= htmlspecialchars($s['keterangan']) ?>"><?= htmlspecialchars($s['keterangan']) ?></td>
                                <?php endif; ?>
                                </tr>
                            <?php endwhile; ?>
                    </tbody>
                </table>
            </form>

            <div class="mt-4 flex justify-between items-center text-sm text-gray-700">
                <div>Halaman <?= $page ?> dari <?= $total_pages ?> (Total <?= $total_rows ?> data)</div>
                <div class="flex items-center gap-1">
                    <?php if ($page > 1): ?>
                        <a href="?mode=<?= $mode ?>&search=<?= urlencode($search) ?>&page=1" class="px-2 py-1 border rounded hover:bg-gray-200">&laquo;</a>
                        <a href="?mode=<?= $mode ?>&search=<?= urlencode($search) ?>&page=<?= $page - 1 ?>" class="px-2 py-1 border rounded hover:bg-gray-200">&lt;</a>
                    <?php endif; ?>

                    <?php
                    $range = 2;
                    $start = max(1, $page - $range);
                    $end = min($total_pages, $page + $range);
                    if ($start > 2) echo '<span class="px-2">...</span>';
                    for ($i = $start; $i <= $end; $i++):
                    ?>
                        <a href="?mode=<?= $mode ?>&search=<?= urlencode($search) ?>&page=<?= $i ?>" class="px-2 py-1 border rounded <?= ($i == $page ? 'bg-blue-500 text-white' : 'hover:bg-gray-200') ?>"><?= $i ?></a>
                    <?php endfor;
                    if ($end < $total_pages - 1) echo '<span class="px-2">...</span>';
                    ?>

                    <?php if ($page < $total_pages): ?>
                        <a href="?mode=<?= $mode ?>&search=<?= urlencode($search) ?>&page=<?= $page + 1 ?>" class="px-2 py-1 border rounded hover:bg-gray-200">&gt;</a>
                        <a href="?mode=<?= $mode ?>&search=<?= urlencode($search) ?>&page=<?= $total_pages ?>" class="px-2 py-1 border rounded hover:bg-gray-200">&raquo;</a>
                    <?php endif; ?>
                </div>
            </div>

            <div id="editModal" class="fixed inset-0 flex items-center justify-center hidden modal" role="dialog" aria-modal="true" aria-labelledby="editModalLabel" tabindex="-1">
                <div class="bg-white p-6 rounded-lg shadow-lg w-full max-w-xl relative">
                    <button onclick="closeEditModal()" aria-label="Tutup edit" class="absolute top-2 right-3 text-gray-500 hover:text-red-600 text-lg">×</button>
                    <h3 id="editModalLabel" class="text-lg font-semibold mb-4">Edit Komponen</h3>
                    <form method="post" id="singleEditForm">
                        <input type="hidden" name="edit_id" id="edit_id">

                        <input type="hidden" name="return_mode" value="<?= htmlspecialchars($mode) ?>">
                        <input type="hidden" name="return_search" value="<?= htmlspecialchars($search) ?>">
                        <input type="hidden" name="return_page" value="<?= htmlspecialchars($page) ?>">

                        <label class="block text-sm">Sub Kategori</label>
                        <select name="edit_kriteria" id="edit_kriteria" class="w-full border p-2 rounded mb-3" required>
                            <?php foreach ($subkategori_list as $opt): ?>
                                <option value="<?= (int)$opt['id_kriteria'] ?>"><?= htmlspecialchars($opt['nama_kategori'] . ' - ' . $opt['kode_kriteria'] . ' ' . $opt['deskripsi']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <label class="block text-sm">Nama Komponen</label>
                        <input type="text" name="edit_komponen" id="edit_komponen" class="w-full border p-2 rounded mb-3" required onblur="this.value = this.value.replace(/\s+/g, ' ').trim();">
                        <label class="block text-sm">Tipe Input</label>
                        <select name="edit_tipe" id="edit_tipe" class="w-full border p-2 rounded mb-3" required>
                            <option value="angka">Angka</option>
                            <option value="pilihan">Pilihan</option>
                            <option value="teks">Teks</option>
                        </select>
                        <label class="block text-sm">Batas Maksimum Nilai (jika angka)</label>
                        <input type="text" name="edit_nilai_batas" id="edit_nilai_batas" class="w-full border p-2 rounded mb-3">
                        <label class="block text-sm">Pilihan Nilai (jika pilihan)</label>
                        <input type="text" name="edit_opsi" id="edit_opsi" class="w-full border p-2 rounded mb-3" placeholder="Contoh: Asli,Fotokopi">
                        <label class="block text-sm">Keterangan Penilaian Otomatis</label>
                        <input type="text" name="edit_keterangan" id="edit_keterangan" class="w-full border p-2 rounded mb-3">
                        <button type="submit" class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700 w-full">Simpan Perubahan</button>
                    </form>
                </div>
            </div>

            <div id="deleteModal" class="fixed inset-0 flex items-center justify-center hidden modal z-50" role="dialog" aria-modal="true" tabindex="-1">
                <div class="bg-white p-6 rounded-lg shadow-lg w-full max-w-md relative">
                    <h3 class="text-lg font-semibold mb-4 text-gray-800">Konfirmasi Hapus</h3>
                    <p class="mb-4 text-gray-600">Apakah kamu yakin ingin menghapus data ini?</p>
                    <form method="get" id="deleteForm">
                        <input type="hidden" name="hapus" id="delete_id">
                        <input type="hidden" name="mode" value="<?= htmlspecialchars($mode) ?>">
                        <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                        <input type="hidden" name="page" value="<?= htmlspecialchars($page) ?>">
                        <div class="flex justify-end gap-2">
                            <button type="button" onclick="closeDeleteModal()" class="px-4 py-2 bg-gray-300 rounded hover:bg-gray-400 text-gray-800">Batal</button>
                            <button id="confirmDeleteBtn" type="submit" class="px-4 py-2 bg-red-600 text-white rounded hover:bg-red-700">Hapus</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div id="sopModal" class="fixed inset-0 flex items-center justify-center hidden modal z-50" role="dialog" aria-modal="true" tabindex="-1">
        <div class="bg-white p-6 rounded-lg shadow-lg w-full max-w-lg relative">
            <button onclick="document.getElementById('sopModal').classList.add('hidden')" aria-label="Tutup panduan" class="absolute top-2 right-3 text-gray-500 hover:text-red-600 text-lg">×</button>
            <h3 class="text-lg font-semibold mb-4 text-gray-800">SOP / Tata Cara Pengisian</h3>
            <ul class="list-disc list-inside text-sm text-gray-700 space-y-2">
                <li>Pilih <b>Sub Kategori</b> sesuai variabel inspeksi.</li>
                <li>Isi <b>Nama Komponen</b> dengan jelas dan spesifik.</li>
                <li>Pilih <b>Tipe Input</b>:
                    <ul class="ml-5 list-decimal">
                        <li><b>Angka</b>: gunakan <i>nilai maksimum</i>, Contoh (5) tanpa satuan.</li>
                        <li><b>Pilihan</b>: masukkan daftar opsi, 1 baris data untuk 1 pilihan dan keterangannya.</li>
                        <li><b>Teks</b>: kolom nilai/opsi jangan diisi.</li>
                    </ul>
                </li>
                <li>Tambahkan <b>Keterangan</b> jika ada aturan penilaian otomatis.</li>
                <li>Klik <b>+ Tambah Baris</b> untuk memasukkan lebih dari satu standar sekaligus.</li>
            </ul>
            <div class="mt-4 flex justify-end">
                <button onclick="document.getElementById('sopModal').classList.add('hidden')" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Tutup</button>
            </div>
        </div>
    </div>
    <script>
        (function() {
            const formEl = document.getElementById('form-standar-edit');
            if (!formEl) return;

            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

            formEl.addEventListener('submit', async function(ev) {
                ev.preventDefault();
                const inputName = document.getElementById('komponen-name');
                const oldNameInit = inputName?.dataset.old ?? '';

                const newName = inputName?.value.trim() ?? '';
                const oldName = inputName?.dataset.old ?? oldNameInit;

                if (!oldName || !newName || oldName === newName) {
                    formEl.submit();
                    return;
                }

                try {
                    const res = await fetch('ajax_check_rename.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': csrfToken
                        },
                        body: JSON.stringify({
                            oldName: oldName,
                            newName: newName,
                            apply: false
                        })
                    });
                    const data = await res.json();
                    if (data.status !== 'ok') {
                        console.warn('Check rename error', data);
                        if (confirm('Terjadi masalah saat verifikasi rename. Lanjut simpan tanpa mapping?')) formEl.submit();
                        return;
                    }
                    const best = data.best;
                    let shouldMap = false;
                    if (best && best.score >= 50) {
                        const msg = `Sistem menemukan kandidat: "${best.candidate}" (skor: ${best.score}%).\nApakah Anda ingin MAPPING otomatis urutan (${oldName} -> ${best.candidate}) sebelum menyimpan? (OK = Map, Cancel = Tidak)`;
                        shouldMap = confirm(msg);
                    } else {
                        const msg = `Tidak ditemukan kandidat yang jelas untuk "${oldName}". Lanjut menyimpan tanpa mapping? (OK = Simpan tanpa mapping, Cancel = Batal)`;
                        if (!confirm(msg)) return;
                        formEl.submit();
                        return;
                    }

                    if (shouldMap) {
                        const res2 = await fetch('ajax_check_rename.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-Token': csrfToken
                            },
                            body: JSON.stringify({
                                oldName: oldName,
                                newName: newName,
                                apply: true
                            })
                        });
                        const data2 = await res2.json();
                        if (data2.status === 'ok' && data2.applied) {
                            formEl.submit();
                        } else {
                            alert('Gagal menerapkan mapping: ' + (data2.message || 'unknown'));
                        }
                    } else {
                        formEl.submit();
                    }
                } catch (err) {
                    console.error('Error during rename check', err);
                    if (confirm('Terjadi error saat verifikasi. Simpan tanpa mapping?')) formEl.submit();
                }
            });
        })();
    </script>
    <script>
        // safe helpers
        function q(sel, ctx = document) {
            return ctx.querySelector(sel);
        }

        function qa(sel, ctx = document) {
            return Array.from(ctx.querySelectorAll(sel));
        }

        function enableMassEdit() {
            const form = q('#massEditForm');
            if (!form) {
                alert('Form mass edit tidak ditemukan.');
                return;
            }

            // enable fields in the table
            qa('#massEditForm [disabled]').forEach(el => {
                el.disabled = false;
                el.classList.add('ring-2', 'ring-blue-100');
            });

            // swap buttons
            const btnMass = q('#btnMassEdit');
            const btnSave = q('#btnSaveAll');
            if (btnMass) btnMass.classList.add('hidden');
            if (btnSave) btnSave.classList.remove('hidden');

            // scroll to table
            q('#dataTable')?.scrollIntoView({
                behavior: 'smooth',
                block: 'center'
            });
        }

        function saveMassEdit() {
            const form = q('#massEditForm');
            if (!form) return;
            if (!confirm('Simpan semua perubahan untuk baris yang telah diedit?')) return;

            // make sure all disabled inputs are enabled so values get sent (defensive)
            qa('#massEditForm input, #massEditForm select, #massEditForm textarea').forEach(el => el.disabled = false);

            // submit
            form.submit();
        }

        // attach fallback listeners (if buttons use inline onclick they still work; but add robust handlers)
        document.addEventListener('DOMContentLoaded', function() {
            const btnMass = q('#btnMassEdit');
            const btnSave = q('#btnSaveAll');
            if (btnMass) btnMass.addEventListener('click', function(e) {
                e.preventDefault();
                enableMassEdit();
            });
            if (btnSave) btnSave.addEventListener('click', function(e) {
                e.preventDefault();
                saveMassEdit();
            });
        });
    </script>

    <script src="standar_inspeksi.js"></script>
</body>

</html>
