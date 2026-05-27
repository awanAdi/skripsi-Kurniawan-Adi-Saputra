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

if (!function_exists('e')) {
  function e(string $s): string
  {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }
}
if (!function_exists('safe_trim')) {
  function safe_trim($v): string
  {
    return is_string($v) ? trim($v) : '';
  }
}
$id_user = (int)($_SESSION['id_user'] ?? 0);
$nama_user = (string)($_SESSION['username'] ?? 'User');

$stmt = $conn->prepare("SELECT username, nama_lengkap, no_hp, email FROM users WHERE id_user = ? LIMIT 1");
$stmt->bind_param('i', $id_user);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc() ?: [];
$stmt->close();

if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$password_min_length = 6;
?>
<!DOCTYPE html>
<html lang="id">

<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Profil Saya — RTECH</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <link rel="icon" type="image/x-icon" href="../favicon.ico">
  <link rel="stylesheet" href="style.css">
  <style>
    :root {
      --bg: #0f1720;
      --card: #0e1520;
      --muted: #a3b0bf;
      --text: #e6eef8;
      --brand: #FF7A2D;
      --brand-dark: #D35400;
      --glass: rgba(255, 255, 255, 0.03);
    }

    body {
      background: var(--bg);
      color: var(--text);
      font-family: Inter, system-ui, Segoe UI, Roboto;
      margin: 0;
      min-height: 100vh
    }

    .card {
      background: var(--card);
      border: 1px solid rgba(255, 255, 255, .04);
      border-radius: 18px
    }

    .input {
      background: transparent;
      border: 1px solid rgba(255, 255, 255, .06);
      color: var(--text);
      border-radius: 10px;
      padding: .6rem
    }

    .input:focus {
      outline: none;
      box-shadow: 0 0 0 4px rgba(255, 122, 45, .08);
      border-color: var(--brand)
    }

    .label-floating {
      transition: all .18s ease;
      color: var(--muted);
      display: block;
      margin-top: .5rem
    }

    .btn-primary {
      background: linear-gradient(90deg, var(--brand-dark), var(--brand));
      color: black;
      border-radius: 10px;
      padding: .5rem .9rem
    }

    .hidden {
      display: none
    }

    .error-text {
      color: #ffb4b4;
      font-size: .85rem
    }

    :focus {
      outline: 3px solid rgba(255, 122, 45, .12);
      outline-offset: 2px
    }

    .modal-overlay {
      background: rgba(0, 0, 0, .6);
      backdrop-filter: blur(6px)
    }
  </style>
</head>

