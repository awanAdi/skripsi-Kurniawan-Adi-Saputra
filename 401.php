<!DOCTYPE html>
<html lang="id">

<head>
  <meta charset="UTF-8">
  <title>401 - Tidak Diizinkan</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-100 flex items-center justify-center h-screen">

  <div class="text-center px-6">
    <div class="text-[120px] md:text-[140px] leading-none font-extrabold text-blue-600 select-none">
      401
    </div>
    <h2 class="text-2xl md:text-3xl font-semibold text-gray-800 mt-2">Tidak Diizinkan</h2>
    <p class="text-gray-600 mt-2 mb-6">Kamu perlu login terlebih dahulu untuk mengakses halaman ini.</p>

    <div class="flex justify-center gap-3 flex-wrap">
      <a href="/inspeksi/auth/login.php" class="bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-2 rounded transition">
        🔑 Login
      </a>
      <a href="/inspeksi/auth/register.php" class="bg-green-600 hover:bg-green-700 text-white px-5 py-2 rounded transition">
        ✍️ Daftar
      </a>
    </div>
  </div>
</body>

</html>