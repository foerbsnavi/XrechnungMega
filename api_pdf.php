<?php
/**
 * api_pdf.php – PDF-Download via API-Key
 *
 * GET api_pdf.php?type=pferd|xr&id=EK_2026_011
 * Auth: Authorization: Bearer <api_key>  oder  ?api_key=<api_key>
 *
 * Antwort: application/pdf (Datei) oder application/json (Fehler)
 */
declare(strict_types=1);

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

require_once __DIR__ . '/config.php';

// ── Auth & Datenkontext ───────────────────────────────────────────────────────
function apdf_err(int $code, string $msg): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}
$apiKey = '';
$authHeader = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
    $apiKey = trim($m[1]);
}
if ($apiKey === '') {
    $apiKey = trim((string)($_GET['api_key'] ?? ''));
}

if (defined('XR_MODE') && XR_MODE === 'platform') {
    // Plattform: Key → Account, Mega + aktiv prüfen, Datenkontext setzen
    $entry = function_exists('apikeys_index_get') ? apikeys_index_get($apiKey) : null;
    if (!$entry || ($entry['enabled'] ?? true) === false) apdf_err(401, 'Ungültiger oder inaktiver API-Key');
    $apiUser = load_user((string)($entry['uid'] ?? ''));
    if (!$apiUser || (string)($apiUser['status'] ?? '') !== 'ok' || !user_freigeschaltet($apiUser)) apdf_err(403, 'Account inaktiv oder gesperrt');
    if (!plan_allows_api($apiUser)) apdf_err(403, 'Die API ist dem Mega-Plan vorbehalten.');
    $uid = (string)$apiUser['id'];
    if (!defined('DATA_ROOT'))     define('DATA_ROOT', user_dir($uid) . '/rechnungen');
    if (!defined('OUTBOX_DIR'))    define('OUTBOX_DIR', DATA_ROOT . '/ausgang');
    if (!defined('TEMPLATE_FILE')) define('TEMPLATE_FILE', DATA_ROOT . '/vorlage.xml');
    if (!defined('STATUS_FILE'))   define('STATUS_FILE', DATA_ROOT . '/status.json');
} else {
    // Standalone: globale Keys
    $keysFile = __DIR__ . '/config/api_keys.php';
    if (!is_file($keysFile)) apdf_err(503, 'API-Keys nicht konfiguriert');
    $allKeys = require $keysFile;
    if ($apiKey === '' || !isset($allKeys[$apiKey]) || !($allKeys[$apiKey]['active'] ?? false)) apdf_err(401, 'Ungültiger oder inaktiver API-Key');
}

// ── Parameter ─────────────────────────────────────────────────────────────────
$type = trim((string)($_GET['type'] ?? ''));
$id   = trim(preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_GET['id'] ?? '')));

if (!in_array($type, ['pferd', 'xr'], true) || $id === '') {
    http_response_code(400);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'error' => 'Parameter "type" (pferd|xr) und "id" erforderlich']);
    exit;
}

// ── Datei auflösen ────────────────────────────────────────────────────────────
$base = realpath(OUTBOX_DIR);
if ($base === false) {
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'error' => 'Rechnungsverzeichnis nicht gefunden']);
    exit;
}
$xmlPath = realpath($base . DIRECTORY_SEPARATOR . $id . '.xml');
if ($xmlPath === false || strpos($xmlPath, $base) !== 0 || !is_file($xmlPath)) {
    http_response_code(404);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'error' => 'Rechnung "' . $id . '" nicht gefunden']);
    exit;
}

// ── PDF generieren ────────────────────────────────────────────────────────────
// $_GET['xml'] muss NICHT gesetzt sein, damit der Web-Modus der Generatoren nicht ausgeführt wird
unset($_GET['xml']);

if ($type === 'pferd') {
    require_once __DIR__ . '/pdf_generator_pferd.php';
    $pdfPath  = preg_replace('/\.xml$/i', '_fx.pdf', $xmlPath);
    $ok       = facturx_pdf_en16931($xmlPath, $pdfPath);
    $filename = $id . '_zugferd.pdf';
} else {
    require_once __DIR__ . '/pdf_generator_x.php';
    $pdfPath  = preg_replace('/\.xml$/i', '.pdf', $xmlPath);
    $ok       = facturx_pdf($xmlPath, $pdfPath, basename($xmlPath));
    $filename = $id . '_xrechnung.pdf';
}

if ($ok === false || !is_file($pdfPath)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'error' => 'PDF konnte nicht erstellt werden']);
    exit;
}

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
header('Content-Length: ' . filesize($pdfPath));
header('Cache-Control: no-store, max-age=0');
readfile($pdfPath);
exit;
