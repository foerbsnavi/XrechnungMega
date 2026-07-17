<?php
/**
 * import.php — Import von XrechnungMega-XML-Dateien
 *
 * Importiert ausschließlich Rechnungen im von diesem Tool erzeugten Format
 * (CII / CrossIndustryInvoice, EN-16931-konform). Mehrere Dateien gleichzeitig
 * möglich. Bestehende Nummern werden übersprungen (nie überschrieben).
 */

header('X-Content-Type-Options: nosniff');
require __DIR__ . '/auth_check.php';
require __DIR__ . '/config.php';

function imp_json($http, $arr) {
  http_response_code($http);
  header('Content-Type: application/json; charset=UTF-8');
  header('Cache-Control: no-store');
  echo json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

// Read-Modify-Write unter EINEM exklusiven Lock auf der Zieldatei — die frühere
// Variante (Snapshot laden, am Ende komplett zurückschreiben über eine geteilte
// .tmp-Datei) konnte parallele Status-Änderungen überschreiben.
function imp_status_rmw(callable $fn): bool {
  if (!defined('STATUS_FILE')) return false;
  $dir = dirname(STATUS_FILE);
  if (!is_dir($dir)) @mkdir($dir, 0755, true);
  $h = @fopen(STATUS_FILE, 'c+');
  if (!$h) return false;
  if (!@flock($h, LOCK_EX)) { fclose($h); return false; }
  $map = json_decode((string)stream_get_contents($h), true);
  if (!is_array($map)) $map = [];
  $map = $fn($map);
  ftruncate($h, 0); rewind($h);
  fwrite($h, json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  fflush($h);
  @flock($h, LOCK_UN); fclose($h);
  return true;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') imp_json(405, ['ok' => false, 'msg' => 'Ungültige Anfrage']);
if (empty($_POST['csrf']) || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string)$_POST['csrf'])) {
  imp_json(403, ['ok' => false, 'msg' => 'Ungültiges Token. Bitte Seite neu laden.']);
}

// Plan-Limit (Plattform): wie bei save.php/API hart erzwungen
$xrMax = 0;
if (defined('XR_MODE') && XR_MODE === 'platform' && function_exists('current_user') && function_exists('plan_limits')) {
  $xrU = current_user();
  if ($xrU) $xrMax = (int)plan_limits((string)($xrU['plan'] ?? 'Basic'))['max_invoices'];
}

// Hochgeladene Dateien normalisieren (ein oder mehrere via xmlfiles[])
$files = [];
if (!empty($_FILES['xmlfiles']) && isset($_FILES['xmlfiles']['name'])) {
  $f = $_FILES['xmlfiles'];
  if (is_array($f['name'])) {
    $n = count($f['name']);
    for ($i = 0; $i < $n; $i++) {
      $files[] = ['name' => (string)$f['name'][$i], 'tmp' => (string)$f['tmp_name'][$i], 'error' => (int)$f['error'][$i], 'size' => (int)$f['size'][$i]];
    }
  } else {
    $files[] = ['name' => (string)$f['name'], 'tmp' => (string)$f['tmp_name'], 'error' => (int)$f['error'], 'size' => (int)$f['size']];
  }
}
if (!$files) imp_json(400, ['ok' => false, 'msg' => 'Keine Datei empfangen.']);

const IMP_MAX_BYTES = 1048576; // 1 MB pro Datei
const IMP_CII_NS    = 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100';

$imported = []; $skipped = []; $invalid = []; $limit = [];
$existing = count(glob(OUTBOX_DIR . '/*.xml') ?: []);
$newStatus = [];

foreach ($files as $f) {
  $label = $f['name'] !== '' ? basename($f['name']) : 'Datei';

  if ($f['error'] !== UPLOAD_ERR_OK)            { $invalid[] = ['file' => $label, 'grund' => 'Upload-Fehler']; continue; }
  if ($f['size'] <= 0 || $f['size'] > IMP_MAX_BYTES) { $invalid[] = ['file' => $label, 'grund' => 'Datei leer oder zu groß (max. 1 MB)']; continue; }
  if (!is_uploaded_file($f['tmp']))             { $invalid[] = ['file' => $label, 'grund' => 'Ungültiger Upload']; continue; }

  $content = (string)@file_get_contents($f['tmp']);
  if ($content === '') { $invalid[] = ['file' => $label, 'grund' => 'Datei leer']; continue; }

  // DTD/Entity-Konstrukte schon am Eingang ablehnen (XXE + Billion-Laughs).
  // EN-16931-Rechnungen enthalten niemals eine DTD oder Entity-Definitionen.
  if (preg_match('/<!DOCTYPE|<!ENTITY/i', $content)) {
    $invalid[] = ['file' => $label, 'grund' => 'Nicht erlaubte XML-Konstrukte (DTD/Entities)'];
    continue;
  }

  // XXE-sicher parsen: LIBXML_NONET unterbindet Netzwerkzugriff; keine Entity-Substitution
  libxml_use_internal_errors(true);
  $dom = new DOMDocument();
  if (!$dom->loadXML($content, LIBXML_NONET)) { $invalid[] = ['file' => $label, 'grund' => 'Kein gültiges XML']; continue; }

  $root      = $dom->documentElement;
  $rootLocal = $root ? (string)$root->localName : '';
  $rootNs    = $root ? (string)$root->namespaceURI : '';
  if ($rootLocal !== 'CrossIndustryInvoice' || $rootNs !== IMP_CII_NS) {
    $invalid[] = ['file' => $label, 'grund' => 'Kein XrechnungMega-Format (CrossIndustryInvoice erwartet)'];
    continue;
  }

  $xp = new DOMXPath($dom);
  $xp->registerNamespace('rsm', IMP_CII_NS);
  $xp->registerNamespace('ram', 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100');

  // Signatur: EN-16931-konforme Guideline (beide Erzeugungswege beginnen damit)
  $guideline = trim($xp->evaluate('string(/rsm:CrossIndustryInvoice/rsm:ExchangedDocumentContext/ram:GuidelineSpecifiedDocumentContextParameter/ram:ID)'));
  if (strpos($guideline, 'urn:cen.eu:en16931:2017') !== 0) {
    $invalid[] = ['file' => $label, 'grund' => 'Keine EN-16931-konforme Rechnung'];
    continue;
  }

  $id = trim($xp->evaluate('string(/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:ID)'));
  $rn = trim(preg_replace('/[^a-zA-Z0-9_-]/', '_', $id), '_-');
  if ($rn === '') { $invalid[] = ['file' => $label, 'grund' => 'Keine Rechnungsnummer in der Datei']; continue; }
  // Eigene Dateien tragen immer eine bereits bereinigte Nummer. Weicht die innere
  // ID vom Dateinamen ab, wären Status/Liste dauerhaft inkonsistent -> ablehnen.
  if ($rn !== $id) { $invalid[] = ['file' => $label, 'grund' => 'Rechnungsnummer enthält unzulässige Zeichen (kein XrechnungMega-Original)']; continue; }

  $target = rtrim(OUTBOX_DIR, '/\\') . DIRECTORY_SEPARATOR . $rn . '.xml';
  if (strtolower($rn) === 'vorlage' || is_file($target)) { $skipped[] = ['file' => $label, 'nr' => $rn]; continue; }

  if ($xrMax > 0 && $existing >= $xrMax) { $limit[] = ['file' => $label, 'nr' => $rn]; continue; }

  // Normalisiert speichern (gesäuberte, well-formed XML in den Ausgang)
  if ($dom->save($target) === false) { $invalid[] = ['file' => $label, 'grund' => 'Speichern fehlgeschlagen']; continue; }

  $existing++;
  $imported[] = ['file' => $label, 'nr' => $rn, 'xml' => $rn . '.xml'];

  $newStatus[$rn] = 'Offen';
}

if ($newStatus) {
  imp_status_rmw(function(array $map) use ($newStatus) {
    foreach ($newStatus as $rn => $st) { if (empty($map[$rn])) $map[$rn] = $st; }
    return $map;
  });
}

$last = $imported ? end($imported)['xml'] : '';
imp_json(200, [
  'ok'        => true,
  'imported'  => $imported,
  'skipped'   => $skipped,
  'invalid'   => $invalid,
  'limit'     => $limit,
  'limit_max' => $xrMax,
  'last'      => $last,
]);
