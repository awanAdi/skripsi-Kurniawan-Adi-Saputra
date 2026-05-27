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

if (!isset($_SESSION['regenerated'])) {
  session_regenerate_id(true);
  $_SESSION['regenerated'] = true;
}
function e(string $s): string
{
  return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function is_valid_url(string $url): bool
{
  if ($url === '') return false;
  $parts = parse_url($url);
  if ($parts === false) return false;
  $scheme = strtolower($parts['scheme'] ?? '');
  return in_array($scheme, ['http', 'https'], true);
}

$id_user = (int)($_SESSION['id_user'] ?? 0);
$nama_user = (string)($_SESSION['username'] ?? 'User');

$jam = (int)date('H');
if ($jam >= 4 && $jam < 12) $salam = "Selamat pagi";
elseif ($jam >= 12 && $jam < 15) $salam = "Selamat siang";
elseif ($jam >= 15 && $jam < 18) $salam = "Selamat sore";
else $salam = "Selamat malam";

if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

function ambil_statistik(mysqli $conn, int $id_user): array
{
  $sql = "
        SELECT status, COUNT(*) as total
        FROM order_inspeksi
        WHERE id_pelanggan = ?
        GROUP BY status
    ";
  $stmt = $conn->prepare($sql);
  $statistik = ['total_order' => 0];
  if ($stmt) {
    $stmt->bind_param('i', $id_user);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
      $statistik['total_order'] += (int)$row['total'];
      $statistik[$row['status']] = (int)$row['total'];
    }
    $stmt->close();
  }
  return $statistik;
}

$statistik = ambil_statistik($conn, $id_user);
$total_order = $statistik['total_order'] ?? 0;
$diproses    = $statistik['Diproses']   ?? 0;
$selesai     = $statistik['Selesai']    ?? 0;
$disetujui   = $statistik['Disetujui']  ?? 0;

$search = '';
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['q'])) {
  $q = (string)$_GET['q'];
  $provided = (string)($_GET['csrf_token'] ?? '');
  if (!hash_equals((string)$csrf_token, $provided)) {
    $_SESSION['flash_error'] = "Pencarian dibatalkan karena token keamanan tidak valid.";
    header("Location: pelanggan_dashboard.php");
    exit();
  }
  $search = mb_substr(trim($q), 0, 255);
}

function fetch_orders_for_user(mysqli $conn, int $id_user, string $search): array
{
  $base_sql = "
        SELECT o.id_order, o.tanggal_order, o.status,
               k.nomor_polisi, k.merk, k.model, k.tahun_produksi, k.alamat, k.link_gmaps
        FROM order_inspeksi o
        JOIN kendaraan k ON o.id_mobil = k.id_mobil
        WHERE o.id_pelanggan = ?
    ";
  $params = [$id_user];
  if ($search !== '') {
    $base_sql .= " AND (
            k.nomor_polisi LIKE ? OR
            k.merk LIKE ? OR
            k.model LIKE ? OR
            k.tahun_produksi LIKE ? OR
            k.alamat LIKE ?
        )";
    $like = '%' . $search . '%';
    $params = [$id_user, $like, $like, $like, $like, $like];
  }
  $base_sql .= " ORDER BY o.tanggal_order DESC";

  $stmt = $conn->prepare($base_sql);
  $out = [];
  if (!$stmt) {
    return $out;
  }

  if ($search !== '') {
    $stmt->bind_param('isssss', $params[0], $params[1], $params[2], $params[3], $params[4], $params[5]);
  } else {
    $stmt->bind_param('i', $params[0]);
  }
  $stmt->execute();

  if (method_exists($stmt, 'get_result')) {
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
      $out[] = $r;
    }
    $res->free();
  } else {
    $stmt->bind_result($r_id_order, $r_tanggal_order, $r_status, $r_nomor_polisi, $r_merk, $r_model, $r_tahun_produksi, $r_alamat, $r_link_gmaps);
    while ($stmt->fetch()) {
      $out[] = [
        'id_order' => $r_id_order,
        'tanggal_order' => $r_tanggal_order,
        'status' => $r_status,
        'nomor_polisi' => $r_nomor_polisi,
        'merk' => $r_merk,
        'model' => $r_model,
        'tahun_produksi' => $r_tahun_produksi,
        'alamat' => $r_alamat,
        'link_gmaps' => $r_link_gmaps
      ];
    }
  }
  $stmt->close();
  return $out;
}

$orders = fetch_orders_for_user($conn, $id_user, $search);

