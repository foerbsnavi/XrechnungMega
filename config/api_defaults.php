<?php
/**
 * Standard-Absender und Bankverbindung für API-Rechnungen (XrechnungMega)
 * Liegt im geschützten Ordner config/ (per .htaccess gegen HTTP gesperrt).
 *
 * Externe Projekte können diese Daten in ihrer Anfrage überschreiben.
 * Wenn sie es nicht tun, werden die hier hinterlegten Werte verwendet.
 *
 * → Einmal mit deinen eigenen Firmendaten ausfüllen, fertig.
 */
return [
    'absender' => [
        'name'    => '',        // Pflichtfeld – dein Name / deine Firma
        'adresse' => '',        // Straße und Hausnummer
        'plzOrt'  => '',        // PLZ Ort
        'telefon' => '',
        'email'   => '',        // Pflichtfeld (Seller-Endpoint)
        'ustid'   => '',        // Leer lassen, wenn keine USt-IdNr.
    ],
    'bankverbindung' => [
        'iban' => '',           // Pflichtfeld
        'bic'  => '',           // Optional
        'name' => '',           // Optional (fällt auf absender.name zurück)
        'bank' => '',           // Optional
    ],
    'payment_code' => '58',     // 58 = SEPA Überweisung (Standard)
];
