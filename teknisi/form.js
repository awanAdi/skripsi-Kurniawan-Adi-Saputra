(function () {
  "use strict";

  let currentStep = 0;
  let estimasiIndex = 1;
  let isSubmitting = false;

  document.addEventListener("DOMContentLoaded", function () {
    const estimasiRows = document.querySelectorAll("#estimasi-body tr");
    estimasiIndex = estimasiRows ? estimasiRows.length : 1;
	const kesInit = document.getElementById('kesimpulan');
    if (kesInit) toggleKesimpulan(kesInit);

    hitungTotalEstimasi();
    showStep(currentStep);

    const form = document.getElementById('formInspeksi');
    if (form) {
      form.addEventListener('submit', function (e) {
        if (isSubmitting) {
          e.preventDefault();
          return false;
        }

        if (!validateFinalStep()) {
          e.preventDefault();
          return false;
        }

        showLoading();
        isSubmitting = true;

        const kes = document.getElementById('kesimpulan');
        const custom = document.getElementById('customKesimpulan');
        if (kes && custom && kes.value === 'lainnya' && custom.value.trim() !== '') {
          kes.value = custom.value.trim();
          custom.disabled = true;
        }
      });
    }
  });


  function showLoading() {
    const overlay = document.getElementById('loadingOverlay');
    if (overlay) {
      overlay.classList.remove('hidden');
      overlay.classList.add('flex');
      
      document.body.style.overflow = 'hidden';
      
      const buttons = document.querySelectorAll('button');
      buttons.forEach(btn => {
        btn.disabled = true;
        btn.style.cursor = 'not-allowed';
        btn.style.opacity = '0.6';
      });
    }
  }

  function hideLoading() {
    const overlay = document.getElementById('loadingOverlay');
    if (overlay) {
      overlay.classList.add('hidden');
      overlay.classList.remove('flex');

      document.body.style.overflow = 'auto';
      
      const buttons = document.querySelectorAll('button');
      buttons.forEach(btn => {
        btn.disabled = false;
        btn.style.cursor = 'pointer';
        btn.style.opacity = '1';
      });
      
      isSubmitting = false;
    }
  }

  /**
   * Validate final step before submission
   */
  function validateFinalStep() {
    const kesimpulan = document.getElementById('kesimpulan');
    const custom = document.getElementById('customKesimpulan');

    if (!kesimpulan || !kesimpulan.value) {
      alert('Mohon pilih kesimpulan inspeksi.');
      return false;
    }

    if (kesimpulan.value === 'lainnya') {
      if (!custom || !custom.value.trim()) {
        alert('Mohon isi kesimpulan custom.');
        return false;
      }
    }

    return true;
  }

  function showStep(n) {
    const steps = document.getElementsByClassName("step");
    if (!steps || steps.length === 0) return;
    n = Math.max(0, Math.min(n, steps.length - 1));
    
    for (let i = 0; i < steps.length; i++) {
      steps[i].classList.add("hidden");
      steps[i].classList.remove("fade-in");
    }
    
    steps[n].classList.remove("hidden");
    steps[n].classList.add("fade-in");

    const prevBtn = document.getElementById("prevBtn");
    const nextBtn = document.getElementById("nextBtn");
    
    if (prevBtn) {
      prevBtn.style.display = n === 0 ? "none" : "inline-block";
      prevBtn.classList.remove("hidden");
    }
    
    if (nextBtn) {
      nextBtn.innerText = n === steps.length - 1 ? "💾 Simpan & Selesai" : "Lanjut →";
      
      if (n === steps.length - 1) {
        nextBtn.classList.remove("bg-blue-600", "hover:bg-blue-700");
        nextBtn.classList.add("bg-green-600", "hover:bg-green-700");
      } else {
        nextBtn.classList.remove("bg-green-600", "hover:bg-green-700");
        nextBtn.classList.add("bg-blue-600", "hover:bg-blue-700");
      }
    }

    window.scrollTo({
      top: 0,
      behavior: 'smooth'
    });

    const stepData = steps[n].dataset && steps[n].dataset.step;
    if (typeof stepData !== 'undefined' && parseInt(stepData, 10) === steps.length - 1) {
      updateReview();
    }
  }

  function nextPrev(n) {
    const steps = document.getElementsByClassName("step");
    if (!steps || steps.length === 0) return;

    if (n === 1 && currentStep < steps.length - 1) {
      if (!validateStep(currentStep)) return;
    }
    if (n === 1 && currentStep === steps.length - 1) {
      const form = document.getElementById("formInspeksi");
      if (form) {
        form.requestSubmit();
      }
      return;
    }

    if (n === 1 && currentStep === 0 && !validateFoto()) return;

    currentStep += n;
    showStep(currentStep);
  }

  window.nextPrev = nextPrev;

  function validateStep(stepIndex) {
    const steps = document.getElementsByClassName("step");
    if (!steps || !steps[stepIndex]) return true;
    
    const current = steps[stepIndex];
    const inputs = current.querySelectorAll("input, select, textarea");
    let valid = true;
    let firstInvalid = null;

    inputs.forEach(el => {
      if (el.type === "number" && el.hasAttribute("required")) {
        let val = parseFloat(el.value);
        if (isNaN(val)) {
          el.classList.add("border-red-500", "ring-2", "ring-red-300");
          valid = false;
          if (!firstInvalid) firstInvalid = el;
        } else {
          if (el.name && el.name.startsWith && el.name.startsWith("nilai_kategori") && (val < 1 || val > 100)) {
            el.classList.add("border-red-500", "ring-2", "ring-red-300");
            valid = false;
            if (!firstInvalid) firstInvalid = el;
          } else {
            el.classList.remove("border-red-500", "ring-2", "ring-red-300");
          }
        }
      } else if (el.tagName === "SELECT" && el.hasAttribute("required")) {
        if (!el.value) {
          el.classList.add("border-red-500", "ring-2", "ring-red-300");
          valid = false;
          if (!firstInvalid) firstInvalid = el;
        } else {
          el.classList.remove("border-red-500", "ring-2", "ring-red-300");
        }
      } else if (el.type === "file" && el.hasAttribute("required")) {
        if (!el.files || !el.files.length) {
          el.classList.add("border-red-500", "ring-2", "ring-red-300");
          valid = false;
          if (!firstInvalid) firstInvalid = el;
        } else {
          el.classList.remove("border-red-500", "ring-2", "ring-red-300");
        }
      }
    });

    if (!valid) {
      alert("❌ Mohon isi semua input wajib dengan benar sebelum lanjut.");
      
      if (firstInvalid) {
        firstInvalid.focus();
        firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
    }
    
    return valid;
  }

  function tambahBarisEstimasi() {
    const tbody = document.getElementById("estimasi-body");
    if (!tbody) return;
    
    const row = document.createElement("tr");
    row.className = "fade-in";
    row.innerHTML = `
      <td class="border border-gray-300 px-2 py-2 text-center">${estimasiIndex + 1}</td>
      <td class="border border-gray-300 px-2 py-1">
        <input type="text" name="servis[${estimasiIndex}][hal]" 
               class="w-full border-none focus:ring-0 input-review" 
               data-review-label="Servis ${estimasiIndex + 1}"
               placeholder="Nama servis">
      </td>
      <td class="border border-gray-300 px-2 py-1">
        <input type="number" name="servis[${estimasiIndex}][biaya]" 
               class="w-full border-none focus:ring-0 biaya-input input-review" 
               min="0" 
               data-review-label="Biaya Servis ${estimasiIndex + 1}"
               placeholder="0">
      </td>
      <td class="border border-gray-300 px-2 py-2 text-center">
        <button type="button" onclick="hapusBarisEstimasi(this)" 
                class="bg-red-500 hover:bg-red-600 text-white px-2 py-1 rounded text-xs sm:text-sm transition">
          Hapus
        </button>
      </td>
    `;
    
    tbody.appendChild(row);
    estimasiIndex++;
    hitungTotalEstimasi();
    
    const newInput = row.querySelector('input[type="text"]');
    if (newInput) newInput.focus();
  }

  function hapusBarisEstimasi(btn) {
    const tbody = document.getElementById("estimasi-body");
    if (!tbody) return;
    
    const rows = tbody.querySelectorAll("tr");
    if (rows.length <= 1) {
      alert("⚠️ Minimal harus ada 1 baris estimasi perbaikan.");
      return;
    }
    
    const row = btn.closest("tr");
    row.style.opacity = '0';
    row.style.transform = 'translateX(-20px)';
    
    setTimeout(() => {
      row.remove();
      hitungTotalEstimasi();
    }, 200);
  }

  window.tambahBarisEstimasi = tambahBarisEstimasi;
  window.hapusBarisEstimasi = hapusBarisEstimasi;

  document.addEventListener("input", function (e) {
    if (e.target && e.target.classList && e.target.classList.contains("biaya-input")) {
      hitungTotalEstimasi();
    }
  });

  function hitungTotalEstimasi() {
    let total = 0;
    document.querySelectorAll(".biaya-input").forEach(input => {
      total += parseFloat(input.value) || 0;
    });
    
    const out = document.getElementById("total-estimasi");
    if (out) {
      out.innerText = "Rp " + total.toLocaleString("id-ID");
      
      out.classList.add("text-indigo-700", "font-bold");
    }
  }

  function validateFoto() {
    const fileInput = document.querySelector('input[name="foto_mobil"]');
    if (!fileInput) return true;
    
    if (!fileInput.files.length) {
      alert("📷 Mohon upload foto mobil.");
      fileInput.classList.add("border-red-500", "ring-2", "ring-red-300");
      return false;
    }

    const file = fileInput.files[0];
    const maxSize = parseInt(fileInput.dataset.maxSize || "52428800", 10); // 50MB
    const allowedTypes = ['image/jpeg', 'image/png', 'image/jpg'];

    if (!allowedTypes.includes(file.type.toLowerCase())) {
      alert("❌ Format file harus JPG atau PNG.");
      fileInput.classList.add("border-red-500", "ring-2", "ring-red-300");
      return false;
    }
    
    if (file.size > maxSize) {
      alert("❌ Ukuran file maksimal 50MB.");
      fileInput.classList.add("border-red-500", "ring-2", "ring-red-300");
      return false;
    }
    
    fileInput.classList.remove("border-red-500", "ring-2", "ring-red-300");
    return true;
  }

  function tambahBarisObd() {
    const tbody = document.getElementById("obd-body");
    if (!tbody) return;
    
    const rowCount = tbody.rows.length;
    const row = tbody.insertRow();
    row.className = "fade-in";
    row.innerHTML = `
      <td class="border border-gray-300 px-2 py-2 text-center">${rowCount + 1}</td>
      <td class="border border-gray-300 px-2 py-1">
        <input type="text" name="scan_obd[${rowCount}][kode]" 
               class="w-full border-none focus:ring-0 input-review" 
               data-review-label="Kode Trouble ${rowCount + 1}"
               placeholder="P0XXX">
      </td>
      <td class="border border-gray-300 px-2 py-1">
        <input type="text" name="scan_obd[${rowCount}][error]" 
               class="w-full border-none focus:ring-0 input-review" 
               data-review-label="Indikasi Error ${rowCount + 1}"
               placeholder="Deskripsi error">
      </td>
      <td class="border border-gray-300 px-2 py-1">
        <input type="text" name="scan_obd[${rowCount}][catatan]" 
               class="w-full border-none focus:ring-0 input-review" 
               data-review-label="Catatan OBD ${rowCount + 1}"
               placeholder="Catatan tambahan">
      </td>
      <td class="border border-gray-300 px-2 py-2 text-center">
        <button type="button" onclick="hapusBarisObd(this)" 
                class="bg-red-500 hover:bg-red-600 text-white px-2 py-1 rounded text-xs sm:text-sm transition">
          Hapus
        </button>
      </td>
    `;
    
    const newInput = row.querySelector('input[type="text"]');
    if (newInput) newInput.focus();
  }

  function hapusBarisObd(btn) {
    const row = btn.closest("tr");
    if (!row) return;
    
    const tbody = document.getElementById("obd-body");
    if (!tbody) return;
    
    row.style.opacity = '0';
    row.style.transform = 'translateX(-20px)';
    
    setTimeout(() => {
      row.parentNode.removeChild(row);
      
      Array.from(tbody.querySelectorAll("tr")).forEach((r, i) => {
        const firstTd = r.querySelector("td");
        if (firstTd) firstTd.textContent = i + 1;
        
        const kode = r.querySelector('input[name^="scan_obd"][name*="[kode]"]');
        const error = r.querySelector('input[name^="scan_obd"][name*="[error]"]');
        const cat = r.querySelector('input[name^="scan_obd"][name*="[catatan]"]');
        
        if (kode) {
          kode.name = `scan_obd[${i}][kode]`;
          kode.dataset.reviewLabel = `Kode Trouble ${i + 1}`;
        }
        if (error) {
          error.name = `scan_obd[${i}][error]`;
          error.dataset.reviewLabel = `Indikasi Error ${i + 1}`;
        }
        if (cat) {
          cat.name = `scan_obd[${i}][catatan]`;
          cat.dataset.reviewLabel = `Catatan OBD ${i + 1}`;
        }
      });
    }, 200);
  }

  window.tambahBarisObd = tambahBarisObd;
  window.hapusBarisObd = hapusBarisObd;

  function updateReview() {
    let html = "<div class='space-y-3'>";
    
    const categories = {};
    
    document.querySelectorAll(".input-review").forEach(el => {
      const label = el.dataset && el.dataset.reviewLabel ? el.dataset.reviewLabel : (el.name || "");
      let val = "";

      if (el.type === "file" && el.files && el.files.length) {
        const file = el.files[0];
        try {
          val = `<img src="${URL.createObjectURL(file)}" alt="${label}" class="h-32 mt-2 rounded-lg object-cover border-2 border-gray-300 shadow-sm">`;
        } catch (e) {
          val = `<span class="text-green-600">✓ File terpilih (${file.name})</span>`;
        }
      } else if (el.tagName === "SELECT") {
        if (el.id === "kesimpulan" && el.value === "lainnya") {
          const custom = document.getElementById("customKesimpulan");
          val = custom && custom.value ? custom.value.trim() : "(belum diisi)";
        } else {
          val = el.value ? el.options[el.selectedIndex].text : "";
        }
      } else {
        val = (el.value || "").toString().trim();
      }

      if (val) {
        let category = "Lainnya";
        if (label.includes("Foto")) category = "📷 Foto";
        else if (label.includes("OBD") || label.includes("Trouble")) category = "🔧 Scan OBD";
        else if (label.includes("Servis") || label.includes("Biaya")) category = "💰 Estimasi";
        else if (label.includes("Kesimpulan") || label.includes("Catatan Kesimpulan")) category = "📝 Kesimpulan";
        else if (label.includes("Nilai Kategori")) category = "⭐ Penilaian";
        else category = "📋 Detail Inspeksi";
        
        if (!categories[category]) categories[category] = [];
        categories[category].push({ label, val });
      }
    });

    // Render by category
    for (const [catName, items] of Object.entries(categories)) {
      html += `<div class="border-l-4 border-indigo-500 pl-4 py-2">`;
      html += `<h4 class="font-bold text-gray-800 mb-2">${catName}</h4>`;
      html += `<ul class="space-y-2">`;
      
      items.forEach(item => {
        html += `<li class="text-sm"><strong class="text-gray-700">${escapeHtml(item.label)}:</strong> <span class="text-gray-900">${item.val}</span></li>`;
      });
      
      html += `</ul></div>`;
    }

    const totalText = document.getElementById("total-estimasi") ? document.getElementById("total-estimasi").innerText : "";
    if (totalText && totalText !== "Rp 0") {
      html += `<div class="bg-indigo-50 border-2 border-indigo-200 rounded-lg p-4 mt-4">`;
      html += `<p class="text-lg font-bold text-indigo-900">💰 Total Estimasi Perbaikan: <span class="text-2xl">${escapeHtml(totalText)}</span></p>`;
      html += `</div>`;
    }

    html += "</div>";
    
    const out = document.getElementById("tinjau-hasil");
    if (out) out.innerHTML = html;
  }

  function escapeHtml(text) {
    return String(text).replace(/[&<>"']/g, function (m) {
      return ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;'
      }[m]);
    });
  }

  function toggleKesimpulan(el) {
    const input = document.getElementById("customKesimpulan");
    if (!input) return;

      input.disabled = false;

    if (el && el.value === "lainnya") {
      input.classList.remove("hidden");
      input.required = true;
      input.focus();
    } else {
      input.classList.add("hidden");
      input.required = false;
      input.value = "";
    }
  }
  window.toggleKesimpulan = toggleKesimpulan;

  function confirmBack() {
    const modal = document.getElementById('confirmModal');
    if (modal) {
      modal.classList.remove('hidden');
      document.body.style.overflow = 'hidden';
    }
  }

  function closeModal() {
    const modal = document.getElementById('confirmModal');
    if (modal) {
      modal.classList.add('hidden');
      document.body.style.overflow = 'auto';
    }
  }

  function goBack() {
    window.location.href = "cek_task.php";
  }

  window.confirmBack = confirmBack;
  window.closeModal = closeModal;
  window.goBack = goBack;
  window.hitungTotalEstimasi = hitungTotalEstimasi;

  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
      closeModal();
    }
  });

})();