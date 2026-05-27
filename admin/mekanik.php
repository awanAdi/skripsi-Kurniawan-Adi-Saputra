<?php
session_start();
if (!isset($_SESSION['username']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit();
}
require_once '../includes/koneksi.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$limit = 15;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int) $_GET['page'] : 1;
$offset = ($page - 1) * $limit;
$totalQuery = $conn->query("SELECT COUNT(*) as total FROM users WHERE role IN ('admin','teknisi')");
$totalRows = $totalQuery->fetch_assoc()['total'] ?? 0;
$totalPages = max(1, ceil($totalRows / $limit));

$res = $conn->query("SELECT * FROM users WHERE role IN ('admin','teknisi') ORDER BY id_user DESC LIMIT " . (int)$limit . " OFFSET " . (int)$offset);
$users = [];
if ($res) {
    while ($r = $res->fetch_assoc()) $users[] = $r;
    $res->free();
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8" />
    <title>Manajemen Akun Rtech</title>
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-100 min-h-screen text-gray-800">
    <?php include 'admin_sidebar.php'; ?>
    <div class="max-w-5xl mx-auto p-4">
        <div class="bg-white rounded-lg shadow p-4 md:p-6">
            <div class="flex items-center justify-between mb-4">
                <h1 class="text-xl md:text-2xl font-bold text-indigo-600">Manajemen Akun Admin & Teknisi</h1>
                <a href="admin_dashboard.php" class="bg-indigo-500 text-white px-3 py-2 rounded">Dashboard</a>
            </div>

            <!-- Alert placeholder -->
            <div id="alertContainer" class="mb-4" aria-live="polite"></div>

            <!-- Add account form (AJAX) -->
            <section class="mb-6">
                <h2 class="text-md font-semibold text-gray-700 mb-2">Tambah Akun Baru</h2>
                <form id="formAdd" class="grid grid-cols-1 md:grid-cols-7 gap-3 items-end">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <div class="md:col-span-1">
                        <label class="block text-sm font-medium md:hidden">Username</label>
                        <input type="text" name="username" placeholder="Username" required class="border p-2 rounded w-full">
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium md:hidden">Nama Lengkap</label>
                        <input type="text" name="nama_lengkap" placeholder="Nama Lengkap" required class="border p-2 rounded w-full">
                    </div>
                    <div class="md:col-span-1">
                        <label class="block text-sm font-medium md:hidden">No HP</label>
                        <input type="text" name="no_hp" placeholder="No HP (10-15 digit)" required class="border p-2 rounded w-full">
                    </div>
                    <div class="relative md:col-span-1">
                        <label class="block text-sm font-medium md:hidden">Password</label>
                        <input type="password" id="password" name="password" placeholder="Password" required class="border p-2 rounded w-full pr-10">
                        <button type="button" onclick="togglePassword('password', this)" class="absolute right-2 top-2 text-gray-500">👁️</button>
                    </div>
                    <div class="relative md:col-span-1">
                        <label class="block text-sm font-medium md:hidden">Konfirmasi</label>
                        <input type="password" id="confirm_password" name="confirm_password" placeholder="Konfirmasi Password" required class="border p-2 rounded w-full pr-10">
                        <button type="button" onclick="togglePassword('confirm_password', this)" class="absolute right-2 top-2 text-gray-500">👁️</button>
                    </div>
                    <div class="md:col-span-1">
                        <label class="block text-sm font-medium md:hidden">Role</label>
                        <select name="role" class="border p-2 rounded w-full" required>
                            <option value="teknisi">Teknisi</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>

                    <div class="md:col-span-7">
                        <button id="btnAdd" type="submit" class="w-full md:w-auto bg-green-600 text-white px-4 py-3 md:py-2 rounded hover:bg-green-700 transition active:scale-95">
                            Tambah Akun
                        </button>
                    </div>
                </form>
            </section>

            <!-- Desktop table -->
            <div id="tableWrapper" class="hidden md:block overflow-x-auto">
                <table id="usersTable" class="w-full border text-sm" role="table" aria-label="Daftar akun admin dan teknisi">
                    <thead class="bg-gray-100">
                        <tr>
                            <th class="border px-4 py-2 text-left">Username</th>
                            <th class="border px-4 py-2 text-left">Nama Lengkap</th>
                            <th class="border px-4 py-2 text-left">No HP</th>
                            <th class="border px-4 py-2 text-left">Role</th>
                            <th class="border px-4 py-2 text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $row): ?>
                            <tr data-user-id="<?= (int)$row['id_user'] ?>">
                                <td class="border px-4 py-2"><?= htmlspecialchars($row['username']) ?></td>
                                <td class="border px-4 py-2"><?= htmlspecialchars($row['nama_lengkap']) ?></td>
                                <td class="border px-4 py-2"><?= htmlspecialchars($row['no_hp']) ?></td>
                                <td class="border px-4 py-2"><?= ucfirst(htmlspecialchars($row['role'])) ?></td>
                                <td class="border px-4 py-2 text-center">
                                    <button onclick="openEditModal(<?= (int)$row['id_user'] ?>)" class="bg-blue-500 text-white px-3 py-1 rounded mr-2">Edit</button>
                                    <button onclick="openResetModal(<?= (int)$row['id_user'] ?>)" class="bg-yellow-500 text-white px-3 py-1 rounded mr-2">Reset</button>
                                    <?php if ($row['username'] === $_SESSION['username']): ?>
                                        <button
                                            class="bg-red-300 text-white px-3 py-1 rounded opacity-60 cursor-not-allowed"
                                            disabled
                                            aria-disabled="true"
                                            title="Tidak bisa menghapus akun yang sedang login"
                                            tabindex="-1">Hapus</button>
                                    <?php else: ?>
                                        <button onclick="openDeleteModal(<?= (int)$row['id_user'] ?>,'<?= addslashes(htmlspecialchars($row['username'])) ?>')" class="bg-red-500 text-white px-3 py-1 rounded">Hapus</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Mobile card view -->
            <div id="cardWrapper" class="md:hidden space-y-3" aria-live="polite">
                <?php foreach ($users as $row): ?>
                    <?php $isCurrent = $row['username'] === $_SESSION['username']; ?>
                    <div class="bg-white border rounded-lg p-3 shadow-sm" data-user-id="<?= (int)$row['id_user'] ?>">
                        <div class="flex justify-between items-start gap-3">
                            <div class="min-w-0">
                                <div class="text-sm text-gray-500">Username</div>
                                <div class="font-semibold truncate"><?= htmlspecialchars($row['username']) ?></div>

                                <div class="text-sm text-gray-500 mt-2">Nama</div>
                                <div class="truncate"><?= htmlspecialchars($row['nama_lengkap']) ?></div>

                                <div class="text-sm text-gray-500 mt-2">No HP</div>
                                <div class="truncate"><?= htmlspecialchars($row['no_hp']) ?></div>

                                <div class="mt-2">
                                    <span class="inline-block px-3 py-1 text-xs rounded-full <?= $row['role'] === 'admin' ? 'bg-indigo-100 text-indigo-700' : 'bg-green-100 text-green-700' ?>">
                                        <?= ucfirst(htmlspecialchars($row['role'])) ?>
                                    </span>
                                </div>
                            </div>
                            <div class="flex flex-col items-end gap-2">
                                <button onclick="openEditModal(<?= (int)$row['id_user'] ?>)" class="bg-blue-500 text-white px-3 py-2 rounded w-28 text-sm">Edit</button>
                                <button onclick="openResetModal(<?= (int)$row['id_user'] ?>)" class="bg-yellow-500 text-white px-3 py-2 rounded w-28 text-sm">Reset</button>
                                <?php if ($isCurrent): ?>
                                    <button
                                        class="bg-red-300 text-white px-3 py-2 rounded w-28 text-sm opacity-60 cursor-not-allowed"
                                        disabled
                                        aria-disabled="true"
                                        title="Tidak bisa menghapus akun yang sedang login"
                                        tabindex="-1">Hapus</button>
                                <?php else: ?>
                                    <button onclick="openDeleteModal(<?= (int)$row['id_user'] ?>,'<?= addslashes(htmlspecialchars($row['username'])) ?>')" class="bg-red-500 text-white px-3 py-2 rounded w-28 text-sm">Hapus</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Pagination -->
            <div class="mt-4">
                <nav class="flex items-center gap-2 overflow-x-auto py-2">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?= $page - 1 ?>" class="px-3 py-2 bg-gray-200 rounded">Sebelumnya</a>
                    <?php endif; ?>
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <a href="?page=<?= $i ?>" class="px-3 py-2 <?= $i == $page ? 'bg-indigo-500 text-white' : 'bg-gray-200' ?> rounded"><?= $i ?></a>
                    <?php endfor; ?>
                    <?php if ($page < $totalPages): ?>
                        <a href="?page=<?= $page + 1 ?>" class="px-3 py-2 bg-gray-200 rounded">Berikutnya</a>
                    <?php endif; ?>
                </nav>
            </div>
        </div>
    </div>

    <!-- Edit Modal -->
    <div id="editModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-end md:items-center justify-center z-50">
        <div class="bg-white w-full md:max-w-lg rounded-t-xl md:rounded-lg p-5 md:p-6">
            <h2 class="text-lg font-semibold mb-3">Edit Akun</h2>
            <form id="formEdit">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="id" id="edit_id">
                <label class="block text-sm mb-1">Username</label>
                <input type="text" name="username" id="edit_username" required class="w-full p-3 border rounded mb-3">
                <label class="block text-sm mb-1">Nama Lengkap</label>
                <input type="text" name="nama_lengkap" id="edit_nama" required class="w-full p-3 border rounded mb-3">
                <label class="block text-sm mb-1">No HP</label>
                <input type="text" name="no_hp" id="edit_nohp" required class="w-full p-3 border rounded mb-3">
                <div class="flex justify-end gap-2">
                    <button type="button" onclick="closeEditModal()" class="px-4 py-2 bg-gray-200 rounded">Batal</button>
                    <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded">Simpan</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Reset Modal -->
    <div id="resetModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-end md:items-center justify-center z-50">
        <div class="bg-white w-full md:max-w-sm rounded-t-xl md:rounded-lg p-5 md:p-6">
            <h2 class="text-lg font-semibold mb-3">Reset Password</h2>
            <form id="formReset">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="id" id="reset_id">
                <label class="block text-sm mb-1">Password Baru</label>
                <input type="password" name="new_password" id="reset_password" required minlength="6" class="w-full p-3 border rounded mb-3">
                <div class="flex justify-end gap-2">
                    <button type="button" onclick="closeResetModal()" class="px-4 py-2 bg-gray-200 rounded">Batal</button>
                    <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded">Simpan</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Modal -->
    <div id="deleteModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-end md:items-center justify-center z-50">
        <div class="bg-white w-full md:max-w-md rounded-t-xl md:rounded-lg p-5 md:p-6">
            <h2 class="text-lg font-semibold mb-2">Konfirmasi Hapus Akun</h2>
            <p class="text-sm text-gray-700 mb-4">Apakah Anda yakin ingin menghapus akun <span id="deleteUsername" class="font-medium"></span>?</p>
            <form id="formDelete" class="flex justify-end gap-2">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="id" id="delete_id">
                <button type="button" onclick="closeDeleteModal()" class="px-4 py-2 bg-gray-200 rounded">Batal</button>
                <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded">Hapus</button>
            </form>
        </div>
    </div>

    <script>
        const CURRENT_USERNAME = '<?= addslashes($_SESSION['username']) ?>';
        const actionUrl = 'mekanik_action.php';

        function showAlert(message, type = 'success', timeout = 3500) {
            const container = document.getElementById('alertContainer');
            const color = type === 'success' ? 'green' : 'red';
            container.innerHTML = `<div class="bg-${color}-50 border border-${color}-200 text-${color}-800 p-3 rounded">${escapeHtml(message)}</div>`;
            setTimeout(() => container.innerHTML = '', timeout);
        }

        function jsonPost(formData) {
            return fetch(actionUrl, {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                })
                .then(r => r.json());
        }

        function togglePassword(id, btn) {
            const el = document.getElementById(id);
            el.type = el.type === 'password' ? 'text' : 'password';
            btn.textContent = btn.textContent === '👁️' ? '🙈' : '👁️';
        }

        document.getElementById('formAdd').addEventListener('submit', function(e) {
            e.preventDefault();
            const btn = document.getElementById('btnAdd');
            btn.disabled = true;
            btn.textContent = 'Mencipta...';

            const fd = new FormData(this);
            fd.append('action', 'add');

            jsonPost(fd).then(res => {
                btn.disabled = false;
                btn.textContent = 'Tambah Akun';
                if (res.success) {
                    showAlert(res.message, 'success');
                    addUserToDOM(res.user);
                    this.reset();
                } else {
                    showAlert(res.message || 'Gagal menambahkan akun', 'error');
                }
            }).catch(err => {
                btn.disabled = false;
                btn.textContent = 'Tambah Akun';
                showAlert('Terjadi kesalahan jaringan', 'error');
                console.error(err);
            });
        });

        function createTableRow(user) {
            const disableDelete = user.username === CURRENT_USERNAME;
            const deleteBtnHtml = disableDelete ?
                `<button class="bg-red-300 text-white px-3 py-1 rounded opacity-60 cursor-not-allowed" disabled aria-disabled="true" title="Tidak bisa menghapus akun yang sedang login" tabindex="-1">Hapus</button>` :
                `<button onclick="openDeleteModal(${user.id_user}, '${escapeAttr(user.username)}')" class="bg-red-500 text-white px-3 py-1 rounded">Hapus</button>`;

            return `
    <tr data-user-id="${user.id_user}">
        <td class="border px-4 py-2">${escapeHtml(user.username)}</td>
        <td class="border px-4 py-2">${escapeHtml(user.nama_lengkap)}</td>
        <td class="border px-4 py-2">${escapeHtml(user.no_hp)}</td>
        <td class="border px-4 py-2">${escapeHtml(capitalize(user.role))}</td>
        <td class="border px-4 py-2 text-center">
            <button onclick="openEditModal(${user.id_user})" class="bg-blue-500 text-white px-3 py-1 rounded mr-2">Edit</button>
            <button onclick="openResetModal(${user.id_user})" class="bg-yellow-500 text-white px-3 py-1 rounded mr-2">Reset</button>
            ${deleteBtnHtml}
        </td>
    </tr>`;
        }

        function createCard(user) {
            const disableDelete = user.username === CURRENT_USERNAME;
            const deleteBtnHtml = disableDelete ?
                `<button class="bg-red-300 text-white px-3 py-2 rounded w-28 text-sm opacity-60 cursor-not-allowed" disabled aria-disabled="true" title="Tidak bisa menghapus akun yang sedang login" tabindex="-1">Hapus</button>` :
                `<button onclick="openDeleteModal(${user.id_user}, '${escapeAttr(user.username)}')" class="bg-red-500 text-white px-3 py-2 rounded w-28 text-sm">Hapus</button>`;

            return `
    <div class="bg-white border rounded-lg p-3 shadow-sm" data-user-id="${user.id_user}">
        <div class="flex justify-between items-start gap-3">
            <div class="min-w-0">
                <div class="text-sm text-gray-500">Username</div>
                <div class="font-semibold truncate">${escapeHtml(user.username)}</div>
                <div class="text-sm text-gray-500 mt-2">Nama</div>
                <div class="truncate">${escapeHtml(user.nama_lengkap)}</div>
                <div class="text-sm text-gray-500 mt-2">No HP</div>
                <div class="truncate">${escapeHtml(user.no_hp)}</div>
                <div class="mt-2">
                    <span class="inline-block px-3 py-1 text-xs rounded-full ${user.role === 'admin' ? 'bg-indigo-100 text-indigo-700' : 'bg-green-100 text-green-700'}">
                        ${escapeHtml(capitalize(user.role))}
                    </span>
                </div>
            </div>
            <div class="flex flex-col items-end gap-2">
                <button onclick="openEditModal(${user.id_user})" class="bg-blue-500 text-white px-3 py-2 rounded w-28 text-sm">Edit</button>
                ${deleteBtnHtml}
            </div>
        </div>
    </div>`;
        }

        function addUserToDOM(user) {
            // table (desktop)
            const tableBody = document.querySelector('#usersTable tbody');
            if (tableBody) {
                tableBody.insertAdjacentHTML('afterbegin', createTableRow(user));
            }
            // card (mobile)
            const cardWrapper = document.getElementById('cardWrapper');
            if (cardWrapper) {
                cardWrapper.insertAdjacentHTML('afterbegin', createCard(user));
            }
        }

        function openEditModal(id) {
            const row = document.querySelector(`[data-user-id="${id}"]`);
            let username = '',
                nama = '',
                no_hp = '';
            if (row) {
                const cols = row.querySelectorAll('td');
                if (cols.length >= 3) {
                    username = cols[0].textContent.trim();
                    nama = cols[1].textContent.trim();
                    no_hp = cols[2].textContent.trim();
                } else {
                    username = row.querySelector('.font-semibold')?.textContent.trim() || '';
                    nama = row.querySelectorAll('div.truncate')[0]?.textContent.trim() || '';
                    no_hp = row.querySelectorAll('div.truncate')[1]?.textContent.trim() || '';
                }
            }
            document.getElementById('edit_id').value = id;
            document.getElementById('edit_username').value = username;
            document.getElementById('edit_nama').value = nama;
            document.getElementById('edit_nohp').value = no_hp;
            const modal = document.getElementById('editModal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            document.getElementById('edit_username').focus();
        }

        function closeEditModal() {
            const modal = document.getElementById('editModal');
            modal.classList.remove('flex');
            modal.classList.add('hidden');
        }
        document.getElementById('formEdit').addEventListener('submit', function(e) {
            e.preventDefault();
            const fd = new FormData(this);
            fd.append('action', 'edit');
            jsonPost(fd).then(res => {
                if (res.success) {
                    showAlert(res.message, 'success');
                    updateUserInDOM(res.user);
                    closeEditModal();
                } else {
                    showAlert(res.message || 'Gagal menyimpan perubahan', 'error');
                }
            }).catch(err => {
                showAlert('Terjadi kesalahan jaringan', 'error');
                console.error(err);
            });
        });

        function openResetModal(id) {
            document.getElementById('reset_id').value = id;
            document.getElementById('reset_password').value = '';
            const modal = document.getElementById('resetModal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            document.getElementById('reset_password').focus();
        }

        function closeResetModal() {
            const modal = document.getElementById('resetModal');
            modal.classList.remove('flex');
            modal.classList.add('hidden');
        }
        document.getElementById('formReset').addEventListener('submit', function(e) {
            e.preventDefault();
            const fd = new FormData(this);
            fd.append('action', 'reset_password');
            jsonPost(fd).then(res => {
                if (res.success) {
                    showAlert(res.message, 'success');
                    closeResetModal();
                } else {
                    showAlert(res.message || 'Gagal mereset password', 'error');
                }
            }).catch(err => {
                showAlert('Terjadi kesalahan jaringan', 'error');
                console.error(err);
            });
        });

        function openDeleteModal(id, username) {
            document.getElementById('delete_id').value = id;
            document.getElementById('deleteUsername').textContent = username;
            const modal = document.getElementById('deleteModal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        function closeDeleteModal() {
            const modal = document.getElementById('deleteModal');
            modal.classList.remove('flex');
            modal.classList.add('hidden');
        }
        document.getElementById('formDelete').addEventListener('submit', function(e) {
            e.preventDefault();
            const fd = new FormData(this);
            fd.append('action', 'delete');
            jsonPost(fd).then(res => {
                if (res.success) {
                    showAlert(res.message, 'success');
                    removeUserFromDOM(res.id);
                    closeDeleteModal();
                } else {
                    showAlert(res.message || 'Gagal menghapus akun', 'error');
                }
            }).catch(err => {
                showAlert('Terjadi kesalahan jaringan', 'error');
                console.error(err);
            });
        });

        function updateUserInDOM(user) {
            const row = document.querySelector(`#usersTable tr[data-user-id="${user.id_user}"]`);
            if (row) {
                row.children[0].textContent = user.username;
                row.children[1].textContent = user.nama_lengkap;
                row.children[2].textContent = user.no_hp;
                row.children[3].textContent = capitalize(user.role);

                const disableDelete = user.username === CURRENT_USERNAME;
                const actionsCell = row.children[4];
                actionsCell.innerHTML = `
            <button onclick="openEditModal(${user.id_user})" class="bg-blue-500 text-white px-3 py-1 rounded mr-2">Edit</button>
            <button onclick="openResetModal(${user.id_user})" class="bg-yellow-500 text-white px-3 py-1 rounded mr-2">Reset</button>
            ${ disableDelete
                ? '<button class="bg-red-300 text-white px-3 py-1 rounded opacity-60 cursor-not-allowed" disabled aria-disabled="true" title="Tidak bisa menghapus akun yang sedang login" tabindex="-1">Hapus</button>'
                : `<button onclick="openDeleteModal(${user.id_user}, '${escapeAttr(user.username)}')" class="bg-red-500 text-white px-3 py-1 rounded">Hapus</button>`
            }
        `;
            }

            const card = document.querySelector(`#cardWrapper [data-user-id="${user.id_user}"]`);
            if (card) {
                const usernameEl = card.querySelector('.font-semibold');
                if (usernameEl) usernameEl.textContent = user.username;
                const truncates = card.querySelectorAll('div.truncate');
                if (truncates[0]) truncates[0].textContent = user.nama_lengkap;
                if (truncates[1]) truncates[1].textContent = user.no_hp;

                // update action buttons on card
                const disableDelete = user.username === CURRENT_USERNAME;
                const actionArea = card.querySelector('div.flex.flex-col.items-end');
                if (actionArea) {
                    actionArea.innerHTML = `
                <button onclick="openEditModal(${user.id_user})" class="bg-blue-500 text-white px-3 py-2 rounded w-28 text-sm">Edit</button>
                ${ disableDelete
                    ? '<button class="bg-red-300 text-white px-3 py-2 rounded w-28 text-sm opacity-60 cursor-not-allowed" disabled aria-disabled="true" title="Tidak bisa menghapus akun yang sedang login" tabindex="-1">Hapus</button>'
                    : `<button onclick="openDeleteModal(${user.id_user}, '${escapeAttr(user.username)}')" class="bg-red-500 text-white px-3 py-2 rounded w-28 text-sm">Hapus</button>`
                }
            `;
                }
            }
        }

        function removeUserFromDOM(id) {
            const row = document.querySelector(`#usersTable tr[data-user-id="${id}"]`);
            if (row) row.remove();
            const card = document.querySelector(`#cardWrapper [data-user-id="${id}"]`);
            if (card) card.remove();
        }

        function escapeHtml(s = '') {
            return String(s)
                .replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;').replaceAll("'", '&#039;');
        }

        function escapeAttr(s = '') {
            return String(s).replaceAll("'", "\\'").replaceAll('"', '&quot;');
        }

        function capitalize(s = '') {
            return String(s).charAt(0).toUpperCase() + String(s).slice(1);
        }
    </script>
</body>

</html>
