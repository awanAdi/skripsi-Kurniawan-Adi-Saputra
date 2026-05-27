<?php
session_start();
require_once '../includes/koneksi.php';
date_default_timezone_set('Asia/Jakarta');

if (!isset($_SESSION['username']) || ($_SESSION['role'] ?? '') !== 'teknisi') {
    header("Location: ../auth/login.php");
    exit;
}
$id_teknisi = intval($_SESSION['id_user'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo "Method not allowed";
    exit;
}

$id_order = intval($_POST['id_order'] ?? 0);
if ($id_order <= 0) {
    die("Order tidak valid.");
}

function konversiHuruf($nilai)
{
    if ($nilai >= 85) return ['A', 'Sangat Layak'];
    if ($nilai >= 75) return ['B', 'Layak'];
    if ($nilai >= 60) return ['C', 'Cukup, perlu perbaikan'];
    if ($nilai >= 40) return ['D', 'Tidak Layak'];
    return ['E', 'Sangat Tidak Layak'];
}

function normalize_name($s)
{
    $s = (string)$s;
    $s = trim(preg_replace('/\s+/', ' ', $s));
    return mb_strtolower($s, 'UTF-8');
}

$hasIdStandarInUrutan = false;
$check = $conn->query("SHOW COLUMNS FROM urutan_komponen LIKE 'id_standar'");
if ($check && $check->num_rows > 0) $hasIdStandarInUrutan = true;

$urutanMapByName = [];
$urutanMapByStandar = [];
$resUr = $conn->query("SELECT * FROM urutan_komponen");
if ($resUr) {
    while ($u = $resUr->fetch_assoc()) {
        if (!empty($u['nama_komponen'])) {
            $urutanMapByName[$u['nama_komponen']] = (int)($u['urutan'] ?? 999);
        }
        if ($hasIdStandarInUrutan && isset($u['id_standar'])) {
            $urutanMapByStandar[(int)$u['id_standar']] = (int)($u['urutan'] ?? 999);
        }
    }
    $resUr->free();
}

$urutanMapByNameNorm = [];
foreach ($urutanMapByName as $raw => $val) {
    $k = normalize_name($raw);
    if (!isset($urutanMapByNameNorm[$k]) || $val < $urutanMapByNameNorm[$k]) {
        $urutanMapByNameNorm[$k] = (int)$val;
    }
}

$sql = "
SELECT 
    kat.id_kategori, kat.nama_kategori,
    kri.id_kriteria, kri.deskripsi AS nama_kriteria,
    sk.id_standar, sk.komponen, sk.tipe_input, sk.nilai_batas, sk.opsi_pilihan, sk.keterangan AS keterangan_standar
FROM kategori_inspeksi kat
JOIN kriteria_inspeksi kri ON kri.id_kategori = kat.id_kategori
LEFT JOIN standar_komponen sk ON sk.id_kriteria = kri.id_kriteria
ORDER BY kat.id_kategori, kri.id_kriteria, sk.id_standar
";
$res = $conn->query($sql);
$grouped = [];
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $idKat = (int)$r['id_kategori'];
        $idKri = (int)$r['id_kriteria'];
        $kom = $r['komponen'];

        if (!isset($grouped[$idKat])) {
            $grouped[$idKat] = [
                'id_kategori' => $idKat,
                'nama' => $r['nama_kategori'],
                'kriteria' => []
            ];
        }
        if (!isset($grouped[$idKat]['kriteria'][$idKri])) {
            $grouped[$idKat]['kriteria'][$idKri] = [
                'id_kriteria' => $idKri,
                'nama' => $r['nama_kriteria'],
                'komponen' => []
            ];
        }

        $urutan = 999;
        if (!empty($r['id_standar']) && isset($urutanMapByStandar[(int)$r['id_standar']])) {
            $urutan = $urutanMapByStandar[(int)$r['id_standar']];
        } elseif (isset($urutanMapByName[$kom])) {
            $urutan = $urutanMapByName[$kom];
        } else {
            $norm = normalize_name($kom);
            if (isset($urutanMapByNameNorm[$norm])) $urutan = $urutanMapByNameNorm[$norm];
        }

        if (!isset($grouped[$idKat]['kriteria'][$idKri]['komponen'][$kom])) {
            $grouped[$idKat]['kriteria'][$idKri]['komponen'][$kom] = [
                'nama' => $kom,
                'urutan' => $urutan,
                'tipe_input' => $r['tipe_input'],
                'standards' => []
            ];
        }

        if (!empty($r['id_standar'])) {
            $grouped[$idKat]['kriteria'][$idKri]['komponen'][$kom]['standards'][] = [
                'id_standar' => (int)$r['id_standar'],
                'nilai_batas' => $r['nilai_batas'],
                'opsi_pilihan' => $r['opsi_pilihan'],
                'keterangan' => $r['keterangan_standar']
            ];
        }
    }
    $res->free();
}

