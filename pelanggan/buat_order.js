let map;
let marker;

function waitForMapContainer() {
  const el = document.getElementById("map");

  if (!el) return;

  if (el.clientWidth === 0 || el.clientHeight === 0) {
    requestAnimationFrame(waitForMapContainer);
    return;
  }

  initMap();
}

document.addEventListener("DOMContentLoaded", () => {
  waitForMapContainer();
});

function initMap() {
  if (map) return;

  map = L.map('map', {
    zoomControl: true,
    tap: false
  }).setView([-7.801194, 110.364917], 13);

  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; OpenStreetMap contributors',
    maxZoom: 19
  }).addTo(map);

  map.on('click', e => {
    const { lat, lng } = e.latlng;
    document.getElementById('lat').value = lat;
    document.getElementById('lng').value = lng;

    if (marker) {
      marker.setLatLng(e.latlng);
    } else {
      marker = L.marker(e.latlng).addTo(map);
    }
  });

  setTimeout(() => {
    map.invalidateSize(true);
    map.setView(map.getCenter());
  }, 300);
}
function safeInvalidateMap() {
  if (!map) return;

  requestAnimationFrame(() => {
    map.invalidateSize(true);
    map.setView(map.getCenter());
  });
}

window.addEventListener('load', safeInvalidateMap);
window.addEventListener('resize', safeInvalidateMap);
window.addEventListener('orientationchange', safeInvalidateMap);

setTimeout(() => {
  const tiles = document.querySelectorAll('.leaflet-tile');
  if (tiles.length === 0) {
    document.getElementById('mapNotice').classList.remove('hidden');
  }
}, 1200);

function validateLocation() {
  return latInput.value && lngInput.value;
}


function previewOrder() {
  const form = document.getElementById("orderForm");
  const requiredFields = form.querySelectorAll("[required]");
  let firstInvalid = null;

  form.querySelectorAll("small.text-red-400").forEach(el => {
    el.textContent = "";
    el.classList.add("hidden");
  });

  requiredFields.forEach(field => {
    if (!field.value.trim()) {
      if (!firstInvalid) firstInvalid = field;
      const err = field.parentElement.querySelector("small.text-red-400");
      if (err) {
        err.textContent = "Kolom ini wajib diisi.";
        err.classList.remove("hidden");
      }
    }
  });

  const tahunInput = form.querySelector("[name='tahun']");
  const tahun = parseInt(tahunInput.value);
  if (isNaN(tahun) || tahun < 1990 || tahun > 2090) {
    firstInvalid = tahunInput;
    const err = tahunInput.parentElement.querySelector("small.text-red-400");
    if (err) {
      err.textContent = "Tahun harus antara 1990–2090.";
      err.classList.remove("hidden");
    }
  }

  const lat = form.querySelector("[name='lat']").value;
  const lng = form.querySelector("[name='lng']").value;
  if (!lat || !lng) {
    alert("Silakan klik lokasi mobil di peta.");
    return;
  }

  if (firstInvalid) {
    if (firstInvalid.focus) firstInvalid.focus();
    firstInvalid.scrollIntoView({ behavior: "smooth", block: "center" });
    return;
  }

  const get = name => document.querySelector(`[name="${name}"]`)?.value || '-';
  document.getElementById("reviewPolisi").innerText = get("nomor_polisi");
  document.getElementById("reviewMerk").innerText = get("merk");
  document.getElementById("reviewModel").innerText = get("model");
  document.getElementById("reviewTahun").innerText = get("tahun");
  document.getElementById("reviewKepemilikan").innerText = get("kepemilikan");
  document.getElementById("reviewAlamat").innerText = get("alamat");
  document.getElementById("reviewLokasi").innerText = `${lat}, ${lng}`;

  document.getElementById("confirmModal").classList.remove("hidden");
  document.getElementById("confirmModal").classList.add("flex");
}


function tampilkanJam() {
  const sekarang = new Date();
  const jam = sekarang.getHours().toString().padStart(2, '0');
  const menit = sekarang.getMinutes().toString().padStart(2, '0');
  document.getElementById("jamClient").innerText = `⏱️ ${jam}:${menit} WIB`;
}
tampilkanJam();
setInterval(tampilkanJam, 60000);

function openLogoutModal() {
  const menu = document.getElementById("profileMenu");
  if (menu) menu.classList.add('hidden');
  document.querySelectorAll('#confirmModal, #termsModal, #popup').forEach(el => { el.classList.add('hidden'); el.classList.remove('flex'); });
  const modal = document.getElementById("logoutModal");
  modal.classList.remove("hidden"); modal.classList.add("flex");
}
function closeLogoutModal() {
  const modal = document.getElementById("logoutModal");
  modal.classList.add("hidden"); modal.classList.remove("flex");
}

function openTermsModal() {
  const modal = document.getElementById("termsModal");
  const termsContent = document.getElementById("termsContent");
  const btn = document.getElementById("btnContinue");
  modal.classList.remove("hidden"); modal.classList.add("flex");
  btn.disabled = true;
  btn.classList.add("opacity-50", "cursor-not-allowed");
  fetch("terms.html")
    .then(res => res.text())
    .then(html => {
      termsContent.innerHTML = html;
      termsContent.scrollTop = 0;
      termsContent.onscroll = () => {
        const atBottom = termsContent.scrollTop + termsContent.clientHeight >= termsContent.scrollHeight - 5;
        if (atBottom) {
          btn.disabled = false;
          btn.classList.remove("opacity-50", "cursor-not-allowed");
        }
      };
    })
    .catch(() => {
      termsContent.innerHTML = "<p class='text-red-400'>Gagal memuat syarat & ketentuan.</p>";
    });
}
function closeTermsModal() {
  const modal = document.getElementById("termsModal");
  modal.classList.add("hidden"); modal.classList.remove("flex");
}
function scrollToBottom() {
  const termsContent = document.getElementById("termsContent");
  termsContent.scrollTo({ top: termsContent.scrollHeight, behavior: "smooth" });
}
function continueToConfirm() {
  closeTermsModal();
  previewOrder();
}

function hideConfirmModal() {
  const modal = document.getElementById("confirmModal");
  modal.classList.add("hidden"); modal.classList.remove("flex");
}

document.addEventListener("click", function (e) {
  const avatar = document.getElementById("avatarBtn");
  const menu = document.getElementById("profileMenu");
  if (menu && !menu.contains(e.target) && e.target !== avatar) {
    menu.classList.add("hidden");
  }
});
document.getElementById("avatarBtn").addEventListener("click", function (e) {
  e.stopPropagation();
  const menu = document.getElementById("profileMenu");
  menu.classList.toggle("hidden");
});

document.querySelector("[name='nomor_polisi']").addEventListener("input", function (e) {
  let value = e.target.value.toUpperCase().replace(/[^A-Z0-9]/g, ""); // hanya huruf & angka
  let result = "";

  // ambil huruf depan (1–2 huruf)
  let prefix = value.match(/^[A-Z]{1,2}/);
  if (prefix) {
    result += prefix[0];
    value = value.slice(prefix[0].length);
  }

  // ambil angka (1–4 digit)
  let numbers = value.match(/^\d{1,4}/);
  if (numbers) {
    result += " " + numbers[0];
    value = value.slice(numbers[0].length);
  }

  // sisanya dianggap huruf belakang
  if (value.length > 0) {
    result += " " + value;
  }

  e.target.value = result.trim();
});