function sanitize_orders_for_json(array $orders): array
{
  foreach ($orders as &$o) {
    $o['id_order'] = isset($o['id_order']) ? (string)$o['id_order'] : '';
    $o['tanggal_order'] = $o['tanggal_order'] ?? '';
    $o['status'] = $o['status'] ?? '';
    $o['nomor_polisi'] = $o['nomor_polisi'] ?? '';
    $o['merk'] = $o['merk'] ?? '';
    $o['model'] = $o['model'] ?? '';
    $o['tahun_produksi'] = $o['tahun_produksi'] ?? '';
    $o['alamat'] = $o['alamat'] ?? '';
    $o['link_gmaps'] = $o['link_gmaps'] ?? '';
    foreach (['nomor_polisi', 'merk', 'model', 'alamat', 'link_gmaps'] as $k) {
      if (is_string($o[$k])) {
        $o[$k] = preg_replace('/[^\P{C}\n\r\t]+/u', '', $o[$k]);
      }
    }
  }
  unset($o);
  return $orders;
}
$orders_safe = sanitize_orders_for_json($orders);

$json_orders = json_encode($orders_safe, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES);

$flash_error = $_SESSION['flash_error'] ?? null;
if ($flash_error) unset($_SESSION['flash_error']);

?>
<!DOCTYPE html>
<html lang="id">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Dashboard Pelanggan — RTECH</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="icon" type="image/x-icon" href="../favicon.ico">
  <style>
    :root {
      --bg: #0a0e1a;
      --bg-secondary: #0f1423;
      --card: #151b2e;
      --card-hover: #1a2235;
      --muted: #94a3b8;
      --text: #f1f5f9;
      --brand: #FF7A2D;
      --brand-dark: #D35400;
      --success: #10b981;
      --warning: #f59e0b;
      --danger: #ef4444;
      --info: #3b82f6;
    }

    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    body {
      font-family: 'Inter', system-ui, -apple-system, sans-serif;
      background: linear-gradient(135deg, var(--bg) 0%, var(--bg-secondary) 100%);
      color: var(--text);
      min-height: 100vh;
      padding-bottom: 84px;
    }

    /* Animations */
    @keyframes fadeInUp {
      from {
        opacity: 0;
        transform: translateY(20px);
      }

      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    @keyframes slideIn {
      from {
        opacity: 0;
        transform: translateX(-10px);
      }

      to {
        opacity: 1;
        transform: translateX(0);
      }
    }

    @keyframes scaleIn {
      from {
        opacity: 0;
        transform: scale(0.95);
      }

      to {
        opacity: 1;
        transform: scale(1);
      }
    }

    @keyframes shimmer {
      0% {
        background-position: -1000px 0;
      }

      100% {
        background-position: 1000px 0;
      }
    }

    .animate-fade-in-up {
      animation: fadeInUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards;
    }

    .animate-slide-in {
      animation: slideIn 0.5s cubic-bezier(0.16, 1, 0.3, 1) forwards;
    }

    .animate-scale-in {
      animation: scaleIn 0.4s cubic-bezier(0.16, 1, 0.3, 1) forwards;
    }

    /* Card Styles */
    .glass-card {
      background: rgba(21, 27, 46, 0.7);
      backdrop-filter: blur(12px);
      border: 1px solid rgba(255, 255, 255, 0.08);
      transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .glass-card:hover {
      background: rgba(26, 34, 53, 0.8);
      border-color: rgba(255, 255, 255, 0.12);
      transform: translateY(-2px);
      box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
    }

    .gradient-card {
      background: linear-gradient(135deg, rgba(255, 122, 45, 0.15) 0%, rgba(211, 84, 0, 0.1) 100%);
      border: 1px solid rgba(255, 122, 45, 0.2);
      position: relative;
      overflow: hidden;
    }

    .gradient-card::before {
      content: '';
      position: absolute;
      top: 0;
      left: -100%;
      width: 100%;
      height: 100%;
      background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.1), transparent);
      transition: left 0.5s;
    }

    .gradient-card:hover::before {
      left: 100%;
    }

    /* Status Badges */
    .status-badge {
      padding: 0.375rem 0.75rem;
      border-radius: 9999px;
      font-weight: 600;
      font-size: 0.75rem;
      display: inline-flex;
      align-items: center;
      gap: 0.375rem;
      text-transform: uppercase;
      letter-spacing: 0.025em;
    }

    .status-diproses {
      background: rgba(59, 130, 246, 0.15);
      color: #60a5fa;
      border: 1px solid rgba(59, 130, 246, 0.3);
    }

    .status-selesai {
      background: rgba(16, 185, 129, 0.15);
      color: #34d399;
      border: 1px solid rgba(16, 185, 129, 0.3);
    }

    .status-disetujui {
      background: rgba(245, 158, 11, 0.15);
      color: #fbbf24;
      border: 1px solid rgba(245, 158, 11, 0.3);
    }

    .status-gagal {
      background: rgba(239, 68, 68, 0.15);
      color: #f87171;
      border: 1px solid rgba(239, 68, 68, 0.3);
    }

    /* Button Styles */
    .btn-primary {
      background: linear-gradient(135deg, var(--brand) 0%, var(--brand-dark) 100%);
      color: white;
      font-weight: 600;
      padding: 0.75rem 1.5rem;
      border-radius: 0.75rem;
      transition: all 0.3s ease;
      box-shadow: 0 4px 14px rgba(255, 122, 45, 0.3);
      position: relative;
      overflow: hidden;
    }

    .btn-primary::before {
      content: '';
      position: absolute;
      top: 0;
      left: -100%;
      width: 100%;
      height: 100%;
      background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
      transition: left 0.5s;
    }

    .btn-primary:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 20px rgba(255, 122, 45, 0.4);
    }

    .btn-primary:hover::before {
      left: 100%;
    }

    .btn-secondary {
      background: rgba(255, 255, 255, 0.05);
      border: 1px solid rgba(255, 255, 255, 0.1);
      color: var(--text);
      font-weight: 500;
      padding: 0.625rem 1.25rem;
      border-radius: 0.625rem;
      transition: all 0.3s ease;
    }

    .btn-secondary:hover {
      background: rgba(255, 255, 255, 0.1);
      border-color: rgba(255, 255, 255, 0.2);
    }

    /* Icon Containers */
    .icon-wrapper {
      width: 3rem;
      height: 3rem;
      border-radius: 1rem;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.5rem;
      position: relative;
      transition: all 0.3s ease;
    }

    .icon-wrapper::before {
      content: '';
      position: absolute;
      inset: 0;
      border-radius: inherit;
      padding: 2px;
      background: linear-gradient(135deg, rgba(255, 122, 45, 0.4), rgba(211, 84, 0, 0.2));
      -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
      -webkit-mask-composite: xor;
      mask-composite: exclude;
    }

    /* Stat Card Animation */
    .stat-card {
      position: relative;
      overflow: hidden;
    }

    .stat-card::after {
      content: '';
      position: absolute;
      top: 0;
      right: 0;
      width: 100px;
      height: 100px;
      background: radial-gradient(circle, rgba(255, 122, 45, 0.1) 0%, transparent 70%);
      border-radius: 50%;
      transform: translate(30%, -30%);
    }

    .search-input {
      background: rgba(15, 20, 35, 0.8);
      border: 1px solid rgba(255, 255, 255, 0.08);
      color: var(--text);
      padding: 0.875rem 1rem;
      padding-right: 3rem;
      border-radius: 0.875rem;
      width: 100%;
      transition: all 0.3s ease;
    }

    .search-input:focus {
      outline: none;
      border-color: var(--brand);
      box-shadow: 0 0 0 3px rgba(255, 122, 45, 0.1);
      background: rgba(15, 20, 35, 1);
    }

    .search-input::placeholder {
      color: var(--muted);
      opacity: 0.5;
    }

    .order-card {
      background: var(--card);
      border: 1px solid rgba(255, 255, 255, 0.06);
      border-radius: 1rem;
      padding: 1.5rem;
      transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
      position: relative;
      overflow: hidden;
    }

    .order-card::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 3px;
      background: linear-gradient(90deg, var(--brand), var(--brand-dark));
      transform: scaleX(0);
      transition: transform 0.3s ease;
    }

    .order-card:hover {
      border-color: rgba(255, 255, 255, 0.12);
      transform: translateY(-4px);
      box-shadow: 0 12px 40px rgba(0, 0, 0, 0.4);
    }

    .order-card:hover::before {
      transform: scaleX(1);
    }

    /* Shimmer Effect */
    .shimmer {
      background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.05), transparent);
      background-size: 200% 100%;
      animation: shimmer 2s infinite;
    }

    /* Scrollbar */
    ::-webkit-scrollbar {
      width: 8px;
      height: 8px;
    }

    ::-webkit-scrollbar-track {
      background: var(--bg);
    }

    ::-webkit-scrollbar-thumb {
      background: rgba(255, 255, 255, 0.1);
      border-radius: 4px;
    }

    ::-webkit-scrollbar-thumb:hover {
      background: rgba(255, 255, 255, 0.2);
    }

    /* Stagger Animation */
    .stagger-item {
      opacity: 0;
      animation: fadeInUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards;
    }

    .stagger-item:nth-child(1) {
      animation-delay: 0.1s;
    }

    .stagger-item:nth-child(2) {
      animation-delay: 0.2s;
    }

    .stagger-item:nth-child(3) {
      animation-delay: 0.3s;
    }

    .stagger-item:nth-child(4) {
      animation-delay: 0.4s;
    }

    .stagger-item:nth-child(5) {
      animation-delay: 0.5s;
    }

    .stagger-item:nth-child(6) {
      animation-delay: 0.6s;
    }
  </style>
