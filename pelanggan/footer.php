<?php

declare(strict_types=1);

if (!function_exists('e')) {
  function e(string $s): string
  {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }
}

if (!function_exists('safe_url')) {
  function safe_url(?string $url): ?string
  {
    if (empty($url)) return null;
    $url = trim($url);
    $parts = parse_url($url);
    if ($parts === false) return null;
    $scheme = strtolower($parts['scheme'] ?? '');
    if (!in_array($scheme, ['http', 'https'], true)) return null;
    return filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
  }
}

$company = [
  'name' => 'Rtech Indonesia',
  'address_lines' => [
    'Kujonsari Tundan No.7, RT.7, RW.3, Tundan, Purwomartani',
    'Kec. Kalasan, Kabupaten Sleman',
    'Daerah Istimewa Yogyakarta 55571'
  ],
  'phones' => [
    'Admin 1' => '089-6556-25-222',
    'Admin 2' => '089-6677-75-755',
    'Admin 3' => '0822-8208-1767'
  ],
  'maps' => safe_url('https://maps.app.goo.gl/jFGPLraHKQ6itP236'),
  'social' => [
    'instagram' => safe_url('https://www.instagram.com/jasainspeksijogja'),
    'tiktok'    => safe_url('https://www.tiktok.com/@jasainspeksijogja'),
    'youtube'   => safe_url('https://www.youtube.com/@Jasainspeksijogja')
  ],
  'icons_path' => '../uploads/icons'
];

if (!function_exists('to_whatsapp_number')) {
  function to_whatsapp_number(string $raw): ?string
  {
    $digits = preg_replace('/\D+/', '', $raw);
    if ($digits === '') return null;
    if (preg_match('/^0/', $digits)) {
      $digits = preg_replace('/^0+/', '', $digits);
      $digits = '62' . $digits;
    }
    if (preg_match('/^62[0-9]{6,}$/', $digits) || preg_match('/^[1-9][0-9]{6,}$/', $digits)) {
      return $digits;
    }
    return null;
  }
}

$year = date('Y');
$default_message = "Halo%20Rtech%20Indonesia%2C%20saya%20mau%20bertanya%20mengenai%20layanan%20inspeksi.";
?>

<style>
.footer-gradient {
  background: linear-gradient(180deg, rgba(10, 14, 26, 0) 0%, rgba(15, 20, 35, 0.8) 50%, rgba(7, 16, 34, 1) 100%);
}

.footer-card {
  background: rgba(21, 27, 46, 0.4);
  backdrop-filter: blur(8px);
  border: 1px solid rgba(255, 255, 255, 0.06);
  transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.footer-card:hover {
  background: rgba(21, 27, 46, 0.6);
  border-color: rgba(255, 122, 45, 0.2);
  transform: translateY(-2px);
}

.social-icon {
  position: relative;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 48px;
  height: 48px;
  border-radius: 12px;
  background: rgba(255, 255, 255, 0.05);
  border: 1px solid rgba(255, 255, 255, 0.1);
  transition: all 0.3s ease;
  overflow: hidden;
}

.social-icon::before {
  content: '';
  position: absolute;
  inset: 0;
  background: linear-gradient(135deg, var(--brand), var(--brand-dark));
  opacity: 0;
  transition: opacity 0.3s ease;
}

.social-icon:hover {
  transform: translateY(-4px) scale(1.05);
  border-color: var(--brand);
  box-shadow: 0 8px 20px rgba(255, 122, 45, 0.3);
}

.social-icon:hover::before {
  opacity: 0.15;
}

.social-icon img {
  position: relative;
  z-index: 1;
  filter: brightness(1.2);
}

.contact-link {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  color: var(--brand);
  text-decoration: none;
  font-weight: 500;
  transition: all 0.2s ease;
  padding: 0.25rem 0;
}

.contact-link:hover {
  color: var(--brand-dark);
  transform: translateX(2px);
}

.contact-link svg {
  transition: transform 0.2s ease;
}

.contact-link:hover svg {
  transform: scale(1.1);
}

.footer-heading {
  position: relative;
  display: inline-block;
  font-size: 1.125rem;
  font-weight: 700;
  color: white;
  margin-bottom: 1.5rem;
  padding-bottom: 0.5rem;
}

.footer-heading::after {
  content: '';
  position: absolute;
  bottom: 0;
  left: 0;
  width: 40px;
  height: 3px;
  background: linear-gradient(90deg, var(--brand), transparent);
  border-radius: 2px;
}

.map-button {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  background: linear-gradient(135deg, var(--brand), var(--brand-dark));
  color: white;
  font-weight: 600;
  padding: 0.75rem 1.5rem;
  border-radius: 12px;
  text-decoration: none;
  transition: all 0.3s ease;
  box-shadow: 0 4px 12px rgba(255, 122, 45, 0.25);
}

.map-button:hover {
  transform: translateY(-2px);
  box-shadow: 0 8px 20px rgba(255, 122, 45, 0.4);
}

.footer-divider {
  height: 1px;
  background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.1), transparent);
  margin: 2rem 0;
}

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

