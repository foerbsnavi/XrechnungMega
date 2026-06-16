<?php
/**
 * API-Keys für XrechnungMega
 * Liegt im geschützten Ordner config/ (per .htaccess gegen HTTP gesperrt).
 *
 * Jeden angebundenen Dienst mit einem eigenen Key einrichten.
 * Key-Format: Empfohlen sind zufällige Strings ≥ 32 Zeichen.
 *
 * Neuen Key generieren (Node.js):
 *   node -e "console.log('xrm_'+require('crypto').randomBytes(24).toString('hex'))"
 *
 * Felder:
 *   name   – Bezeichnung des Projekts (nur für Dokumentation)
 *   active – true = aktiv, false = gesperrt
 */
return [

    // Beispiel-Key (bitte ersetzen oder deaktivieren!)
    'xrm_demokey_bitte_ersetzen_12345678' => [
        'name'   => 'Demo-Key (deaktiviert)',
        'active' => false,
    ],

];
