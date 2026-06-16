<?php
// XrechnungMega — Pfad-/Modus-Konfiguration. EIN Core, zwei Modi:
//   - PLATTFORM: läuft als Engine der Account-App (Erkennung: ../core.php existiert).
//     Jeder eingeloggte Nutzer hat einen eigenen Datenbereich
//     (app_daten/{uid}/rechnungen). Login/Absender/Daten kommen aus dem Account.
//   - STANDALONE: Selbst-Hosting, gemeinsamer daten/-Ordner, eigener Passwort-Login.

if (!defined('XR_MODE')) {
    $xrPlatformCore = __DIR__ . '/../core.php';
    if (is_file($xrPlatformCore)) {
        define('XR_MODE', 'platform');
        require_once $xrPlatformCore;
        $xrU = function_exists('current_user') ? current_user() : null;
        $xrOk = is_array($xrU)
            && (string)($xrU['status'] ?? '') === 'ok'
            && function_exists('user_freigeschaltet') && user_freigeschaltet($xrU);
        if ($xrOk) {
            define('XR_UID', (string)$xrU['id']);
            define('DATA_ROOT', user_dir(XR_UID) . '/rechnungen');
        } else {
            // Kein gültiger Account in dieser Session (z. B. API-Client ohne
            // Session oder ausgeloggt). DATA_ROOT bleibt undefiniert; auth_check
            // bzw. der API-Guard im jeweiligen Endpunkt fängt das ab.
            define('XR_UID', '');
        }
    } else {
        define('XR_MODE', 'standalone');
        $xrRoot = realpath(__DIR__ . '/daten');
        if ($xrRoot === false) { http_response_code(500); exit('DATA_ROOT fehlt'); }
        define('DATA_ROOT', $xrRoot);
    }
}

if (defined('DATA_ROOT')) {
    if (!defined('OUTBOX_DIR'))    define('OUTBOX_DIR', DATA_ROOT . '/ausgang');
    if (!defined('TEMPLATE_FILE')) define('TEMPLATE_FILE', DATA_ROOT . '/vorlage.xml');
    if (!defined('STATUS_FILE'))   define('STATUS_FILE', DATA_ROOT . '/status.json');
    // Globale Einstellungen (Firmendaten): platform pro Nutzer
    // (app_daten/{uid}/firmendaten.json), standalone in config/.
    if (!defined('FIRMENDATEN_FILE')) {
        define('FIRMENDATEN_FILE', XR_MODE === 'platform'
            ? dirname(DATA_ROOT) . '/firmendaten.json'
            : __DIR__ . '/config/firmendaten.json');
    }
    if (!is_dir(OUTBOX_DIR) && !@mkdir(OUTBOX_DIR, 0775, true)) {
        http_response_code(500); exit('OUTBOX_DIR nicht anlegbar');
    }
    // Plattform: jedem Nutzer eine eigene Rechnungs-Vorlage seeden (aus der
    // mitgelieferten Engine-Vorlage), falls noch keine vorhanden.
    if (XR_MODE === 'platform' && !is_file(TEMPLATE_FILE)) {
        $xrSeed = __DIR__ . '/daten/vorlage.xml';
        if (is_file($xrSeed)) @copy($xrSeed, TEMPLATE_FILE);
    }
}
