<?php
require_once '../includes/koneksi.php';

$success = $error = "";
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $identifier = trim($_POST["identifier"]);

    if (empty($identifier)) {
        $error = "Silakan masukkan username atau nomor HP.";
    } else {
        $stmt = $conn->prepare("SELECT id_user FROM users WHERE username = ? OR no_hp = ?");
        $stmt->bind_param("ss", $identifier, $identifier);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            $token = bin2hex(random_bytes(16)); // token unik
            $expiry = time() + (60 * 10); // 10 menit

            $update = $conn->prepare("UPDATE users SET reset_token = ?, reset_expiry = ? WHERE id_user = ?");
            $update->bind_param("sii", $token, $expiry, $user["id_user"]);
            $update->execute();

            $reset_url = "reset_password.php?token=$token&user=" . urlencode($identifier);
            $success = "✅ Link reset:<br><a href='" . htmlspecialchars($reset_url) . "' class='text-orange-600 underline break-all'>" . htmlspecialchars($reset_url) . "</a><br><span class='text-sm text-gray-600'>(berlaku 10 menit)</span>";
        } else {
            $error = "❌ User tidak ditemukan.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Lupa Password</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" type="image/x-icon" href="../favicon.ico">
    <link rel="shortcut icon" type="image/x-icon" href="../favicon.ico">
</head>

<body class="bg-orange-600 min-h-screen flex items-center justify-center">

    <div class="bg-white w-full max-w-sm mx-auto p-6 rounded shadow-md text-center">
        <h2 class="text-xl font-bold text-gray-800 mb-4">Lupa Password</h2>

        <?php if ($success): ?>
            <div class="bg-green-100 text-green-700 p-3 mb-4 rounded text-sm"><?= $success ?></div>
        <?php elseif ($error): ?>
            <div class="bg-red-100 text-red-700 p-3 mb-4 rounded text-sm"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post" class="text-left">
            <label class="block mb-1 text-sm text-gray-700">Username / No HP</label>
            <input type="text" name="identifier" required
                class="w-full px-4 py-2 mb-4 border border-gray-300 rounded focus:outline-none focus:ring-2 focus:ring-orange-400">
            <button type="submit"
                class="w-full bg-orange-600 hover:bg-orange-700 text-white font-semibold py-2 rounded transition">
                Kirim Link Reset
            </button>
        </form>

        <p class="text-sm text-gray-500 mt-4">
            Ingat password?
            <a href="login.php" class="text-orange-600 hover:underline">Kembali ke login</a>
        </p>
    </div>

</body>

</html>