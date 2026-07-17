<?php
header('X-Content-Type-Options: nosniff');
header('Content-Type: application/json; charset=UTF-8');
require __DIR__ . '/auth_check.php';
require __DIR__ . '/config.php';

if (!defined('STATUS_FILE')) define('STATUS_FILE', DATA_ROOT . '/status.json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'msg'=>'Ungültige Methode']); exit; }
if (empty($_POST['csrf']) || !is_string($_POST['csrf']) || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string)$_POST['csrf'])) { http_response_code(403); echo json_encode(['ok'=>false,'msg'=>'Ungültiges Token']); exit; }

$invoiceRaw = (string)($_POST['invoice'] ?? '');
$status     = (string)($_POST['status']  ?? '');

$invoice = preg_replace('/\.xml$/i','', $invoiceRaw);
if ($invoice === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $invoice)) { http_response_code(400); echo json_encode(['ok'=>false,'msg'=>'Ungültige Rechnungsnummer']); exit; }

$ALLOWED = ['Offen','Erinnerung gesendet','Bezahlt','Problem','Entwurf'];
if (!in_array($status, $ALLOWED, true)) { http_response_code(400); echo json_encode(['ok'=>false,'msg'=>'Ungültiger Status']); exit; }

// Read-Modify-Write unter EINEM exklusiven Lock auf der Zieldatei: vorher lagen
// Lesen und Schreiben getrennt (Lost Update) und alle Schreiber teilten sich
// dieselbe .tmp-Datei (halbe Schreibstände konnten per rename gewinnen).
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

$ok = status_rmw(STATUS_FILE, function(array $map) use ($invoice, $status) {
  $map[$invoice] = $status;
  return $map;
});

if (!$ok) { http_response_code(500); echo json_encode(['ok'=>false,'msg'=>'Status konnte nicht gespeichert werden']); exit; }

echo json_encode(['ok'=>true]);
