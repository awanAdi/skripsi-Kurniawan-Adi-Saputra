<?php
require_once '../vendor/autoload.php';
use Dompdf\Dompdf;

ob_start();
include "template_pdf.php"; // isi template HTML di atas
$html = ob_get_clean();

$dompdf = new Dompdf();
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream("laporan_inspeksi.pdf", ["Attachment" => true]);