$urutanKategori = [
    'Eksterior'         => 1,
    'Mesin'             => 2,
    'Rangka & Kaki-Kaki' => 3,
    'Kelistrikan'       => 4,
    'Interior'          => 5,
    'Dokumen & Kunci'   => 6,
];

$katKeys = array_keys($grouped);
usort($katKeys, function ($a, $b) use ($grouped, $urutanKategori) {
    $nameA = $grouped[$a]['nama'] ?? '';
    $nameB = $grouped[$b]['nama'] ?? '';
    $posA = $urutanKategori[$nameA] ?? 999;
    $posB = $urutanKategori[$nameB] ?? 999;
    if ($posA === $posB) return strcmp($nameA, $nameB);
    return $posA <=> $posB;
});
$ordered = [];
foreach ($katKeys as $k) $ordered[$k] = $grouped[$k];

foreach ($ordered as $idKat => &$katData) {
    $kriArr = [];
    foreach ($katData['kriteria'] as $kId => $kData) {
        $komArr = array_values($kData['komponen']);
        usort($komArr, function ($a, $b) {
            $ua = $a['urutan'] ?? 999;
            $ub = $b['urutan'] ?? 999;
            if ($ua === $ub) return strcmp($a['nama'], $b['nama']);
            return $ua <=> $ub;
        });
        $minUr = 999;
        foreach ($komArr as $kc) $minUr = min($minUr, $kc['urutan'] ?? 999);

        $kriArr[] = [
            'id_kriteria' => $kData['id_kriteria'],
            'nama' => $kData['nama'],
            'komponen' => $komArr,
            'min_urutan' => $minUr
        ];
    }
    usort($kriArr, function ($a, $b) {
        if (($a['min_urutan'] ?? 999) === ($b['min_urutan'] ?? 999)) {
            return ($a['id_kriteria'] ?? 0) <=> ($b['id_kriteria'] ?? 0);
        }
        return ($a['min_urutan'] ?? 999) <=> ($b['min_urutan'] ?? 999);
    });
    foreach ($kriArr as &$tk) unset($tk['min_urutan']);
    $katData['kriteria'] = $kriArr;
}
unset($katData);

$postInputNormalized = [];
if (!empty($_POST['input']) && is_array($_POST['input'])) {
    foreach ($_POST['input'] as $k => $v) {
        if (is_numeric($k)) {
            $postInputNormalized[(string)$k] = $v;
        } else {
            $norm = normalize_name($k);
            $postInputNormalized['name::' . $norm] = $v;
        }
    }
}

$postCatatanNormalized = [];
if (!empty($_POST['catatan']) && is_array($_POST['catatan'])) {
    foreach ($_POST['catatan'] as $k => $v) {
        if (is_numeric($k)) {
            $postCatatanNormalized[(string)$k] = $v;
        } else {
            $norm = normalize_name($k);
            $postCatatanNormalized['name::' . $norm] = $v;
        }
    }
}

