<?php
header('X-Content-Type-Options: nosniff');
header('Content-Type: application/json; charset=UTF-8');
require __DIR__ . '/auth_check.php';
require __DIR__ . '/config.php';

if (!defined('STATUS_FILE')) define('STATUS_FILE', DATA_ROOT . '/status.json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'msg'=>'Ungültige Methode']); exit; }
if (empty($_POST['csrf']) || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) { http_response_code(403); echo json_encode(['ok'=>false,'msg'=>'Ungültiges Token']); exit; }

$invoiceRaw = (string)($_POST['invoice'] ?? '');
$status     = (string)($_POST['status']  ?? '');

$invoice = preg_replace('/\.xml$/i','', $invoiceRaw);
if ($invoice === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $invoice)) { http_response_code(400); echo json_encode(['ok'=>false,'msg'=>'Ungültige Rechnungsnummer']); exit; }

$ALLOWED = ['Offen','Erinnerung gesendet','Bezahlt','Problem','Entwurf'];
if (!in_array($status, $ALLOWED, true)) { http_response_code(400); echo json_encode(['ok'=>false,'msg'=>'Ungültiger Status']); exit; }

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

$map = load_json(STATUS_FILE);
$map[$invoice] = $status;

if (!write_json_atomic(STATUS_FILE, $map)) { http_response_code(500); echo json_encode(['ok'=>false,'msg'=>'Status konnte nicht gespeichert werden']); exit; }

echo json_encode(['ok'=>true]);
