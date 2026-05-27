<?php

declare(strict_types=1);

$cookieSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => $_SERVER['HTTP_HOST'],
    'secure' => $cookieSecure,
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();

require_once '../includes/koneksi.php';

if (!isset($_SESSION['username'], $_SESSION['role']) || ($_SESSION['role'] ?? '') !== 'teknisi') {
    header("Location: ../auth/login.php");
    exit();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$show_sop = false;
if (!empty($_SESSION['show_sop'])) {
    $show_sop = true;
    unset($_SESSION['show_sop']);
}

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function fetchTeknisiByUsername(mysqli $conn, string $username): array
{
    $out = ['id_user' => 0, 'username' => $username, 'nama_lengkap' => $username];
    $sql = "SELECT id_user, username, nama_lengkap FROM users WHERE username = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows === 1) {
            $row = $res->fetch_assoc();
            $out['id_user'] = (int)($row['id_user'] ?? 0);
            $out['username'] = $row['username'] ?? $username;
            $out['nama_lengkap'] = $row['nama_lengkap'] ?? $out['username'];
        }
        $stmt->close();
    }
    return $out;
}

function getNotifCount(mysqli $conn, int $id_teknisi): int
{
    if ($id_teknisi <= 0) return 0;
    $sql = "SELECT COUNT(*) AS jumlah FROM order_inspeksi WHERE id_teknisi = ? AND status = 'Disetujui'";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return 0;
    $stmt->bind_param('i', $id_teknisi);
    $stmt->execute();
    $res = $stmt->get_result();
    $cnt = 0;
    if ($res) {
        $cnt = (int)($res->fetch_assoc()['jumlah'] ?? 0);
        $res->free();
    }
    $stmt->close();
    return $cnt;
}

$username_session = (string)($_SESSION['username'] ?? '');
$teknisi = fetchTeknisiByUsername($conn, $username_session);
$id_user = (int)($teknisi['id_user'] ?? 0);
$nama_display = $teknisi['nama_lengkap'] ?? $username_session;
$jumlah_notifikasi = getNotifCount($conn, $id_user);

date_default_timezone_set('Asia/Jakarta');
?>
<!doctype html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <title>Dashboard Teknisi</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>

