<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['username']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit();
}
?>
<div class="flex">
    <div class="md:hidden fixed top-4 left-4 z-50">
        <button onclick="toggleSidebar()"
            class="p-2 bg-indigo-600 text-white rounded shadow">
            ☰
        </button>
    </div>

    <aside id="sidebar"
        class="w-60 bg-white border-r border-gray-200 min-h-screen fixed flex flex-col 
               transition-transform duration-300 transform -translate-x-full md:translate-x-0 z-40">
        <div class="px-4 py-6 border-b pt-7">
            <h1 class="text-xl font-bold text-gray-800 mt-7">RTECH Admin</h1>
            <p class="text-sm text-gray-500 mt-1">
                Halo, <?= htmlspecialchars($_SESSION['username']) ?>
            </p>
        </div>
        <nav class="flex-1 px-3 py-4 space-y-1 text-gray-700">
            <a href="admin_dashboard.php" class="flex items-center gap-2 px-3 py-2 rounded hover:bg-gray-100">
                <span>🏠</span> Dashboard
            </a>
            <a href="buat_task.php" class="flex items-center gap-2 px-3 py-2 rounded hover:bg-gray-100">
                <span>📋</span>Penugasan Teknisi
            </a>
            <a href="buat_order_manual.php" class="flex items-center gap-2 px-3 py-2 rounded hover:bg-gray-100">
                <span>➕</span> Buat Order
            </a>
            <a href="history.php" class="flex items-center gap-2 px-3 py-2 rounded hover:bg-gray-100">
                <span>📜</span> Histori Inspeksi
            </a>
            <a href="mekanik.php" class="flex items-center gap-2 px-3 py-2 rounded hover:bg-gray-100">
                <span>👥</span> Akun Teknisi
            </a>
            <a href="pelanggan.php" class="flex items-center gap-2 px-3 py-2 rounded hover:bg-gray-100">
                <span>👥</span> Data Pelanggan
            </a>
        </nav>
    </aside>

    <div id="overlay"
        class="fixed inset-0 bg-black bg-opacity-50 hidden z-30 md:hidden"
        onclick="toggleSidebar()"></div>

    <div class="flex-1 md:ml-60 p-6 bg-gray-50 min-h-screen transition-all duration-300">
        <script>
            function toggleSidebar() {
                const sidebar = document.getElementById('sidebar');
                const overlay = document.getElementById('overlay');
                sidebar.classList.toggle('-translate-x-full');
                overlay.classList.toggle('hidden');
            }
        </script>