$conn->begin_transaction();
try {
    // create inspeksi
    $insSt = $conn->prepare("INSERT INTO inspeksi (id_order, id_teknisi, tanggal_inspeksi) VALUES (?, ?, NOW())");
    if (!$insSt) throw new Exception("Prepare inspeksi failed: " . $conn->error);
    $insSt->bind_param("ii", $id_order, $id_teknisi);
    $insSt->execute();
    $id_inspeksi = $insSt->insert_id;
    $insSt->close();

    if (!$id_inspeksi) throw new Exception("Gagal buat record inspeksi.");

    $insDet = $conn->prepare("
        INSERT INTO detail_inspeksi
        (id_inspeksi, id_kriteria, komponen, hasil_lapangan, keterangan, nilai, catatan)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$insDet) throw new Exception("Prepare insert detail_inspeksi gagal: " . $conn->error);

    $qRange = $conn->prepare("
        SELECT nilai_batas, keterangan 
        FROM standar_komponen 
        WHERE id_kriteria = ? AND komponen = ? AND tipe_input = 'angka'
        ORDER BY nilai_batas ASC
    ");
    if (!$qRange) throw new Exception("Prepare qRange failed: " . $conn->error);

    $qPil = $conn->prepare("
        SELECT keterangan 
        FROM standar_komponen 
        WHERE id_kriteria = ? AND komponen = ? AND FIND_IN_SET(?, opsi_pilihan)
        LIMIT 1
    ");
    if (!$qPil) throw new Exception("Prepare qPil failed: " . $conn->error);

    foreach ($ordered as $kat) {
        foreach ($kat['kriteria'] as $kri) {
            $id_kriteria_fixed = $kri['id_kriteria'] ?? null;
            foreach ($kri['komponen'] as $kom) {
                $komponen_name = $kom['nama'];
                $tipe_input = $kom['tipe_input'] ?? null;
                $standards = $kom['standards'] ?? [];

                $valueRaw = null;
                $foundKey = null;

                foreach ($standards as $s) {
                    if (!empty($s['id_standar'])) {
                        $sid = (string)$s['id_standar'];
                        if (isset($postInputNormalized[$sid])) {
                            $valueRaw = $postInputNormalized[$sid];
                            $foundKey = $sid;
                            break;
                        }
                    }
                }

                if ($valueRaw === null) {
                    $normName = normalize_name($komponen_name);
                    $nameKey = 'name::' . $normName;
                    if (isset($postInputNormalized[$nameKey])) {
                        $valueRaw = $postInputNormalized[$nameKey];
                        $foundKey = $nameKey;
                    }
                }

                if ($valueRaw === null) continue;

                $raw = is_string($valueRaw) ? trim($valueRaw) : $valueRaw;
                $hasil_lapangan = ($raw === '' || $raw === null) ? null : (string)$raw;

                $catatan = null;
                if ($foundKey !== null && isset($postCatatanNormalized[$foundKey])) {
                    $catatan = trim($postCatatanNormalized[$foundKey]);
                }

                $keterangan = null;
                if ($tipe_input === 'angka') {
                    if ($hasil_lapangan !== null && is_numeric(str_replace(',', '.', $hasil_lapangan))) {
                        $valNum = floatval(str_replace(',', '.', $hasil_lapangan));
                        $qRange->bind_param("is", $id_kriteria_fixed, $komponen_name);
                        $qRange->execute();
                        $resRange = $qRange->get_result();
                        while ($r = $resRange->fetch_assoc()) {
                            if ($r['nilai_batas'] === null) continue;
                            if ($valNum <= floatval($r['nilai_batas'])) {
                                $keterangan = $r['keterangan'];
                                break;
                            }
                        }
                        if ($keterangan === null) {
                            $resRange->data_seek(0);
                            $all = $resRange->fetch_all(MYSQLI_ASSOC);
                            if (!empty($all)) $keterangan = end($all)['keterangan'];
                        }
                        $resRange->free();
                    }
                } elseif ($tipe_input === 'pilihan') {
                    if ($hasil_lapangan !== null) {
                        $pilihan = $hasil_lapangan;
                        $qPil->bind_param("iss", $id_kriteria_fixed, $komponen_name, $pilihan);
                        $qPil->execute();
                        $resP = $qPil->get_result();
                        $rowP = $resP ? $resP->fetch_assoc() : null;
                        if ($rowP) $keterangan = $rowP['keterangan'];
                        if ($resP) $resP->free();
                    }
                }

                $nilai_bind = null;
                if ($tipe_input === 'angka' && $hasil_lapangan !== null && is_numeric(str_replace(',', '.', $hasil_lapangan))) {
                    $nilai_bind = str_replace(',', '.', $hasil_lapangan);
                }

                $id_kriteria_bind = ($id_kriteria_fixed !== null && intval($id_kriteria_fixed) > 0) ? intval($id_kriteria_fixed) : null;
                $komponen_bind = $komponen_name !== '' ? $komponen_name : null;
                $hasil_bind = $hasil_lapangan !== null ? $hasil_lapangan : null;
                $keterangan_bind = $keterangan !== null ? $keterangan : null;
                $nilai_bind = $nilai_bind !== null ? $nilai_bind : null;
                $catatan_bind = $catatan !== null && $catatan !== '' ? $catatan : null;

                if ($id_kriteria_bind === null && $komponen_bind === null && $hasil_bind === null && $catatan_bind === null) {
                    continue;
                }

                $insDet->bind_param(
                    "iisssss",
                    $id_inspeksi,
                    $id_kriteria_bind,
                    $komponen_bind,
                    $hasil_bind,
                    $keterangan_bind,
                    $nilai_bind,
                    $catatan_bind
                );
                if (!$insDet->execute()) {
                    throw new Exception("Gagal simpan detail_inspeksi: " . $insDet->error);
                }
            }
        }
    }

    $insDet->close();
    if ($qRange) $qRange->close();
    if ($qPil) $qPil->close();

    $countKategori = 0;
    if (!empty($_POST['nilai_kategori']) && is_array($_POST['nilai_kategori'])) {
        $stmtNil = $conn->prepare("INSERT INTO nilai_kategori (id_inspeksi, id_kategori, skor) VALUES (?, ?, ?)");
        if (!$stmtNil) throw new Exception("Prepare nilai_kategori failed: " . $conn->error);
        foreach ($ordered as $kat) {
            $id_kat = $kat['id_kategori'];
            if (isset($_POST['nilai_kategori'][$id_kat]) && is_array($_POST['nilai_kategori'][$id_kat])) {
                $skor = floatval($_POST['nilai_kategori'][$id_kat]['nilai'] ?? 0);
                $stmtNil->bind_param("iid", $id_inspeksi, $id_kat, $skor);
                $stmtNil->execute();
                $countKategori++;
            }
        }
        $stmtNil->close();
    }

    if ($countKategori > 0) {
        $q = $conn->prepare("SELECT AVG(skor) AS rata FROM nilai_kategori WHERE id_inspeksi = ?");
        $q->bind_param("i", $id_inspeksi);
        $q->execute();
        $resAvg = $q->get_result()->fetch_assoc();
        $q->close();
        $nilai_akhir = round(floatval($resAvg['rata'] ?? 0), 2);
        list($huruf, $ket) = konversiHuruf($nilai_akhir);

        $kesimpulan_manual = trim($_POST['kesimpulan'] ?? '');
        $kesimpulan = $kesimpulan_manual !== '' ? $kesimpulan_manual : $ket;

        $up = $conn->prepare("UPDATE inspeksi SET nilai_akhir = ?, nilai_huruf = ?, keterangan_penilaian = ?, kesimpulan = ? WHERE id_inspeksi = ?");
        $up->bind_param("dsssi", $nilai_akhir, $huruf, $ket, $kesimpulan, $id_inspeksi);
        $up->execute();
        $up->close();
    }

    if (!empty($_POST['servis']) && is_array($_POST['servis'])) {
        $stEst = $conn->prepare("INSERT INTO estimasi_perbaikan (id_inspeksi, pekerjaan, biaya) VALUES (?, ?, ?)");
        if (!$stEst) throw new Exception("Prepare estimasi failed: " . $conn->error);
        foreach ($_POST['servis'] as $s) {
            $pekerjaan = trim($s['hal'] ?? $s['pekerjaan'] ?? '');
            $biaya = floatval($s['biaya'] ?? 0);
            if ($pekerjaan === '') continue;
            $stEst->bind_param("isd", $id_inspeksi, $pekerjaan, $biaya);
            $stEst->execute();
        }
        $stEst->close();
    }

    if (!empty($_FILES['foto_mobil']['name'])) {
        $targetDir = "../uploads/foto_mobil/";
        if (!is_dir($targetDir)) mkdir($targetDir, 0755, true);
        $fname = time() . "_" . preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($_FILES['foto_mobil']['name']));
        $targetPath = $targetDir . $fname;
        if (move_uploaded_file($_FILES['foto_mobil']['tmp_name'], $targetPath)) {
            $stF = $conn->prepare("INSERT INTO bukti_foto (id_inspeksi, path_file, keterangan) VALUES (?, ?, ?)");
            $keterangan_foto = "Foto Mobil Utama";
            $stF->bind_param("iss", $id_inspeksi, $fname, $keterangan_foto);
            $stF->execute();
            $stF->close();
        }
    }

    if (!empty($_POST['scan_obd']) && is_array($_POST['scan_obd'])) {
        $stmtScan = $conn->prepare("
            INSERT INTO hasil_scan_obd (id_inspeksi, kode_trouble, indikasi_error, catatan)
            VALUES (?, ?, ?, ?)
        ");
        if (!$stmtScan) throw new Exception("Prepare scan_obd failed: " . $conn->error);
        foreach ($_POST['scan_obd'] as $scan) {
            $kode_trouble   = trim($scan['kode'] ?? '');
            $indikasi_error = trim($scan['error'] ?? '');
            $catatan        = trim($scan['catatan'] ?? '');
            if ($kode_trouble === '' && $indikasi_error === '') continue;
            $stmtScan->bind_param("isss", $id_inspeksi, $kode_trouble, $indikasi_error, $catatan);
            $stmtScan->execute();
        }
        $stmtScan->close();
    }

    if (!empty($id_order)) {
        $stmtUpdate = $conn->prepare("UPDATE order_inspeksi SET status = 'Selesai' WHERE id_order = ?");
        $stmtUpdate->bind_param("i", $id_order);
        if (!$stmtUpdate->execute()) {
            throw new Exception("Gagal update status order: " . $stmtUpdate->error);
        }
        $stmtUpdate->close();
    }

    $conn->commit();
    header("Location: sukses.php?id_inspeksi=" . $id_inspeksi);
    exit;
} catch (Exception $ex) {
    $conn->rollback();
    error_log("process_final ordered error: " . $ex->getMessage());
    http_response_code(500);
    echo "Terjadi kesalahan saat menyimpan inspeksi: " . htmlspecialchars($ex->getMessage());
    exit;
}
