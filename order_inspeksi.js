
function togglePelanggan(val) {
    document.getElementById('form_terdaftar').classList.toggle('hidden', val !== 'terdaftar');
    document.getElementById('form_baru').classList.toggle('hidden', val !== 'baru');
}

window.addEventListener('DOMContentLoaded', function () {
    const jenis = document.querySelector("select[name='jenis_pelanggan']").value;
    togglePelanggan(jenis);
});

const map = L.map('map').setView([-7.801194, 110.364917], 13);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(map);

let marker;
map.on('click', function(e) {
    const { lat, lng } = e.latlng;
    document.getElementById('lat').value = lat;
    document.getElementById('lng').value = lng;

    if (marker) map.removeLayer(marker);
    marker = L.marker([lat, lng]).addTo(map);
});

function tampilkanReview() {
    const jenis = document.querySelector("select[name='jenis_pelanggan']").value;
    const jenisText = jenis === 'baru' ? 'Pelanggan Baru' : 'Pelanggan Terdaftar';

    const nama = jenis === 'baru'
        ? document.querySelector("input[name='nama_baru']").value
        : document.querySelector("select[name='id_pelanggan']").selectedOptions[0].text;

    const nohp = jenis === 'baru'
        ? document.querySelector("input[name='nohp_baru']").value
        : '-';

    const merk = document.querySelector("input[name='merk']").value;
    const model = document.querySelector("input[name='model']").value;
    const kepemilikan = document.querySelector("select[name='kepemilikan']").value;
    const tahun = document.querySelector("input[name='tahun']").value;
    const nopol = document.querySelector("input[name='nopol']").value;
    const alamat = document.querySelector("textarea[name='alamat']").value;

    document.getElementById("reviewJenis").innerText = jenisText;
    document.getElementById("reviewNama").innerText = nama;
    document.getElementById("reviewNoHP").innerText = nohp;
    document.getElementById("reviewMerk").innerText = merk;
    document.getElementById("reviewModel").innerText = model;
    document.getElementById("reviewKepemilikan").innerText = kepemilikan;
    document.getElementById("reviewTahun").innerText = tahun;
    document.getElementById("reviewNopol").innerText = nopol;
    document.getElementById("reviewAlamat").innerText = alamat;

    document.getElementById("modalReview").classList.remove("hidden");
}

function validasiFormSebelumReview() {
    let valid = true;
    let errors = [];

    const jenis = document.querySelector("select[name='jenis_pelanggan']").value;
    if (jenis === 'baru') {
        const nama = document.querySelector("input[name='nama_baru']").value.trim();
        const nohp = document.querySelector("input[name='nohp_baru']").value.trim();
        const pass = document.querySelector("input[name='password_baru']").value.trim();

        if (!nama) errors.push("Nama pelanggan baru wajib diisi.");
        if (!/^[0-9]{10,15}$/.test(nohp)) errors.push("Nomor HP pelanggan baru tidak valid (10–15 digit).");
        if (!pass) errors.push("Password sementara wajib diisi.");
    }

    const merk = document.querySelector("input[name='merk']").value.trim();
    const model = document.querySelector("input[name='model']").value.trim();
    const tahun = parseInt(document.querySelector("input[name='tahun']").value);
    const nopol = document.querySelector("input[name='nopol']").value.trim();
    const alamat = document.querySelector("textarea[name='alamat']").value.trim();
    const lat = document.getElementById('lat').value;
    const lng = document.getElementById('lng').value;

    if (!merk) errors.push("Merk mobil wajib diisi.");
    if (!model) errors.push("Model mobil wajib diisi.");
    if (!tahun || tahun < 1900 || tahun > 2099) errors.push("Tahun tidak valid.");

    const plat = nopol.replace(/\s+/g, '').toUpperCase();  // hilangkan spasi untuk validasi
    const platMatch = plat.match(/^([A-Z]{1,2})([0-9]{1,4})/);
    const kodeWilayah = [
        'A', 'B','D', 'E', 'F', 'T', 'Z','G', 'H', 'K', 'R', 'AA', 'AB',
        'L', 'M', 'N', 'P', 'S', 'W', 'AE', 'AG','DK', 'DR', 'EA', 'DH', 
        'EB', 'ED','DA', 'KB', 'KH', 'KT', 'KU','DB', 'DL', 'DM', 'DN', 'DT', 'DW', 'DD',
        'DE', 'DG', 'PA', 'PB', 'BA', 'BB', 'BD', 'BE', 'BG', 'BH', 'BK', 'BL', 'BM', 'BN', 'BP'
    ];
    if (!platMatch) {
        errors.push("Format nomor polisi tidak valid.");
    } else {
        const kode = platMatch[1];
        const angka = parseInt(platMatch[2]);
        if (!kodeWilayah.includes(kode)) {
            errors.push("Kode wilayah plat nomor tidak valid.");
        } else if (angka < 1 || angka > 9999) {
            errors.push("Angka plat harus 1–9999.");
        }
    }

    if (!alamat) errors.push("Alamat wajib diisi.");
    if (!lat || !lng) errors.push("Tandai lokasi mobil pada peta.");

    if (errors.length > 0) {
        const list = document.getElementById("errorList");
        list.innerHTML = "";
        errors.forEach(err => {
            const li = document.createElement("li");
            li.textContent = err;
            list.appendChild(li);
        });
        document.getElementById("modalError").classList.remove("hidden");
        valid = false;
    }

    return valid;
}

document.getElementById('nopol').addEventListener('input', function(e) {
    let value = e.target.value.toUpperCase().replace(/[^A-Z0-9]/g, '');

    const match = value.match(/^([A-Z]{1,2})([0-9]{1,4})([A-Z]{0,3})$/);
    if (match) {
        let kode = match[1];
        let angka = match[2];
        let huruf = match[3];
        let formatted = kode + ' ' + angka;
        if (huruf) formatted += ' ' + huruf;
        e.target.value = formatted;
    }
});

