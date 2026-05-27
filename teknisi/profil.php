<?php
session_start();
require_once '../includes/koneksi.php';

if (!isset($_SESSION['username'], $_SESSION['role']) || $_SESSION['role'] !== 'teknisi') {
    header("Location: ../auth/login.php");
    exit();
}

$username = htmlspecialchars($_SESSION['username'], ENT_QUOTES, 'UTF-8');

$stmt = $conn->prepare("SELECT id_user, username, nama_lengkap, email, no_hp, link_gmaps, role 
                        FROM users WHERE username = ?");
$stmt->bind_param("s", $_SESSION['username']);
$stmt->execute();
$result = $stmt->get_result();
$data_user = $result->fetch_assoc();
$stmt->close();
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Profil Teknisi</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" crossorigin="anonymous" />
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
</head>

<body class="bg-gray-100 min-h-screen font-sans">
    <!-- Header -->
    <header class="bg-black text-white px-6 py-4 flex justify-between items-center">
        <div class="flex items-center space-x-3">
            <img src="e62b0286-8bb2-4260-9ec0-825b4f890808.png" alt="Logo RTECH" class="h-8">
            <span class="font-bold">RTECH JASA INSPEKSI</span>
        </div>
        <a href="teknisi_dashboard.php" class="text-sm hover:underline">← Kembali</a>
    </header>

    <!-- Konten Utama -->
    <main class="max-w-md mx-auto p-6">
        <h2 class="text-xl font-semibold text-gray-800 mb-4">
            <i class="fas fa-user-circle text-orange-500"></i> Profil Teknisi
        </h2>

        <div class="bg-white shadow rounded-2xl p-4 space-y-4">
            <div>
                <p class="text-gray-500 text-sm">Nama Lengkap</p>
                <p class="font-semibold"><?= htmlspecialchars($data_user['nama_lengkap'] ?? '-') ?></p>
            </div>
            <div>
                <p class="text-gray-500 text-sm">Username</p>
                <p class="font-semibold"><?= htmlspecialchars($data_user['username']) ?></p>
            </div>
            <div>
                <p class="text-gray-500 text-sm">Email</p>
                <p class="font-semibold"><?= htmlspecialchars($data_user['email'] ?? '-') ?></p>
            </div>
            <div>
                <p class="text-gray-500 text-sm">No. HP</p>
                <p class="font-semibold"><?= htmlspecialchars($data_user['no_hp'] ?? '-') ?></p>
            </div>
            <div>
                <p class="text-gray-500 text-sm">Role</p>
                <p class="font-semibold"><?= htmlspecialchars($data_user['role']) ?></p>
            </div>
            <div>
                <p class="text-gray-500 text-sm">Lokasi (Google Maps)</p>
                <?php if (!empty($data_user['link_gmaps']) && preg_match('/@(-?\d+\.\d+),(-?\d+\.\d+)/', $data_user['link_gmaps'], $match)): ?>
                    <div id="map-view" style="height:200px; border-radius:8px;" class="mt-2"></div>
                    <script>
                        document.addEventListener("DOMContentLoaded", function() {
                            const lat = parseFloat("<?= $match[1] ?>");
                            const lng = parseFloat("<?= $match[2] ?>");
                            const mapView = L.map('map-view').setView([lat, lng], 15);
                            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                                attribution: '&copy; OpenStreetMap'
                            }).addTo(mapView);
                            L.marker([lat, lng]).addTo(mapView);
                        });
                    </script>
                <?php else: ?>
                    <p class="font-semibold text-gray-400 italic">Belum diatur</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="mt-6 flex space-x-4">
            <button onclick="showEditProfil()" class="flex-1 bg-orange-500 text-white py-2 rounded-xl hover:bg-orange-600">
                <i class="fas fa-edit"></i> Edit Profil
            </button>
            <button onclick="showUbahPassword()" class="flex-1 bg-blue-500 text-white py-2 rounded-xl hover:bg-blue-600">
                <i class="fas fa-key"></i> Ubah Password
            </button>
        </div>
    </main>

    <script>
        function showEditProfil() {
            Swal.fire({
                title: 'Edit Profil',
                html: `
            <form id="form-edit" class="space-y-2">
                <input type="text" id="nama_lengkap" class="swal2-input" placeholder="Nama Lengkap" value="<?= htmlspecialchars($data_user['nama_lengkap'] ?? '') ?>">
                <input type="email" id="email" class="swal2-input" placeholder="Email" value="<?= htmlspecialchars($data_user['email'] ?? '') ?>">
                <input type="text" id="no_hp" class="swal2-input" placeholder="No HP" value="<?= htmlspecialchars($data_user['no_hp'] ?? '') ?>">

                <div id="map" style="height:250px; border-radius:8px; margin-top:6px;"></div>
                <input type="hidden" id="link_gmaps" value="<?= htmlspecialchars($data_user['link_gmaps'] ?? '') ?>">
                <small class="text-gray-500">Klik pada peta untuk memilih lokasi.</small>
            </form>
        `,
                showCancelButton: true,
                cancelButtonText: 'Batal',
                confirmButtonText: 'Simpan',
                showCloseButton: true,
                didOpen: () => {
                    // buat map di scope fungsi supaya bisa di-remove saat ditutup
                    let prevVal = document.getElementById('link_gmaps').value;
                    // global untuk closure willClose
                    window._swal_edit_map = window._swal_edit_map || {};
                    const obj = window._swal_edit_map;
                    // init map only once per open
                    obj.map = L.map('map').setView([-7.7956, 110.3695], 12);
                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        attribution: '&copy; OpenStreetMap'
                    }).addTo(obj.map);

                    obj.marker = null;
                    if (prevVal) {
                        const match = prevVal.match(/@(-?\d+\.\d+),(-?\d+\.\d+)/);
                        if (match) {
                            const latlng = [parseFloat(match[1]), parseFloat(match[2])];
                            obj.marker = L.marker(latlng).addTo(obj.map);
                            obj.map.setView(latlng, 15);
                        }
                    }

                    obj.map.on('click', function(e) {
                        if (obj.marker) obj.map.removeLayer(obj.marker);
                        obj.marker = L.marker(e.latlng).addTo(obj.map);
                        document.getElementById('link_gmaps').value =
                            `https://www.google.com/maps/@${e.latlng.lat},${e.latlng.lng},17z`;
                    });
                },
                willClose: () => {
                    // bersihkan map/marker untuk mencegah memory leak
                    if (window._swal_edit_map && window._swal_edit_map.map) {
                        try {
                            if (window._swal_edit_map.marker) window._swal_edit_map.map.removeLayer(window._swal_edit_map.marker);
                        } catch (e) {
                            /* noop */
                        }
                        try {
                            window._swal_edit_map.map.remove();
                        } catch (e) {
                            /* noop */
                        }
                        window._swal_edit_map = null;
                    }
                },
                preConfirm: () => {
                    return {
                        nama_lengkap: document.getElementById('nama_lengkap').value,
                        email: document.getElementById('email').value,
                        no_hp: document.getElementById('no_hp').value,
                        link_gmaps: document.getElementById('link_gmaps').value
                    }
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    fetch('proses_edit_profil.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify(result.value)
                        })
                        .then(res => res.json())
                        .then(data => {
                            Swal.fire({
                                title: data.status === 'success' ? 'Berhasil!' : 'Gagal!',
                                text: data.message,
                                icon: data.status === 'success' ? 'success' : 'error',
                                confirmButtonText: 'OK'
                            }).then(() => {
                                if (data.status === 'success') location.reload();
                            });
                        });
                }
            });
        }

        function showUbahPassword() {
            Swal.fire({
                title: 'Ubah Password',
                html: `
            <form id="form-pass">
                <input type="password" id="password_lama" class="swal2-input" placeholder="Password Lama">
                <input type="password" id="password_baru" class="swal2-input" placeholder="Password Baru">
                <input type="password" id="konfirmasi_password" class="swal2-input" placeholder="Konfirmasi Password Baru">
            </form>
        `,
                showCancelButton: true,
                confirmButtonText: 'Ubah',
                cancelButtonText: 'Batal',
                preConfirm: () => {
                    const pw1 = document.getElementById('password_baru').value;
                    const pw2 = document.getElementById('konfirmasi_password').value;
                    if (pw1 !== pw2) {
                        Swal.showValidationMessage('Konfirmasi password tidak cocok!');
                        return false;
                    }
                    return {
                        password_lama: document.getElementById('password_lama').value,
                        password_baru: pw1
                    }
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    fetch('proses_ubah_password.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify(result.value)
                        })
                        .then(res => res.json())
                        .then(data => {
                            Swal.fire({
                                title: data.status === 'success' ? 'Berhasil!' : 'Gagal!',
                                text: data.message,
                                icon: data.status === 'success' ? 'success' : 'error',
                                confirmButtonText: 'OK'
                            }).then(() => {
                                if (data.status === 'success') location.reload();
                            });
                        });
                }
            });
        }
    </script>
</body>

</html>