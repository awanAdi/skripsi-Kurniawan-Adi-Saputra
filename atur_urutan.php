<?php
session_start();
if (!isset($_SESSION['username']) || ($_SESSION['role'] ?? '') !== 'admin') {
  header("Location: ../auth/login.php");
  exit();
}
require_once '../includes/koneksi.php';

function normalize_component_name(string $s): string
{
  $s = trim($s);
  $s = preg_replace('/\s+/', ' ', $s);
  return $s;
}

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(24));

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
  header('Content-Type: application/json; charset=utf-8');

  $csrf_header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
  if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf_header)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF token']);
    exit;
  }

  $raw = file_get_contents('php://input');
  $data = json_decode($raw, true);
  if (!is_array($data) || !isset($data['order']) || !is_array($data['order'])) {
    echo json_encode(['status' => 'error', 'message' => 'Payload invalid']);
    exit;
  }

  $max_items_allowed = 2000;
  if (count($data['order']) > $max_items_allowed) {
    echo json_encode(['status' => 'error', 'message' => 'Payload terlalu besar']);
    exit;
  }

  /* Ambil semua komponen standar (referensi) */
  $all = [];
  $sqlAll = "
      SELECT s.komponen AS nama_komponen, MIN(u.urutan) AS urutan
      FROM standar_komponen s
      LEFT JOIN urutan_komponen u ON s.komponen = u.nama_komponen
      GROUP BY s.komponen
      ORDER BY COALESCE(MIN(u.urutan), 999999), MIN(u.urutan) ASC, s.komponen ASC
    ";
  $res = $conn->query($sqlAll);
  if ($res && $res->num_rows > 0) {
    while ($r = $res->fetch_assoc()) $all[] = $r['nama_komponen'];
  }
  if (empty($all)) {
    echo json_encode(['status' => 'error', 'message' => 'Tidak ada komponen di standar_komponen']);
    exit;
  }
  $N = count($all);
  $indexMap = array_flip($all);

  /* Ambil nama komponen yang ada di urutan_komponen */
  $existingUrutanNames = [];
  $resCurAll = $conn->query("SELECT nama_komponen FROM urutan_komponen");
  if ($resCurAll) {
    while ($r = $resCurAll->fetch_assoc()) $existingUrutanNames[] = $r['nama_komponen'];
  }

  $orphan = array_values(array_diff($existingUrutanNames, $all));

  $confirm_map = $data['confirm_map'] ?? null;
  if (is_array($confirm_map) && count($confirm_map) > 0) {
    $mergeErrors = [];
    foreach ($confirm_map as $oldRaw => $newRaw) {
      if ($newRaw === null || $newRaw === '') continue;

      $old = normalize_component_name((string)$oldRaw);
      $new = normalize_component_name((string)$newRaw);
      if ($old === '' || $new === '') continue;
      if ($old === $new) continue;

      if (!in_array($old, $existingUrutanNames, true)) continue;

      $conn->begin_transaction();
      try {
        $stmtOld = $conn->prepare("SELECT nama_komponen, urutan FROM urutan_komponen WHERE nama_komponen = ? LIMIT 1 FOR UPDATE");
        $stmtOld->bind_param("s", $old);
        $stmtOld->execute();
        $resOld = $stmtOld->get_result();
        $rowOld = $resOld->fetch_assoc();
        $stmtOld->close();

        if (!$rowOld) {
          $conn->commit();
          continue;
        }

        $stmtNew = $conn->prepare("SELECT nama_komponen, urutan FROM urutan_komponen WHERE nama_komponen = ? LIMIT 1 FOR UPDATE");
        $stmtNew->bind_param("s", $new);
        $stmtNew->execute();
        $resNew = $stmtNew->get_result();
        $rowNew = $resNew->fetch_assoc();
        $stmtNew->close();

        $oldUrutan = $rowOld['urutan'] === null ? null : (int)$rowOld['urutan'];

        if ($rowNew) {
          $newUrutan = $rowNew['urutan'] === null ? null : (int)$rowNew['urutan'];
          if ($newUrutan === null && $oldUrutan !== null) {
            $upd = $conn->prepare("UPDATE urutan_komponen SET urutan = ? WHERE nama_komponen = ?");
            if (!$upd) throw new Exception("Prepare UPDATE gagal: " . $conn->error);
            $upd->bind_param("is", $oldUrutan, $new);
            if (!$upd->execute()) throw new Exception("Gagal update urutan untuk $new: " . $upd->error);
            $upd->close();
          }
          $del = $conn->prepare("DELETE FROM urutan_komponen WHERE nama_komponen = ?");
          if (!$del) throw new Exception("Prepare DELETE gagal: " . $conn->error);
          $del->bind_param("s", $old);
          if (!$del->execute()) throw new Exception("Gagal menghapus old $old: " . $del->error);
          $del->close();
        } else {
          if (strcasecmp($old, $new) === 0) {
            $tmp = "__tmp__" . bin2hex(random_bytes(8));

            $upd1 = $conn->prepare("UPDATE urutan_komponen SET nama_komponen = ? WHERE nama_komponen = ?");
            if (!$upd1) throw new Exception("Prepare rename old->tmp gagal: " . $conn->error);
            $upd1->bind_param("ss", $tmp, $old);
            if (!$upd1->execute()) throw new Exception("Gagal rename old->tmp: " . $upd1->error);
            $upd1->close();

            $ins = $conn->prepare("INSERT INTO urutan_komponen (nama_komponen, urutan) SELECT ?, urutan FROM urutan_komponen WHERE nama_komponen = ? ON DUPLICATE KEY UPDATE urutan = COALESCE(VALUES(urutan), urutan)");
            if (!$ins) throw new Exception("Prepare insert tmp->new gagal: " . $conn->error);
            $ins->bind_param("ss", $new, $tmp);
            if (!$ins->execute()) throw new Exception("Gagal insert tmp->new: " . $ins->error);
            $ins->close();

            $delTmp = $conn->prepare("DELETE FROM urutan_komponen WHERE nama_komponen = ?");
            if (!$delTmp) throw new Exception("Prepare delete tmp gagal: " . $conn->error);
            $delTmp->bind_param("s", $tmp);
            if (!$delTmp->execute()) throw new Exception("Gagal hapus tmp: " . $delTmp->error);
            $delTmp->close();
          } else {
            $ins = $conn->prepare("INSERT INTO urutan_komponen (nama_komponen, urutan) SELECT ?, urutan FROM urutan_komponen WHERE nama_komponen = ? ON DUPLICATE KEY UPDATE urutan = COALESCE(VALUES(urutan), urutan)");
            if (!$ins) throw new Exception("Prepare insert-select gagal: " . $conn->error);
            $ins->bind_param("ss", $new, $old);
            if (!$ins->execute()) throw new Exception("Merge gagal (insert-select): " . $ins->error);
            $ins->close();

            $del = $conn->prepare("DELETE FROM urutan_komponen WHERE nama_komponen = ?");
            if (!$del) throw new Exception("Prepare delete old gagal: " . $conn->error);
            $del->bind_param("s", $old);
            if (!$del->execute()) throw new Exception("Hapus sumber gagal: " . $del->error);
            $del->close();
          }
        }

        $conn->commit();
      } catch (Exception $e) {
        if ($conn->connect_errno === 0) $conn->rollback();
        $mergeErrors[] = "Merge $old -> $new gagal: " . $e->getMessage();
      }
    } 
    if (!empty($mergeErrors)) {
      foreach ($mergeErrors as $me) error_log($me);
    }

    $existingUrutanNames = [];
    $resCurAll2 = $conn->query("SELECT nama_komponen FROM urutan_komponen");
    if ($resCurAll2) {
      while ($r = $resCurAll2->fetch_assoc()) $existingUrutanNames[] = $r['nama_komponen'];
    }
    $orphan = array_values(array_diff($existingUrutanNames, $all));
  } else {
    if (!empty($orphan)) {
      $SIMILARITY_THRESHOLD = 55;
      $suggestions = [];
      foreach ($orphan as $oldName) {
        $best = null;
        $bestScore = 0;
        foreach ($all as $cand) {
          similar_text(mb_strtolower($oldName, 'UTF-8'), mb_strtolower($cand, 'UTF-8'), $percent);
          if ($percent > $bestScore) {
            $ld = null;
            $a = @iconv('UTF-8', 'ASCII//TRANSLIT', $oldName);
            $b = @iconv('UTF-8', 'ASCII//TRANSLIT', $cand);
            if ($a !== false && $b !== false) {
              $ld = levenshtein($a, $b);
            } else {
              $ld = null;
            }
            $bestScore = $percent;
            $best = ['candidate' => $cand, 'score' => round($percent, 2), 'dist' => $ld];
          }
        }
        $suggestions[] = ['old' => $oldName, 'best' => $best];
      }

      echo json_encode(['status' => 'confirm', 'suggestions' => $suggestions]);
      exit;
    }
  }

  $currentPos = [];
  $resCur = $conn->query("SELECT nama_komponen, urutan FROM urutan_komponen");
  if ($resCur) {
    while ($r = $resCur->fetch_assoc()) {
      $currentPos[$r['nama_komponen']] = $r['urutan'] === null ? null : (int)$r['urutan'];
    }
  }

  $seq = [];
  $inSeq = [];
  $withPos = [];
  foreach ($currentPos as $kom => $pos) {
    if ($pos !== null && isset($indexMap[$kom])) $withPos[] = ['kom' => $kom, 'pos' => $pos];
  }
  usort($withPos, function ($a, $b) {
    return $a['pos'] <=> $b['pos'];
  });
  foreach ($withPos as $wp) {
    $seq[] = $wp['kom'];
    $inSeq[$wp['kom']] = true;
  }
  foreach ($all as $kom) {
    if (!isset($inSeq[$kom])) {
      $seq[] = $kom;
      $inSeq[$kom] = true;
    }
  }

  $incoming = [];
  foreach ($data['order'] as $it) {
    if (!is_array($it) || empty($it['komponen'])) continue;
    $kom = (string)$it['komponen'];
    if (!isset($indexMap[$kom])) continue;
    $posRaw = $it['pos'] ?? null;
    if ($posRaw === '' || $posRaw === null) {
      $pos = null;
    } else if (is_numeric($posRaw)) {
      $pos = (int)$posRaw;
      if ($pos < 1) $pos = 1;
      if ($pos > $N) $pos = $N;
    } else {
      $pos = null;
    }
    $incoming[] = ['komponen' => $kom, 'pos' => $pos];
  }

  $shouldBeNull = [];
  foreach ($incoming as $it) {
    $kom = $it['komponen'];
    $pos = $it['pos'];

    $foundIndex = array_search($kom, $seq, true);
    if ($foundIndex !== false) {
      array_splice($seq, $foundIndex, 1);
    }

    if ($pos === null) {
      $shouldBeNull[$kom] = true;
    } else {
      $pos = max(1, min($N, (int)$pos));
      $insertAt = $pos - 1;
      if ($insertAt >= count($seq)) {
        $seq[] = $kom;
      } else {
        array_splice($seq, $insertAt, 0, [$kom]);
      }
      if (isset($shouldBeNull[$kom])) unset($shouldBeNull[$kom]);
    }
  }

  foreach ($shouldBeNull as $k => $_) {
    $idx = array_search($k, $seq, true);
    if ($idx !== false) array_splice($seq, $idx, 1);
  }

  $finalAssigned = [];
  $pos = 1;
  foreach ($seq as $kom) {
    $finalAssigned[$kom] = $pos++;
  }
  foreach ($all as $kom) {
    if (!isset($finalAssigned[$kom])) $finalAssigned[$kom] = null;
  }

  try {
    $conn->begin_transaction();

    $delSql = "DELETE u FROM urutan_komponen u LEFT JOIN standar_komponen s ON u.nama_komponen = s.komponen WHERE s.komponen IS NULL";
    if (!$conn->query($delSql)) {
      $errNo = $conn->errno;
      $errMsg = $conn->error;
      $conn->rollback();
      error_log("Gagal membersihkan urutan_komponen usang: ($errNo) $errMsg");
      echo json_encode(['status' => 'error', 'message' => 'Gagal membersihkan entri lama.']);
      exit;
    }

    $existing = [];
    $resExist = $conn->query("SELECT nama_komponen FROM urutan_komponen");
    if ($resExist) {
      while ($r = $resExist->fetch_assoc()) $existing[$r['nama_komponen']] = true;
    }

    $upd_non = $conn->prepare("UPDATE urutan_komponen SET urutan = ? WHERE nama_komponen = ?");
    $upd_null = $conn->prepare("UPDATE urutan_komponen SET urutan = NULL WHERE nama_komponen = ?");
    $ins_non = $conn->prepare("INSERT INTO urutan_komponen (nama_komponen, urutan) VALUES (?, ?) ON DUPLICATE KEY UPDATE urutan = VALUES(urutan)");
    $ins_null = $conn->prepare("INSERT INTO urutan_komponen (nama_komponen, urutan) VALUES (?, NULL) ON DUPLICATE KEY UPDATE urutan = NULL");

    if (!$upd_non || !$upd_null || !$ins_non || !$ins_null) {
      throw new Exception("Gagal prepare statement");
    }

    foreach ($finalAssigned as $kom => $pval) {
      if (isset($existing[$kom])) {
        if ($pval === null) {
          $upd_null->bind_param("s", $kom);
          if (!$upd_null->execute()) throw new Exception("Update NULL gagal untuk $kom");
        } else {
          $upd_non->bind_param("is", $pval, $kom);
          if (!$upd_non->execute()) throw new Exception("Update gagal untuk $kom");
        }
      } else {
        if ($pval === null) {
          $ins_null->bind_param("s", $kom);
          if (!$ins_null->execute()) throw new Exception("Insert NULL gagal untuk $kom");
        } else {
          $ins_non->bind_param("si", $kom, $pval);
          if (!$ins_non->execute()) throw new Exception("Insert gagal untuk $kom");
        }
        $existing[$kom] = true;
      }
    }

    $upd_non->close();
    $upd_null->close();
    $ins_non->close();
    $ins_null->close();
    $conn->commit();

    $out = [];
    $res3 = $conn->query("
          SELECT s.komponen AS nama_komponen, u.urutan
          FROM standar_komponen s
          LEFT JOIN urutan_komponen u ON s.komponen = u.nama_komponen
          GROUP BY s.komponen
          ORDER BY COALESCE(MIN(u.urutan), 999999), MIN(u.urutan) ASC, s.komponen ASC
        ");
    if ($res3) {
      while ($r = $res3->fetch_assoc()) {
        $out[] = ['komponen' => $r['nama_komponen'], 'pos' => $r['urutan'] === null ? null : (int)$r['urutan']];
      }
    }
    echo json_encode(['status' => 'ok', 'saved' => $out]);
    exit;
  } catch (Exception $e) {
    if ($conn->connect_errno === 0) $conn->rollback();
    error_log("Exception at atur_urutan: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Terjadi kesalahan internal saat menyimpan.']);
    exit;
  }
}

$items = [];
$res = $conn->query("
  SELECT s.komponen AS nama_komponen, MIN(u.urutan) AS urutan
  FROM standar_komponen s
  LEFT JOIN urutan_komponen u ON s.komponen = u.nama_komponen
  GROUP BY s.komponen
  ORDER BY COALESCE(MIN(u.urutan), 999999), MIN(u.urutan) ASC, s.komponen ASC
");
if ($res && $res->num_rows > 0) {
  while ($r = $res->fetch_assoc()) $items[] = ['komponen' => $r['nama_komponen'], 'pos' => ($r['urutan'] === null ? null : (int)$r['urutan'])];
}

$existingUrutanNames = [];
$resCurAll = $conn->query("SELECT nama_komponen FROM urutan_komponen");
if ($resCurAll) {
  while ($r = $resCurAll->fetch_assoc()) $existingUrutanNames[] = $r['nama_komponen'];
}
$standarNames = array_column($items, 'komponen');
$orphan_init = array_values(array_diff($existingUrutanNames, $standarNames));

$suggestions_for_init = [];
if (!empty($orphan_init)) {
  foreach ($orphan_init as $oldName) {
    $best = null;
    $bestScore = 0;
    foreach ($standarNames as $cand) {
      similar_text(mb_strtolower($oldName, 'UTF-8'), mb_strtolower($cand, 'UTF-8'), $percent);
      if ($percent > $bestScore) {
        $bestScore = $percent;
        $best = ['candidate' => $cand, 'score' => round($percent, 2)];
      }
    }
    $suggestions_for_init[] = ['old' => $oldName, 'best' => $best];
  }
}
$script_suggestion = json_encode($suggestions_for_init, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
?>
<!doctype html>
<html lang="id">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=5">
  <title>Atur Urutan Komponen</title>
  <link rel="icon" type="image/x-icon" href="../favicon.ico">
  <style>
    :root {
      --bg: #f6f7fb;
      --card: #fff;
      --muted: #64748b;
      --accent: #0b5ed7;
      --accent-hover: #0a52c0;
      --border: #e2e8f0;
      --text: #0f172a;
      --shadow: 0 1px 3px rgba(0,0,0,0.08);
      --transition: all 0.15s ease;
    }

    * {
      box-sizing: border-box;
    }

    body {
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      margin: 0;
      background: var(--bg);
      color: var(--text);
      font-size: 16px;
      line-height: 1.5;
    }

    .container {
      max-width: 1100px;
      margin: 0 auto;
      padding: 12px;
    }

    .toolbar {
      position: sticky;
      top: 0;
      background: var(--bg);
      padding: 8px 0;
      z-index: 100;
      border-bottom: 1px solid var(--border);
      box-shadow: var(--shadow);
    }

    .toolbar-inner {
      display: flex;
      flex-wrap: wrap;
      gap: 6px;
      align-items: center;
      max-width: 1100px;
      margin: 0 auto;
      padding: 0 12px;
    }

    input[type="search"],
    input[type="number"] {
      padding: 8px 10px;
      border: 1px solid var(--border);
      border-radius: 6px;
      font-size: 16px;
      transition: var(--transition);
      background: var(--card);
    }

    input[type="search"]:focus,
    input[type="number"]:focus {
      outline: none;
      border-color: var(--accent);
      box-shadow: 0 0 0 3px rgba(11, 94, 215, 0.1);
    }

    .search-wrapper {
      flex: 1;
      min-width: 180px;
    }

    button {
      padding: 8px 12px;
      border-radius: 6px;
      border: 1px solid var(--border);
      background: var(--card);
      cursor: pointer;
      font-size: 15px;
      font-weight: 500;
      transition: var(--transition);
      white-space: nowrap;
      min-height: 38px;
    }

    button:hover:not(:disabled) {
      background: #f8fafc;
      border-color: var(--accent);
    }

    button:disabled {
      opacity: 0.5;
      cursor: not-allowed;
    }

    .btn-primary {
      background: var(--accent);
      color: #fff;
      border-color: var(--accent);
    }

    .btn-primary:hover:not(:disabled) {
      background: var(--accent-hover);
    }

    .panel {
      background: var(--card);
      padding: 8px;
      border-radius: 8px;
      margin-top: 12px;
      box-shadow: var(--shadow);
    }

    .page-title {
      font-size: 22px;
      font-weight: 700;
      margin: 16px 0 6px;
    }

    .page-desc {
      color: var(--muted);
      font-size: 15px;
      margin: 0 0 12px;
    }

    .list {
      list-style: none;
      padding: 0;
      margin: 0;
      max-height: calc(100vh - 320px);
      min-height: 200px;
      overflow-y: auto;
    }

    .list::-webkit-scrollbar {
      width: 6px;
    }

    .list::-webkit-scrollbar-thumb {
      background: #cbd5e1;
      border-radius: 3px;
    }

    .item {
      display: grid;
      grid-template-columns: 50px 1fr auto;
      gap: 8px;
      align-items: center;
      padding: 8px;
      border-bottom: 1px solid #f1f5f9;
      transition: var(--transition);
    }

    .item:hover {
      background: #f8fafc;
    }

    .pos {
      font-weight: 700;
      text-align: center;
      color: var(--muted);
      font-size: 17px;
    }

    .name {
      font-size: 16px;
      line-height: 1.4;
      word-break: break-word;
    }

    .controls {
      display: flex;
      gap: 4px;
      align-items: center;
    }

    .pos-input {
      width: 60px;
      padding: 6px;
      font-size: 15px;
      text-align: center;
      min-height: 34px;
    }

    .small-btn {
      padding: 6px 8px;
      min-width: 34px;
      min-height: 34px;
      font-size: 15px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }

    .highlight {
      background: #fef3c7 !important;
    }

    .search-info {
      color: var(--muted);
      font-size: 14px;
      margin-left: 6px;
    }

    .max-box {
      padding: 6px 10px;
      border-radius: 6px;
      background: var(--card);
      border: 1px solid var(--border);
      font-weight: 600;
      font-size: 14px;
      white-space: nowrap;
    }

    .total-info {
      margin-top: 8px;
      padding: 6px;
      text-align: center;
      color: var(--muted);
      font-size: 14px;
    }

    .modal-overlay {
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, 0.5);
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 9999;
      padding: 12px;
    }

    .modal-box {
      max-width: 700px;
      width: 100%;
      background: var(--card);
      padding: 20px;
      border-radius: 8px;
      max-height: 85vh;
      overflow-y: auto;
      box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    }

    .modal-box h3 {
      margin-top: 0;
      font-size: 19px;
      font-weight: 700;
    }

    .map-item {
      padding: 12px;
      border: 1px solid var(--border);
      border-radius: 6px;
      margin-bottom: 10px;
      background: #fafbfc;
    }

    .map-item strong {
      font-size: 16px;
    }

    .map-item label {
      display: block;
      margin-top: 8px;
      font-size: 15px;
      cursor: pointer;
      padding: 4px;
    }

    .map-item input[type="radio"] {
      margin-right: 6px;
    }

    .modal-actions {
      display: flex;
      gap: 6px;
      justify-content: flex-end;
      margin-top: 16px;
    }

    .btn-back {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      padding: 8px 12px;
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 6px;
      text-decoration: none;
      color: var(--text);
      font-weight: 500;
      font-size: 15px;
      transition: var(--transition);
      min-height: 38px;
    }

    .btn-back:hover {
      background: #f8fafc;
      border-color: var(--accent);
    }

    @media (max-width: 768px) {
      .item {
        grid-template-columns: 45px 1fr;
        padding: 8px 6px;
      }

      .controls {
        grid-column: 1 / -1;
        margin-top: 6px;
      }

      .pos-input {
        flex: 1;
      }

      .small-btn {
        flex: 1;
      }

      .max-box {
        display: none;
      }

      button {
        flex: 1;
      }

      .search-wrapper {
        width: 100%;
      }
    }

    @media (max-width: 480px) {
      .page-title {
        font-size: 19px;
      }

      .item {
        grid-template-columns: 1fr;
      }

      .pos {
        display: inline-block;
        margin-right: 8px;
        text-align: left;
      }

      .name {
        font-size: 15px;
      }
    }

    .loading {
      opacity: 0.6;
      pointer-events: none;
    }
  </style>
  <script>
    window.__initialMappingSuggestions = <?php echo $script_suggestion; ?>;
  </script>
</head>

<body>
  <div class="toolbar">
    <div class="toolbar-inner">
      <div class="search-wrapper">
        <input id="search" type="search" placeholder="🔍 Cari komponen..." autofocus />
      </div>
      <span class="search-info" id="search-info"></span>
      <div id="max-info" class="max-box">-</div>
      <button id="btn-fill" title="Isi nomor otomatis">Isi Next</button>
      <button id="btn-reset" title="Reset angka">Reset</button>
      <button id="btn-save" class="btn-primary">Simpan</button>
      <a href="standar_inspeksi.php" class="btn-back">← Kembali</a>
    </div>
  </div>

  <div class="container">
    <h1 class="page-title">Atur Urutan Komponen</h1>
    <p class="page-desc">Isi angka untuk posisi. Kosongkan untuk NULL. Sistem auto-hapus entri yang tidak di standar.</p>

    <div class="panel">
      <ul id="list" class="list">
        <?php foreach ($items as $it):
          $safe = htmlspecialchars($it['komponen'], ENT_QUOTES, 'UTF-8');
          $pos = $it['pos'] === null ? '' : (int)$it['pos'];
          $displayPos = $it['pos'] === null ? '-' : $it['pos'];
        ?>
          <li class="item" data-name="<?php echo $safe ?>">
            <div class="pos" data-pos-display><?php echo $displayPos ?></div>
            <div class="name"><?php echo $safe ?></div>
            <div class="controls">
              <input class="pos-input" type="number" min="1" value="<?php echo $pos ?>" inputmode="numeric" />
              <button class="small-btn btn-up" title="Atas">↑</button>
              <button class="small-btn btn-down" title="Bawah">↓</button>
              <button class="small-btn btn-top" title="Top">⇈</button>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
      <div id="total-info" class="total-info">Total: <?php echo count($items); ?> komponen</div>
    </div>
  </div>

  <script>
    const listEl = document.getElementById('list');
    const searchEl = document.getElementById('search');
    const searchInfo = document.getElementById('search-info');
    const maxInfo = document.getElementById('max-info');
    const btnSave = document.getElementById('btn-save');
    const csrfToken = "<?php echo $_SESSION['csrf_token']; ?>";

    setInterval(() => location.reload(), 900000);

    function refreshPosDisplays() {
      Array.from(listEl.querySelectorAll('li.item')).forEach(li => {
        const input = li.querySelector('.pos-input');
        const disp = li.querySelector('[data-pos-display]');
        if (input && disp) {
          const val = input.value.trim();
          disp.textContent = val || '-';
        }
      });
      updateMaxInfo();
    }

    function computeMaxInput() {
      let max = 0;
      Array.from(listEl.querySelectorAll('.pos-input')).forEach(input => {
        const v = input.value.trim();
        if (v && /^\d+$/.test(v)) {
          const n = parseInt(v, 10);
          if (!isNaN(n) && n > max) max = n;
        }
      });
      return max;
    }

    function updateMaxInfo() {
      const max = computeMaxInput();
      maxInfo.textContent = max > 0 ? `Max: ${max}` : '-';
    }

    function moveElement(li, toIndex) {
      const items = Array.from(listEl.querySelectorAll('li.item'));
      const fromIndex = items.indexOf(li);
      if (fromIndex === -1) return;
      toIndex = Math.max(0, Math.min(items.length - 1, toIndex));
      if (fromIndex === toIndex) return;
      
      if (toIndex < fromIndex) {
        listEl.insertBefore(li, items[toIndex]);
      } else {
        if (toIndex === items.length - 1) {
          listEl.appendChild(li);
        } else {
          listEl.insertBefore(li, items[toIndex + 1]);
        }
      }
      
      refreshPosDisplays();
      li.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    listEl.addEventListener('click', function(ev) {
      const btn = ev.target.closest('button');
      if (!btn) return;
      
      const li = btn.closest('li.item');
      if (!li) return;
      
      const items = Array.from(listEl.querySelectorAll('li.item'));
      const idx = items.indexOf(li);
      
      if (btn.classList.contains('btn-up')) {
        moveElement(li, idx - 1);
      } else if (btn.classList.contains('btn-down')) {
        moveElement(li, idx + 1);
      } else if (btn.classList.contains('btn-top')) {
        moveElement(li, 0);
      }
      
      li.classList.add('highlight');
      setTimeout(() => li.classList.remove('highlight'), 400);
    });

    listEl.addEventListener('input', function(ev) {
      if (ev.target && ev.target.classList.contains('pos-input')) {
        updateMaxInfo();
      }
    });

    listEl.addEventListener('keydown', function(ev) {
      if (ev.key !== 'Enter') return;
      const input = ev.target;
      if (!input.classList.contains('pos-input')) return;
      
      ev.preventDefault();
      const v = input.value.trim();
      if (!v) {
        refreshPosDisplays();
        return;
      }
      
      const num = parseInt(v, 10);
      if (isNaN(num)) return;
      
      const li = input.closest('li.item');
      const items = listEl.querySelectorAll('li.item');
      const targetIndex = Math.max(1, Math.min(items.length, num)) - 1;
      moveElement(li, targetIndex);
    });

    function filterList(q) {
      q = q.trim().toLowerCase();
      let visible = 0;
      
      Array.from(listEl.querySelectorAll('li.item')).forEach(li => {
        const name = (li.dataset.name || '').toLowerCase();
        if (!q || name.includes(q)) {
          li.style.display = '';
          visible++;
        } else {
          li.style.display = 'none';
        }
      });
      
      searchInfo.textContent = q ? `${visible} hasil` : '';
      updateMaxInfo();
    }

    searchEl.addEventListener('input', function() {
      filterList(this.value);
    });

    document.getElementById('btn-reset').addEventListener('click', function() {
      if (!confirm('Reset semua nomor?')) return;
      Array.from(listEl.querySelectorAll('.pos-input')).forEach(i => i.value = '');
      refreshPosDisplays();
    });

    document.getElementById('btn-fill').addEventListener('click', function() {
      const start = computeMaxInput() + 1;
      let cur = start;
      
      Array.from(listEl.querySelectorAll('li.item')).forEach(li => {
        if (li.style.display === 'none') return;
        const input = li.querySelector('.pos-input');
        if (!input || input.value.trim()) return;
        input.value = cur++;
      });
      
      refreshPosDisplays();
    });

    function escapeHtml(s) {
      const div = document.createElement('div');
      div.textContent = s;
      return div.innerHTML;
    }

    function escapeCssId(s) {
      return btoa(unescape(encodeURIComponent(s))).replace(/=+$/, '');
    }

    function showMappingModal(suggestions) {
      return new Promise((resolve) => {
        const overlay = document.createElement('div');
        overlay.className = 'modal-overlay';
        
        const box = document.createElement('div');
        box.className = 'modal-box';
        box.innerHTML = `
          <h3>Verifikasi Perubahan Nama</h3>
          <p style="margin:6px 0 12px;color:var(--muted);font-size:15px">
            Sistem menemukan nama yang tidak cocok. Pilih tindakan:
          </p>
          <div id="map-list"></div>
          <div class="modal-actions">
            <button id="map-cancel">Batal</button>
            <button id="map-apply" class="btn-primary">Terapkan</button>
          </div>
        `;
        overlay.appendChild(box);
        document.body.appendChild(overlay);

        const mapList = box.querySelector('#map-list');

        suggestions.forEach(s => {
          const wrapper = document.createElement('div');
          wrapper.className = 'map-item';
          
          let html = `<strong>${escapeHtml(s.old)}</strong>`;
          if (s.best) {
            html += `<div style="font-size:14px;color:var(--muted);margin-top:4px">
              Saran: <em>${escapeHtml(s.best.candidate)}</em> (${s.best.score}%)
            </div>`;
          }
          wrapper.innerHTML = html;

          const rid = 'map-' + escapeCssId(s.old);
          
          const r1 = document.createElement('label');
          r1.innerHTML = `<input type="radio" name="${rid}" value="map" checked> Ganti ke saran`;
          
          const r2 = document.createElement('label');
          r2.innerHTML = `<input type="radio" name="${rid}" value="remove"> Hapus`;
          
          wrapper.appendChild(r1);
          wrapper.appendChild(r2);
          mapList.appendChild(wrapper);
        });

        box.querySelector('#map-cancel').addEventListener('click', () => {
          overlay.remove();
          resolve(null);
        });
        
        box.querySelector('#map-apply').addEventListener('click', () => {
          const mapping = {};
          suggestions.forEach(s => {
            const rid = 'map-' + escapeCssId(s.old);
            const radios = box.querySelectorAll(`input[name="${rid}"]`);
            let choice = 'map';
            radios.forEach(r => { if (r.checked) choice = r.value; });
            mapping[s.old] = (choice === 'map' && s.best) ? s.best.candidate : null;
          });
          overlay.remove();
          resolve(mapping);
        });

        overlay.addEventListener('click', (e) => {
          if (e.target === overlay) {
            overlay.remove();
            resolve(null);
          }
        });
      });
    }

    (async function() {
      try {
        if (Array.isArray(window.__initialMappingSuggestions) && window.__initialMappingSuggestions.length) {
          const mapping = await showMappingModal(window.__initialMappingSuggestions);
          if (!mapping) return;
          
          const items = Array.from(listEl.querySelectorAll('li.item'));
          const payload = items.map(li => {
            const kom = li.dataset.name;
            const input = li.querySelector('.pos-input');
            const raw = input ? input.value.trim() : '';
            return { komponen: kom, pos: raw || null };
          });

          const res = await fetch(location.href, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify({ order: payload, confirm_map: mapping })
          });
          
          const data = await res.json();
          if (data.status === 'ok' && Array.isArray(data.saved)) {
            const mapSaved = {};
            data.saved.forEach(it => mapSaved[it.komponen] = it.pos);
            
            Array.from(listEl.querySelectorAll('li.item')).forEach(li => {
              const kom = li.dataset.name;
              const input = li.querySelector('.pos-input');
              if (kom in mapSaved) {
                input.value = mapSaved[kom] || '';
              }
            });
            refreshPosDisplays();
          }
        }
      } catch (e) {
        console.error('Error:', e);
      }
    })();

    btnSave.addEventListener('click', async function() {
      const btn = this;
      const items = Array.from(listEl.querySelectorAll('li.item'));
      const payload = items.map(li => {
        const kom = li.dataset.name;
        const input = li.querySelector('.pos-input');
        const raw = input ? input.value.trim() : '';
        return { komponen: kom, pos: raw || null };
      });

      btn.disabled = true;
      const original = btn.textContent;
      btn.textContent = 'Menyimpan...';
      listEl.classList.add('loading');

      try {
        const res = await fetch(location.href, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken
          },
          body: JSON.stringify({ order: payload })
        });
        
        const data = await res.json();

        if (data.status === 'confirm' && Array.isArray(data.suggestions)) {
          listEl.classList.remove('loading');
          btn.textContent = original;
          btn.disabled = false;
          
          const mapping = await showMappingModal(data.suggestions);
          if (!mapping) return;
          
          btn.disabled = true;
          btn.textContent = 'Menerapkan...';
          listEl.classList.add('loading');
          
          const res2 = await fetch(location.href, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify({ order: payload, confirm_map: mapping })
          });
          
          const data2 = await res2.json();
          if (data2.status === 'ok') {
            updateAfterSave(data2.saved);
            btn.textContent = '✓ Tersimpan';
            setTimeout(() => btn.textContent = original, 2000);
          } else {
            throw new Error(data2.message || 'Gagal');
          }
        } else if (data.status === 'ok') {
          updateAfterSave(data.saved);
          btn.textContent = '✓ Tersimpan';
          setTimeout(() => btn.textContent = original, 2000);
        } else {
          throw new Error(data.message || 'Gagal');
        }
      } catch (err) {
        console.error(err);
        alert('Error: ' + err.message);
        btn.textContent = original;
      } finally {
        btn.disabled = false;
        listEl.classList.remove('loading');
        refreshPosDisplays();
      }
    });

    function updateAfterSave(saved) {
      if (Array.isArray(saved)) {
        const mapSaved = {};
        saved.forEach(it => mapSaved[it.komponen] = it.pos);
        
        Array.from(listEl.querySelectorAll('li.item')).forEach(li => {
          const kom = li.dataset.name;
          const input = li.querySelector('.pos-input');
          input.value = (kom in mapSaved && mapSaved[kom]) ? mapSaved[kom] : '';
        });
      }
    }

    refreshPosDisplays();
    filterList('');
  </script>
</body>

</html>