<?php
session_start();
require_once '../includes/koneksi.php';

if (!isset($_SESSION['username']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit();
}

$success = $error = "";

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function validate_csrf($token)
{
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

if (isset($_POST['tambah'])) {
    if (!validate_csrf($_POST['csrf_token'] ?? '')) {
        $error = "Token keamanan tidak valid.";
    } else {
        $username          = trim($_POST['username']);
        $password_plain    = $_POST['password'] ?? '';
        $confirm_password  = $_POST['confirm_password'] ?? '';
        $nama              = trim($_POST['nama_lengkap']);
        $email             = filter_var(trim($_POST['email']), FILTER_VALIDATE_EMAIL);
        $no_hp             = preg_replace('/\D/', '', $_POST['no_hp']);

        if (!$email) {
            $error = "Format email tidak valid.";
        } elseif ($password_plain !== $confirm_password) {
            $error = "Konfirmasi password tidak cocok.";
        } elseif (strlen($password_plain) < 6) {
            $error = "Password minimal 6 karakter.";
        } else {
            $check = $conn->prepare("SELECT id_user FROM users WHERE username=? OR no_hp=?");
            $check->bind_param("ss", $username, $no_hp);
            $check->execute();
            $check->store_result();
            if ($check->num_rows > 0) {
                $error = "Username atau No HP sudah digunakan.";
            } else {
                $password_hash = password_hash($password_plain, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("
                    INSERT INTO users 
                    (username, password, nama_lengkap, email, no_hp, role, status_aktif) 
                    VALUES (?, ?, ?, ?, ?, 'pelanggan', 1)
                ");
                $stmt->bind_param("sssss", $username, $password_hash, $nama, $email, $no_hp);
                $success = $stmt->execute() ? "Pelanggan berhasil ditambahkan." : "Gagal menambahkan pelanggan.";
                $stmt->close();
            }
            $check->close();
        }
    }
}

if (isset($_POST['edit'])) {
    if (!validate_csrf($_POST['csrf_token'] ?? '')) {
        $error = "Token keamanan tidak valid.";
    } else {
        $id       = intval($_POST['id']);
        $username = trim($_POST['username']);
        $nama     = trim($_POST['nama_lengkap']);
        $email    = filter_var(trim($_POST['email']), FILTER_VALIDATE_EMAIL);
        $no_hp    = preg_replace('/\D/', '', $_POST['no_hp']);
        $status   = isset($_POST['status_aktif']) ? 1 : 0;

        if (!$email) {
            $error = "Format email tidak valid.";
        } else {
            $check = $conn->prepare("SELECT id_user FROM users WHERE (username=? OR no_hp=?) AND id_user != ?");
            $check->bind_param("ssi", $username, $no_hp, $id);
            $check->execute();
            $check->store_result();
            if ($check->num_rows > 0) {
                $error = "Username atau No HP sudah digunakan oleh pengguna lain.";
            } else {
                $get_old = $conn->prepare("SELECT username, nama_lengkap, email, no_hp, status_aktif FROM users WHERE id_user=? AND role='pelanggan'");
                $get_old->bind_param("i", $id);
                $get_old->execute();
                $old = $get_old->get_result()->fetch_assoc();
                $get_old->close();

                if (
                    $old &&
                    $old['username'] === $username &&
                    $old['nama_lengkap'] === $nama &&
                    $old['email'] === $email &&
                    $old['no_hp'] === $no_hp &&
                    intval($old['status_aktif']) === $status
                ) {
                    $error = "Tidak ada perubahan yang dilakukan.";
                } else {
                    $stmt = $conn->prepare("UPDATE users SET username=?, nama_lengkap=?, email=?, no_hp=?, status_aktif=? WHERE id_user=? AND role='pelanggan'");
                    $stmt->bind_param("ssssii", $username, $nama, $email, $no_hp, $status, $id);
                    $success = $stmt->execute() ? "Data pelanggan berhasil diperbarui." : "Gagal memperbarui data pelanggan.";
                    $stmt->close();
                }
            }
            $check->close();
        }
    }
}

if (isset($_POST['reset_password'])) {
    if (!validate_csrf($_POST['csrf_token'] ?? '')) {
        $error = "Token keamanan tidak valid.";
    } else {
        $id             = intval($_POST['id']);
        $new_password   = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if ($new_password !== $confirm_password) {
            $error = "Konfirmasi password tidak cocok.";
        } elseif (strlen($new_password) < 6) {
            $error = "Password minimal 6 karakter.";
        } else {
            $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password=? WHERE id_user=? AND role='pelanggan'");
            $stmt->bind_param("si", $password_hash, $id);
            $success = $stmt->execute() ? "Password berhasil direset." : "Gagal mereset password.";
            $stmt->close();
        }
    }
}

if (isset($_POST['hapus'])) {
    if (!validate_csrf($_POST['csrf_token'] ?? '')) {
        $error = "Token keamanan tidak valid.";
    } else {
        $id = intval($_POST['id']);
        $stmt = $conn->prepare("DELETE FROM users WHERE id_user=? AND role='pelanggan'");
        $stmt->bind_param("i", $id);
        $success = $stmt->execute() ? "Akun pelanggan berhasil dihapus." : "Gagal menghapus akun pelanggan.";
        $stmt->close();
    }
}
$perPage = 10;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $perPage;

$totalQuery = $conn->query("SELECT COUNT(*) AS total FROM users WHERE role='pelanggan'");
$totalRows = $totalQuery->fetch_assoc()['total'] ?? 0;
$totalPages = max(1, ceil($totalRows / $perPage));

$query = "
    SELECT 
      u.id_user, u.username, u.nama_lengkap, u.email, u.no_hp, u.status_aktif, u.tanggal_daftar,
      COUNT(CASE WHEN o.status = 'Selesai' THEN 1 END) AS total_order,
      MAX(o.tanggal_order) AS terakhir_order
    FROM users u
    LEFT JOIN order_inspeksi o ON u.id_user = o.id_pelanggan
    WHERE u.role = 'pelanggan'
    GROUP BY u.id_user, u.username, u.nama_lengkap, u.email, u.no_hp, u.status_aktif, u.tanggal_daftar
    ORDER BY u.tanggal_daftar DESC
    LIMIT " . (int)$perPage . " OFFSET " . (int)$offset . "
";
$result = $conn->query($query);
if (!$result) {
    die("Query gagal: " . $conn->error);
}
$customers = [];
while ($r = $result->fetch_assoc()) $customers[] = $r;
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Manajemen Pelanggan</title>
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        html,
        body {
            overflow-x: hidden;
        }

        /* mobile bottom sheet behavior */
        @media (max-width: 767px) {
            .modal-sheet {
                border-top-left-radius: 12px;
                border-top-right-radius: 12px;
                height: auto;
                max-height: 90vh;
                overflow: auto;
            }
        }
    </style>
</head>

<body class="bg-gray-100 min-h-screen text-gray-800">
    <?php include 'admin_sidebar.php'; ?>

    <main class="max-w-4xl mx-auto p-4">
        <div class="bg-white rounded-xl shadow-lg p-4 md:p-6 space-y-4">
            <header class="flex items-center justify-between gap-3">
                <h1 class="text-2xl font-bold text-indigo-700">📋 Manajemen Pelanggan</h1>
                <div class="hidden md:flex items-center gap-2">
                    <a href="admin_dashboard.php" class="bg-indigo-500 text-white px-3 py-2 rounded hover:bg-indigo-600">Dashboard</a>
                </div>
            </header>

            <div id="alertContainer" aria-live="polite" class="min-h-[34px]">
                <?php if ($success): ?>
                    <div class="bg-green-50 border border-green-200 text-green-800 p-3 rounded"><?= htmlspecialchars($success) ?></div>
                <?php elseif ($error): ?>
                    <div class="bg-red-50 border border-red-200 text-red-800 p-3 rounded"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
            </div>

            <section class="bg-gray-50 p-3 rounded-lg">
                <h2 class="text-sm font-semibold text-gray-700 mb-2">➕ Tambah Pelanggan Baru</h2>
                <form id="formTambah" method="post" class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                    <div class="col-span-1">
                        <label class="block text-xs text-gray-600 md:hidden">Username</label>
                        <input name="username" required class="w-full p-2 border rounded focus:ring-2 focus:ring-indigo-400" placeholder="Username (unik)">
                    </div>

                    <div class="col-span-1">
                        <label class="block text-xs text-gray-600 md:hidden">Nama Lengkap</label>
                        <input name="nama_lengkap" required class="w-full p-2 border rounded focus:ring-2 focus:ring-indigo-400" placeholder="Nama lengkap">
                    </div>

                    <div class="col-span-1">
                        <label class="block text-xs text-gray-600 md:hidden">Email</label>
                        <input name="email" type="email" required class="w-full p-2 border rounded focus:ring-2 focus:ring-indigo-400" placeholder="email@contoh.com">
                    </div>

                    <div class="col-span-1">
                        <label class="block text-xs text-gray-600 md:hidden">No HP</label>
                        <input name="no_hp" class="w-full p-2 border rounded focus:ring-2 focus:ring-indigo-400" placeholder="08xx...">
                    </div>

                    <div class="col-span-1">
                        <label class="block text-xs text-gray-600 md:hidden">Password</label>
                        <input name="password" type="password" required class="w-full p-2 border rounded focus:ring-2 focus:ring-indigo-400" placeholder="Minimal 6 karakter">
                    </div>

                    <div class="col-span-1">
                        <label class="block text-xs text-gray-600 md:hidden">Konfirmasi</label>
                        <input name="confirm_password" type="password" required class="w-full p-2 border rounded focus:ring-2 focus:ring-indigo-400" placeholder="Ulangi password">
                    </div>

                    <div class="md:col-span-3">
                        <button type="submit" name="tambah" class="w-full md:w-auto bg-green-600 hover:bg-green-700 text-white font-semibold py-3 px-4 rounded-lg transition active:scale-95">
                            Tambah Pelanggan
                        </button>
                    </div>
                </form>
            </section>

            <div class="hidden md:block">
                <div class="overflow-x-auto bg-white rounded">
                    <table class="w-full text-sm border-collapse">
                        <thead class="bg-indigo-100 text-indigo-800">
                            <tr>
                                <th class="p-3 text-left">Username</th>
                                <th class="p-3 text-left">Nama Lengkap</th>
                                <th class="p-3 text-left">Email</th>
                                <th class="p-3 text-left">No HP</th>
                                <th class="p-3 text-center">Order Selesai</th>
                                <th class="p-3 text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($customers as $row): ?>
                                <tr class="border-b hover:bg-gray-50"
                                    data-id="<?= (int)$row['id_user'] ?>"
                                    data-username="<?= htmlspecialchars($row['username']) ?>"
                                    data-nama="<?= htmlspecialchars($row['nama_lengkap']) ?>"
                                    data-email="<?= htmlspecialchars($row['email']) ?>"
                                    data-nohp="<?= htmlspecialchars($row['no_hp']) ?>"
                                    data-status="<?= (int)$row['status_aktif'] ?>">
                                    <td class="p-3"><?= htmlspecialchars($row['username']) ?></td>
                                    <td class="p-3"><?= htmlspecialchars($row['nama_lengkap']) ?></td>
                                    <td class="p-3"><?= htmlspecialchars($row['email']) ?></td>
                                    <td class="p-3"><?= htmlspecialchars($row['no_hp']) ?></td>
                                    <td class="p-3 text-center"><?= (int)$row['total_order'] ?></td>
                                    <td class="p-3 text-center">
                                        <div class="inline-flex items-center gap-2">
                                            <button type="button" onclick="openEditModal(<?= (int)$row['id_user'] ?>)" class="bg-blue-500 hover:bg-blue-600 text-white px-3 py-1 rounded">✏️ Edit</button>
                                            <button type="button" onclick="openResetModal(<?= (int)$row['id_user'] ?>)" class="bg-yellow-500 hover:bg-yellow-600 text-white px-3 py-1 rounded">🔑 Reset</button>
                                            <button
                                                type="button"
                                                class="btn-delete bg-red-500 hover:bg-red-600 text-white px-3 py-2 rounded text-sm"
                                                data-id="<?= (int)$row['id_user'] ?>"
                                                data-username="<?= htmlspecialchars($row['username'], ENT_QUOTES) ?>">
                                                🗑️ Hapus
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="md:hidden space-y-3">
                <?php foreach ($customers as $row): ?>
                    <article class="bg-white p-3 rounded-lg shadow-sm"
                        data-id="<?= (int)$row['id_user'] ?>"
                        data-username="<?= htmlspecialchars($row['username']) ?>"
                        data-nama="<?= htmlspecialchars($row['nama_lengkap']) ?>"
                        data-email="<?= htmlspecialchars($row['email']) ?>"
                        data-nohp="<?= htmlspecialchars($row['no_hp']) ?>"
                        data-status="<?= (int)$row['status_aktif'] ?>">
                        <div class="flex justify-between items-start gap-3">
                            <div class="min-w-0">
                                <div class="text-xs text-gray-500">Username</div>
                                <div class="font-semibold truncate"><?= htmlspecialchars($row['username']) ?></div>

                                <div class="text-xs text-gray-500 mt-2">Nama</div>
                                <div class="truncate"><?= htmlspecialchars($row['nama_lengkap']) ?></div>

                                <div class="text-xs text-gray-500 mt-2">No HP</div>
                                <div class="truncate"><?= htmlspecialchars($row['no_hp']) ?></div>

                                <div class="text-xs text-gray-500 mt-2">Email</div>
                                <div class="truncate text-sm"><?= htmlspecialchars($row['email']) ?></div>

                                <div class="mt-2">
                                    <span class="inline-block px-2 py-1 text-xs rounded bg-indigo-50 text-indigo-700">Orders selesai: <?= (int)$row['total_order'] ?></span>
                                </div>
                            </div>

                            <div class="flex flex-col items-end gap-2">
                                <button onclick="openEditModal(<?= (int)$row['id_user'] ?>)" class="w-28 bg-blue-500 hover:bg-blue-600 text-white px-3 py-2 rounded text-sm">Edit</button>

                                <button onclick="openResetModal(<?= (int)$row['id_user'] ?>)" class="w-28 bg-yellow-500 hover:bg-yellow-600 text-white px-3 py-2 rounded text-sm">Reset</button>
                                <button
                                    type="button"
                                    class="btn-delete bg-red-500 hover:bg-red-600 text-white px-3 py-2 rounded text-sm"
                                    data-id="<?= (int)$row['id_user'] ?>"
                                    data-username="<?= htmlspecialchars($row['username'], ENT_QUOTES) ?>">
                                    🗑️ Hapus
                                </button>

                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <?php if ($totalPages > 1): ?>
                <nav class="flex items-center justify-center gap-2 mt-3 overflow-x-auto">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?= $page - 1 ?>" class="px-3 py-2 bg-indigo-500 text-white rounded hover:bg-indigo-600">‹ Prev</a>
                    <?php else: ?>
                        <span class="px-3 py-2 bg-gray-200 text-gray-500 rounded">‹ Prev</span>
                    <?php endif; ?>

                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <?php if ($i == $page): ?>
                            <span class="px-3 py-2 bg-indigo-700 text-white rounded"><?= $i ?></span>
                        <?php else: ?>
                            <a href="?page=<?= $i ?>" class="px-3 py-2 bg-gray-100 text-indigo-700 rounded hover:bg-indigo-100"><?= $i ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($page < $totalPages): ?>
                        <a href="?page=<?= $page + 1 ?>" class="px-3 py-2 bg-indigo-500 text-white rounded hover:bg-indigo-600">Next ›</a>
                    <?php else: ?>
                        <span class="px-3 py-2 bg-gray-200 text-gray-500 rounded">Next ›</span>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        </div>
    </main>

    <div id="editModal" class="fixed inset-0 z-50 hidden items-end md:items-center justify-center bg-black bg-opacity-50" role="dialog" aria-modal="true" aria-labelledby="editTitle">
        <div class="bg-white w-full md:max-w-lg modal-sheet p-4 md:rounded-lg md:p-6">
            <h2 id="editTitle" class="text-lg font-semibold mb-3">✏️ Edit Pelanggan</h2>
            <form id="formEditModal" method="post" class="space-y-3">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="id" id="edit_id">

                <label class="block text-sm">Username</label>
                <input name="username" id="edit_username" required class="w-full p-2 border rounded">

                <label class="block text-sm">Nama Lengkap</label>
                <input name="nama_lengkap" id="edit_nama" required class="w-full p-2 border rounded">

                <label class="block text-sm">Email</label>
                <input name="email" id="edit_email" type="email" required class="w-full p-2 border rounded">

                <label class="block text-sm">No HP</label>
                <input name="no_hp" id="edit_nohp" class="w-full p-2 border rounded">

                <label class="inline-flex items-center gap-2 mt-1">
                    <input type="checkbox" name="status_aktif" id="edit_status" class="rounded">
                    <span class="text-sm">Akun Aktif</span>
                </label>

                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" onclick="closeEditModal()" class="px-4 py-2 bg-gray-200 rounded">Batal</button>
                    <button type="submit" name="edit" class="px-4 py-2 bg-blue-600 text-white rounded">Simpan</button>
                </div>
            </form>
        </div>
    </div>

    <div id="resetModal" class="fixed inset-0 z-50 hidden items-end md:items-center justify-center bg-black bg-opacity-50" role="dialog" aria-modal="true" aria-labelledby="resetTitle">
        <div class="bg-white w-full md:max-w-sm modal-sheet p-4 md:rounded-lg md:p-6">
            <h2 id="resetTitle" class="text-lg font-semibold mb-3">🔑 Reset Password</h2>
            <form id="formResetModal" method="post" onsubmit="return validateResetModal()">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="id" id="reset_id">

                <label class="block text-sm">Password Baru</label>
                <input type="password" name="new_password" id="reset_new_password" required class="w-full p-2 border rounded">

                <label class="block text-sm">Konfirmasi Password</label>
                <input type="password" name="confirm_password" id="reset_confirm_password" required class="w-full p-2 border rounded">

                <div id="resetModalError" class="text-red-500 text-sm mt-1 hidden">Password tidak cocok.</div>

                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" onclick="closeResetModal()" class="px-4 py-2 bg-gray-200 rounded">Batal</button>
                    <button type="submit" name="reset_password" class="px-4 py-2 bg-green-600 text-white rounded">Simpan</button>
                </div>
            </form>
        </div>
    </div>

    <div id="deleteModal"
        class="fixed inset-0 z-50 hidden items-end md:items-center justify-center bg-black bg-opacity-50"
        role="dialog"
        aria-modal="true"
        aria-labelledby="deleteTitle">

        <div class="bg-white w-full md:max-w-sm p-4 md:rounded-lg md:p-6">
            <h2 id="deleteTitle" class="text-lg font-semibold mb-2 text-red-600">
                🗑️ Hapus Akun
            </h2>

            <p class="text-sm text-gray-600 mb-4">
                Apakah Anda yakin ingin menghapus akun
                <span id="deleteUsername" class="font-semibold"></span>?
            </p>

            <form method="post" action="">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="id" id="delete_id">

                <div class="flex justify-end gap-2">
                    <button type="button" id="btnCancelDelete"
                        class="px-4 py-2 bg-gray-200 rounded">
                        Batal
                    </button>
                    <button type="submit" name="hapus"
                        class="px-4 py-2 bg-red-600 text-white rounded">
                        Hapus
                    </button>
                </div>
            </form>
        </div>
    </div>


    <script>
        const editModal = document.getElementById('editModal');
        const resetModal = document.getElementById('resetModal');

        function openEditModal(id) {
            const row = document.querySelector(`[data-id='${id}']`);
            if (!row) return;

            document.getElementById('edit_id').value = id;
            document.getElementById('edit_username').value = row.dataset.username;
            document.getElementById('edit_nama').value = row.dataset.nama;
            document.getElementById('edit_email').value = row.dataset.email;
            document.getElementById('edit_nohp').value = row.dataset.nohp;
            document.getElementById('edit_status').checked = row.dataset.status === "1";

            editModal.classList.remove('hidden');
            editModal.classList.add('flex');
        }

        function closeEditModal() {
            editModal.classList.remove('flex');
            editModal.classList.add('hidden');
        }

        function openResetModal(id) {
            document.getElementById('reset_id').value = id;
            resetModal.classList.remove('hidden');
            resetModal.classList.add('flex');
            setTimeout(() => document.getElementById('reset_new_password').focus(), 100);
        }

        function closeResetModal() {
            resetModal.classList.remove('flex');
            resetModal.classList.add('hidden');
            document.getElementById('resetModalError').classList.add('hidden');
        }


        [editModal, resetModal, deleteModal].forEach(modal => {
            modal && modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    if (modal === editModal) closeEditModal();
                    if (modal === resetModal) closeResetModal();
                    if (modal === deleteModal) closeDeleteModal();
                }
            });
        });
        document.querySelectorAll('.modal-sheet').forEach(content => {
            content.addEventListener('click', function(e) {
                e.stopPropagation();
            });
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                if (!editModal.classList.contains('hidden')) closeEditModal();
                if (!resetModal.classList.contains('hidden')) closeResetModal();
                if (!deleteModal.classList.contains('hidden')) closeDeleteModal();
            }
        });

        function validateResetModal() {
            const a = document.getElementById('reset_new_password').value;
            const b = document.getElementById('reset_confirm_password').value;
            const err = document.getElementById('resetModalError');
            if (a !== b) {
                err.classList.remove('hidden');
                return false;
            }
            if (a.length < 6) {
                err.textContent = 'Password minimal 6 karakter.';
                err.classList.remove('hidden');
                return false;
            }
            err.classList.add('hidden');
            return true;
        }

        function showAlert(msg, type = 'success') {
            const container = document.getElementById('alertContainer');
            container.innerHTML = `<div class="p-3 rounded ${type === 'success' ? 'bg-green-50 border border-green-200 text-green-800' : 'bg-red-50 border border-red-200 text-red-800'}">${msg}</div>`;
            setTimeout(() => container.innerHTML = '', 4000);
        }

        document.querySelectorAll('button').forEach(btn => {
            btn.classList.add('touch-manipulation');
        });
    </script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const deleteModal = document.getElementById('deleteModal');
            const deleteIdInput = document.getElementById('delete_id');
            const deleteUsername = document.getElementById('deleteUsername');
            const cancelBtn = document.getElementById('btnCancelDelete');

            // Buka modal
            document.querySelectorAll('.btn-delete').forEach(button => {
                button.addEventListener('click', () => {
                    deleteIdInput.value = button.dataset.id;
                    deleteUsername.textContent = button.dataset.username;

                    deleteModal.classList.remove('hidden');
                    deleteModal.classList.add('flex');
                });
            });

            cancelBtn.addEventListener('click', closeDeleteModal);

            deleteModal.addEventListener('click', e => {
                if (e.target === deleteModal) {
                    closeDeleteModal();
                }
            });

            function closeDeleteModal() {
                deleteModal.classList.add('hidden');
                deleteModal.classList.remove('flex');
            }
        });
    </script>

</body>

</html>