<body>
  <header class="p-4">
    <div class="max-w-4xl mx-auto flex items-center justify-between">
      <div class="flex items-center gap-3 text-[var(--brand)]">
        <div class="rounded p-2 bg-[color:var(--glass)] border border-white/6">
          <a href="pelanggan_dashboard.php">
            <span class="font-extrabold text-lg tracking-tight">Rtech</span>
          </a>
        </div>
        <p class="text-sm" style="color:var(--muted)">Jasa Inspeksi Mobil — Laporan PDF & Video</p>
      </div>
      <div class="flex items-center gap-3">
        <p id="jamClient" class="text-sm" aria-live="polite">⏱️ --:-- WIB</p>
        <div class="relative">
          <button id="avatarBtn" aria-haspopup="true" aria-expanded="false" class="w-10 h-10 rounded-full bg-[color:var(--brand)] text-black flex items-center justify-center font-bold" aria-label="Buka menu profil">
            <?= e(strtoupper(substr($nama_user, 0, 1))) ?>
          </button>
          <div id="profileMenu" class="hidden absolute right-0 mt-2 w-48 card p-1 z-40" role="menu" aria-label="Menu profil">
            <a href="pelanggan_dashboard.php" class="block px-4 py-2 hover:bg-white/5">🏠 Dashboard</a>
            <button id="logoutBtn" class="w-full text-left px-4 py-2 hover:bg-white/5">🚪 Logout</button>
          </div>
        </div>
      </div>
    </div>
  </header>

  <div id="logoutModal" class="fixed inset-0 hidden items-center justify-center z-50 modal-overlay" role="dialog" aria-modal="true" aria-labelledby="logoutTitle" style="display:none;">
    <div class="max-w-sm w-full mx-4 p-4">
      <div class="card p-6">
        <h3 id="logoutTitle" class="text-lg font-semibold" style="color:var(--brand)">Konfirmasi Logout</h3>
        <p class="text-sm" style="color:var(--muted)">Anda yakin ingin keluar dari akun?</p>
        <div class="flex justify-center gap-3 mt-4">
          <button id="logoutCancel" class="px-4 py-2 border rounded-lg">Batal</button>
          <form action="../auth/logout.php" method="POST" class="m-0">
            <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">
            <button type="submit" class="btn-primary">Logout</button>
          </form>
        </div>
      </div>
    </div>
  </div>

  <main class="max-w-2xl mx-auto p-6">
    <div class="card p-8">
      <h1 class="text-3xl font-bold mb-2">Profil Saya</h1>
      <p class="text-sm" style="color:var(--muted)">Kelola informasi akun Anda dengan mudah</p>

      <form id="profilForm" class="space-y-5 mt-6" autocomplete="off" novalidate>
        <input type="hidden" name="csrf_token" id="csrf_token" value="<?= e($csrf_token) ?>">
        <input type="hidden" name="id_user" value="<?= e((string)$id_user) ?>">

        <div>
          <label for="username" class="label-floating">Username</label>
          <div class="mt-1">
            <input id="username" name="username" type="text" class="input w-full" required
              value="<?= e(safe_trim($user['username'] ?? '')) ?>" aria-describedby="err_username" aria-required="true" />
          </div>
          <div id="err_username" class="error-text hidden" role="alert" aria-live="polite"></div>
        </div>

        <div>
          <label for="nama_lengkap" class="label-floating">Nama Lengkap</label>
          <div class="mt-1">
            <input id="nama_lengkap" name="nama_lengkap" type="text" class="input w-full" required
              value="<?= e(safe_trim($user['nama_lengkap'] ?? '')) ?>" aria-describedby="err_nama" />
          </div>
          <div id="err_nama" class="error-text hidden" role="alert" aria-live="polite"></div>
        </div>

        <div>
          <label for="no_hp" class="label-floating">Nomor HP</label>
          <div class="mt-1">
            <input id="no_hp" name="no_hp" type="tel" inputmode="numeric" class="input w-full" required
              pattern="[0-9]{10,15}" maxlength="15"
              value="<?= e(safe_trim($user['no_hp'] ?? '')) ?>" aria-describedby="err_hp" />
          </div>
          <div id="err_hp" class="error-text hidden" role="alert" aria-live="polite"></div>
        </div>

        <div>
          <label for="email" class="label-floating">Email</label>
          <div class="mt-1">
            <input id="email" name="email" type="email" class="input w-full" required
              value="<?= e(safe_trim($user['email'] ?? '')) ?>" aria-describedby="err_email" />
          </div>
          <div id="err_email" class="error-text hidden" role="alert" aria-live="polite"></div>
        </div>

        <div>
          <label for="password" class="label-floating">Password Baru <span class="text-xs" style="color:var(--muted)">(opsional)</span></label>
          <div class="mt-1 relative">
            <input id="password" name="password" type="password" class="input w-full pr-10" placeholder="Kosongkan jika tidak ingin mengganti" aria-describedby="err_pass" minlength="<?= $password_min_length ?>">
            <button type="button" id="togglePassword" class="absolute right-2 top-1/2 -translate-y-1/2" aria-label="Tampilkan / sembunyikan password">👁️</button>
          </div>
        </div>

        <div>
          <label for="confirm_password" class="label-floating">Konfirmasi Password Baru</label>
          <div class="mt-1">
            <input id="confirm_password" name="confirm_password" type="password" class="input w-full" placeholder="Ulangi password baru" aria-describedby="err_pass">
          </div>
          <div id="err_pass" class="error-text hidden" role="alert" aria-live="polite"></div>
        </div>

        <div class="flex items-center justify-between gap-3 pt-2">
          <a href="pelanggan_dashboard.php" class="text-[color:var(--brand)] hover:opacity-90 text-sm">← Kembali</a>
          <button id="btnSubmit" type="submit" class="btn-primary flex items-center gap-2" aria-live="polite">
            <span id="btnText">💾 Simpan Perubahan</span>
          </button>
        </div>
      </form>
    </div>
  </main>

  <nav class="fixed bottom-0 inset-x-0 bg-[color:var(--card)] border-t border-white/6 flex justify-around items-center py-2 z-40 md:hidden">
    <a href="pelanggan_dashboard.php" class="flex flex-col items-center text-xs" style="color:var(--muted)">🏠<span>Dashboard</span></a>
    <a href="buat_order.php" class="flex flex-col items-center text-xs" style="color:var(--muted)">➕<span>Order</span></a>
    <a href="profil_pelanggan.php" class="flex flex-col items-center text-xs" style="color:var(--brand)">👤<span>Profil</span></a>
  </nav>

  <script>
    (() => {
      const csrfInput = document.getElementById('csrf_token');
      const csrf = csrfInput ? csrfInput.value : '';
      const btn = document.getElementById('btnSubmit');
      const btnText = document.getElementById('btnText');
      const form = document.getElementById('profilForm');

      // toggle password visibility
      const togglePassword = document.getElementById('togglePassword');
      const passwordField = document.getElementById('password');
      if (togglePassword && passwordField) {
        togglePassword.addEventListener('click', () => {
          const t = passwordField.type === 'password' ? 'text' : 'password';
          passwordField.type = t;
          togglePassword.textContent = t === 'text' ? '🙈' : '👁️';
        });
      }

      // profile menu & logout modal
      const avatarBtn = document.getElementById('avatarBtn');
      const profileMenu = document.getElementById('profileMenu');

      if (avatarBtn && profileMenu) {
        avatarBtn.addEventListener('click', (e) => {
          e.stopPropagation();
          const hidden = profileMenu.classList.toggle('hidden');
          avatarBtn.setAttribute('aria-expanded', (!hidden).toString());
        });
        document.addEventListener('click', (e) => {
          if (!profileMenu.contains(e.target) && e.target !== avatarBtn) profileMenu.classList.add('hidden');
        });
      }

      const logoutBtn = document.getElementById('logoutBtn');
      const logoutModal = document.getElementById('logoutModal');
      const logoutCancel = document.getElementById('logoutCancel');

      // Safety: sembunyikan overlay jika terbuka saat load
      document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.modal-overlay, .overlay, .modal-backdrop').forEach(el => {
          el.classList.add('hidden');
          el.style.display = 'none';
          el.style.visibility = 'hidden';
        });
        // Pastikan main/cards dapat berinteraksi
        const main = document.querySelector('main');
        if (main) main.style.zIndex = '10';
      });

      // Show/hide modal with explicit display values
      if (logoutBtn && logoutModal) {
        logoutBtn.addEventListener('click', () => {
          logoutModal.classList.remove('hidden');
          logoutModal.style.display = 'flex';
          logoutModal.style.visibility = 'visible';
          logoutModal.style.opacity = '1';
          logoutModal.querySelector('button, form')?.focus();
        });
      }

      if (logoutCancel && logoutModal) {
        logoutCancel.addEventListener('click', () => {
          logoutModal.classList.add('hidden');
          logoutModal.style.display = 'none';
          logoutModal.style.visibility = 'hidden';
          logoutModal.style.opacity = '0';
        });
      }

      function showError(fieldId, message) {
        const el = document.getElementById(fieldId);
        if (!el) return;
        el.textContent = message;
        el.classList.remove('hidden');
        el.setAttribute('aria-hidden', 'false');
      }

      function clearErrors() {
        document.querySelectorAll('.error-text').forEach(el => {
          el.textContent = '';
          el.classList.add('hidden');
          el.setAttribute('aria-hidden', 'true');
        });
      }

      if (form) {
        form.addEventListener('submit', async (ev) => {
          ev.preventDefault();
          clearErrors();

          const username = (document.getElementById('username').value || '').trim();
          const nama = (document.getElementById('nama_lengkap').value || '').trim();
          const no_hp = (document.getElementById('no_hp').value || '').trim();
          const email = (document.getElementById('email').value || '').trim();
          const password = (document.getElementById('password').value || '');
          const confirm = (document.getElementById('confirm_password').value || '');
          const minPass = <?= json_encode($password_min_length) ?>;

          if (!username) {
            showError('err_username', 'Username harus diisi');
            document.getElementById('username').focus();
            return;
          }
          if (!nama) {
            showError('err_nama', 'Nama lengkap harus diisi');
            document.getElementById('nama_lengkap').focus();
            return;
          }
          if (!/^[0-9]{10,15}$/.test(no_hp)) {
            showError('err_hp', 'Nomor HP tidak valid (10-15 digit)');
            document.getElementById('no_hp').focus();
            return;
          }
          if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            showError('err_email', 'Email tidak valid');
            document.getElementById('email').focus();
            return;
          }
          if (password && password.length < minPass) {
            showError('err_pass', `Password minimal ${minPass} karakter`);
            document.getElementById('password').focus();
            return;
          }
          if (password && confirm !== password) {
            showError('err_pass', 'Password dan konfirmasi tidak cocok');
            document.getElementById('confirm_password').focus();
            return;
          }

          const fd = new FormData(form);
          btn.disabled = true;
          btnText.textContent = '⏳ Menyimpan...';

          try {
            const resp = await fetch('update_profil.php', {
              method: 'POST',
              body: fd,
              headers: {
                'X-CSRF-Token': csrf
              }
            });

            if (!resp.ok) throw new Error('Network response not ok');
            const data = await resp.json();

            btn.disabled = false;
            btnText.textContent = '💾 Simpan Perubahan';

            if (data.status === 'success') {
            Swal.fire({
                  icon: 'success',
                  title: 'Berhasil',
                  text: data.message || 'Profil disimpan',
                  position: 'top',
                  timer: 2000,
                  timerProgressBar: true,
                  showConfirmButton: false,
                  allowOutsideClick: true,
                  allowEscapeKey: true,
                  didOpen: (toast) => {
                    toast.addEventListener('click', () => Swal.close());
                  }
                });
              if (data.username) {
                document.getElementById('avatarBtn').textContent = data.username.charAt(0).toUpperCase();
              }
            } else {
              Swal.fire({
                  icon: 'error',
                  title: 'Gagal',
                  text: data.message || 'Terjadi kesalahan',
                  position: 'top',
                  timer: 2500,
                  timerProgressBar: true,
                  showConfirmButton: false,
                  allowOutsideClick: true,
                  allowEscapeKey: true,
                  didOpen: (toast) => {
                    toast.addEventListener('click', () => Swal.close());
                  }
                });
              if (data.errors) {
                if (data.errors.username) showError('err_username', data.errors.username);
                if (data.errors.nama_lengkap) showError('err_nama', data.errors.nama_lengkap);
                if (data.errors.no_hp) showError('err_hp', data.errors.no_hp);
                if (data.errors.email) showError('err_email', data.errors.email);
                if (data.errors.password) showError('err_pass', data.errors.password);
                const firstErr = document.querySelector('.error-text:not(.hidden)');
                if (firstErr) {
                  const id = firstErr.id.replace('err_', '');
                  const fld = document.getElementById(id) || firstErr;
                  if (fld.focus) fld.focus();
                }
              }
            }
          } catch (err) {
            btn.disabled = false;
            btnText.textContent = '💾 Simpan Perubahan';
            Swal.fire({
              icon: 'error',
              title: 'Error',
              text: 'Terjadi kesalahan koneksi. Coba lagi.'
            });
            console.error(err);
          }
        });
      }

      // Label floating logic (simpan)
      document.querySelectorAll('#profilForm input').forEach(input => {
        const id = input.id;
        const label = document.querySelector(`label[for="${id}"]`);
        if (!label) return;
        const update = () => {
          if (input.value.trim() !== '') {
            label.style.transform = 'translateY(-0.5rem)';
            label.style.fontSize = '0.85rem';
            label.style.color = 'var(--brand)';
          } else {
            label.style.transform = '';
            label.style.fontSize = '';
            label.style.color = '';
          }
        };
        update();
        input.addEventListener('input', update);
      });

      function tampilkanJam() {
        const now = new Date();
        const h = String(now.getHours()).padStart(2, '0');
        const m = String(now.getMinutes()).padStart(2, '0');
        document.getElementById('jamClient').innerText = `⏱️ ${h}:${m} WIB`;
      }
      tampilkanJam();
      setInterval(tampilkanJam, 60000);

    })();
  </script>

  <?php include 'footer.php'; ?>
</body>

</html>