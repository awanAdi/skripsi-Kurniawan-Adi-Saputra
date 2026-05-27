<?php
require_once __DIR__ . '/../includes/koneksi.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;

session_start();

if (!isset($_SESSION['username']) || !isset($_SESSION['role']) || !isset($_SESSION['id_user'])) {
    http_response_code(401);
    exit('Unauthorized');
}

$idOrder = 0;
if (isset($_POST['id'])) $idOrder = (int) $_POST['id'];
elseif (isset($_GET['id'])) $idOrder = (int) $_GET['id'];
if ($idOrder <= 0) {
    http_response_code(400);
    exit('ID order tidak valid.');
}

$idUser = (int) $_SESSION['id_user'];
$role   = $_SESSION['role'];

$URUTAN_KATEGORI = [
    'eksterior' => 1,
    'mesin' => 2,
    'kelistrikan' => 3,
    'interior' => 4,
    'rangka & kaki-kaki' => 5,
    'dokumen & kunci' => 6,
];

$BULAN_ID = [
    1 => 'Jan',
    2 => 'Feb',
    3 => 'Mar',
    4 => 'Apr',
    5 => 'Mei',
    6 => 'Jun',
    7 => 'Jul',
    8 => 'Agt',
    9 => 'Sep',
    10 => 'Okt',
    11 => 'Nov',
    12 => 'Des'
];

$UPLOAD_DIR_REL = __DIR__ . '/../uploads/foto_mobil';
$UPLOAD_ICONS_REL = __DIR__ . '/../uploads/icons';
$iconsDirReal = realpath($UPLOAD_ICONS_REL) ?: (__DIR__ . '/../uploads/icons');
function normalize_name($s)
{
    return mb_strtolower(preg_replace('/\s+/', ' ', trim((string)$s)));
}

function fetchOrderData($conn, $idOrder, $role, $idUser)
{
    $sql = "
        SELECT oi.id_order, u.nama_lengkap, 
               k.merk, k.model, k.tahun_produksi, k.nomor_polisi, k.alamat AS alamat_mobil, k.link_gmaps,
               i.id_inspeksi, i.tanggal AS tanggal_inspeksi, i.nilai_akhir, i.nilai_huruf, i.kesimpulan
        FROM order_inspeksi oi
        JOIN users u ON oi.id_pelanggan = u.id_user
        JOIN kendaraan k ON oi.id_mobil = k.id_mobil
        LEFT JOIN inspeksi i ON oi.id_order = i.id_order
        WHERE oi.id_order = ?";

    if ($role === 'pelanggan') {
        $sql .= " AND oi.id_pelanggan = ? LIMIT 1";
        $stmt = $conn->prepare($sql);
        if (!$stmt) return null;
        $stmt->bind_param('ii', $idOrder, $idUser);
    } else {
        $sql .= " LIMIT 1";
        $stmt = $conn->prepare($sql);
        if (!$stmt) return null;
        $stmt->bind_param('i', $idOrder);
    }

    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row ?: null;
}

function fetchCategoryScores($conn, $idInspeksi)
{
    $stmt = $conn->prepare("SELECT nk.id_kategori, ki.nama_kategori, nk.skor FROM nilai_kategori nk JOIN kategori_inspeksi ki ON nk.id_kategori = ki.id_kategori WHERE nk.id_inspeksi = ?");
    $stmt->bind_param('i', $idInspeksi);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows ?: [];
}

function fetchDetailInspeksi($conn, $idInspeksi)
{
    $stmt = $conn->prepare("SELECT ki.nama_kategori AS kategori, d.komponen, d.hasil_lapangan, d.nilai, d.status, d.keterangan, d.catatan FROM detail_inspeksi d JOIN kriteria_inspeksi kri ON d.id_kriteria = kri.id_kriteria JOIN kategori_inspeksi ki ON kri.id_kategori = ki.id_kategori WHERE d.id_inspeksi = ?");
    $stmt->bind_param('i', $idInspeksi);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows ?: [];
}

function fetchPhotos($conn, $idInspeksi)
{
    $stmt = $conn->prepare('SELECT path_file FROM bukti_foto WHERE id_inspeksi = ? LIMIT 1');
    $stmt->bind_param('i', $idInspeksi);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? [$row] : [];
}


