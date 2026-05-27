<?php
require_once '../includes/koneksi.php';

$token = $_GET["token"] ?? "";
$identifier = $_GET["user"] ?? "";
$error = $success = "";
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $password = $_POST["password"];
    $token = $_POST["token"];
    $identifier = $_POST["identifier"];

    if (strlen($password) < 6 || !preg_match('/^[A-Za-z0-9]+$/', $password)) {
        $error = "Password harus minimal 6 karakter dan hanya boleh mengandung huruf dan angka.";
    } else {
        $stmt = $conn->prepare("SELECT id_user, reset_expiry FROM users WHERE (username = ? OR no_hp = ?) AND reset_token = ?");
        $stmt->bind_param("sss", $identifier, $identifier, $token);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            if (time() > $user["reset_expiry"]) {
                $error = "Token sudah kadaluarsa.";
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $update = $conn->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_expiry = NULL WHERE id_user = ?");
                $update->bind_param("si", $hash, $user["id_user"]);
                $update->execute();
                $success = "Password berhasil diperbarui. <a href='login.php' class='text-orange-600 underline'>Login sekarang</a>";
            }
        } else {
            $error = "Token tidak valid.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Reset Password</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" type="image/x-icon" href="../favicon.ico">
    <link rel="shortcut icon" type="image/x-icon" href="../favicon.ico">
    <script>
        function togglePassword() {
            const field = document.getElementById('password');
            const icon = document.getElementById('toggleIcon');
            if (field.type === "password") {
                field.type = "text";
                icon.innerText = "🙈";
            } else {
                field.type = "password";
                icon.innerText = "👁️";
            }
        }
    </script>
</head>

<body class="bg-orange-600 min-h-screen flex items-center justify-center">

    <div class="bg-white w-full max-w-sm mx-auto p-6 rounded shadow-md text-center">
        <h2 class="text-xl font-bold text-gray-800 mb-4">Reset Password</h2>

        <?php if ($success): ?>
            <div class="bg-green-100 text-green-700 p-3 mb-4 rounded text-sm"><?= $success ?></div>
        <?php elseif ($error): ?>
            <div class="bg-red-100 text-red-700 p-3 mb-4 rounded text-sm"><?= $error ?></div>
        <?php endif; ?>

        <form method="post" class="text-left">
            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
            <input type="hidden" name="identifier" value="<?= htmlspecialchars($identifier) ?>">

            <label class="block mb-1 text-sm text-gray-700">Password Baru</label>
            <div class="relative">
                <input type="password" name="password" id="password" required
                    class="w-full px-4 py-2 pr-10 mb-4 border border-gray-300 rounded focus:outline-none focus:ring-2 focus:ring-orange-400"
                    placeholder="********">
                <span id="toggleIcon" onclick="togglePassword()"
                    class="absolute right-3 top-2.5 cursor-pointer select-none text-xl">👁️</span>
            </div>

            <ul class="text-xs text-gray-500 mb-4 list-disc list-inside">
                <li>Minimal 6 karakter</li>
                <li>Huruf dan angka saja</li>
            </ul>

            <button type="submit"
                class="w-full bg-orange-600 hover:bg-orange-700 text-white font-semibold py-2 rounded transition">
                Simpan Password
            </button>
        </form>

        <p class="text-sm text-gray-500 mt-4">
            Sudah ingat password?
            <a href="login.php" class="text-orange-600 hover:underline">Kembali ke login</a>
        </p>
    </div>

</body>

</html>