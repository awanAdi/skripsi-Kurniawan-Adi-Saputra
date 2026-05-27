<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../includes/koneksi.php';
date_default_timezone_set('Asia/Jakarta');

if (empty($_SESSION['username']) || ($_SESSION['role'] ?? '') !== 'pelanggan') {
    header("Location: ../auth/login.php");
    exit;
}

$id_pelanggan = $_SESSION['id_user'];
$nama_user    = $_SESSION['username'];

$success = $error = "";
$input   = $_POST;

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

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $nomor_polisi = strtoupper(trim($input['nomor_polisi'] ?? ''));
    $merk         = trim($input['merk'] ?? '');
    $model        = trim($input['model'] ?? '');
    $tahun        = intval($input['tahun'] ?? 0);
    $kepemilikan  = trim($input['kepemilikan'] ?? '');
    $alamat       = trim($input['alamat'] ?? '');
    $lat          = trim($input['lat'] ?? '');
    $lng          = trim($input['lng'] ?? '');
    $link_gmaps   = ($lat && $lng) ? "https://maps.google.com/?q=$lat,$lng" : '';

    if (preg_match('/^[A-Z]+/', $nomor_polisi, $matches)) {
        $prefix = $matches[0];
    } else {
        $prefix = '';
    }

    if (!in_array($prefix, $kode_wilayah)) {
        $error = "Kode wilayah plat tidak valid untuk Indonesia.";
    } elseif (!is_numeric($lat) || !is_numeric($lng) || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        $error = "Koordinat lokasi tidak valid.";
    } else {
        $cek_mobil = $conn->prepare("
            SELECT COUNT(*) 
            FROM kendaraan k 
            JOIN order_inspeksi o ON k.id_mobil = o.id_mobil 
            WHERE k.nomor_polisi = ? AND o.status IN ('Menunggu', 'Diproses')
        ");
        $cek_mobil->bind_param("s", $nomor_polisi);
        $cek_mobil->execute();
        $cek_mobil->bind_result($jumlah_mobil);
        $cek_mobil->fetch();
        $cek_mobil->close();

        $cek_order = $conn->prepare("
            SELECT COUNT(*) 
            FROM order_inspeksi 
            WHERE id_pelanggan = ? AND status IN ('Menunggu', 'Diproses')
        ");
        $cek_order->bind_param("i", $id_pelanggan);
        $cek_order->execute();
        $cek_order->bind_result($jumlah_order);
        $cek_order->fetch();
        $cek_order->close();

        if ($jumlah_order >= 10) {
            $error = "Anda sudah memiliki 10 order aktif, tunggu hingga selesai.";
        } else {
            $stmt = $conn->prepare("
                INSERT INTO kendaraan (id_pelanggan, nomor_polisi, merk, model, tahun_produksi, kepemilikan, alamat, link_gmaps) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("isssisss", $id_pelanggan, $nomor_polisi, $merk, $model, $tahun, $kepemilikan, $alamat, $link_gmaps);

            if ($stmt->execute()) {
                $id_mobil = $conn->insert_id;
                $insert_order = $conn->prepare("INSERT INTO order_inspeksi (id_pelanggan, id_mobil, status) VALUES (?, ?, 'Menunggu')");
                $insert_order->bind_param("ii", $id_pelanggan, $id_mobil);
                $insert_order->execute();

                $success = "Order inspeksi berhasil dibuat. Menunggu persetujuan admin.";
                $_POST = [];
            } else {
                $error = "Gagal membuat order.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Buat Order Inspeksi</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.3/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.3/dist/leaflet.js"></script>
    <link rel="icon" type="image/x-icon" href="../favicon.ico">
    <link rel="shortcut icon" type="image/x-icon" href="../favicon.ico">
    <style>
        :root {
            --bg: #0f1724;
            --card: #0b1220;
            --muted: #94a3b8;
            --text: #e6eef8;
            --input-bg: #0f1728;
            --input-border: #1f2937;
            --accent: #10b981;
            --accent-cta: #059669;
            --text: #e6eef8;
            --brand: #FF7A2D;
            --brand-dark: #D35400;
        }

        body {
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto;
            background: var(--bg);
            color: var(--text);
            padding-bottom: 70px;
        }

        .card {
            background: var(--card);
            border: 1px solid rgba(255, 255, 255, 0.03);
        }

        .input,
        textarea,
        select {
            background: var(--input-bg);
            border: 1px solid var(--input-border);
            color: var(--text);
            min-height: 48px;
        }

        .placeholder-muted::placeholder {
            color: rgba(255, 255, 255, 0.35);
        }

        .fade-in {
            animation: fadeIn 0.28s ease-in-out;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(-6px)
            }

            to {
                opacity: 1;
                transform: translateY(0)
            }
        }

        .leaflet-container {
            border-radius: 8px;
        }

        :focus {
            outline: 3px solid rgba(16, 185, 129, 0.12);
            outline-offset: 2px;
        }

        .overlay {
            background: rgba(0, 0, 0, 0.7);
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.65);
            backdrop-filter: blur(6px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 100000;
            pointer-events: auto;
        }

        .modal {
            z-index: 100001;
            max-width: min(95%, 900px);
        }

        .leaflet-container {
            z-index: 1;
            position: relative;
        }

        @media (max-width: 640px) {
            #map {
                display: none;
            }
        }

        @media (min-width: 641px) {
            #mapModal {
                display: none !important;
            }
        }
    </style>
</head>

<body class="bg-[color:var(--bg)] text-[color:var(--text)]">
    <header class="card px-4 py-3 shadow-md">
        <div class="flex justify-between items-center">
            <h1 class="text-base font-semibold">Buat Order</h1>
            <button id="avatarBtn" class="w-9 h-9 rounded-full bg-[color:var(--brand)] text-black flex items-center justify-center font-bold">
                <?= htmlspecialchars(strtoupper(substr($nama_user, 0, 1))) ?>
            </button>
        </div>
        <p id="jamClient" class="text-xs text-[color:var(--muted)] mt-1"></p>
    </header>

    <div id="profileMenu" class="hidden absolute right-0 mt-2 w-44 bg-[#071023] text-[color:var(--text)] rounded shadow-lg z-50">
        <a href="pelanggan_dashboard.php" class="block px-4 py-2 hover:bg-white/3">🏠 Dashboard</a>
        <a href="profil_pelanggan.php" class="block px-4 py-2 hover:bg-white/3">👤 Profil</a>
        <button onclick="event.stopPropagation(); openLogoutModal();"
            class="block text-left w-full px-4 py-2 hover:bg-white/3">🚪 Logout</button>
    </div>

    <div class="max-w-md mx-auto mt-4 card rounded-lg shadow px-4 py-5">
        <h2 class="text-xl font-semibold text-white/95 mb-4">Form Order Inspeksi</h2>
        <form method="post" id="orderForm" class="space-y-4">
            <div class="relative">
                <input type="text" name="nomor_polisi" placeholder="Nomor Polisi" required
                    value="<?= htmlspecialchars($input['nomor_polisi'] ?? '') ?>"
                    class="input w-full p-3 pl-10 rounded text-base placeholder-muted" />
                <span class="absolute left-3 top-3 text-[color:var(--muted)]">🚗</span>
                <small class="text-red-400 hidden"></small>
            </div>
            <div class="relative">
                <input type="text" name="merk" placeholder="Merk Mobil" required
                    value="<?= htmlspecialchars($input['merk'] ?? '') ?>"
                    class="input w-full p-3 pl-10 rounded text-base placeholder-muted" />
                <span class="absolute left-3 top-3 text-[color:var(--muted)]">🏷️</span>
                <small class="text-red-400 hidden"></small>
            </div>
            <div class="relative">
                <input type="text" name="model" placeholder="Model Mobil" required
                    value="<?= htmlspecialchars($input['model'] ?? '') ?>"
                    class="input w-full p-3 pl-10 rounded text-base placeholder-muted" />
                <span class="absolute left-3 top-3 text-[color:var(--muted)]">🚙</span>
                <small class="text-red-400 hidden"></small>
            </div>
            <div class="relative">
                <input type="number" name="tahun" placeholder="Tahun Produksi" required min="1990" max="2090"
                    value="<?= htmlspecialchars($input['tahun'] ?? '') ?>"
                    class="input w-full p-3 rounded text-base placeholder-muted" />
                <small class="text-red-400 hidden"></small>
            </div>
            <div class="relative">
                <select name="kepemilikan" required class="input w-full p-3 rounded text-base">
                    <option value="">Pilih Kepemilikan</option>
                    <option value="Pribadi" <?= (isset($input['kepemilikan']) && $input['kepemilikan'] === 'Pribadi') ? 'selected' : '' ?>>Pribadi</option>
                    <option value="Perusahaan" <?= (isset($input['kepemilikan']) && $input['kepemilikan'] === 'Perusahaan') ? 'selected' : '' ?>>Perusahaan</option>
                    <option value="Tidak Mengetahui" <?= (isset($input['kepemilikan']) && $input['kepemilikan'] === 'Tidak Mengetahui') ? 'selected' : '' ?>>Tidak Mengetahui</option>
                </select>
                <small class="text-red-400 hidden"></small>
            </div>
            <div class="relative">
                <textarea name="alamat" placeholder="Alamat Mobil" required
                    class="input w-full p-3 rounded text-base placeholder-muted"><?= htmlspecialchars($input['alamat'] ?? '') ?></textarea>
                <small class="text-red-400 hidden"></small>
            </div>
            <label class="block text-sm text-[color:var(--muted)] mb-1">Tandai Lokasi Mobil (klik pada peta)</label>
            <button type="button"
                onclick="openMapModal()"
                class="w-full py-3 rounded bg-[color:var(--accent)] text-white font-medium">
                📍 Tandai Lokasi di Peta
            </button>
            <div id="map"
                class="w-full rounded border border-[#14202b]"
                style="height: 320px;">
            </div>
            <p id="mapNotice"
                class="hidden text-xs text-yellow-400 italic mt-2">
                Peta belum tampil? Klik tombol ‘Tandai Lokasi di Peta’ untuk menampilkannya.
            </p>
            <p id="locationStatus"
                class="text-xs text-green-400 mt-2 hidden">
                ✓ Lokasi berhasil ditandai
            </p>
            <input type="hidden" name="lat" id="lat">
            <input type="hidden" name="lng" id="lng">
            <div class="flex justify-between items-center">
                <a href="pelanggan_dashboard.php" class="text-[color:var(--muted)] hover:underline text-sm">← Kembali</a>
                <button type="button" onclick="previewOrder()"
class="bg-[color:var(--accent-cta)] hover:bg-[color:var(--accent)] text-white px-4 py-2 rounded">
    Kirim Order
</button>
            </div>
        </form>
    </div>
    <div id="confirmModal" class="fixed inset-0 overlay hidden items-center justify-center">
        <div class="bg-[#071022] p-5 rounded-lg shadow w-full h-full sm:h-auto sm:max-w-md">
            <h2 class="text-lg font-bold mb-4 text-white/95">Konfirmasi Order</h2>
            <p><b>Nomor Polisi:</b> <span id="reviewPolisi"></span></p>
            <p><b>Merk:</b> <span id="reviewMerk"></span></p>
            <p><b>Model:</b> <span id="reviewModel"></span></p>
            <p><b>Tahun:</b> <span id="reviewTahun"></span></p>
            <p><b>Kepemilikan:</b> <span id="reviewKepemilikan"></span></p>
            <p><b>Alamat:</b> <span id="reviewAlamat"></span></p>
            <p><b>Lokasi:</b> <span id="reviewLokasi"></span></p>
            <div class="mt-4 flex justify-center gap-4">
                <button type="submit" form="orderForm" class="w-full bg-[color:var(--accent)] text-white py-3 rounded-lg hover:bg-[color:var(--accent-cta)]">Ya, Kirim</button>
                <button onclick="hideConfirmModal()" class="px-4 py-2 border rounded hover:bg-white/5">Batal</button>
            </div>
        </div>
    </div>
    <div id="termsModal" class="fixed inset-0 overlay hidden z-50">
        <div class="bg-[#071022] text-[color:var(--text)]
                w-full h-full sm:h-auto sm:max-w-lg
                sm:rounded-lg sm:shadow
                flex flex-col fade-in">
            <div class="flex items-center justify-between px-4 py-3 border-b border-white/10">
                <h2 class="text-base font-semibold">Syarat & Ketentuan</h2>
                <button onclick="closeTermsModal()" class="text-sm text-red-400">✕</button>
            </div>
            <div class="sticky top-0 flex justify-end mb-2 z-10 bg-transparent p-1 rounded">
                <button type="button" onclick="scrollToBottom()"
                    class="w-8 h-8 flex items-center justify-center bg-[color:var(--accent-cta)] rounded-full shadow hover:bg-[color:var(--accent)]">
                    <span class="text-white text-lg">↓</span>
                </button>
            </div>
            <div id="termsContent" class="text-sm text-[color:var(--muted)] mb-4 overflow-y-auto max-h-[55vh] border p-3 rounded bg-[#051018]">
                Memuat...
            </div>
            <p id="scrollNote" class="text-xs text-[color:var(--muted)] mb-3 italic">
                Gulir ke bawah untuk mengaktifkan tombol Lanjut.
            </p>
            <div class="flex justify-end gap-2">
                <button onclick="closeTermsModal()" class="px-4 py-2 border rounded hover:bg-white/5">Batal</button>
                <button id="btnContinue" onclick="continueToConfirm()"
                    class="bg-[color:var(--accent)] text-white px-4 py-2 rounded opacity-50 cursor-not-allowed w-full sm:w-auto" disabled>
                    Lanjut
                </button>
            </div>
        </div>
    </div>

    <div id="mapModal"
        class="fixed inset-0 z-[9999] hidden bg-black/50">
        <div class="bg-[#071022] w-full rounded-t-2xl p-3"
            style="height: 65vh;">

            <div class="flex justify-between items-center mb-2">
                <span class="font-semibold text-sm">Ketuk area pada peta untuk menandai lokasi mobil yang akan diinspeksi.</span>
                <button onclick="closeMapModal()" class="text-xl leading-none">×</button>
            </div>

            <div id="mapMobile"
                class="w-full rounded"
                style="height: calc(100% - 90px);">
            </div>

            <button onclick="closeMapModal()"
                class="mt-3 w-full py-3 rounded bg-[color:var(--accent)] text-white font-medium">
                Simpan dan Gunakan Lokasi
            </button>

        </div>
    </div>


    <div id="logoutModal" class="fixed inset-0 overlay hidden items-center justify-center">
        <div class="bg-[#071022] text-[color:var(--text)] p-6 rounded shadow max-w-sm w-full text-center fade-in">
            <h2 class="text-lg font-bold mb-2 text-red-400">Mau Pergi?</h2>
            <p class="text-[color:var(--muted)] mb-4">Kami akan menantimu untuk inspeksi selanjutnya.</p>
            <div class="flex justify-center gap-4">
                <a href="../auth/logout.php" class="bg-red-600 text-white px-4 py-2 rounded hover:bg-red-700">Ya, Logout</a>
                <button onclick="closeLogoutModal()" class="px-4 py-2 border border-white/10 rounded hover:bg-white/5">Batal</button>
            </div>
        </div>
    </div>

    <?php if ($success || $error): ?>
        <div id="popup" class="fixed inset-0 overlay flex items-center justify-center z-50">
            <div class="bg-[#071022] p-6 rounded-lg shadow-lg w-full max-w-sm text-center">
                <?php if ($success): ?>
                    <h2 class="text-green-400 font-bold text-lg mb-4">✅ <?= $success ?></h2>
                    <a href="pelanggan_dashboard.php" class="bg-[color:var(--accent)] text-white px-4 py-2 rounded hover:bg-[color:var(--accent-cta)]">Kembali ke Dashboard</a>
                <?php else: ?>
                    <h2 class="text-red-400 font-bold text-lg mb-4">❌ <?= $error ?></h2>
                    <button onclick="document.getElementById('popup').remove()" class="bg-yellow-500 text-black px-4 py-2 rounded hover:bg-yellow-600">Tutup</button>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
    <?php include 'footer.php'; ?>
    <script src="buat_order.js"></script>
    <script>
        let mapMobile, markerMobile;

        function openMapModal() {
            const modal = document.getElementById('mapModal');
            modal.classList.remove('hidden');

            if (mapMobile) {
                setTimeout(() => mapMobile.invalidateSize(true), 200);
                return;
            }

            setTimeout(() => {
                mapMobile = L.map('mapMobile').setView([-7.801194, 110.364917], 13);

                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19
                }).addTo(mapMobile);

                mapMobile.on('click', e => {
                    const {
                        lat,
                        lng
                    } = e.latlng;
                    document.getElementById('lat').value = lat;
                    document.getElementById('lng').value = lng;

                    if (markerMobile) {
                        markerMobile.setLatLng(e.latlng);
                    } else {
                        markerMobile = L.marker(e.latlng).addTo(mapMobile);
                    }
                });

                mapMobile.invalidateSize(true);
            }, 150);
        }

        function closeMapModal() {
            document.getElementById('mapModal').classList.add('hidden');
        }

        function validateLocation() {
            return latInput.value && lngInput.value;
        }
    </script>
</body>

</html>