<body class="bg-gray-100 min-h-screen font-sans">
    <header class="bg-black text-white px-6 py-4 flex justify-between items-center relative z-10">
        <div class="flex items-center space-x-3">
            <img src="e62b0286-8bb2-4260-9ec0-825b4f890808.png" alt="Logo RTECH" class="h-8" onerror="this.style.display='none'">
            <span class="font-bold">RTECH JASA INSPEKSI</span>
        </div>

        <div class="flex space-x-4 items-center">
            <button id="sopBtn" class="px-3 py-2 rounded hover:bg-orange-500 text-white bg-transparent">
                <i class="fas fa-file-alt"></i> SOP
            </button>

            <div class="relative" title="Notifikasi">
                <button id="notifBtn" class="relative">
                    <i class="fas fa-bell text-lg cursor-pointer text-white"></i>
                    <span id="notif-badge" class="absolute -top-1 -right-1 inline-flex items-center justify-center px-2 py-1 text-xs font-bold text-white bg-red-600 rounded-full animate-bounce" style="<?= $jumlah_notifikasi > 0 ? '' : 'display:none;' ?>">
                        <?= e((string)$jumlah_notifikasi) ?>
                    </span>
                </button>
            </div>

            <button id="logoutBtn" class="text-white bg-red-500 px-3 py-2 rounded hover:bg-red-600" title="Logout">
                <i class="fas fa-sign-out-alt"></i> Logout
            </button>
        </div>
    </header>

    <main class="max-w-md mx-auto p-6">
        <h2 class="text-lg font-medium text-gray-700 mb-2">Halo, kak <?= e($nama_display) ?>!</h2>

        <div id="tanggal-jam" class="inline-block bg-gray-200 text-gray-800 text-sm font-semibold px-4 py-2 rounded mb-4 shadow-inner">
            Memuat waktu...
        </div>

        <div class="bg-orange-100 text-sm text-gray-800 p-4 rounded-lg shadow mb-6">
            <strong>Pengumuman :</strong><br>
            Kepada kak <?= e($nama_display) ?>, tetap semangat bekerja dan patuhi SOP ya kak agar kualitas selalu terjaga.
        </div>

        <div class="space-y-6 text-center">
            <a href="profil.php" class="block">
                <i class="fas fa-user-circle text-orange-500 text-3xl mb-2"></i>
                <p class="text-gray-600 font-semibold">PROFIL</p>
            </a>
            <a href="cek_task.php" class="block">
                <i class="fas fa-search text-orange-500 text-3xl mb-2"></i>
                <p class="text-gray-600 font-semibold">CEK TASK INSPEKSI</p>
            </a>
            <a href="history_inspeksi.php" class="block">
                <i class="fas fa-folder text-orange-500 text-3xl mb-2"></i>
                <p class="text-gray-600 font-semibold">HISTORY PEKERJAAN</p>
            </a>
            <a href="keuangan.php" class="block">
                <i class="fas fa-money-bill-wave text-orange-500 text-3xl mb-2"></i>
                <p class="text-gray-600 font-semibold">KEUANGAN</p>
            </a>
        </div>
    </main>

    <!-- SOP modal content via SweetAlert2 -->
    <script>
        const showSOPModal = <?= $show_sop ? 'true' : 'false' ?>;
        document.addEventListener('DOMContentLoaded', () => {
            const sopBtn = document.getElementById('sopBtn');
            const logoutBtn = document.getElementById('logoutBtn');
            const notifBtn = document.getElementById('notifBtn');

            sopBtn?.addEventListener('click', () => openSOP());
            logoutBtn?.addEventListener('click', () => confirmLogout());
            notifBtn?.addEventListener('click', () => {
                window.location.href = 'cek_task.php';
            });

            if (showSOPModal) openSOP();
        });

        function openSOP() {
            Swal.fire({
                title: 'SOP Teknisi',
                html: `
                    <ul style="text-align:left; font-size:14px; line-height:1.5;">
                        <li>1. Inspektor wajib melakukan pengecekan dengan teliti, hati-hati, dan penuh tanggung jawab semua poin pengecekan yang ada pada sistem.</li>
                        <li>2. Inspektor diharapkan tidak terlambat datang ke lokasi unit yang di inspeksi.</li>
                        <li>3. Inspektor dilarang meminta/memaksa sorum/penjual/pembeli memberikan insentif dalam bentuk apapun.</li>
                        <li>4. Inspektor dilarang melakukan kebohongan dengan meloloskan secara sadar unit yang tidak sesuai standar Rtech Jasa Inspeksi.</li>
                        <li>5. Inspektor dilarang merokok/mengangkat telepon pada saat bertugas.</li>
                        <li>6. Inspektor wajib menggunakan seragam pdh lapangan dan safety shoes.</li>
                        <li>7. Inspektor diperbolehkan melakukan aktifitas lain jika selesai melakukan inspeksi - report.</li>
                        <li>8. Inspektor dilarang bypass order ( menerima order langsung dari klien Rtech Jasa Inspeksi ).</li>
                        <li>9. Inspektor wajib mengikuti training yang diadakan Rtech untuk pengembangan skill 1 tahun sekali.</li>
                        <li>10. Inspektor yang lalai terhadap unit bekas laka mayor/bekas banjir akan terkena sangsi.</li>
                        <li>11. Inspektor wajib menggunakan alat yang telah distandarisasi Rtech Inspeksi ketika bertugas di lapangan.</li>
                    </ul>
                `,
                icon: 'info',
                confirmButtonText: 'Tutup',
                confirmButtonColor: '#3085d6'
            });
        }

        function confirmLogout() {
            Swal.fire({
                title: 'Keluar dari sistem?',
                text: 'Anda akan logout dari akun ini.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#e3342f',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Ya, Logout',
                cancelButtonText: 'Batal'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = '../auth/logout.php';
                }
            });
        }

        // Polling notifikasi setiap 5 detik (dengan error handling)
        async function fetchNotif() {
            try {
                const resp = await fetch('cek_notif.php', {
                    cache: 'no-store'
                });
                if (!resp.ok) throw new Error('Network response not ok');
                const data = await resp.json();
                const badge = document.getElementById('notif-badge');
                if (data && typeof data.jumlah !== 'undefined' && Number(data.jumlah) > 0) {
                    badge.innerText = data.jumlah;
                    badge.style.display = 'inline-flex';
                } else {
                    badge.style.display = 'none';
                }
            } catch (err) {
                // silent fail (optionally log to server)
                console.error('Gagal mengambil notifikasi:', err);
            }
        }
        setInterval(fetchNotif, 5000);

        // Update tanggal & jam real-time (Indonesia)
        function updateTanggalJam() {
            const now = new Date();
            const hari = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'][now.getDay()];
            const bulan = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'][now.getMonth()];
            const tanggal = `${hari}, ${now.getDate()} ${bulan} ${now.getFullYear()}`;
            const jam = now.getHours().toString().padStart(2, '0') + ':' + now.getMinutes().toString().padStart(2, '0');
            const el = document.getElementById('tanggal-jam');
            if (el) el.innerText = `${tanggal} pukul ${jam}`;
        }
        updateTanggalJam();
        setInterval(updateTanggalJam, 1000);
    </script>
</body>

</html>