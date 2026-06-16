<?php
/**
 * XRechnungMega – REST-API
 *
 * Authentifizierung: Authorization: Bearer <api_key>
 * Alle Antworten: JSON, UTF-8
 *
 * Endpunkte:
 *   GET    api.php?action=list
 *   GET    api.php?action=get&id=2026_001
 *   POST   api.php?action=create           Body: JSON
 *   PUT    api.php?action=update&id=...    Body: JSON
 *   DELETE api.php?action=delete&id=...
 *   GET    api.php?action=download&id=...
 *   POST   api.php?action=status&id=...    Body: {"status":"Bezahlt"}
 */

declare(strict_types=1);

// ── Fehler als JSON ausgeben (Debug) ──────────────────────────────────────────
ob_start();
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR], true)) {
        ob_end_clean();
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8');
            http_response_code(500);
        }
        // Details nur ins Server-Log, nach außen generisch (keine Pfad-/Code-Infos)
        error_log('XrechnungMega API Fatal: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']);
        echo json_encode(['ok' => false, 'error' => 'Interner Serverfehler.'], JSON_UNESCAPED_UNICODE);
    } else {
        ob_end_flush();
    }
});

// ── Basis-Header ──────────────────────────────────────────────────────────────
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

// CORS – externe Projekte dürfen aufrufen
$allowedOrigin = '*'; // Für Produktion auf erlaubte Origins einschränken
header('Access-Control-Allow-Origin: ' . $allowedOrigin);
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept');
header('Access-Control-Max-Age: 3600');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

header('Content-Type: application/json; charset=UTF-8');

// ── Hilfsfunktionen ───────────────────────────────────────────────────────────
function api_resp(int $code, array $data): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}
function api_ok(array $data = []): void  { api_resp(200, array_merge(['ok' => true], $data)); }
function api_err(string $msg, int $code = 400, array $extra = []): void {
    api_resp($code, array_merge(['ok' => false, 'error' => $msg], $extra));
}

// ── Config & Core ─────────────────────────────────────────────────────────────
require_once __DIR__ . '/config.php';

require_once __DIR__ . '/invoice_core.php';

// ── Bearer-Key lesen ──────────────────────────────────────────────────────────
$apiKey = '';
$authHeader = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
    $apiKey = trim($m[1]);
}
if ($apiKey === '') {
    // Fallback: ?api_key= als Query-Parameter (für Testzwecke / Download-Links)
    $apiKey = trim((string)($_GET['api_key'] ?? ''));
}

