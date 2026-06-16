<?php
header('X-Content-Type-Options: nosniff');
require __DIR__ . '/auth_check.php';
require_once __DIR__ . '/config.php';

if (!isset($_GET['file'])) { http_response_code(400); exit('Datei nicht angegeben.'); }
$req = basename((string)$_GET['file']);
if (!preg_match('/^[\w.\-]+\.xml$/i',$req)) { http_response_code(400); exit('Ungültiger Dateiname.'); }

$baseAusgang = realpath(OUTBOX_DIR);
$baseRoot    = realpath(DATA_ROOT);
$path = ($req === 'vorlage.xml') ? realpath(TEMPLATE_FILE) : realpath($baseAusgang . DIRECTORY_SEPARATOR . $req);

if ($path === false || !is_file($path)) { http_response_code(404); exit('Datei nicht gefunden.'); }
if ($req !== 'vorlage.xml' && strpos($path, $baseAusgang) !== 0) { http_response_code(403); exit('Zugriff verweigert.'); }
if ($req === 'vorlage.xml' && strpos($path, $baseRoot) !== 0)    { http_response_code(403); exit('Zugriff verweigert.'); }

libxml_use_internal_errors(true);
$xml = new DOMDocument('1.0','UTF-8'); $xml->preserveWhiteSpace=false; $xml->formatOutput=true;
if (!$xml->load($path, LIBXML_NONET)) { http_response_code(500); exit('Fehler beim Laden der XML-Datei.'); }

$out = $xml->saveXML();
header('Content-Type: application/xml; charset=UTF-8');
header('Content-Disposition: attachment; filename="'.rawurlencode($req).'"; filename*=UTF-8\'\''.rawurlencode($req));
header('Content-Length: '.strlen($out));
header('Cache-Control: no-store, max-age=0');
echo $out; exit;
