(function () {
    'use strict';
    function titleCase(str) {
        if (!str) return '';
        str = String(str).replace(/\s+/g, ' ').trim();
        return str.split(' ').map(function (word) {
            if (!word) return '';
            const chars = Array.from(word);
            const first = chars.shift();
            try {
                return first.toLocaleUpperCase('id') + (chars.join('').toLocaleLowerCase('id') || '');
            } catch (e) {
                return first.toUpperCase() + (chars.join('').toLowerCase() || '');
            }
        }).join(' ');
    }

    const $ = (sel, ctx = document) => ctx.querySelector(sel);
    const $$ = (sel, ctx = document) => Array.from(ctx.querySelectorAll(sel));

    function toggleOpsi(selectEl) {
        if (!selectEl) return;
        const row = selectEl.closest('.form-row');
        if (!row) {
            if (selectEl.id === 'edit_tipe') editToggleOpsi(selectEl);
            return;
        }
        const opsi = row.querySelector('.opsi-pilihan');
        const nilai = row.querySelector('.nilai-batas');

        if (opsi) opsi.classList.add('hidden');
        if (nilai) nilai.classList.add('hidden');

        if (selectEl.value === 'pilihan' && opsi) opsi.classList.remove('hidden');
        else if (selectEl.value === 'angka' && nilai) nilai.classList.remove('hidden');
    }
    function editToggleOpsi(selectEl) {
        const opsiEl = document.getElementById('edit_opsi');
        const nilaiEl = document.getElementById('edit_nilai_batas');
        if (opsiEl) opsiEl.classList.add('hidden');
        if (nilaiEl) nilaiEl.classList.add('hidden');
        if (!selectEl) return;
        if (selectEl.value === 'pilihan' && opsiEl) opsiEl.classList.remove('hidden');
        else if (selectEl.value === 'angka' && nilaiEl) nilaiEl.classList.remove('hidden');
    }

    function hapusBaris(button) {
        try {
            const container = document.getElementById('form-container');
            if (!container) return;
            const rows = container.querySelectorAll('.form-row');
            const row = button.closest('.form-row');
            if (!row) return;

            if (rows.length > 1) {
                row.remove();
            } else {
                row.querySelectorAll('input, textarea, select').forEach(el => {
                    if (el.tagName === 'SELECT') el.selectedIndex = 0;
                    else el.value = '';
                });
                const tipe = row.querySelector('.tipe-input');
                if (tipe) toggleOpsi(tipe);
            }
        } catch (err) {
            console.error('hapusBaris error:', err);
        }
    }

    function tambahBaris(keepValues = true) {
        try {
            const container = document.getElementById('form-container');
            if (!container) return;
            const rows = Array.from(container.querySelectorAll('.form-row'));
            if (rows.length === 0) return;

            let sourceRow = null;
            const active = document.activeElement;
            if (active) {
                const activeRow = active.closest && active.closest('.form-row');
                if (activeRow) sourceRow = activeRow;
            }
            if (!sourceRow) {
                for (let i = rows.length - 1; i >= 0; i--) {
                    const r = rows[i];
                    const inputs = Array.from(r.querySelectorAll('input, textarea, select'));
                    const hasValue = inputs.some(el => {
                        if (el.tagName === 'SELECT') return el.selectedIndex > 0 || (el.value && el.value !== '');
                        if (el.tagName === 'TEXTAREA') return el.value.trim() !== '';
                        if (el.type === 'checkbox' || el.type === 'radio') return el.checked;
                        return el.value !== null && String(el.value).trim() !== '';
                    });
                    if (hasValue) { sourceRow = r; break; }
                }
            }
            if (!sourceRow) sourceRow = rows[rows.length - 1];
            const newRow = sourceRow.cloneNode(true);
            const sources = Array.from(sourceRow.querySelectorAll('input, textarea, select'));
            const targets = Array.from(newRow.querySelectorAll('input, textarea, select'));

            if (sources.length === targets.length) {
                for (let i = 0; i < sources.length; i++) {
                    const src = sources[i];
                    const tgt = targets[i];

                    if (tgt.id) tgt.removeAttribute('id');

                    if (!keepValues) {
                        if (tgt.tagName === 'SELECT') tgt.selectedIndex = 0;
                        else if (tgt.type === 'checkbox' || tgt.type === 'radio') { tgt.checked = false; tgt.value = ''; }
                        else tgt.value = '';
                        continue;
                    }

                    if (src.tagName === 'SELECT') {
                        tgt.value = src.value;
                        if (tgt.value !== src.value) tgt.selectedIndex = src.selectedIndex;
                    } else if (src.tagName === 'TEXTAREA') {
                        tgt.value = src.value;
                    } else if (src.type === 'checkbox' || src.type === 'radio') {
                        tgt.checked = src.checked;
                        tgt.value = src.value;
                    } else {
                        tgt.value = src.value;
                    }
                }
            } else {
                newRow.querySelectorAll('input, textarea, select').forEach(el => {
                    if (el.tagName === 'SELECT') el.selectedIndex = 0;
                    else if (el.type === 'checkbox' || el.type === 'radio') { el.checked = false; el.value = ''; }
                    else el.value = '';
                    if (el.id) el.removeAttribute('id');
                });
            }

            let delBtns = Array.from(newRow.querySelectorAll('button[title="Hapus Baris"], .hapus-btn'));
            if (delBtns.length === 0) {
                const candidate = Array.from(newRow.querySelectorAll('button')).find(b => {
                    const t = b.textContent.trim().toLowerCase();
                    return t === '×' || t === 'x';
                });
                if (candidate) delBtns = [candidate];
            }
            delBtns.forEach(b => {
                try { b.removeAttribute('onclick'); } catch (e) { }
                b.onclick = function () { hapusBaris(this); };
            });

            const tipeBaru = newRow.querySelector('.tipe-input');
            if (tipeBaru) {
                try { tipeBaru.removeAttribute('onchange'); } catch (e) { }
                tipeBaru.addEventListener('change', function () { toggleOpsi(this); });
            }

            container.appendChild(newRow);
            if (tipeBaru) toggleOpsi(tipeBaru);

            const komBaru = newRow.querySelector('input[name="komponen[]"]');
            if (komBaru) {
                try { komBaru.removeAttribute('onblur'); } catch (e) { }
                komBaru.addEventListener('blur', function () {
                    this.value = titleCase(this.value);
                });
                komBaru.focus();
            }

        } catch (err) {
            console.error('tambahBaris error:', err);
        }
    }

    function openEditModal(data) {
        try {
            if (!data) return;
            if (typeof data === 'string') {
                try { data = JSON.parse(data); } catch (e) { /* ignore */ }
            }
            const set = (id, val) => {
                const el = document.getElementById(id);
                if (!el) return;
                el.value = (val === null || typeof val === 'undefined') ? '' : val;
            };

            set('edit_id', data.id_standar || data.id || '');
            set('edit_kriteria', data.id_kriteria || '');
            set('edit_komponen', data.komponen || '');
            const editKomEl = document.getElementById('edit_komponen');
            if (editKomEl) editKomEl.dataset.old = data.komponen ?? '';

            set('edit_tipe', data.tipe_input || 'angka');
            set('edit_nilai_batas', data.nilai_batas || '');
            set('edit_opsi', data.opsi_pilihan || '');
            set('edit_keterangan', data.keterangan || '');
            const modal = document.getElementById('editModal');
            if (modal) modal.classList.remove('hidden');
            const editTipe = document.getElementById('edit_tipe');
            if (editTipe) editToggleOpsi(editTipe);
        } catch (err) {
            console.error('openEditModal error:', err);
        }
    }

    function closeEditModal() {
        const modal = document.getElementById('editModal');
        if (modal) modal.classList.add('hidden');
    }

    function showDeleteModal(id) {
        try {
            const input = document.getElementById('delete_id');
            if (input) input.value = id;
            const modal = document.getElementById('deleteModal');
            if (modal) modal.classList.remove('hidden');
        } catch (err) {
            console.error('showDeleteModal error:', err);
        }
    }

    function closeDeleteModal() {
        const modal = document.getElementById('deleteModal');
        if (modal) modal.classList.add('hidden');
    }

    function init() {
        $$('.tipe-input').forEach(s => {
            try { s.removeAttribute('onchange'); } catch (e) { }
            s.addEventListener('change', function () { toggleOpsi(this); });
            toggleOpsi(s);
        });

        $$('.form-row').forEach(row => {
            row.querySelectorAll('button[title="Hapus Baris"], .hapus-btn').forEach(b => {
                try { b.removeAttribute('onclick'); } catch (e) { }
                b.onclick = function () { hapusBaris(this); };
            });
        });

        const tambahBtns = Array.from(document.querySelectorAll('button[onclick*="tambahBaris"], .btn-tambah-baris'));
        tambahBtns.forEach(btn => {
            if (btn.dataset.tambahListenerAttached) return;
            try { btn.removeAttribute('onclick'); } catch (e) { }
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                tambahBaris(true);
            });
            btn.dataset.tambahListenerAttached = '1';
        });

        $$('input[name="komponen[]"]').forEach(inp => {
            try { inp.removeAttribute('onblur'); } catch (e) { }
            inp.addEventListener('blur', function () {
                this.value = titleCase(this.value);
            });
        });

        const editKom = document.getElementById('edit_komponen');
        if (editKom) {
            try { editKom.removeAttribute('onblur'); } catch (e) { }
            editKom.addEventListener('blur', function () {
                this.value = titleCase(this.value);
            });
        }

        const editTipe = document.getElementById('edit_tipe');
        if (editTipe) {
            try { editTipe.removeAttribute('onchange'); } catch (e) { }
            editTipe.addEventListener('change', function () { editToggleOpsi(this); });
            editToggleOpsi(editTipe);
        }

        $$('.close-modal').forEach(b => b.addEventListener('click', () => {
            const modal = b.closest('.modal');
            if (modal) modal.classList.add('hidden');
        }));

        document.addEventListener('click', function (e) {
            const editBtn = e.target.closest('.edit-btn');
            if (editBtn) {
                e.preventDefault();
                const payload = editBtn.getAttribute('data-payload');
                try {
                    const data = JSON.parse(payload);
                    openEditModal(data);
                } catch (err) {
                    console.error('Gagal parse edit payload', err, payload);
                    alert('Gagal membuka modal edit — periksa console.');
                }
                return;
            }

            const delBtn = e.target.closest('.delete-btn');
            if (delBtn) {
                e.preventDefault();
                const ids = delBtn.getAttribute('data-delete');
                showDeleteModal(ids);
                return;
            }
        });
    }

    window.tambahBaris = tambahBaris;
    window.hapusBaris = hapusBaris;
    window.toggleOpsi = toggleOpsi;
    window.editToggleOpsi = editToggleOpsi;
    window.openEditModal = openEditModal;
    window.closeEditModal = closeEditModal;
    window.showDeleteModal = showDeleteModal;
    window.closeDeleteModal = closeDeleteModal;
    document.addEventListener('DOMContentLoaded', init);

    (function () {
        const form = document.getElementById('singleEditForm');
        if (!form) return;

        form.addEventListener('submit', async function (e) {
            e.preventDefault();

            if (e.shiftKey) {
                form.submit();
                return;
            }

            const fd = new FormData(form);
            fd.append('ajax_edit', '1');

            try {
                const res = await fetch('standar_inspeksi.php', { method: 'POST', body: fd });
                const data = await res.json();

                console.debug('AJAX edit response', data);

                if (data.status === 'ok') {
                    const row = data.row || {};
                    const id = row.id_standar ?? row.id ?? fd.get('edit_id');

                    let tr = document.querySelector('tr[data-row-id="' + id + '"]');
                    if (!tr) {
                        tr = Array.from(document.querySelectorAll('tr')).find(trEl => {
                            const hid = trEl.querySelector('input[name="id_standar[]"]');
                            return hid && String(hid.value) === String(id);
                        }) || null;
                    }

                    if (tr) {
                        try {
                            const tds = Array.from(tr.querySelectorAll('td'));
                            if (tds.length >= 2) {
                                if (typeof row.nama_kategori !== 'undefined') {
                                    tds[0].textContent = row.nama_kategori ?? tds[0].textContent;
                                }
                                const kode = row.kode_kriteria ? String(row.kode_kriteria).trim() : '';
                                const sub = row.sub_kriteria ? String(row.sub_kriteria).trim() : '';
                                if (kode || sub) {
                                    tds[1].textContent = (kode ? (kode + ' ') : '') + (sub || '');
                                }
                            }
                        } catch (err) {
                            console.warn('Gagal update kolom kategori/sub_kriteria', err);
                        }

                        const komInput = tr.querySelector('input[name="komponen[]"], textarea[name="komponen[]"]');
                        if (komInput) {
                            komInput.value = row.komponen ?? '';
                            komInput.setAttribute('data-original', row.komponen ?? '');
                        } else {
                            const komTd = tr.querySelector('td[data-col="komponen"]') || tr.querySelector('td:nth-child(3)');
                            if (komTd) komTd.textContent = row.komponen ?? komTd.textContent;
                        }

                        const tipeSelect = tr.querySelector('select[name="tipe_input[]"]');
                        if (tipeSelect) {
                            tipeSelect.value = row.tipe_input ?? tipeSelect.value;
                        } else {
                            const tipeTd = tr.querySelector('td[data-col="tipe_input"]') || tr.querySelector('td:nth-child(4)');
                            if (tipeTd) tipeTd.textContent = row.tipe_input ?? tipeTd.textContent;
                        }

                        const nilaiInput = tr.querySelector('input[name="nilai_batas[]"], textarea[name="nilai_batas[]"]');
                        if (nilaiInput) {
                            nilaiInput.value = row.nilai_batas ?? '';
                            nilaiInput.setAttribute('data-original', row.nilai_batas ?? '');
                        } else {
                            const nilaiTd = tr.querySelector('td[data-col="nilai_batas"]') || tr.querySelector('td:nth-child(5)');
                            if (nilaiTd) nilaiTd.textContent = row.nilai_batas ?? nilaiTd.textContent;
                        }

                        const opsiInput = tr.querySelector('input[name="opsi_pilihan[]"], textarea[name="opsi_pilihan[]"]');
                        if (opsiInput) {
                            opsiInput.value = row.opsi_pilihan ?? '';
                            opsiInput.setAttribute('data-original', row.opsi_pilihan ?? '');
                        } else {
                            const opsiTd = tr.querySelector('td[data-col="opsi_pilihan"]') || tr.querySelector('td:nth-child(6)');
                            if (opsiTd) opsiTd.textContent = row.opsi_pilihan ?? opsiTd.textContent;
                        }

                        const ketInput = tr.querySelector('input[name="keterangan[]"], textarea[name="keterangan[]"]');
                        if (ketInput) {
                            ketInput.value = row.keterangan ?? '';
                            ketInput.setAttribute('data-original', row.keterangan ?? '');
                        } else {
                            const ketTd = tr.querySelector('td[data-col="keterangan"]') || tr.querySelector('td:nth-child(7)');
                            if (ketTd) ketTd.textContent = row.keterangan ?? ketTd.textContent;
                        }

                        const editBtn = tr.querySelector('.edit-btn');
                        if (editBtn) {
                            try {
                                const payload = JSON.parse(editBtn.getAttribute('data-payload') || '{}');
                                payload.id_standar = row.id_standar ?? payload.id_standar;
                                payload.id_kriteria = row.id_kriteria ?? payload.id_kriteria;
                                payload.komponen = row.komponen ?? payload.komponen;
                                payload.tipe_input = row.tipe_input ?? payload.tipe_input;
                                payload.nilai_batas = row.nilai_batas ?? payload.nilai_batas;
                                payload.opsi_pilihan = row.opsi_pilihan ?? payload.opsi_pilihan;
                                payload.keterangan = row.keterangan ?? payload.keterangan;
                                if (row.sub_kriteria) payload.sub_kriteria = row.sub_kriteria;
                                if (row.kode_kriteria) payload.kode_kriteria = row.kode_kriteria;
                                if (row.nama_kategori) payload.nama_kategori = row.nama_kategori;
                                editBtn.setAttribute('data-payload', JSON.stringify(payload));
                            } catch (err) {
                                console.error('update payload error', err);
                            }
                        }
                    } // end if(tr)

                    const editModal = document.getElementById('editModal');
                    if (editModal) editModal.classList.add('hidden');

                    (function showCenteredNotif(text = 'Perubahan tersimpan') {
                        try {
                            const n = document.createElement('div');
                            n.className = 'custom-center-notif';
                            n.style.position = 'fixed';
                            n.style.left = '50%';
                            n.style.top = '50%';
                            n.style.transform = 'translate(-50%, -50%)';
                            n.style.zIndex = '99999';
                            n.style.padding = '14px 18px';
                            n.style.background = 'white';
                            n.style.border = '1px solid rgba(156,163,175,0.3)';
                            n.style.boxShadow = '0 8px 30px rgba(2,6,23,0.12)';
                            n.style.borderRadius = '10px';
                            n.style.fontSize = '14px';
                            n.style.color = '#065f46';
                            n.textContent = text;

                            document.body.appendChild(n);

                            setTimeout(() => {
                                n.style.transition = 'opacity 0.35s, transform 0.35s';
                                n.style.opacity = '0';
                                n.style.transform = 'translate(-50%, -40%)';
                                setTimeout(() => n.remove(), 360);
                            }, 1400);
                        } catch (err) {
                            console.warn('notif center error', err);
                        }
                    })();

                } else {
                    alert('Gagal menyimpan: ' + (data.message || 'unknown'));
                }
            } catch (err) {
                console.error('AJAX error saat menyimpan edit:', err);
                if (confirm('Terjadi error saat menyimpan via AJAX. Ingin melanjutkan dengan submit biasa?')) {
                    form.submit();
                }
            }
        });
    })();
})();
