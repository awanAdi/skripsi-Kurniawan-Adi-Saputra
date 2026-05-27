<?php
$cookieSecure = (
    !empty($_SERVER['HTTPS']) &&
    $_SERVER['HTTPS'] !== 'off'
);

session_start();

if (!empty($_SESSION['logged_in'])) {
    $_SESSION = [];
    session_destroy();
    session_start();
}

require_once '../includes/koneksi.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (empty($_SESSION['lp_token']) || empty($_SESSION['lp_token_expiry']) || time() > $_SESSION['lp_token_expiry']) {
    $_SESSION['lp_token'] = bin2hex(random_bytes(16));
    $_SESSION['lp_token_expiry'] = time() + 300;
}

$success = "";
$error = "";
$show_reset_link = false;

$max_attempts_ip   = 20;
$max_attempts_user = 8;
$lockout_time_ip   = 600;   
$lockout_time_user = 3600; 

function dbg_log($msg) {
    global $debug;
    if ($debug) error_log("[DEBUG] " . $msg);
}

function get_client_ip()
{
    if (!empty($_SERVER['REMOTE_ADDR']) && filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP)) {
        return $_SERVER['REMOTE_ADDR'];
    }

    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // X-Forwarded-For may contain a list
        $ip_list = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($ip_list[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }

    if (!empty($_SERVER['HTTP_CLIENT_IP']) && filter_var($_SERVER['HTTP_CLIENT_IP'], FILTER_VALIDATE_IP)) {
        return $_SERVER['HTTP_CLIENT_IP'];
    }

    return 'unknown';
}

$ip_address = get_client_ip();
dbg_log("Client IP: {$ip_address}");