// XR_PLAN_MAX_INVOICES: 0 = unbegrenzt (Standalone), sonst Plan-Limit (Plattform)
if (defined('XR_MODE') && XR_MODE === 'platform') {
    // ── Plattform: Key → Account (globaler Index), Mega-Plan + aktiv prüfen ──
    $entry = function_exists('apikeys_index_get') ? apikeys_index_get($apiKey) : null;
    if (!$entry || ($entry['enabled'] ?? true) === false) api_err('Ungültiger oder inaktiver API-Key', 401);
    $apiUser = load_user((string)($entry['uid'] ?? ''));
    if (!$apiUser || (string)($apiUser['status'] ?? '') !== 'ok' || !user_freigeschaltet($apiUser)) {
        api_err('Account inaktiv oder gesperrt', 403);
    }
    if (!plan_allows_api($apiUser)) {
        api_err('Die REST-API ist dem Mega-Plan vorbehalten.', 403, ['plan' => plan_label((string)($apiUser['plan'] ?? 'Basic'))]);
    }
    $uid = (string)$apiUser['id'];
    if (!defined('DATA_ROOT'))     define('DATA_ROOT', user_dir($uid) . '/rechnungen');
    if (!defined('OUTBOX_DIR'))    define('OUTBOX_DIR', DATA_ROOT . '/ausgang');
    if (!defined('TEMPLATE_FILE')) define('TEMPLATE_FILE', DATA_ROOT . '/vorlage.xml');
    if (!defined('STATUS_FILE'))   define('STATUS_FILE', DATA_ROOT . '/status.json');
    if (!is_dir(OUTBOX_DIR)) @mkdir(OUTBOX_DIR, 0775, true);
    if (!is_file(TEMPLATE_FILE) && is_file(__DIR__ . '/daten/vorlage.xml')) @copy(__DIR__ . '/daten/vorlage.xml', TEMPLATE_FILE);
    define('XR_PLAN_MAX_INVOICES', (int)plan_limits((string)($apiUser['plan'] ?? 'Basic'))['max_invoices']);
    // Absender-Standard aus den Firmendaten des Nutzers
    $fd = app_json_read(user_dir($uid) . '/firmendaten.json', []);
    $apiDefaults = [
        'absender' => ['name' => $fd['name'] ?? '', 'adresse' => $fd['adresse'] ?? '', 'plzOrt' => trim(($fd['plz'] ?? '') . ' ' . ($fd['ort'] ?? '')), 'telefon' => $fd['telefon'] ?? '', 'email' => $fd['email'] ?? '', 'ustid' => $fd['ustid'] ?? ''],
        'bankverbindung' => ['iban' => $fd['iban'] ?? '', 'bic' => $fd['bic'] ?? '', 'name' => ($fd['bank_inhaber'] ?? '') !== '' ? $fd['bank_inhaber'] : ($fd['name'] ?? ''), 'bank' => $fd['bank'] ?? ''],
        'payment_code' => '58',
    ];
} else {
    // ── Standalone: globale Keys + api_defaults wie gehabt ──
    $keysFile = __DIR__ . '/config/api_keys.php';
    if (!is_file($keysFile)) api_err('API-Keys nicht konfiguriert', 503);
    $allKeys = require $keysFile;
    if ($apiKey === '' || !isset($allKeys[$apiKey]) || !($allKeys[$apiKey]['active'] ?? false)) {
        api_err('Ungültiger oder inaktiver API-Key', 401);
    }
    $defaultsFile = __DIR__ . '/config/api_defaults.php';
    $apiDefaults  = is_file($defaultsFile) ? (array)(require $defaultsFile) : [];
    define('XR_PLAN_MAX_INVOICES', 0);
}

// ── Request-Body parsen ───────────────────────────────────────────────────────
$rawBody = (string)file_get_contents('php://input');
$body    = ($rawBody !== '') ? (json_decode($rawBody, true) ?? []) : [];
$method  = $_SERVER['REQUEST_METHOD'];
$action  = trim((string)($_GET['action'] ?? ''));

// ── Router ────────────────────────────────────────────────────────────────────
match ($action) {
    'list'     => api_list(),
    'get'      => api_get_invoice(),
    'create'   => api_create(),
    'update'   => api_update(),
    'delete'   => api_delete(),
    'download' => api_download(),
    'status'   => api_status(),
    default    => api_err(
        'Unbekannte Aktion. Verfügbar: list, get, create, update, delete, download, status',
        400,
        ['actions' => ['list', 'get', 'create', 'update', 'delete', 'download', 'status']]
    ),
};

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

function api_list(): void {
    $files     = glob(OUTBOX_DIR . '/*.xml') ?: [];
    $statusMap = xr_load_status();
    $result    = [];

    foreach ($files as $path) {
        [$id, $iso, $emp, $sum, $typ] = xr_invoice_list_meta($path);
        $filename = basename($path);
        $key      = preg_replace('/\.xml$/i', '', $id !== '' ? $id : $filename);
        $result[] = [
            'id'         => $id !== '' ? $id : preg_replace('/\.xml$/i', '', $filename),
            'file'       => $filename,
            'datum'      => $iso,
            'empfaenger' => $emp,
            'betrag'     => (float)$sum,
            'typ'        => $typ,
            'status'     => $statusMap[$key] ?? 'Offen',
            'geaendert'  => date('c', (int)filemtime($path)),
        ];
    }

    // Sortierung: neueste zuerst
    usort($result, fn($a, $b) => strcmp((string)$b['datum'], (string)$a['datum']));

    api_ok(['rechnungen' => $result, 'anzahl' => count($result)]);
}

// ─────────────────────────────────────────────────────────────────────────────

