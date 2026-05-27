<?php
declare(strict_types=1);
session_start();
require_once '../includes/koneksi.php';

$success = '';
$error   = '';
$redirectToLogin = false;

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

$result = $conn->query("SELECT id_user FROM users WHERE role = 'admin' LIMIT 1");
$admin_terdaftar = $result && $result->num_rows > 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Ambil & rapikan input
    $username     = trim($_POST['username'] ?? '');
    $password     = $_POST['password'] ?? '';
    $confirm_pass = $_POST['confirm_password'] ?? '';
    $nama_lengkap = trim($_POST['nama_lengkap'] ?? '');
    $email        = trim($_POST['email'] ?? '');
    $no_hp        = trim($_POST['no_hp'] ?? '');

    $role = $admin_terdaftar ? 'pelanggan' : 'admin';

    if (
        $username === '' || $password === '' || $confirm_pass === '' ||
        $nama_lengkap === '' || $email === '' || $no_hp === ''
    ) {
        $error = "Semua field wajib diisi.";

    } elseif ($password !== $confirm_pass) {
        $error = "Konfirmasi password tidak cocok.";

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Format email tidak valid.";

    } elseif (!preg_match('/^[0-9]{10,15}$/', $no_hp)) {
        $error = "Nomor HP harus berupa angka (10–15 digit).";

    } elseif (!preg_match('/^[a-zA-Z0-9_]{4,20}$/', $username)) {
        $error = "Username hanya boleh huruf, angka, dan underscore (4–20 karakter).";
    }

    if ($error === '') {

        $stmt = $conn->prepare("SELECT id_user FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            $error = "Username sudah digunakan.";
            $stmt->close();
        } else {
            $stmt->close();

            $password_hash = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $conn->prepare(
                "INSERT INTO users 
                (username, password, role, nama_lengkap, email, no_hp, status_aktif)
                VALUES (?, ?, ?, ?, ?, ?, 1)"
            );
            $stmt->bind_param(
                "ssssss",
                $username,
                $password_hash,
                $role,
                $nama_lengkap,
                $email,
                $no_hp
            );

            if ($stmt->execute()) {
    $success = ($role === 'admin')
        ? "Akun administrator pertama berhasil dibuat."
        : "Akun pelanggan berhasil dibuat.";

    $redirectToLogin = true;

    $_POST = [];
            } else {
                $error = "Terjadi kegagalan sistem. Silakan coba kembali.";
            }

            $stmt->close();
        }
    }
}

function old(string $name): string {
    return isset($_POST[$name]) ? htmlspecialchars($_POST[$name], ENT_QUOTES, 'UTF-8') : '';
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Registrasi Akun</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" href="../favicon.ico">
    <style>
        body {
            font-family: 'Inter', sans-serif;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: none;
            }
        }

        .animate-fadeIn {
            animation: fadeIn .4s ease-out;
        }

        .touch-target {
            min-height: 44px;
        }
    </style>
    <script>
        function togglePassword(id, btn) {
            const input = document.getElementById(id);
            const shown = input.type === 'text';
            input.type = shown ? 'password' : 'text';
            btn.textContent = shown ? 'Tampilkan' : 'Sembunyikan';
        }
    </script>
</head>

<body class="min-h-screen bg-black flex items-start justify-center p-4 text-gray-100">

    <div class="w-full max-w-lg mt-6 sm:mt-10">
        <div class="bg-zinc-900 rounded-2xl shadow-xl p-6 sm:p-8 animate-fadeIn">

            <h1 class="text-xl sm:text-2xl font-semibold text-center mb-6">Registrasi Akun</h1>

<?php if ($success): ?>
    <div class="mb-4 rounded-lg bg-green-900/40 border border-green-700 px-3 py-3 text-sm text-green-300">
        <p class="font-semibold mb-1"><?= htmlspecialchars($success) ?></p>
        <p>
            Anda akan diarahkan ke halaman login dalam
            <span id="countdown" class="font-bold text-green-200">5</span>
            detik…
        </p>
    </div>
<?php elseif ($error): ?>
    <div class="mb-4 rounded-lg bg-red-900/40 border border-red-700 px-3 py-2 text-sm text-red-300">
        <?= htmlspecialchars($error) ?>
    </div>
<?php endif; ?>


            <form method="post" class="space-y-3" autocomplete="on">
                <input type="text" name="username" placeholder="Username" required
    value="<?= old('username') ?>"
    class="w-full px-4 py-3 rounded-xl bg-zinc-800 border border-zinc-700 focus:ring-2 focus:ring-orange-500 outline-none text-sm">
               <input type="text" name="nama_lengkap" placeholder="Nama Lengkap" required
    value="<?= old('nama_lengkap') ?>"
    class="w-full px-4 py-3 rounded-xl bg-zinc-800 border border-zinc-700 focus:ring-2 focus:ring-orange-500 outline-none text-sm">
                <input type="text" name="no_hp" inputmode="numeric" placeholder="Nomor HP" required
    value="<?= old('no_hp') ?>"
    class="w-full px-4 py-3 rounded-xl bg-zinc-800 border border-zinc-700 focus:ring-2 focus:ring-orange-500 outline-none text-sm">
                <div class="relative">
                    <input id="password" type="password" name="password" placeholder="Password" required
                        class="w-full px-4 py-3 pr-28 rounded-xl bg-zinc-800 border border-zinc-700 focus:ring-2 focus:ring-orange-500 outline-none text-sm">
                    <button type="button" onclick="togglePassword('password', this)"
                        class="absolute right-2 top-1.5 touch-target px-3 rounded-lg text-xs">Tampilkan</button>
                </div>

                <div class="relative">
                    <input id="confirm" type="password" name="confirm_password" placeholder="Ulangi Password" required
                        class="w-full px-4 py-3 pr-28 rounded-xl bg-zinc-800 border border-zinc-700 focus:ring-2 focus:ring-orange-500 outline-none text-sm">
                    <button type="button" onclick="togglePassword('confirm', this)"
                        class="absolute right-2 top-1.5 touch-target px-3 rounded-lg text-xs">Tampilkan</button>
                </div>

               <input type="email" name="email" placeholder="Email" required
    value="<?= old('email') ?>"
    class="w-full px-4 py-3 rounded-xl bg-zinc-800 border border-zinc-700 focus:ring-2 focus:ring-orange-500 outline-none text-sm">

                <button type="submit"
                    class="w-full bg-orange-600 hover:bg-orange-700 transition-colors text-white font-semibold py-3 rounded-xl touch-target">
                    Daftar
                </button>
            </form>

            <div class="text-center mt-4">
                <a href="login.php" class="text-sm text-orange-400 hover:underline">← Kembali ke Login</a>
            </div>

        </div>
    </div>
<?php if ($redirectToLogin): ?>
<script>
    let seconds = 5;
    const el = document.getElementById('countdown');

    const timer = setInterval(() => {
        seconds--;
        if (el) el.textContent = seconds;

        if (seconds <= 0) {
            clearInterval(timer);
            window.location.href = 'login.php';
        }
    }, 1000);
</script>
<?php endif; ?>

</body>

</html>