.footer-animate {
  animation: fadeInUp 0.6s ease-out forwards;
}

.footer-animate:nth-child(1) { animation-delay: 0.1s; opacity: 0; }
.footer-animate:nth-child(2) { animation-delay: 0.2s; opacity: 0; }
.footer-animate:nth-child(3) { animation-delay: 0.3s; opacity: 0; }

@media (max-width: 640px) {
  .footer-heading::after {
    left: 50%;
    transform: translateX(-50%);
  }
}
</style>

<footer role="contentinfo" class="footer-gradient text-white mt-16 pt-8">
  <div class="max-w-6xl mx-auto px-4 py-12">
    
    <!-- Main Footer Content -->
    <div class="grid gap-8 md:grid-cols-3 mb-12">
      
      <!-- Contact Information -->
      <div class="footer-card rounded-2xl p-6 footer-animate text-center md:text-left">
        <h3 class="footer-heading">
          <svg class="inline-block w-5 h-5 mr-2 mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
          </svg>
          Lokasi Kami
        </h3>
        
        <address class="not-italic text-sm text-[color:var(--muted)] leading-relaxed mb-6">
          <?php foreach ($company['address_lines'] as $line): ?>
            <?= e($line) ?><br>
          <?php endforeach; ?>
        </address>

        <div class="space-y-3 mb-6">
          <p class="text-xs font-semibold text-[color:var(--brand)] uppercase tracking-wider mb-2">Hubungi Kami</p>
          <?php foreach ($company['phones'] as $label => $number): ?>
            <?php
            $wa_num = to_whatsapp_number((string)$number);
            if ($wa_num !== null) {
              $wa_href = "https://wa.me/" . e($wa_num) . "?text=" . $default_message;
            ?>
              <div class="flex items-center justify-center md:justify-start gap-2 text-sm">
                <svg class="w-4 h-4 text-green-400 flex-shrink-0" fill="currentColor" viewBox="0 0 24 24">
                  <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/>
                </svg>
                <span class="text-gray-400"><?= e($label) ?>:</span>
                <a href="<?= $wa_href ?>" target="_blank" rel="noopener noreferrer" 
                   class="contact-link" aria-label="Hubungi <?= e($label) ?> via WhatsApp">
                  <?= e($number) ?>
                </a>
              </div>
            <?php } else { ?>
              <div class="flex items-center justify-center md:justify-start gap-2 text-sm">
                <svg class="w-4 h-4 text-blue-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
                </svg>
                <span class="text-gray-400"><?= e($label) ?>:</span>
                <a href="tel:<?= e(preg_replace('/\D+/', '', $number)) ?>" class="contact-link">
                  <?= e($number) ?>
                </a>
              </div>
            <?php } ?>
          <?php endforeach; ?>
        </div>

        <?php if ($company['maps']): ?>
          <a href="<?= e($company['maps']) ?>" target="_blank" rel="noopener noreferrer" 
             aria-label="Buka lokasi di Google Maps" class="map-button w-full justify-center md:w-auto">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"/>
            </svg>
            Lihat di Google Maps
          </a>
        <?php endif; ?>
      </div>

      <!-- Social Media -->
      <div class="footer-card rounded-2xl p-6 footer-animate text-center md:text-left">
        <h3 class="footer-heading">
          <svg class="inline-block w-5 h-5 mr-2 mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
          </svg>
          Ikuti Kami
        </h3>
        
        <p class="text-sm text-[color:var(--muted)] mb-6 leading-relaxed">
          Dapatkan update terbaru tentang tips perawatan kendaraan dan promo menarik dari kami
        </p>

        <div class="flex justify-center md:justify-start gap-4 flex-wrap" aria-label="Ikon media sosial">
          <?php
          $social_config = [
            'instagram' => [
              'url' => $company['social']['instagram'] ?? null,
              'label' => 'Instagram',
              'color' => 'from-purple-500 to-pink-500'
            ],
            'tiktok' => [
              'url' => $company['social']['tiktok'] ?? null,
              'label' => 'TikTok',
              'color' => 'from-gray-800 to-gray-900'
            ],
            'youtube' => [
              'url' => $company['social']['youtube'] ?? null,
              'label' => 'YouTube',
              'color' => 'from-red-600 to-red-700'
            ]
          ];
          
          foreach ($social_config as $key => $config):
            $iconFile = $company['icons_path'] . '/' . $key . '.png';
            $href = safe_url($config['url']);
          ?>
            <?php if ($href): ?>
              <a href="<?= e($href) ?>" target="_blank" rel="noopener noreferrer"
                 class="social-icon group" aria-label="<?= e($config['label']) ?>" title="<?= e($config['label']) ?>">
                <img src="<?= e($iconFile) ?>" alt="<?= e($config['label']) ?>" 
                     class="h-6 w-6" width="24" height="24" loading="lazy">
              </a>
            <?php else: ?>
              <span class="social-icon opacity-50 cursor-not-allowed" aria-hidden="true">
                <img src="<?= e($iconFile) ?>" alt="<?= e($config['label']) ?>" 
                     class="h-6 w-6 grayscale" width="24" height="24" loading="lazy">
              </span>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>

        <div class="mt-8 p-4 bg-white/5 rounded-xl border border-white/10">
          <p class="text-xs text-[color:var(--muted)] leading-relaxed">
            <svg class="inline w-4 h-4 mr-1 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            Jam operasional: Senin - Sabtu, 08:00 - 17:00 WIB
          </p>
        </div>
      </div>

      <!-- Quick Info -->
      <div class="footer-card rounded-2xl p-6 footer-animate text-center md:text-left">
        <h3 class="footer-heading">
          <svg class="inline-block w-5 h-5 mr-2 mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
          </svg>
          Informasi
        </h3>
        
        <div class="space-y-4 mb-6">
          <div class="p-4 bg-gradient-to-r from-[color:var(--brand)]/10 to-transparent rounded-xl border border-[color:var(--brand)]/20">
            <h4 class="text-sm font-semibold text-[color:var(--brand)] mb-2">Butuh Bantuan?</h4>
            <p class="text-sm text-[color:var(--muted)] leading-relaxed">
              Hubungi admin melalui nomor yang tersedia atau kunjungi halaman 
              <a href="contact.php" class="text-[color:var(--brand)] hover:underline font-medium">Kontak</a> kami.
            </p>
          </div>

          <div class="space-y-2">
            <a href="#" class="flex items-center gap-3 p-3 rounded-lg hover:bg-white/5 transition text-sm text-[color:var(--muted)] hover:text-white">
              <svg class="w-5 h-5 text-[color:var(--brand)]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
              </svg>
              <span>Syarat & Ketentuan</span>
            </a>
            <a href="#" class="flex items-center gap-3 p-3 rounded-lg hover:bg-white/5 transition text-sm text-[color:var(--muted)] hover:text-white">
              <svg class="w-5 h-5 text-[color:var(--brand)]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
              </svg>
              <span>Kebijakan Privasi</span>
            </a>
          </div>
        </div>

        <div class="p-3 bg-blue-500/10 rounded-lg border border-blue-500/20">
          <div class="flex items-start gap-2">
            <svg class="w-5 h-5 text-blue-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
            </svg>
            <p class="text-xs text-[color:var(--muted)] leading-relaxed">Pastikan Anda logout setelah selesai.
            </p>
          </div>
        </div>
      </div>
    </div>

    <div class="footer-divider"></div>
    
    <div class="flex flex-col md:flex-row items-center justify-between gap-4 text-sm text-[color:var(--muted)]">
      <div class="flex items-center gap-2">
        <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-[color:var(--brand)] to-[color:var(--brand-dark)] flex items-center justify-center font-bold text-white text-xs">
          R
        </div>
        <p>
          © <?= e((string)$year) ?> <span class="font-semibold text-white"><?= e($company['name']) ?></span>
        </p>
      </div>
      
    </div>
  </div>
</footer>