function api_get_invoice(): void {
    $id   = get_id_param();
    $path = resolve_invoice_path($id);

    $data = xr_parse_invoice($path);
    if ($data === null) api_err('Rechnung konnte nicht gelesen werden', 500);

    $statusMap = xr_load_status();
    $data['status'] = $statusMap[$id] ?? 'Offen';
    $data['file']   = basename($path);

    api_ok(['rechnung' => $data]);
}

// ─────────────────────────────────────────────────────────────────────────────

function api_create(): void {
    global $body, $method, $apiDefaults;

    if ($method !== 'POST') api_err('Methode muss POST sein', 405);

    $data = merge_with_defaults($body, $apiDefaults);

    validate_required_fields($data);

    $rn      = trim(preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)($data['rechnungsnummer'] ?? '')), '_-');
    $outFile = rtrim(OUTBOX_DIR, '/\\') . DIRECTORY_SEPARATOR . $rn . '.xml';

    if (is_file($outFile) && empty($data['ueberschreiben'])) {
        api_err('Rechnung mit dieser Nummer existiert bereits', 409, [
            'file'   => $rn . '.xml',
            'hinweis' => 'Sende "ueberschreiben": true um zu überschreiben'
        ]);
    }

    // Plan-Limit (Plattform): neue Rechnung nur, wenn das Kontingent reicht
    if (!is_file($outFile) && defined('XR_PLAN_MAX_INVOICES') && XR_PLAN_MAX_INVOICES > 0) {
        $anzahl = count(glob(OUTBOX_DIR . '/*.xml') ?: []);
        if ($anzahl >= XR_PLAN_MAX_INVOICES) {
            api_err('Rechnungs-Limit deines Plans erreicht (' . XR_PLAN_MAX_INVOICES . ').', 403,
                ['limit' => XR_PLAN_MAX_INVOICES, 'anzahl' => $anzahl]);
        }
    }

    $result = xr_build_invoice($data, $outFile);
    if (!$result['ok']) api_err($result['error']);

    // Status auf Entwurf setzen (Standard für neue Rechnungen via API)
    $statusMap = xr_load_status();
    $statusMap[$rn] = $data['status'] ?? 'Entwurf';
    xr_save_status($statusMap);

    api_ok([
        'nachricht' => 'Rechnung erstellt',
        'id'        => $result['id'],
        'file'      => $result['file'],
        'status'    => $statusMap[$rn],
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────

function api_update(): void {
    global $body, $method, $apiDefaults;

    if ($method !== 'PUT' && $method !== 'POST') api_err('Methode muss PUT sein', 405);

    $id      = get_id_param();
    $oldPath = resolve_invoice_path($id);

    $data = merge_with_defaults($body, $apiDefaults);
    // Rechnungsnummer aus URL übernehmen, wenn nicht im Body
    if (empty($data['rechnungsnummer'])) $data['rechnungsnummer'] = $id;

    validate_required_fields($data);

    $newRn   = trim(preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$data['rechnungsnummer']), '_-');
    $outFile = rtrim(OUTBOX_DIR, '/\\') . DIRECTORY_SEPARATOR . $newRn . '.xml';

    // Alte Datei löschen wenn Nummer geändert
    if ($newRn !== $id && is_file($oldPath)) {
        @unlink($oldPath);
        // Status-Eintrag umbenennen
        $statusMap = xr_load_status();
        if (isset($statusMap[$id])) {
            $statusMap[$newRn] = $statusMap[$id];
            unset($statusMap[$id]);
            xr_save_status($statusMap);
        }
    }

    $result = xr_build_invoice($data, $outFile);
    if (!$result['ok']) api_err($result['error']);

    api_ok([
        'nachricht' => 'Rechnung aktualisiert',
        'id'        => $result['id'],
        'file'      => $result['file'],
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────

function api_delete(): void {
    global $method;

    if ($method !== 'DELETE' && $method !== 'POST') api_err('Methode muss DELETE sein', 405);

    $id   = get_id_param();
    $path = resolve_invoice_path($id);

    if (!@unlink($path)) api_err('Löschen fehlgeschlagen', 500);

    // Aus Status entfernen
    $statusMap = xr_load_status();
    unset($statusMap[$id]);
    xr_save_status($statusMap);

    api_ok(['nachricht' => 'Rechnung gelöscht', 'id' => $id]);
}

// ─────────────────────────────────────────────────────────────────────────────

function api_download(): void {
    $id   = get_id_param();
    $path = resolve_invoice_path($id);

    // Content-Type für XML – header() überschreibt die JSON-Header von oben
    header_remove('Content-Type');
    header('Content-Type: application/xml; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────

function api_status(): void {
    global $body, $method;

    if ($method !== 'POST') api_err('Methode muss POST sein', 405);

    $id     = get_id_param();
    resolve_invoice_path($id); // Existenzcheck

    $allowed = ['Offen', 'Erinnerung gesendet', 'Bezahlt', 'Problem', 'Entwurf'];
    $status  = trim((string)($body['status'] ?? ''));

    if (!in_array($status, $allowed, true)) {
        api_err('Ungültiger Status. Erlaubt: ' . implode(', ', $allowed), 400, ['erlaubt' => $allowed]);
    }

    $statusMap     = xr_load_status();
    $statusMap[$id] = $status;

    if (!xr_save_status($statusMap)) api_err('Status konnte nicht gespeichert werden', 500);

    api_ok(['nachricht' => 'Status aktualisiert', 'id' => $id, 'status' => $status]);
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// Interne Hilfsfunktionen

function get_id_param(): string {
    $id = trim(preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_GET['id'] ?? '')));
    if ($id === '') api_err('Parameter "id" fehlt oder ungültig', 400);
    return $id;
}

function resolve_invoice_path(string $id): string {
    $base = realpath(OUTBOX_DIR);
    if ($base === false) api_err('Rechnungsverzeichnis nicht gefunden', 500);

    $path = realpath($base . DIRECTORY_SEPARATOR . $id . '.xml');
    if ($path === false || strpos($path, $base) !== 0 || !is_file($path)) {
        api_err('Rechnung "' . $id . '" nicht gefunden', 404);
    }
    return $path;
}

/**
 * Führt API-Body mit Standardwerten zusammen.
 * Body-Werte haben Vorrang vor Defaults.
 */
function merge_with_defaults(array $body, array $defaults): array {
    // Absender: Default überschreibbar
    if (empty($body['absender']) && isset($defaults['absender'])) {
        $body['absender'] = $defaults['absender'];
    } elseif (isset($defaults['absender'])) {
        $body['absender'] = array_merge($defaults['absender'], (array)$body['absender']);
    }

    // Bankverbindung: Default überschreibbar
    if (empty($body['bankverbindung']) && isset($defaults['bankverbindung'])) {
        $body['bankverbindung'] = $defaults['bankverbindung'];
    } elseif (isset($defaults['bankverbindung'])) {
        $body['bankverbindung'] = array_merge($defaults['bankverbindung'], (array)$body['bankverbindung']);
    }

    // Payment Code Default
    if (empty($body['payment_code']) && isset($defaults['payment_code'])) {
        $body['payment_code'] = $defaults['payment_code'];
    }

    return $body;
}

function validate_required_fields(array $data): void {
    if (empty($data['rechnungsnummer']))           api_err('rechnungsnummer fehlt');
    if (empty($data['rechnungsdatum']))            api_err('rechnungsdatum fehlt (Format: YYYY-MM-DD)');
    if (empty($data['faelligkeitsdatum']))         api_err('faelligkeitsdatum fehlt (Format: YYYY-MM-DD)');
    if (empty($data['empfaenger']['email']))       api_err('empfaenger.email fehlt');
    if (empty($data['empfaenger']['name']))        api_err('empfaenger.name fehlt');
    if (empty($data['positionen']) || !is_array($data['positionen']) || count($data['positionen']) === 0)
        api_err('positionen fehlt oder leer');
    if (empty($data['absender']['name']))          api_err('absender.name fehlt (oder api_defaults.php konfigurieren)');
    if (empty($data['absender']['email']))         api_err('absender.email fehlt (oder api_defaults.php konfigurieren)');
    if (empty($data['bankverbindung']['iban']))    api_err('bankverbindung.iban fehlt (oder api_defaults.php konfigurieren)');
}
