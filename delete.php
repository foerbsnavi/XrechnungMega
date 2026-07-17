<?php
header('X-Content-Type-Options: nosniff');
header('Content-Type: application/json; charset=UTF-8');
require __DIR__ . '/auth_check.php';
require __DIR__ . '/config.php';

if (!defined('STATUS_FILE')) define('STATUS_FILE', DATA_ROOT . '/status.json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405); echo json_encode(['ok'=>false,'msg'=>'Ungültige Methode']); exit;
}
$token = (string)($_POST['csrf'] ?? '');
if (!$token || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) {
  http_response_code(403); echo json_encode(['ok'=>false,'msg'=>'Ungültiges Token']); exit;
}

$file = basename((string)($_POST['file'] ?? ''));
if (!preg_match('/^[\w.-]+\.xml$/i', $file)) {
  http_response_code(400); echo json_encode(['ok'=>false,'msg'=>'Ungültiger Dateiname']); exit;
}

// Read-Modify-Write unter EINEM exklusiven Lock auf der Zieldatei — verhindert
// Lost Updates zwischen parallelen Schreibern und die frühere geteilte .tmp-Datei.
function status_rmw(string $file, callable $fn): bool {
  $dir = dirname($file);
  if (!is_dir($dir)) @mkdir($dir,0755,true);
  $h=@fopen($file,'c+'); if(!$h) return false;
  if(!@flock($h,LOCK_EX)){ fclose($h); return false; }
  $map=json_decode((string)stream_get_contents($h),true);
  if(!is_array($map)) $map=[];
  $map=$fn($map);
  ftruncate($h,0); rewind($h);
  fwrite($h, json_encode($map, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
  fflush($h);
  @flock($h,LOCK_UN); fclose($h);
  return true;
}

$base = realpath(OUTBOX_DIR);
$pathXml = realpath($base . DIRECTORY_SEPARATOR . $file);
if ($base === false || $pathXml === false || strpos($pathXml, $base) !== 0 || !is_file($pathXml)) {
  http_response_code(404); echo json_encode(['ok'=>false,'msg'=>'Datei nicht gefunden']); exit;
}

$inv = preg_replace('/\.xml$/i','',$file);
$pdfName = $inv . '.pdf';
$pathPdf = realpath(OUTBOX_DIR . DIRECTORY_SEPARATOR . $pdfName);

$deleted = ['outbox_xml'=>false,'outbox_pdf'=>false,'status_removed'=>false];

$deleted['outbox_xml'] = @unlink($pathXml);
if ($pathPdf && is_file($pathPdf) && strpos($pathPdf, $base) === 0) $deleted['outbox_pdf'] = @unlink($pathPdf);

$deleted['status_removed'] = status_rmw(STATUS_FILE, function(array $map) use ($inv) {
  unset($map[$inv], $map[$inv.'.xml']);
  return $map;
});

echo json_encode(['ok'=>$deleted['outbox_xml'], 'invoice'=>$inv, 'deleted'=>$deleted]);