</head>

<body>

  <?php if ($flash_error): ?>
    <div class="max-w-6xl mx-auto px-4 mt-4 animate-scale-in">
      <div class="bg-red-500/10 border border-red-500/30 text-red-300 px-5 py-4 rounded-xl backdrop-blur-sm">
        <div class="flex items-start gap-3">
          <span class="text-xl">⚠️</span>
          <div>
            <strong class="font-semibold">Error</strong>
            <p class="text-sm mt-1"><?= e($flash_error) ?></p>
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <header class="sticky top-0 z-40 bg-black/20 backdrop-blur-xl border-b border-white/5">
    <div class="max-w-6xl mx-auto px-4 py-4">
      <div class="flex items-center justify-between gap-4">
        <div class="flex items-center gap-4">
          <div class="glass-card rounded-xl px-4 py-2">
            <h1 class="text-[color:var(--brand)] font-extrabold text-xl tracking-tight cursor-pointer hover:opacity-80 transition"
              onclick="window.scrollTo({ top: 0, behavior: 'smooth' });">
              Rtech Indonesia
            </h1>
          </div>
          <p class="text-sm text-[color:var(--muted)] hidden md:block">Jasa Inspeksi Mobil Profesional</p>
        </div>

        <div class="flex items-center gap-4">
          <div class="glass-card px-3 py-2 rounded-lg hidden sm:flex items-center gap-2">
            <span class="text-lg">⏱️</span>
            <p id="jamClient" class="text-sm font-medium">--:-- WIB</p>
          </div>
          <div class="relative">
            <button id="avatarBtn" class="w-11 h-11 rounded-xl bg-gradient-to-br from-[color:var(--brand)] to-[color:var(--brand-dark)] text-white flex items-center justify-center font-bold text-lg shadow-lg hover:shadow-xl transition-all hover:scale-105">
              <?= e(strtoupper(substr($nama_user, 0, 1))) ?>
            </button>
            <div id="profileMenu" class="hidden absolute right-0 mt-3 w-48 glass-card rounded-xl shadow-2xl overflow-hidden">
              <a href="profil_pelanggan.php" class="flex items-center gap-3 px-4 py-3 hover:bg-white/5 transition">
                <span>👤</span>
                <span class="font-medium">Profil</span>
              </a>
              <a href="pelanggan_dashboard.php" class="flex items-center gap-3 px-4 py-3 hover:bg-white/5 transition">
                <span>🏠</span>
                <span class="font-medium">Dashboard</span>
              </a>
              <div class="border-t border-white/5 my-1"></div>
              <form method="POST" action="../auth/logout.php">
                <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">
                <button type="submit" class="flex items-center gap-3 px-4 py-3 hover:bg-red-500/10 transition w-full text-left text-red-400">
                  <span>🚪</span>
                  <span class="font-medium">Logout</span>
                </button>
              </form>
            </div>
          </div>
        </div>
      </div>
    </div>
  </header>

  <main class="max-w-6xl mx-auto px-4 mt-8 space-y-6">

    <!-- Welcome Card -->
    <div class="stagger-item glass-card rounded-2xl p-6 flex items-center gap-5">
      <div class="icon-wrapper bg-gradient-to-br from-[color:var(--brand)]/20 to-[color:var(--brand-dark)]/10">
        <span class="text-3xl"><?= e(strtoupper(substr($nama_user, 0, 1))) ?></span>
      </div>
      <div>
        <p class="text-[color:var(--muted)] text-sm font-medium"><?= e($salam) ?></p>
        <h2 class="text-2xl font-bold bg-gradient-to-r from-[color:var(--brand)] to-[color:var(--brand-dark)] bg-clip-text text-transparent">
          <?= e($nama_user) ?> 👋
        </h2>
      </div>
    </div>

    <!-- CTA Card -->
    <div class="stagger-item gradient-card rounded-2xl p-6 md:p-8 group">
      <div class="flex flex-col md:flex-row items-start md:items-center justify-between gap-6">
        <div class="flex-1">
          <div class="flex items-center gap-2 mb-2">
            <span class="text-3xl">🚗</span>
            <h2 class="text-2xl md:text-3xl font-extrabold text-white">
              Jangan beli mobil bekas tanpa dicek!
            </h2>
          </div>
          <p class="text-[color:var(--muted)] text-sm md:text-base">
            Laporan PDF profesional + video inspeksi lengkap — teknisi berpengalaman datang ke lokasi Anda.
          </p>
          <div class="flex flex-wrap gap-3 mt-4">
            <div class="flex items-center gap-2 text-sm text-[color:var(--muted)]">
              <span class="text-green-400">✓</span>
              <span>Laporan PDF Detail</span>
            </div>
            <div class="flex items-center gap-2 text-sm text-[color:var(--muted)]">
              <span class="text-green-400">✓</span>
              <span>Video Inspeksi</span>
            </div>
            <div class="flex items-center gap-2 text-sm text-[color:var(--muted)]">
              <span class="text-green-400">✓</span>
              <span>Teknisi Berpengalaman</span>
            </div>
          </div>
        </div>
        <a href="buat_order.php" class="btn-primary flex items-center gap-2 whitespace-nowrap">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
          </svg>
          <span>Cek Mobil Sekarang</span>
        </a>
      </div>
    </div>

    <!-- Stats Cards -->
    <div class="stagger-item grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
      <div class="glass-card rounded-xl p-5 stat-card group hover:scale-105 transition-transform">
        <div class="flex items-center gap-4">
          <div class="icon-wrapper bg-blue-500/10 text-blue-400 group-hover:scale-110 transition-transform">
            📦
          </div>
          <div>
            <p class="text-[color:var(--muted)] text-xs font-medium uppercase tracking-wide">Total Order</p>
            <p class="text-3xl font-bold mt-1"><?= e((string)$total_order) ?></p>
          </div>
        </div>
      </div>

      <div class="glass-card rounded-xl p-5 stat-card group hover:scale-105 transition-transform">
        <div class="flex items-center gap-4">
          <div class="icon-wrapper bg-yellow-500/10 text-yellow-400 group-hover:scale-110 transition-transform">
            ⏳
          </div>
          <div>
            <p class="text-[color:var(--muted)] text-xs font-medium uppercase tracking-wide">Diproses</p>
            <p class="text-3xl font-bold mt-1"><?= e((string)$diproses) ?></p>
          </div>
        </div>
      </div>

      <div class="glass-card rounded-xl p-5 stat-card group hover:scale-105 transition-transform">
        <div class="flex items-center gap-4">
          <div class="icon-wrapper bg-orange-500/10 text-orange-400 group-hover:scale-110 transition-transform">
            ✅
          </div>
          <div>
            <p class="text-[color:var(--muted)] text-xs font-medium uppercase tracking-wide">Disetujui</p>
            <p class="text-3xl font-bold mt-1"><?= e((string)$disetujui) ?></p>
          </div>
        </div>
      </div>

      <div class="glass-card rounded-xl p-5 stat-card group hover:scale-105 transition-transform">
        <div class="flex items-center gap-4">
          <div class="icon-wrapper bg-green-500/10 text-green-400 group-hover:scale-110 transition-transform">
            🎉
          </div>
          <div>
            <p class="text-[color:var(--muted)] text-xs font-medium uppercase tracking-wide">Selesai</p>
            <p class="text-3xl font-bold mt-1"><?= e((string)$selesai) ?></p>
          </div>
        </div>
      </div>
    </div>

    <!-- Search Section -->
    <div class="stagger-item">
      <form id="searchForm" method="GET" class="flex gap-3">
        <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">
        <div class="relative flex-1">
          <input id="q" name="q" type="text" placeholder="Cari nomor polisi, merk, model, tahun..." value="<?= e($search) ?>"
            class="search-input" autocomplete="off">
          <button id="clearBtn" type="button" class="absolute right-3 top-1/2 -translate-y-1/2 text-[color:var(--muted)] hover:text-[color:var(--text)] transition hidden">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>
        <button type="submit" class="btn-primary flex items-center gap-2 px-6">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <circle cx="11" cy="11" r="7" stroke-width="2" />
            <path d="M21 21l-4.35-4.35" stroke-width="2" stroke-linecap="round" />
          </svg>
          <span class="hidden sm:inline">Cari</span>
        </button>
      </form>
    </div>

    <!-- Orders Grid -->
    <div class="stagger-item">
      <?php if (count($orders) > 0): ?>
        <div id="ordersContainer" class="grid gap-5 grid-cols-1 sm:grid-cols-2 lg:grid-cols-3"></div>
        <div class="flex items-center justify-between mt-6 glass-card rounded-xl p-4">
          <div class="text-sm text-[color:var(--muted)] font-medium" id="pageInfo"></div>
          <div class="flex gap-2">
            <button id="prevPage" class="btn-secondary px-4 py-2 disabled:opacity-30 disabled:cursor-not-allowed">
              ← Prev
            </button>
            <button id="nextPage" class="btn-secondary px-4 py-2 disabled:opacity-30 disabled:cursor-not-allowed">
              Next →
            </button>
          </div>
        </div>
      <?php else: ?>
        <div class="glass-card rounded-2xl p-12 text-center">
          <div class="text-6xl mb-4">📭</div>
          <h3 class="text-xl font-semibold mb-2">Belum ada order inspeksi</h3>
          <p class="text-[color:var(--muted)] mb-6">Mulai inspeksi mobil bekas Anda sekarang untuk mendapatkan laporan lengkap.</p>
          <a href="buat_order.php" class="btn-primary inline-flex items-center gap-2">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
            Buat Order Pertama
          </a>
        </div>
      <?php endif; ?>
    </div>

  </main>

  <!-- Delete Modal -->
  <div id="deleteModal" class="fixed inset-0 hidden items-center justify-center z-50 px-4">
    <div class="relative w-full max-w-md animate-scale-in max-h-[90vh] overflow-y-auto">
      <div class="absolute inset-0 bg-black/70 backdrop-blur-sm"></div>
      <div class="relative w-full max-w-md animate-scale-in">
        <div class="glass-card rounded-2xl p-6 shadow-2xl">
          <div class="flex items-start gap-4 mb-5">
            <div class="w-12 h-12 rounded-xl bg-red-500/10 flex items-center justify-center text-2xl">
              🗑️
            </div>
            <div>
              <h2 class="text-xl font-bold text-red-400 mb-1">Hapus Order</h2>
              <p class="text-sm text-[color:var(--muted)]">
                Order inspeksi ini akan dihapus permanen. Tindakan ini tidak bisa dibatalkan.
              </p>
            </div>
          </div>
        </div>
        <div class="flex gap-3">
          <button id="cancelDelete" class="flex-1 btn-secondary py-3">
            Batal
          </button>
          <button id="confirmDelete" class="flex-1 bg-red-600 hover:bg-red-700 text-white font-semibold py-3 rounded-xl transition">
            Ya, Hapus
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- Logout Modal -->
  <div id="logoutConfirmModal" class="fixed inset-0 hidden items-center justify-center z-50 px-4">
    <div class="relative w-full max-w-md animate-scale-in max-h-[90vh] overflow-y-auto">
      <div class="absolute inset-0 bg-black/70 backdrop-blur-sm"></div>
      <div class="relative w-full max-w-md animate-scale-in">
        <div class="glass-card rounded-2xl p-6 shadow-2xl">
          <div class="flex items-start gap-4 mb-5">
            <div class="w-12 h-12 rounded-xl bg-[color:var(--brand)]/10 flex items-center justify-center text-2xl">
              🚪
            </div>
            <div>
              <h2 class="text-xl font-bold text-[color:var(--brand)] mb-1">Konfirmasi Logout</h2>
              <p class="text-sm text-[color:var(--muted)]">
                Anda akan keluar dari akun. Lanjutkan?
              </p>
            </div>
          </div>

          <div class="flex gap-3">
            <button id="logoutCancelBtn" class="flex-1 btn-secondary py-3">
              Batal
            </button>
            <button id="logoutConfirmBtn" class="flex-1 bg-red-600 hover:bg-red-700 text-white font-semibold py-3 rounded-xl transition">
              Ya, Logout
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
  <script>
    const ORDERS = <?= $json_orders ?: '[]' ?>;
    const CSRF_TOKEN = <?= json_encode($csrf_token, JSON_HEX_TAG | JSON_HEX_AMP) ?>;

    (function() {
      const avatarBtn = document.getElementById('avatarBtn');
      const profileMenu = document.getElementById('profileMenu');

      avatarBtn?.addEventListener('click', (e) => {
        e.stopPropagation();
        profileMenu.classList.toggle('hidden');
      });

      document.addEventListener('click', (e) => {
        if (!avatarBtn.contains(e.target) && !profileMenu.contains(e.target)) {
          profileMenu.classList.add('hidden');
        }
      });
    })();

    // Search Clear Button
    (function() {
      const qInput = document.getElementById('q');
      const clearBtn = document.getElementById('clearBtn');

      function updateClear() {
        clearBtn.classList.toggle('hidden', qInput.value.trim().length === 0);
      }

      qInput?.addEventListener('input', updateClear);
      clearBtn?.addEventListener('click', () => {
        qInput.value = '';
        updateClear();
        const url = new URL(window.location.href);
        url.searchParams.delete('q');
        url.searchParams.delete('csrf_token');
        history.replaceState(null, '', url.pathname + url.search);
      });

      updateClear();
    })();

    // Clock
    function tampilkanJam() {
      const sekarang = new Date();
      const jam = sekarang.getHours().toString().padStart(2, '0');
      const menit = sekarang.getMinutes().toString().padStart(2, '0');
      const el = document.getElementById('jamClient');
      if (el) el.innerText = `${jam}:${menit} WIB`;
    }
    tampilkanJam();
    setInterval(tampilkanJam, 60000);

    // Orders Management
    (function() {
      const orders = Array.isArray(ORDERS) ? ORDERS : [];
      const container = document.getElementById('ordersContainer');
      const deleteModal = document.getElementById('deleteModal');
      const cancelDeleteBtn = document.getElementById('cancelDelete');
      const confirmDeleteBtn = document.getElementById('confirmDelete');
      const prevPage = document.getElementById('prevPage');
      const nextPage = document.getElementById('nextPage');
      const pageInfo = document.getElementById('pageInfo');

      let currentPage = 1;
      let deleteTargetId = null;

      const statusConfig = {
        'Diproses': {
          class: 'status-diproses',
          icon: '⏳'
        },
        'Selesai': {
          class: 'status-selesai',
          icon: '✅'
        },
        'Disetujui': {
          class: 'status-disetujui',
          icon: '✓'
        },
        'Gagal': {
          class: 'status-gagal',
          icon: '❌'
        }
      };

      function getCols() {
        const w = window.innerWidth;
        if (w >= 1024) return 3;
        if (w >= 640) return 2;
        return 1;
      }

      function rowsForViewport() {
        const w = window.innerWidth;
        if (w >= 1024) return 4;
        if (w < 640) return 9;
        return 6;
      }

      function itemsPerPage() {
        if (window.innerWidth < 640) return 6;
        if (window.innerWidth < 1024) return 8;
        return 9;
      }


      function escapeHtml(str) {
        return String(str ?? '')
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;')
          .replace(/'/g, '&#039;');
      }

      function formatDate(dateStr) {
        if (!dateStr) return '-';
        const date = new Date(dateStr);
        const options = {
          day: 'numeric',
          month: 'short',
          year: 'numeric'
        };
        return date.toLocaleDateString('id-ID', options);
      }

      function renderPage(page = 1) {
        const perPage = itemsPerPage();
        const totalPages = Math.max(1, Math.ceil(orders.length / perPage));

        currentPage = Math.min(Math.max(1, page), totalPages);
        const start = (currentPage - 1) * perPage;
        const pageItems = orders.slice(start, start + perPage);

        container.innerHTML = pageItems.map(o => {
          const statusInfo = statusConfig[o.status] || statusConfig['Diproses'];

          return `
            <article class="order-card group">
              <div class="flex items-start justify-between mb-4">
                <div class="flex items-center gap-3">
                  <div class="icon-wrapper bg-gradient-to-br from-[color:var(--brand)]/20 to-[color:var(--brand-dark)]/10 text-2xl">
                    🚗
                  </div>
                  <div>
                    <h3 class="font-bold text-lg">${escapeHtml(o.nomor_polisi)}</h3>
                    <p class="text-sm text-[color:var(--muted)]">${escapeHtml(o.merk)} ${escapeHtml(o.model)}</p>
                  </div>
                </div>
              </div>

              <div class="space-y-2 mb-4">
                <div class="flex items-center gap-2 text-sm">
                  <span class="text-[color:var(--muted)]">📅</span>
                  <span class="text-[color:var(--muted)]">${formatDate(o.tanggal_order)}</span>
                </div>
                <div class="flex items-center gap-2 text-sm">
                  <span class="text-[color:var(--muted)]">📍</span>
                  <span class="text-[color:var(--muted)] line-clamp-2">${escapeHtml(o.alamat) || 'Tidak ada alamat'}</span>
                </div>
                <div class="flex items-center gap-2 text-sm">
                  <span class="text-[color:var(--muted)]">🗓️</span>
                  <span class="text-[color:var(--muted)]">${escapeHtml(o.tahun_produksi) || '-'}</span>
                </div>
              </div>

              <div class="flex items-center justify-between pt-4 border-t border-white/5">
                <span class="status-badge ${statusInfo.class}">
                  <span>${statusInfo.icon}</span>
                  <span>${escapeHtml(o.status)}</span>
                </span>
                ${o.status === 'Gagal' 
                  ? `<button class="delBtn text-red-400 hover:text-red-300 transition-colors font-medium text-sm" data-id="${o.id_order}">
                       Hapus
                     </button>`
                  : `<a href="detail_order.php?id=${o.id_order}" class="text-[color:var(--brand)] hover:text-[color:var(--brand-dark)] transition-colors font-medium text-sm">
                       Lihat Detail →
                     </a>`
                }
              </div>
            </article>
          `;
        }).join('');

        pageInfo.textContent = `Halaman ${currentPage} dari ${totalPages}`;
        prevPage.disabled = currentPage === 1;
        nextPage.disabled = currentPage === totalPages;
      }

      container.addEventListener('click', e => {
        const btn = e.target.closest('.delBtn');
        if (!btn) return;

        deleteTargetId = btn.dataset.id;
        deleteModal.classList.remove('hidden');
        deleteModal.classList.add('flex');
      });

      cancelDeleteBtn?.addEventListener('click', () => {
        deleteModal.classList.add('hidden');
        deleteModal.classList.remove('flex');
        deleteTargetId = null;
      });

      confirmDeleteBtn?.addEventListener('click', () => {
        if (!deleteTargetId) return;

        fetch('hapus_order.php', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: new URLSearchParams({
              id_order: deleteTargetId,
              csrf_token: CSRF_TOKEN
            })
          })
          .then(res => res.json())
          .then(data => {
            if (!data.success) {
              alert(data.message || 'Gagal menghapus');
              return;
            }

            const idx = orders.findIndex(o => String(o.id_order) === String(deleteTargetId));
            if (idx !== -1) orders.splice(idx, 1);

            deleteModal.classList.add('hidden');
            deleteModal.classList.remove('flex');
            renderPage(currentPage);
          })
          .catch(() => alert('Koneksi server gagal'));
      });

      prevPage?.addEventListener('click', () => renderPage(currentPage - 1));
      nextPage?.addEventListener('click', () => renderPage(currentPage + 1));

      window.addEventListener('resize', () => {
        clearTimeout(window._resizeTimer);
        window._resizeTimer = setTimeout(() => renderPage(currentPage), 200);
      });

      renderPage(1);
    })();

    // Logout Modal
    (function() {
      const logoutForm = document.querySelector('form[action="../auth/logout.php"]');
      const logoutModal = document.getElementById('logoutConfirmModal');
      const cancelBtn = document.getElementById('logoutCancelBtn');
      const confirmBtn = document.getElementById('logoutConfirmBtn');

      if (!logoutForm || !logoutModal) return;

      logoutForm.addEventListener('submit', function(e) {
        if (logoutForm.dataset.confirmed === '1') return;
        e.preventDefault();
        logoutModal.classList.remove('hidden');
        logoutModal.classList.add('flex');
      });

      cancelBtn?.addEventListener('click', () => {
        logoutModal.classList.add('hidden');
        logoutModal.classList.remove('flex');
      });

      confirmBtn?.addEventListener('click', () => {
        logoutForm.dataset.confirmed = '1';
        logoutForm.submit();
      });

      logoutModal.addEventListener('click', (e) => {
        if (e.target === logoutModal) {
          logoutModal.classList.add('hidden');
          logoutModal.classList.remove('flex');
        }
      });
    })();
  </script>

  <?php include 'footer.php'; ?>

</body>

</html>