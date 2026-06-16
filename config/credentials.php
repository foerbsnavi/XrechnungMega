<?php
/**
 * Login-Zugangsdaten für XrechnungMega
 * Liegt im geschützten Ordner config/ (per .htaccess gegen HTTP gesperrt).
 *
 * Hier muss nichts von Hand eingetragen werden: Beim ERSTEN Aufruf führt die App
 * durch die Einrichtung (Benutzername + Passwort festlegen). Das Passwort wird
 * sicher als bcrypt-Hash gespeichert. Ändern später: in der App auf „Zugangsdaten".
 */
return [
    'username'      => 'admin',
    'password_hash' => '',   // leer = Erst-Einrichtung beim ersten Aufruf
];
