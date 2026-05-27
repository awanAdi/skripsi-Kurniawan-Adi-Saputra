<?php
session_start();
require_once '../includes/koneksi.php';
header('Content-Type: application/json; charset=utf-8');

// auth
if (!isset($_SESSION['username']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$csrf_header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf_header)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF token']);
    exit;
}

$raw = file_get_contents('php://input');
$req = json_decode($raw, true);
if (!is_array($req)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid payload']);
    exit;
}

$old = trim($req['oldName'] ?? '');
$new = trim($req['newName'] ?? '');
$apply = !empty($req['apply']) ? true : false;

if ($old === '' || $new === '') {
    echo json_encode(['status' => 'error', 'message' => 'oldName/newName required']);
    exit;
}

$standar = [];
$res = $conn->query("SELECT komponen FROM standar_komponen");
if ($res) {
    while ($r = $res->fetch_assoc()) $standar[] = $r['komponen'];
}

$best = null;
$bestScore = 0;
foreach ($standar as $cand) {
    similar_text(mb_strtolower($old, 'UTF-8'), mb_strtolower($cand, 'UTF-8'), $percent);
    if ($percent > $bestScore) {
        $bestScore = $percent;
        $best = ['candidate' => $cand, 'score' => round($percent, 2)];
    }
}

if ($apply) {
    $conn->begin_transaction();
    try {
        $check = $conn->prepare("SELECT nama_komponen, urutan FROM urutan_komponen WHERE nama_komponen = ? LIMIT 1 FOR UPDATE");
        $check->bind_param("s", $old);
        $check->execute();
        $resC = $check->get_result();
        $row = $resC->fetch_assoc();
        $check->close();
        if (!$row) {
            $conn->rollback();
            echo json_encode(['status' => 'error', 'message' => 'Old name tidak ditemukan di urutan_komponen']);
            exit;
        }

        $oldUrutan = $row['urutan'] === null ? null : (int)$row['urutan'];

        $chkNew = $conn->prepare("SELECT nama_komponen, urutan FROM urutan_komponen WHERE nama_komponen = ? LIMIT 1 FOR UPDATE");
        $chkNew->bind_param("s", $new);
        $chkNew->execute();
        $resNew = $chkNew->get_result();
        $rowNew = $resNew->fetch_assoc();
        $chkNew->close();

        if ($rowNew) {
            $newUrutan = $rowNew['urutan'] === null ? null : (int)$rowNew['urutan'];
            if ($newUrutan === null && $oldUrutan !== null) {
                $upd = $conn->prepare("UPDATE urutan_komponen SET urutan = ? WHERE nama_komponen = ?");
                $upd->bind_param("is", $oldUrutan, $new);
                if (!$upd->execute()) throw new Exception("Gagal update urutan untuk $new: " . $upd->error);
                $upd->close();
            }
            $del = $conn->prepare("DELETE FROM urutan_komponen WHERE nama_komponen = ?");
            $del->bind_param("s", $old);
            if (!$del->execute()) throw new Exception("Gagal menghapus old $old: " . $del->error);
            $del->close();
        } else {
            // new tidak ada
            if (strcasecmp($old, $new) === 0) {
                $tmp = "__tmp__" . bin2hex(random_bytes(8));
                $upd1 = $conn->prepare("UPDATE urutan_komponen SET nama_komponen = ? WHERE nama_komponen = ?");
                $upd1->bind_param("ss", $tmp, $old);
                if (!$upd1->execute()) throw new Exception("Gagal rename old->tmp: " . $upd1->error);
                $upd1->close();

                $ins = $conn->prepare("INSERT INTO urutan_komponen (nama_komponen, urutan) SELECT ?, urutan FROM urutan_komponen WHERE nama_komponen = ? ON DUPLICATE KEY UPDATE urutan = COALESCE(VALUES(urutan), urutan)");
                $ins->bind_param("ss", $new, $tmp);
                if (!$ins->execute()) throw new Exception("Gagal insert tmp->new: " . $ins->error);
                $ins->close();

                $delTmp = $conn->prepare("DELETE FROM urutan_komponen WHERE nama_komponen = ?");
                $delTmp->bind_param("s", $tmp);
                if (!$delTmp->execute()) throw new Exception("Gagal hapus tmp: " . $delTmp->error);
                $delTmp->close();
            } else {
                $ins = $conn->prepare("INSERT INTO urutan_komponen (nama_komponen, urutan) SELECT ?, urutan FROM urutan_komponen WHERE nama_komponen = ? ON DUPLICATE KEY UPDATE urutan = COALESCE(VALUES(urutan), urutan)");
                $ins->bind_param("ss", $new, $old);
                if (!$ins->execute()) throw new Exception("Insert/merge gagal: " . $ins->error);
                $ins->close();

                $del = $conn->prepare("DELETE FROM urutan_komponen WHERE nama_komponen = ?");
                $del->bind_param("s", $old);
                if (!$del->execute()) throw new Exception("Hapus old gagal: " . $del->error);
                $del->close();
            }
        }

        $conn->commit();
        echo json_encode(['status' => 'ok', 'applied' => true, 'best' => $best]);
        exit;
    } catch (Exception $e) {
        if ($conn->connect_errno === 0) $conn->rollback();
        $msg = $e->getMessage();
        error_log("apply mapping error: $msg");
        echo json_encode(['status' => 'error', 'message' => 'Gagal mengaplikasikan mapping']);
        exit;
    }
}

echo json_encode(['status' => 'ok', 'applied' => false, 'best' => $best]);
exit;