function create_thumbnail_if_not_exists($srcPath, $thumbPath, $maxW = 720, $maxH = 480)
{
    if (!file_exists($srcPath) || !is_readable($srcPath)) return false;

    if (file_exists($thumbPath) && filemtime($thumbPath) >= filemtime($srcPath)) {
        return realpath($thumbPath);
    }

    $info = @getimagesize($srcPath);
    if ($info === false) return false;
    $mime = $info['mime'];

    switch ($mime) {
        case 'image/jpeg':
        case 'image/jpg':
            $srcImg = @imagecreatefromjpeg($srcPath);
            break;
        case 'image/png':
            $srcImg = @imagecreatefrompng($srcPath);
            break;
        case 'image/heic':
        case 'image/heif':
            $tempJpg = $srcPath . '.jpg';
            @exec("magick convert " . escapeshellarg($srcPath) . " " . escapeshellarg($tempJpg));
            if (file_exists($tempJpg)) {
                $srcImg = @imagecreatefromjpeg($tempJpg);
                unlink($tempJpg);
            } else {
                return false;
            }
            break;
        case 'image/gif':
            return false;

        default:
            return false;
    }

    if (!$srcImg) return false;

    $w = imagesx($srcImg);
    $h = imagesy($srcImg);

    $ratio = min($maxW / $w, $maxH / $h, 1);
    $nw = max(1, (int) round($w * $ratio));
    $nh = max(1, (int) round($h * $ratio));

    $thumb = imagecreatetruecolor($nw, $nh);

    if ($mime === 'image/png' || $mime === 'image/gif') {
        $white = imagecolorallocate($thumb, 255, 255, 255);
        imagefill($thumb, 0, 0, $white);
        imagecopyresampled($thumb, $srcImg, 0, 0, 0, 0, $nw, $nh, $w, $h);
    } else {
        imagecopyresampled($thumb, $srcImg, 0, 0, 0, 0, $nw, $nh, $w, $h);
    }

    $dir = dirname($thumbPath);
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
            imagedestroy($srcImg);
            imagedestroy($thumb);
            return false;
        }
    }

    $saved = @imagejpeg($thumb, $thumbPath, 85);

    imagedestroy($srcImg);
    imagedestroy($thumb);

    if ($saved) return realpath($thumbPath);
    return false;
}

function resolve_path($path)
{
    if (!$path) return '';
    if (file_exists($path)) return realpath($path);
    $try = realpath(__DIR__ . '/../' . ltrim($path, '/\\'));
    if ($try) return $try;
    $try2 = realpath(__DIR__ . '/' . ltrim($path, '/\\'));
    return $try2 ?: $path;
}

function embedImageAsBase64($path)
{
    $rp = resolve_path($path);
    if (!$rp || !file_exists($rp) || !is_readable($rp)) return '';
    $maxEmbedBytes = 6 * 1024 * 1024; // 6MB
    if (is_file($rp) && filesize($rp) > $maxEmbedBytes) {
        return 'file://' . $rp;
    }
    $data = @file_get_contents($rp);
    if ($data === false) return '';
    $mime = mime_content_type($rp) ?: 'image/jpeg';
    return 'data:' . $mime . ';base64,' . base64_encode($data);
}

function fetchScanData($conn, $idInspeksi)
{
    $stmt = $conn->prepare('SELECT kode_trouble, indikasi_error, catatan, tanggal_scan FROM hasil_scan_obd WHERE id_inspeksi = ?');
    $stmt->bind_param('i', $idInspeksi);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows ?: [];
}

function fetchEstimasi($conn, $idInspeksi)
{
    $stmt = $conn->prepare('SELECT pekerjaan, biaya FROM estimasi_perbaikan WHERE id_inspeksi = ?');
    $stmt->bind_param('i', $idInspeksi);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows ?: [];
}

