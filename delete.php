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

function load_json(string $path): array {
  if (!is_file($path)) return [];
  $h=@fopen($path,'r'); if(!$h) return [];
  @flock($h,LOCK_SH);
  $c=stream_get_contents($h);
  @flock($h,LOCK_UN); @fclose($h);
  $a=json_decode((string)$c,true);
  return is_array($a)?$a:[];
}
function write_json_atomic(string $file, array $data): bool {
  $dir = dirname($file);
  if (!is_dir($dir)) @mkdir($dir,0755,true);
  $tmp = $file.'.tmp';
  $h=@fopen($tmp,'c+'); if(!$h) return false;
  @flock($h,LOCK_EX);
  ftruncate($h,0);
  fwrite($h, json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
  fflush($h);
  @flock($h,LOCK_UN); fclose($h);
  return @rename($tmp,$file);
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

$map = load_json(STATUS_FILE);
if ($map) {
  unset($map[$inv], $map[$inv.'.xml']);
  $deleted['status_removed'] = write_json_atomic(STATUS_FILE, $map);
} else {
  $deleted['status_removed'] = true;
}

echo json_encode(['ok'=>$deleted['outbox_xml'], 'invoice'=>$inv, 'deleted'=>$deleted]);