$threshold_cleanup = time() - 60 * 60 * 24 * 30;
$del_q = $conn->prepare("DELETE FROM login_attempts WHERE attempt_time < ?");
if ($del_q) {
    $del_q->bind_param("i", $threshold_cleanup);
    $del_q->execute();
    $del_q->close();
} else {
    dbg_log("Failed prepare delete old attempts: " . $conn->error);
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $error = "Permintaan tidak valid (Reload Halaman).";
    } else {

        $username_input = trim((string)($_POST['username'] ?? ''));
        $password_input = $_POST['password'] ?? '';

        if ($username_input === '' || $password_input === '') {
            $error = "Username dan password wajib diisi.";
        } else {

            $stmt = $conn->prepare("SELECT id_user, username, password, role, status_aktif 
                                    FROM users 
                                    WHERE (username = ? OR no_hp = ?) AND status_aktif = 1 LIMIT 1");
            $user = null;

            if ($stmt) {
                $stmt->bind_param("ss", $username_input, $username_input);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result && $result->num_rows === 1) {
                    $user = $result->fetch_assoc();
                }
                $stmt->close();
            } else {
                dbg_log("Failed prepare user lookup: " . $conn->error);
            }

            $valid_username = $user ? true : false;

            $detected_username = $valid_username ? $user['username'] : $username_input;

            $time_check_ip   = time() - $lockout_time_ip;
            $time_check_user = time() - $lockout_time_user;

            $attempts_ip = 0;
            $stmt = $conn->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND attempt_time > ?");
            if ($stmt) {
                $stmt->bind_param("si", $ip_address, $time_check_ip);
                $stmt->execute();
                $stmt->bind_result($attempts_ip);
                $stmt->fetch();
                $stmt->close();
            } else {
                dbg_log("Failed prepare count attempts_ip: " . $conn->error);
            }

            $attempts_user = 0;
            $stmt = $conn->prepare("SELECT COUNT(*) FROM login_attempts WHERE username = ? AND attempt_time > ?");
            if ($stmt) {
                $stmt->bind_param("si", $detected_username, $time_check_user);
                $stmt->execute();
                $stmt->bind_result($attempts_user);
                $stmt->fetch();
                $stmt->close();
            } else {
                dbg_log("Failed prepare count attempts_user: " . $conn->error);
            }

            dbg_log("attempts_ip={$attempts_ip} attempts_user={$attempts_user} detected_username={$detected_username}");

            if (
                ($valid_username && $attempts_user >= $max_attempts_user) ||
                (!$valid_username && $attempts_ip >= $max_attempts_ip)
            ) {
                $error = "Terlalu banyak percobaan login. Silakan coba lagi nanti.";
            } else {

                $valid_login = false;
                if ($valid_username && password_verify($password_input, $user['password'])) {
                    $valid_login = true;
                }

                if ($valid_login) {
                    $del = $conn->prepare("DELETE FROM login_attempts WHERE username = ? OR ip_address = ?");
                    if ($del) {
                        $del->bind_param("ss", $detected_username, $ip_address);
                        $del->execute();
                        $del->close();
                    } else {
                        dbg_log("Failed prepare delete attempts on success: " . $conn->error);
                    }

                    session_regenerate_id(true);
                    $_SESSION['id_user']   = (int) $user['id_user'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['role']     = strtolower(trim($user['role']));
                    $_SESSION['logged_in'] = true;


                    switch ($user['role']) {
                        case 'admin':
                            header("Location: ../admin/admin_dashboard.php");
                            exit();
                        case 'teknisi':
                            $_SESSION['id_teknisi'] = $user['id_user'];
                            $_SESSION['show_sop']   = true;
                            header("Location: ../teknisi/teknisi_dashboard.php");
                            exit();
                        case 'pelanggan':
                            header("Location: ../pelanggan/pelanggan_dashboard.php");
                            exit();
                        default:
                            $error = "Role tidak dikenali.";
                    }
                } else {
                    $time_now = time();
                    $is_password_error = $valid_username ? 1 : 0; 

                    $ins = $conn->prepare("INSERT INTO login_attempts (ip_address, username, attempt_time, is_password_error) VALUES (?, ?, ?, ?)");
                    if ($ins) {
                        $safe_username_for_db = mb_substr($detected_username, 0, 100);
                        $ins->bind_param("ssii", $ip_address, $safe_username_for_db, $time_now, $is_password_error);
                        $ins->execute();
                        $ins->close();
                    } else {
                        dbg_log("Failed prepare insert attempt: " . $conn->error);
                    }

                    $error = "Username atau password salah.";

                    if ($valid_username) {
                        $fail_count = 0;
                        $stmt = $conn->prepare("SELECT COUNT(*) FROM login_attempts WHERE username = ? AND is_password_error = 1 AND attempt_time > ?");
                        if ($stmt) {
                            $stmt->bind_param("si", $detected_username, $time_check_user);
                            $stmt->execute();
                            $stmt->bind_result($fail_count);
                            $stmt->fetch();
                            $stmt->close();
                        }
                        if ($fail_count >= 3) {
                            $show_reset_link = true;
                        }
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <title>Login Sistem Inspeksi</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" type="image/x-icon" href="../favicon.ico">
    <link rel="shortcut icon" type="image/x-icon" href="../favicon.ico">
    <style>
        body { font-family: 'Inter', sans-serif; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
        .animate-fadeIn { animation: fadeIn 0.36s ease-out; }
        .touch-target { min-height: 44px; min-width: 44px; }
        .safe-area { padding-top: env(safe-area-inset-top); padding-bottom: env(safe-area-inset-bottom); }
    </style>
    <script>
        function onSubmitForm(btn) {
            btn.disabled = true;
            const spinner = document.getElementById('btnSpinner');
            spinner.classList.remove('hidden');
            btn.querySelector('span.btn-text').textContent = 'Masuk...';
            return true;
        }
        function togglePasswordButton(el) {
            const field = document.getElementById('password');
            const pressed = el.getAttribute('aria-pressed') === 'true';
            if (pressed) {
                field.type = 'password';
                el.setAttribute('aria-pressed', 'false');
                el.innerHTML = 'Tampilkan';
            } else {
                field.type = 'text';
                el.setAttribute('aria-pressed', 'true');
                el.innerHTML = 'Sembunyikan';
            }
            field.focus();
        }
        window.addEventListener('load', function() {
            const u = document.getElementById('username');
            if (u) u.focus();
        });
    </script>
</head>
<body class="min-h-screen bg-black flex items-start justify-center p-4 safe-area">
    <div class="w-full max-w-lg mx-auto mt-6 sm:mt-12 px-3">
        <div class="flex rounded-2xl bg-white justify-center items-center mb-6">
            <img src="../uploads/icons/logo.png" alt="Logo Rtech Jasa Inspeksi" class="max-w-[220px] w-full h-auto object-contain">
        </div>

        <div class="bg-white rounded-2xl shadow-lg p-5 sm:p-8 animate-fadeIn">
            <?php if (!empty($error)): ?>
                <div role="alert" aria-live="assertive" class="mb-4">
                    <div class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-red-700 text-sm">
                        ⚠️ <?= htmlspecialchars($error) ?>
                    </div>
                </div>
            <?php endif; ?>

            <form method="post" action="" onsubmit="return onSubmitForm(this.querySelector('button[type=submit]'))" autocomplete="on">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                <label for="username" class="sr-only">Username atau Nomor HP</label>
                <div class="mb-3">
                    <input id="username" name="username" type="text" inputmode="text" autocomplete="username" required
                        placeholder="Username atau Nomor HP"
                        class="w-full px-4 py-3 rounded-xl border shadow-sm focus:outline-none focus:ring-2 focus:ring-orange-300 text-sm"
                        aria-label="Username atau nomor hp" value="<?= isset($username_input) ? htmlspecialchars($username_input) : '' ?>">
                </div>

                <label for="password" class="sr-only">Kata sandi</label>
                <div class="mb-3 relative">
                    <input id="password" name="password" type="password" autocomplete="current-password" required
                        placeholder="Kata sandi"
                        class="w-full px-4 py-3 pr-28 rounded-xl border shadow-sm focus:outline-none focus:ring-2 focus:ring-orange-300 text-sm"
                        aria-label="Kata sandi">
                    <div class="absolute right-2 top-1.5">
                        <button type="button" class="touch-target px-3 py-2 rounded-lg text-sm font-medium" aria-pressed="false" aria-label="Tampilkan kata sandi" onclick="togglePasswordButton(this)">Tampilkan</button>
                    </div>
                </div>

                <div class="flex items-center justify-between mb-4">
                    <label class="flex items-center gap-2 text-sm text-gray-600"><span>Belum Punya Akun?</span></label>
                    <?php if ($show_reset_link): ?>
                        <a href="lupa_password.php" class="text-orange-600 text-sm font-medium">Lupa kata sandi?</a>
                    <?php else: ?>
                        <a href="register.php" class="text-orange-600 text-sm font-medium">Daftar</a>
                    <?php endif; ?>
                </div>

                <button type="submit" name="login" class="w-full bg-orange-600 hover:bg-orange-700 text-white font-semibold py-3 rounded-xl shadow-sm flex items-center justify-center gap-3 touch-target" aria-label="Tombol masuk">
                    <svg id="btnSpinner" class="w-5 h-5 animate-spin hidden" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                    </svg>
                    <span class="btn-text">Masuk</span>
                </button>
            </form>

            <p class="text-xs text-gray-500 mt-3 text-center">
                Dengan masuk, Anda menyetujui
                <button type="button" id="btnSyarat" class="text-orange-600 underline">Syarat & Ketentuan</button>
            </p>
            <div id="modalSyarat" class="modal-overlay">
                <div class="modal-content">
                    <button class="modal-close" id="closeModal">&times;</button>
                    <iframe src="syarat_ketentuan.php" frameborder="0"></iframe>
                </div>
            </div>
        </div>
    </div>

    <link rel="stylesheet" href="modal-syarat.css">
    <script src="modal-syarat.js"></script>
</body>
</html>