function fetchTechnician($conn, $idInspeksi)
{
    $stmt = $conn->prepare('SELECT i.id_teknisi, u.nama_lengkap AS nama_teknisi FROM inspeksi i LEFT JOIN users u ON i.id_teknisi = u.id_user WHERE i.id_inspeksi = ? LIMIT 1');
    $stmt->bind_param('i', $idInspeksi);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

$orderData = fetchOrderData($conn, $idOrder, $role, $idUser);
if (!$orderData) {
    http_response_code(403);
    exit('Akses ditolak. Order tidak ditemukan atau bukan milik Anda.');
}
if (empty($orderData['id_inspeksi'])) exit('Order ini belum memiliki data inspeksi.');

$idInspeksi = (int)$orderData['id_inspeksi'];

$categoryScores = fetchCategoryScores($conn, $idInspeksi);
$detailRows = fetchDetailInspeksi($conn, $idInspeksi);
$photoRows = fetchPhotos($conn, $idInspeksi);
$scanData = fetchScanData($conn, $idInspeksi);
$estimasiData = fetchEstimasi($conn, $idInspeksi);
$techRow = fetchTechnician($conn, $idInspeksi);
$namaTeknisi = $techRow['nama_teknisi'] ?? '';

$totalBiaya = 0;
foreach ($estimasiData as $e) {
    $totalBiaya += (int)($e['biaya'] ?? 0);
}

$uploadDirReal = realpath($UPLOAD_DIR_REL) ?: $UPLOAD_DIR_REL;
$thumbDir = rtrim($uploadDirReal, '/\\') . DIRECTORY_SEPARATOR . 'thumbs';
if (!is_dir($thumbDir)) @mkdir($thumbDir, 0755, true);

$fotos = [];
$missingFotos = [];
if (!empty($photoRows) && !empty($photoRows[0]['path_file'])) {
    $p = $photoRows[0];
    $fileName = basename($p['path_file']);
    $srcFull = rtrim($uploadDirReal, '/\\') . DIRECTORY_SEPARATOR . $fileName;

    $baseName = pathinfo($fileName, PATHINFO_FILENAME);
    $thumbName = $baseName . '_thumb.jpg';
    $thumbFull = rtrim($thumbDir, '/\\') . DIRECTORY_SEPARATOR . $thumbName;

    $thumbAbs = create_thumbnail_if_not_exists($srcFull, $thumbFull, 720, 480);
    if ($thumbAbs !== false) {
        $dataUri = embedImageAsBase64($thumbAbs);
        $fotos[] = ['file' => $fileName, 'data_uri' => $dataUri, 'exists' => true];
    } elseif (file_exists($srcFull) && is_readable($srcFull)) {
        $dataUri = embedImageAsBase64($srcFull);
        $fotos[] = ['file' => $fileName, 'data_uri' => $dataUri, 'exists' => true];
    } else {
        $fotos[] = ['file' => $fileName, 'data_uri' => '', 'exists' => false];
        $missingFotos[] = $fileName;
    }
}

$logoFull = realpath($UPLOAD_ICONS_REL . '/logo.png');
$logoData = '';
if ($logoFull && is_readable($logoFull)) {
    $logoData = embedImageAsBase64($logoFull);
}

$normOrder = [];
foreach ($URUTAN_KATEGORI as $k => $v) {
    $normOrder[normalize_name($k)] = (int)$v;
}

usort($categoryScores, function ($a, $b) use ($normOrder) {
    $na = normalize_name($a['nama_kategori'] ?? '');
    $nb = normalize_name($b['nama_kategori'] ?? '');
    $pa = $normOrder[$na] ?? 999;
    $pb = $normOrder[$nb] ?? 999;
    if ($pa === $pb) return strcmp($na, $nb);
    return $pa <=> $pb;
});

$parts = [];
foreach ($categoryScores as $r) {
    if (!isset($r['skor']) || $r['skor'] === null || $r['skor'] === '') continue;
    $score = (float) $r['skor'];
    $fmt = number_format($score, 1, ',', '.');
    $parts[] = htmlspecialchars($r['nama_kategori']) . ': ' . $fmt;
}
$perKategoriLine = implode(' | ', $parts);

$urutanMapByName = [];
$resUr = $conn->query("SELECT * FROM urutan_komponen");
if ($resUr) {
    while ($u = $resUr->fetch_assoc()) {
        if (!empty($u['nama_komponen'])) {
            $key = normalize_name($u['nama_komponen']);
            $urutanMapByName[$key] = (int)($u['urutan'] ?? 999);
        }
    }
    $resUr->free();
}

$grouped = [];
foreach ($detailRows as $d) {
    $catRaw = $d['kategori'] ?? 'Lainnya';
    $cat = preg_replace('/\s+/', ' ', trim($catRaw));
    if (!isset($grouped[$cat])) $grouped[$cat] = [];
    $grouped[$cat][] = $d;
}

$katKeys = array_keys($grouped);
usort($katKeys, function ($a, $b) use ($normOrder) {
    $na = normalize_name($a);
    $nb = normalize_name($b);
    $posA = $normOrder[$na] ?? 999;
    $posB = $normOrder[$nb] ?? 999;
    if ($posA === $posB) return strcmp($a, $b);
    return $posA <=> $posB;
});

$groupedOrdered = [];
foreach ($katKeys as $k) {
    $items = $grouped[$k];
    usort($items, function ($x, $y) use ($urutanMapByName) {
        $ka = normalize_name($x['komponen'] ?? '');
        $kb = normalize_name($y['komponen'] ?? '');
        $ua = $urutanMapByName[$ka] ?? 999;
        $ub = $urutanMapByName[$kb] ?? 999;
        if ($ua === $ub) return strcmp($ka, $kb);
        return $ua <=> $ub;
    });
    $groupedOrdered[$k] = $items;
}

$nilaiAkhirFormatted = is_numeric($orderData['nilai_akhir']) ? number_format((float)$orderData['nilai_akhir'], 2, ',', '.') : htmlspecialchars($orderData['nilai_akhir']);
ob_start();
?>
<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="<?= __DIR__ . '/../admin/pdf_style.css' ?>">
</head>

<body>
    <?php if (!empty($logoData)): ?>
        <div style="text-align:center; margin-top:6px; margin-bottom:8px;">
            <img src="<?= $logoData ?>" alt="Logo Rtech" style="max-width:500px; display:block; margin:0 auto;">
        </div>
    <?php endif; ?>

    <div class="first-header">
        <h1>REPORT PELAPORAN INSPEKSI</h1>
        <div class="info">
            <div class="left">
                <strong>No Inspeksi:</strong> <?= htmlspecialchars($orderData['id_order']) ?><br>
                Merek dan tipe kendaraan : <?= htmlspecialchars($orderData['merk'] . ' ' . $orderData['model']) ?><br>
                Tahun pembuatan : <?= htmlspecialchars($orderData['tahun_produksi']) ?><br>
                Waktu inspeksi : <?= !empty($orderData['tanggal_inspeksi']) ? date('d F Y', strtotime($orderData['tanggal_inspeksi'])) : '-' ?><br>
            </div>
        </div>
    </div>

    <div class="footer" style="margin-top:12px;">
        <div class="footer-content">
            <div class="footer-left">
                <strong>Kontak Rtech</strong>
                <span class="footer-line">
                    <span class="footer-item">
                        <span class="fl-label">Jasa Inspeksi Kendaraan :</span>
                        <img class="icon" src="<?= htmlspecialchars(embedImageAsBase64($iconsDirReal . '/whatsapp.png')) ?>" width="14" height="14" alt="WhatsApp">
                        <span class="fl-label">089-6556-25-222</span>
                    </span>
                    <span class="footer-item">
                        <span class="fl-label">Website :</span>
                        <a class="fl-label" href="https://www.jasainspeksijogja.com">www.jasainspeksijogja.com</a>
                    </span>
                    <span class="footer-item">
                        <span class="fl-label">Our group :</span>
                        <span class="fl-label">Rtech IT Development, RTech Jasa Inspeksi</span>
                    </span>
                </span>
            </div>

            <div class="footer-right">
                <img src="<?= htmlspecialchars(embedImageAsBase64($UPLOAD_ICONS_REL . '/instagram.png')) ?>" width="14" height="14" alt="Instagram"> @jasainspeksijogja
                <img src="<?= htmlspecialchars(embedImageAsBase64($UPLOAD_ICONS_REL . '/tiktok.png')) ?>" width="14" height="14" alt="TikTok"> @jasainspeksijogja
                <img src="<?= htmlspecialchars(embedImageAsBase64($UPLOAD_ICONS_REL . '/youtube.png')) ?>" width="14" height="14" alt="Youtube"> @jasainspeksijogja
            </div>
        </div>
        <?php $footer_tgl = str_pad((int)date('d'), 2, '0', STR_PAD_LEFT) . '-' . ($BULAN_ID[(int)date('n')] ?? date('M')) . '-' . date('Y') . ' ' . date('H:i'); ?>
        <div class="footer-meta">Dicetak pada: <?= htmlspecialchars($footer_tgl) ?> | Halaman <span class="pageNumber"></span></div>
    </div>

    <div class="container">
        <?php
        $hasPhoto = !empty($fotos) && !empty($fotos[0]['data_uri']);
        ?>
        <?php if ($hasPhoto): ?>
            <table class="media-table">
                <tr>
                    <td class="media-left">
                        <div class="order-info">
                            <p><span class="label">No Inspeksi:</span> <?= htmlspecialchars($orderData['id_order']) ?></p>
                            <p><span class="label">Merek dan Tipe Kendaraan:</span> <?= htmlspecialchars($orderData['merk'] . ' ' . $orderData['model']) ?></p>
                            <p><span class="label">Tahun Pembuatan:</span> <?= htmlspecialchars($orderData['tahun_produksi']) ?></p>
                            <p><span class="label">Tanggal Inspeksi:</span> <?= !empty($orderData['tanggal_inspeksi']) ? date('d F Y', strtotime($orderData['tanggal_inspeksi'])) : '-' ?></p>
                        </div>
                    </td>
                    <td class="media-right">
                        <div class="foto-item main">
                            <div class="foto-caption"><?= htmlspecialchars('Mobil ' . $orderData['merk'] . ' ' . $orderData['model']) ?></div>
                            <div class="foto-container">
                                <img src="<?= htmlspecialchars($fotos[0]['data_uri']) ?>" alt="Foto Mobil">
                            </div>
                        </div>
                    </td>
                </tr>
            </table>
        <?php endif; ?>

        <h3>Data Kendaraan</h3>
        <p><span class="label">Pelanggan:</span> <?= htmlspecialchars($orderData['nama_lengkap']) ?></p>
        <p><span class="label">Kendaraan:</span> <?= htmlspecialchars($orderData['merk'] . ' ' . $orderData['model'] . ' (' . $orderData['tahun_produksi'] . ') - ' . $orderData['nomor_polisi']) ?></p>
        <p><span class="label">Alamat Mobil:</span> <?= htmlspecialchars($orderData['alamat_mobil']) ?></p>
        <?php if (!empty($orderData['link_gmaps'])): ?><p><span class="label">Lokasi:</span> <?= htmlspecialchars($orderData['link_gmaps']) ?></p><?php endif; ?>
        <p><span class="label">Tanggal Inspeksi:</span> <?= !empty($orderData['tanggal_inspeksi']) ? date('d F Y', strtotime($orderData['tanggal_inspeksi'])) : '-' ?></p>
        <?php if (!empty($perKategoriLine)): ?>
            <p class="nilai-kategori"><span class="label">Nilai per Kategori:</span><span class="nilai-content"><?= $perKategoriLine ?></span></p>
        <?php endif; ?>
        <p><span class="label">Nilai Akhir:</span> <?= $nilaiAkhirFormatted ?> (<?= htmlspecialchars($orderData['nilai_huruf']) ?>)</p>
        <p><span class="label">Kesimpulan:</span> <?= htmlspecialchars($orderData['kesimpulan']) ?></p>

        <div class="detail-penilaian no-page-break">
            <h3>Detail Penilaian:</h3>
            <?php $no = 1;
            foreach ($groupedOrdered as $kategori => $items): ?>
                <div class="kategori-block">
                    <h4 style="margin-top:20px;"><?= htmlspecialchars($kategori) ?></h4>
                    <table>
                        <thead>
                            <tr>
                                <th style="width:6%;">No</th>
                                <th style="width:39%;">Komponen</th>
                                <th style="width:25%;">Input Inspektor</th>
                                <th style="width:30%;">Catatan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $d):
                                if (isset($d['hasil_lapangan']) && trim((string)$d['hasil_lapangan']) !== '') {
                                    $input = $d['hasil_lapangan'];
                                } elseif ($d['nilai'] !== null && $d['nilai'] !== '') {
                                    $input = $d['nilai'];
                                } elseif (!empty($d['status'])) {
                                    $input = $d['status'];
                                } else {
                                    $input = '-';
                                }

                                $ket = isset($d['keterangan']) ? trim((string)$d['keterangan']) : '';
                                $cat = isset($d['catatan']) ? trim((string)$d['catatan']) : '';
                                if ($ket !== '' && $cat !== '') {
                                    $catatan = $ket . ' (' . $cat . ')';
                                } elseif ($ket !== '') {
                                    $catatan = $ket;
                                } elseif ($cat !== '') {
                                    $catatan = $cat;
                                } else {
                                    $catatan = '-';
                                }
                            ?>
                                <tr>
                                    <td class="no-col"><?= htmlspecialchars($no++) ?></td>
                                    <td><?= htmlspecialchars($d['komponen']) ?></td>
                                    <td><?= htmlspecialchars($input) ?></td>
                                    <td><?= htmlspecialchars($catatan) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="no-page-break">
            <h3>Hasil Scan OBD:</h3>
            <?php if (!empty($scanData)): ?>
                <table>
                    <thead>
                        <tr>
                            <th style="width:20%;">Kode Trouble</th>
                            <th style="width:35%;">Indikasi Error</th>
                            <th style="width:25%;">Catatan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($scanData as $s): ?>
                            <tr>
                                <td><?= htmlspecialchars($s['kode_trouble']) ?></td>
                                <td><?= htmlspecialchars($s['indikasi_error']) ?></td>
                                <td><?= htmlspecialchars($s['catatan'] ?? '-') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?><p class="muted">Tidak ada data hasil scan OBD.</p><?php endif; ?>
        </div>

        <div class="estimasi-wrapper">
            <h3>Estimasi Perbaikan dan Perawatan:</h3>
            <?php if (!empty($estimasiData)): ?>
                <table class="estimasi-table">
                    <thead>
                        <tr>
                            <th style="width:6%;">No</th>
                            <th>Nama Kegiatan</th>
                            <th class="right">Biaya (Rp)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $noEstimasi = 1; ?>
                        <?php foreach ($estimasiData as $e): ?>
                            <tr>
                                <td><?= htmlspecialchars($noEstimasi++) ?></td>
                                <td><?= htmlspecialchars($e['pekerjaan'] ?? '-') ?></td>
                                <td class="right"><?= number_format((int)($e['biaya'] ?? 0), 0, ',', '.') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="estimasi-total">Total: Rp <?= number_format($totalBiaya, 0, ',', '.') ?></div>
            <?php else: ?>
                <p class="muted">Tidak ada estimasi perbaikan.</p>
            <?php endif; ?>
        </div>

        <?php if (!empty($namaTeknisi)): ?>
            <div class="ttd-teknisi" style="margin-top:18px; page-break-inside:avoid;">
                <p><strong>Teknisi:</strong> <?= htmlspecialchars($namaTeknisi) ?></p>
                <p style="font-size:11px; color:#444; margin-top:6px;">Laporan inspeksi berdasarkan hasil pemeriksaan pada waktu dan tempat yang telah ditentukan, kami tidak bertanggung jawab atas segala perubahan kondisi yang terjadi pada kendaraan setelah inspeksi dilakukan. Dengan menggunakan jasa kami, maka kami anggap anda telah menyetujui, memahami, dan membaca syarat dan ketentuan yang berlaku di Rtech Inspeksi yang tertuang pada <a href="https://jasainspeksijogja.com/syarat-dan-ketentuan-rtech-jasa-inspeksi/">https://jasainspeksijogja.com/syarat-dan-ketentuan-rtech-jasa-inspeksi/</a></p>
            </div>
        <?php endif; ?>
    </div>

    <script type="text/php">
    </script>
