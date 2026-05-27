<?php
session_start();
require_once '../includes/koneksi.php';

if (!isset($_SESSION['username']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$pelanggan_result = $conn->query("SELECT id_user, nama_lengkap FROM users WHERE role = 'pelanggan'");

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
date_default_timezone_set('Asia/Jakarta');
$kode_wilayah = [
    'A',
    'B',
    'D',
    'E',
    'F',
    'T',
    'Z',
    'G',
    'H',
    'K',
    'R',
    'AA',
    'AB',
    'L',
    'M',
    'N',
    'P',
    'S',
    'W',
    'AE',
    'AG',
    'DK',
    'DR',
    'EA',
    'DH',
    'EB',
    'ED',
    'DA',
    'KB',
    'KH',
    'KT',
    'KU',
    'DB',
    'DL',
    'DM',
    'DN',
    'DT',
    'DW',
    'DD',
    'DE',
    'DG',
    'PA',
    'PB',
    'BA',
    'BB',
    'BD',
    'BE',
    'BG',
    'BH',
    'BK',
    'BL',
    'BM',
    'BN',
    'BP'
];
$errors = [];
$error_flag = 0;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $jenis = $_POST['jenis_pelanggan'] ?? 'terdaftar';

    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        die("CSRF validation failed.");
    }

    if ($jenis === 'baru') {
        $nama_baru = trim($_POST['nama_baru'] ?? '');
        $nohp_baru = trim($_POST['nohp_baru'] ?? '');
        $password_baru = password_hash($_POST['password_baru'] ?? '', PASSWORD_DEFAULT);

        if (!preg_match('/^[0-9]{10,15}$/', $nohp_baru)) {
            $errors[] = "Format No HP tidak valid. Harus 10-15 digit angka.";
            $error_flag = 1;
        }

        $cek = $conn->prepare("SELECT id_user FROM users WHERE username = ?");
        $cek->bind_param("s", $nohp_baru);
        $cek->execute();
        $cek->store_result();
        if ($cek->num_rows > 0) {
            $errors[] = "Nomor HP sudah terdaftar.";
            $error_flag = 1;
        }
        $cek->close();

        if (!$error_flag) {
            $stmt_user = $conn->prepare("INSERT INTO users (username, password, role, nama_lengkap, no_hp) VALUES (?, ?, 'pelanggan', ?, ?)");
            $stmt_user->bind_param("ssss", $nohp_baru, $password_baru, $nama_baru, $nohp_baru);
            if (!$stmt_user->execute()) $error_flag = 1;
            $id_pelanggan = $stmt_user->insert_id;
            $stmt_user->close();
        }
    } else {
        $id_pelanggan = intval($_POST['id_pelanggan'] ?? 0);
    }

    $merk = trim($_POST['merk'] ?? '');
    $model = trim($_POST['model'] ?? '');
    $kepemilikan = trim($_POST['kepemilikan'] ?? '');
    $tahun = trim($_POST['tahun'] ?? '');
    $nopol = strtoupper(trim($_POST['nopol'] ?? ''));
    $alamat = trim($_POST['alamat'] ?? '');
    $lat = trim($_POST['lat'] ?? '');
    $lng = trim($_POST['lng'] ?? '');
    $link_gmaps = ($lat && $lng) ? "https://maps.google.com/?q=$lat,$lng" : '';

    if (preg_match('/^([A-Z]{1,2})\s?([0-9]{1,4})/i', $nopol, $match)) {
        $kode = strtoupper($match[1]);
        $angka = intval($match[2]);

        if (!in_array($kode, $kode_wilayah)) {
            $errors[] = "Kode wilayah '$kode' tidak dikenal.";
            $error_flag = 1;
        } elseif ($angka < 1 || $angka > 9999) {
            $errors[] = "Angka pada nomor polisi harus antara 1–9999.";
            $error_flag = 1;
        }
    } else {
        $errors[] = "Format nomor polisi tidak valid.";
        $error_flag = 1;
    }

    if (empty($lat) || empty($lng)) {
        $errors[] = "Silakan tandai lokasi mobil pada peta.";
        $error_flag = 1;
    }

    $cek_nopol = $conn->prepare("SELECT id_mobil FROM kendaraan WHERE nomor_polisi = ? AND id_pelanggan = ?");
    $cek_nopol->bind_param("si", $nopol, $id_pelanggan);
    $cek_nopol->execute();
    $cek_nopol->store_result();
    if ($cek_nopol->num_rows > 0) {
        $errors[] = "Nomor polisi sudah digunakan.";
        $error_flag = 1;
    }
    $cek_nopol->close();

    if (!$error_flag) {
        $stmt_mobil = $conn->prepare("INSERT INTO kendaraan (id_pelanggan, merk, model, tahun_produksi, nomor_polisi, kepemilikan, alamat, link_gmaps) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt_mobil->bind_param("isssssss", $id_pelanggan, $merk, $model, $tahun, $nopol, $kepemilikan, $alamat, $link_gmaps);
        if ($stmt_mobil->execute()) {
            $id_mobil = $stmt_mobil->insert_id;
            $stmt_mobil->close();
            $stmt = $conn->prepare("INSERT INTO order_inspeksi (id_pelanggan, id_mobil, id_teknisi, status) VALUES (?, ?, NULL, 'Menunggu')");
            $stmt->bind_param("ii", $id_pelanggan, $id_mobil);
            if ($stmt->execute()) {
                $status = ($jenis === 'baru') ? 2 : 1;
                header("Location: buat_order_manual.php?berhasil=$status");
                exit();
            } else {
                $errors[] = "Gagal menyimpan order.";
            }
            $stmt->close();
        } else {
            $errors[] = "Gagal menyimpan data kendaraan.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Buat Order Manual</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.3/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.3/dist/leaflet.js"></script>
    <link rel="icon" type="image/x-icon" href="../favicon.ico">
    <style>
        /* minor fixes for leaflet on mobile */
        #map {
            z-index: 0;
        }

        /* fixed bottom action on small screens */
        @media (max-width: 767px) {
            .mobile-action {
                position: fixed;
                inset: auto 0 0 0;
                padding: 0.5rem;
                background: rgba(255, 255, 255, 0.95);
                box-shadow: 0 -6px 18px rgba(15, 23, 42, 0.06);
                z-index: 60;
            }
        }
    </style>
</head>

<body class="bg-gray-100 min-h-screen">
    <div class="max-w-4xl mx-auto p-4 md:p-6">
        <div class="bg-white p-5 rounded-lg shadow">
            <div class="flex items-center justify-between mb-4">
                <h1 class="text-lg font-bold text-indigo-600">Form Order Manual oleh Admin</h1>
                <a href="buat_task.php" class="text-sm bg-gray-200 px-3 py-1 rounded hover:bg-gray-300">← Kembali</a>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="bg-red-50 border border-red-200 text-red-700 p-3 rounded mb-4">
                    <h3 class="font-semibold">Terjadi kesalahan:</h3>
                    <ul class="list-disc ml-5 mt-2 text-sm">
                        <?php foreach ($errors as $e): ?>
                            <li><?= htmlspecialchars($e) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="post" id="formOrder" class="space-y-6">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                <div class="bg-gray-50 border rounded p-4">
                    <h2 class="text-md font-semibold mb-2">Data Pelanggan</h2>

                    <label class="block text-sm mb-2">Jenis Pelanggan</label>
                    <select name="jenis_pelanggan" id="jenis_pelanggan" onchange="togglePelanggan(this.value)" class="w-full border rounded p-2">
                        <option value="terdaftar" <?= ($_POST['jenis_pelanggan'] ?? '') === 'terdaftar' ? 'selected' : '' ?>>Pelanggan Terdaftar</option>
                        <option value="baru" <?= ($_POST['jenis_pelanggan'] ?? '') === 'baru' ? 'selected' : '' ?>>Pelanggan Baru</option>
                    </select>

                    <div id="form_terdaftar" class="mt-3">
                        <label class="text-sm">Pilih Pelanggan</label>
                        <select name="id_pelanggan" id="id_pelanggan" class="w-full border rounded p-2 mt-1">
                            <?php if ($pelanggan_result && $pelanggan_result->num_rows > 0): ?>
                                <?php
                                // preserve previous selected
                                $selected_pelanggan = intval($_POST['id_pelanggan'] ?? 0);
                                $pelanggan_result->data_seek(0);
                                while ($p = $pelanggan_result->fetch_assoc()):
                                ?>
                                    <option value="<?= (int)$p['id_user'] ?>" <?= $selected_pelanggan === (int)$p['id_user'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($p['nama_lengkap']) ?>
                                    </option>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <option disabled>Tidak ada pelanggan terdaftar</option>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div id="form_baru" class="mt-3 hidden">
                        <label class="text-sm">Nama Lengkap</label>
                        <input type="text" name="nama_baru" id="nama_baru" class="w-full border rounded p-2 mt-1" value="<?= htmlspecialchars($_POST['nama_baru'] ?? '') ?>">

                        <label class="text-sm mt-3">No HP</label>
                        <input type="tel" name="nohp_baru" id="nohp_baru" pattern="[0-9]{10,15}" title="Hanya angka 10-15 digit" class="w-full border rounded p-2 mt-1" value="<?= htmlspecialchars($_POST['nohp_baru'] ?? '') ?>">

                        <label class="text-sm mt-3">Password Sementara</label>
                        <input type="password" name="password_baru" id="password_baru" class="w-full border rounded p-2 mt-1">
                    </div>
                </div>

                <div class="bg-gray-50 border rounded p-4">
                    <h2 class="text-md font-semibold mb-2">Data Kendaraan</h2>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <input type="text" name="merk" placeholder="Merk Mobil" required class="border rounded p-2" value="<?= htmlspecialchars($_POST['merk'] ?? '') ?>">
                        <input type="text" name="model" placeholder="Model Mobil" required class="border rounded p-2" value="<?= htmlspecialchars($_POST['model'] ?? '') ?>">
                        <select name="kepemilikan" required class="border rounded p-2">
                            <option value="">Pilih Kepemilikan</option>
                            <option value="Pribadi" <?= ($_POST['kepemilikan'] ?? '') === 'Pribadi' ? 'selected' : '' ?>>Pribadi</option>
                            <option value="Perusahaan" <?= ($_POST['kepemilikan'] ?? '') === 'Perusahaan' ? 'selected' : '' ?>>Perusahaan</option>
                            <option value="Tidak Mengetahui" <?= ($_POST['kepemilikan'] ?? '') === 'Tidak Mengetahui' ? 'selected' : '' ?>>Tidak Mengetahui</option>
                        </select>
                        <input type="number" name="tahun" placeholder="Tahun Produksi" required min="1900" max="2099" class="border rounded p-2" value="<?= htmlspecialchars($_POST['tahun'] ?? '') ?>">
                        <input type="text" name="nopol" id="nopol" placeholder="Nomor Polisi (contoh: B 1234 AB)" required class="border rounded p-2" value="<?= htmlspecialchars($_POST['nopol'] ?? '') ?>">
                        <textarea name="alamat" placeholder="Alamat Mobil" required rows="2" class="border rounded p-2 md:col-span-2"><?= htmlspecialchars($_POST['alamat'] ?? '') ?></textarea>
                    </div>

                    <div class="mt-4">
                        <label class="block text-sm mb-2">Tandai Lokasi Mobil (klik pada peta)</label>
                        <div id="map" class="w-full h-56 md:h-60 rounded border"></div>
                        <div class="mt-2 flex gap-2 items-center">
                            <button type="button" id="btn-geolocate" class="bg-indigo-600 text-white px-3 py-2 rounded text-sm hover:bg-indigo-700">Gunakan Lokasi Saya</button>
                            <span id="gmapsPreview" class="text-sm text-gray-600 truncate"></span>
                        </div>
                        <input type="hidden" name="lat" id="lat" value="<?= htmlspecialchars($_POST['lat'] ?? '') ?>">
                        <input type="hidden" name="lng" id="lng" value="<?= htmlspecialchars($_POST['lng'] ?? '') ?>">
                    </div>
                </div>

                <div class="hidden md:flex justify-end">
                    <button type="button" id="btnReviewDesktop" onclick="if(validasiFormSebelumReview()) tampilkanReview()" class="bg-indigo-600 text-white px-4 py-2 rounded hover:bg-indigo-700">Simpan Order</button>
                </div>

                <!-- mobile action bottom -->
                <div class="mobile-action md:hidden flex justify-center" role="region" aria-label="Tindakan">
                    <button type="button" id="btnReviewMobile" onclick="if(validasiFormSebelumReview()) tampilkanReview()" class="w-full max-w-lg bg-indigo-600 text-white px-4 py-3 rounded-lg">Simpan Order</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Error -->
    <div id="modalError" class="hidden fixed inset-0 bg-black bg-opacity-50 items-center justify-center z-50">
        <div class="bg-white rounded-lg shadow-lg w-full max-w-md p-5 m-4">
            <h2 class="text-lg font-bold text-red-600 mb-3">❌ Form Tidak Valid</h2>
            <ul id="errorList" class="list-disc ml-5 text-sm text-gray-700 space-y-1"></ul>
            <div class="flex justify-end mt-4">
                <button id="closeErrorBtn" class="bg-gray-300 px-3 py-1 rounded hover:bg-gray-400">Tutup</button>
            </div>
        </div>
    </div>

    <!-- Modal Review -->
    <div id="modalReview" class="hidden fixed inset-0 bg-black bg-opacity-50 items-center justify-center z-50">
        <div class="bg-white rounded-lg shadow-lg w-full max-w-xl p-5 m-4">
            <h2 class="text-lg font-bold text-indigo-600 mb-3">Review Order</h2>
            <div class="space-y-2 text-sm text-gray-700">
                <p><strong>Jenis Pelanggan:</strong> <span id="reviewJenis"></span></p>
                <p><strong>Nama:</strong> <span id="reviewNama"></span></p>
                <p><strong>No HP:</strong> <span id="reviewNoHP"></span></p>
                <p><strong>Merk:</strong> <span id="reviewMerk"></span></p>
                <p><strong>Model:</strong> <span id="reviewModel"></span></p>
                <p><strong>Kepemilikan:</strong> <span id="reviewKepemilikan"></span></p>
                <p><strong>Tahun:</strong> <span id="reviewTahun"></span></p>
                <p><strong>No Polisi:</strong> <span id="reviewNopol"></span></p>
                <p><strong>Alamat:</strong> <span id="reviewAlamat"></span></p>
                <p><strong>Lokasi (lat,lng):</strong> <span id="reviewLatLng"></span></p>
            </div>
            <div class="flex justify-end gap-2 mt-4">
                <button id="cancelReviewBtn" class="bg-gray-300 px-3 py-1 rounded hover:bg-gray-400">Batal</button>
                <button id="confirmReviewBtn" class="bg-green-600 px-4 py-2 text-white rounded hover:bg-green-700">Konfirmasi</button>
            </div>
        </div>
    </div>

    <!-- Popup hasil -->
    <?php if (isset($_GET['berhasil'])): ?>
        <div id="popup" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
            <div class="bg-white p-6 rounded-lg shadow-lg w-full max-w-sm text-center">
                <?php if ($_GET['berhasil'] == 1): ?>
                    <h2 class="text-green-600 font-bold text-lg mb-4">✅ Order untuk Pelanggan Terdaftar Berhasil!</h2>
                    <a href="buat_task.php" class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700">Dashboard</a>
                <?php elseif ($_GET['berhasil'] == 2): ?>
                    <h2 class="text-green-600 font-bold text-lg mb-4">✅ Order + Akun Pelanggan Baru Berhasil Dibuat!</h2>
                    <a href="buat_task.php" class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700">Dashboard</a>
                <?php else: ?>
                    <h2 class="text-red-600 font-bold text-lg mb-4">❌ Gagal Membuat Order</h2>
                    <div class="flex justify-center space-x-2">
                        <a href="buat_order_manual.php" class="bg-yellow-500 text-white px-4 py-2 rounded hover:bg-yellow-600">Coba Lagi</a>
                        <a href="buat_task.php" class="bg-gray-500 text-white px-4 py-2 rounded hover:bg-gray-600">Asign Teknisi</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <script>
            setTimeout(() => {
                const p = document.getElementById("popup");
                if (p) p.remove();
            }, 5000);
        </script>
    <?php endif; ?>

    <script>
        // data from php
        const kodeWilayah = <?= json_encode($kode_wilayah) ?>;
        const form = document.getElementById('formOrder');
        const jenisSelect = document.getElementById('jenis_pelanggan');
        const formBaru = document.getElementById('form_baru');
        const formTerdaftar = document.getElementById('form_terdaftar');
        const errorModal = document.getElementById('modalError');
        const errorList = document.getElementById('errorList');
        const closeErrorBtn = document.getElementById('closeErrorBtn');
        const modalReview = document.getElementById('modalReview');
        const confirmReviewBtn = document.getElementById('confirmReviewBtn');
        const cancelReviewBtn = document.getElementById('cancelReviewBtn');

        function togglePelanggan(val) {
            if (val === 'baru') {
                formBaru.classList.remove('hidden');
                formTerdaftar.classList.add('hidden');
                // set required
                document.getElementById('nama_baru').setAttribute('required', 'required');
                document.getElementById('nohp_baru').setAttribute('required', 'required');
                document.getElementById('password_baru').setAttribute('required', 'required');
            } else {
                formBaru.classList.add('hidden');
                formTerdaftar.classList.remove('hidden');
                document.getElementById('nama_baru').removeAttribute('required');
                document.getElementById('nohp_baru').removeAttribute('required');
                document.getElementById('password_baru').removeAttribute('required');
            }
        }

        // initialize visibility on load (preserve state)
        document.addEventListener('DOMContentLoaded', function() {
            togglePelanggan(jenisSelect.value);

            // close error modal
            closeErrorBtn && closeErrorBtn.addEventListener('click', function() {
                errorModal.classList.add('hidden');
            });

            // modal review events
            cancelReviewBtn && cancelReviewBtn.addEventListener('click', function() {
                modalReview.classList.add('hidden');
            });
            confirmReviewBtn && confirmReviewBtn.addEventListener('click', function() {
                // prevent double submit
                confirmReviewBtn.disabled = true;
                confirmReviewBtn.textContent = 'Mengirim...';
                form.submit();
            });
        });

        const latInput = document.getElementById('lat');
        const lngInput = document.getElementById('lng');
        const gmapsPreview = document.getElementById('gmapsPreview');

        let defaultLat = parseFloat(latInput.value) || -7.795580; // Yogyakarta
        let defaultLng = parseFloat(lngInput.value) || 110.369490;

        const map = L.map('map', {
            scrollWheelZoom: false
        }).setView([defaultLat, defaultLng], 13);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: false
        }).addTo(map);

        let marker = null;

        function placeMarker(lat, lng, move = true) {
            if (marker) {
                marker.setLatLng([lat, lng]);
            } else {
                marker = L.marker([lat, lng], {
                    draggable: true
                }).addTo(map);
                marker.on('dragend', function(e) {
                    const p = e.target.getLatLng();
                    updateLatLngInputs(p.lat, p.lng);
                });
            }
            if (move) map.setView([lat, lng], 15);
            updateLatLngInputs(lat, lng);
        }

        function updateLatLngInputs(lat, lng) {
            latInput.value = String(lat);
            lngInput.value = String(lng);
            gmapsPreview.textContent = `https://maps.google.com/?q=${lat},${lng}`;
            gmapsPreview.title = 'Buka di Google Maps';
            gmapsPreview.onclick = () => {
                window.open(`https://maps.google.com/?q=${lat},${lng}`, '_blank');
            };
        }

        // click map to set marker
        map.on('click', function(e) {
            placeMarker(e.latlng.lat, e.latlng.lng);
        });

        // if existing coords present, show marker
        if (latInput.value && lngInput.value) {
            placeMarker(parseFloat(latInput.value), parseFloat(lngInput.value), false);
        }

        // geolocate button
        document.getElementById('btn-geolocate').addEventListener('click', function() {
            if (!navigator.geolocation) {
                alert('Geolocation tidak didukung pada perangkat ini.');
                return;
            }
            this.disabled = true;
            this.textContent = 'Mencari lokasi...';
            navigator.geolocation.getCurrentPosition(function(pos) {
                placeMarker(pos.coords.latitude, pos.coords.longitude);
                document.getElementById('btn-geolocate').disabled = false;
                document.getElementById('btn-geolocate').textContent = 'Gunakan Lokasi Saya';
            }, function(err) {
                alert('Gagal mendapatkan lokasi: ' + err.message);
                document.getElementById('btn-geolocate').disabled = false;
                document.getElementById('btn-geolocate').textContent = 'Gunakan Lokasi Saya';
            }, {
                enableHighAccuracy: true,
                timeout: 8000
            });
        });

        const nopolEl = document.getElementById('nopol');
        nopolEl && nopolEl.addEventListener('input', function() {
            this.value = this.value.toUpperCase();
        });

        function validasiFormSebelumReview() {
            const errs = [];
            const jenis = jenisSelect.value;
            if (jenis === 'baru') {
                const nama = document.getElementById('nama_baru').value.trim();
                const nohp = document.getElementById('nohp_baru').value.trim();
                const pwd = document.getElementById('password_baru').value.trim();
                if (!nama) errs.push('Nama pelanggan baru wajib diisi.');
                if (!/^[0-9]{10,15}$/.test(nohp)) errs.push('No HP pelanggan baru harus 10-15 digit angka.');
                if (!pwd) errs.push('Password sementara wajib diisi.');
            } else {
                const idpel = document.getElementById('id_pelanggan').value;
                if (!idpel) errs.push('Pilih pelanggan terdaftar terlebih dahulu.');
            }

            const merk = document.querySelector('[name="merk"]').value.trim();
            const model = document.querySelector('[name="model"]').value.trim();
            const tahun = document.querySelector('[name="tahun"]').value.trim();
            const nopol = nopolEl.value.trim();
            const alamat = document.querySelector('[name="alamat"]').value.trim();
            const lat = latInput.value.trim();
            const lng = lngInput.value.trim();

            if (!merk) errs.push('Merk kendaraan wajib diisi.');
            if (!model) errs.push('Model kendaraan wajib diisi.');
            if (!tahun || isNaN(parseInt(tahun))) errs.push('Tahun produksi tidak valid.');
            if (!nopol) errs.push('Nomor polisi wajib diisi.');

            const m = nopol.match(/^([A-Z]{1,2})\s?([0-9]{1,4})/i);
            if (!m) {
                errs.push('Format nomor polisi tidak valid.');
            } else {
                const pref = m[1].toUpperCase();
                const ang = parseInt(m[2], 10);
                if (!kodeWilayah.includes(pref)) errs.push(`Kode wilayah '${pref}' tidak dikenal.`);
                if (isNaN(ang) || ang < 1 || ang > 9999) errs.push('Angka pada nomor polisi harus antara 1–9999.');
            }

            if (!lat || !lng) errs.push('Silakan tandai lokasi kendaraan pada peta.');

            if (errs.length) {
                showErrors(errs);
                return false;
            }
            return true;
        }

        function showErrors(list) {
            errorList.innerHTML = '';
            list.forEach(function(it) {
                const li = document.createElement('li');
                li.textContent = it;
                errorList.appendChild(li);
            });
            errorModal.classList.remove('hidden');
        }

        // populate review modal
        function tampilkanReview() {
            const jenis = jenisSelect.value;
            document.getElementById('reviewJenis').textContent = jenis === 'baru' ? 'Baru' : 'Terdaftar';
            document.getElementById('reviewNama').textContent = jenis === 'baru' ? (document.getElementById('nama_baru').value.trim() || '-') : (document.getElementById('id_pelanggan').selectedOptions[0].text || '-');
            document.getElementById('reviewNoHP').textContent = jenis === 'baru' ? (document.getElementById('nohp_baru').value.trim() || '-') : '-';
            document.getElementById('reviewMerk').textContent = document.querySelector('[name="merk"]').value.trim() || '-';
            document.getElementById('reviewModel').textContent = document.querySelector('[name="model"]').value.trim() || '-';
            document.getElementById('reviewKepemilikan').textContent = document.querySelector('[name="kepemilikan"]').value || '-';
            document.getElementById('reviewTahun').textContent = document.querySelector('[name="tahun"]').value || '-';
            document.getElementById('reviewNopol').textContent = nopolEl.value.trim() || '-';
            document.getElementById('reviewAlamat').textContent = document.querySelector('[name="alamat"]').value.trim() || '-';
            document.getElementById('reviewLatLng').textContent = (latInput.value && lngInput.value) ? `${latInput.value}, ${lngInput.value}` : '-';
            modalReview.classList.remove('hidden');
            confirmReviewBtn.focus();
        }

        // accessibility: close modal with Esc
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                errorModal.classList.add('hidden');
                modalReview.classList.add('hidden');
            }
        });
    </script>
</body>

</html>