</body>

</html>
<?php
$html = ob_get_clean();

try {
    $dompdf = new Dompdf();
    $dompdf->set_option('isPhpEnabled', false);
    $dompdf->set_option('isRemoteEnabled', true);

    $projectRoot = realpath(__DIR__ . '/..');
    if ($projectRoot !== false) {
        $dompdf->set_option('chroot', $projectRoot);
    }

    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $namaMobil = preg_replace('/\s+/', '_', trim($orderData['merk'] . '_' . $orderData['model']));

    $ts = !empty($orderData['tanggal_inspeksi']) ? @strtotime($orderData['tanggal_inspeksi']) : false;
    if ($ts === false) $ts = time();
    $tgl = (int) date('d', $ts);
    $bl  = (int) date('n', $ts);
    $th  = date('Y', $ts);
    $tanggalCetak = str_pad($tgl, 2, '0', STR_PAD_LEFT) . '-' . ($BULAN_ID[$bl] ?? date('M', $ts)) . '-' . $th;
    $namaFile = "Inspeksi_" . $namaMobil . "_" . $tanggalCetak . ".pdf";

    $dompdf->stream($namaFile, ['Attachment' => true]);
    exit;
} catch (Exception $e) {
    // error_log('[PDF_RENDER_ERROR] ' . $e->getMessage());
    // http_response_code(500);
    // exit('Terjadi kesalahan saat menghasilkan PDF. Silakan periksa log.');
}
