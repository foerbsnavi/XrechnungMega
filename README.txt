XrechnungMega
=============

Elektronische Rechnungen nach dem deutschen Standard XRechnung (EN 16931)
erstellen, verwalten und versenden. Erzeugt normkonformes Rechnungs-XML und
PDF-Belege (auch als Hybrid-PDF mit eingebettetem XML), verwaltet eine
Rechnungsübersicht mit Bezahlstatus und bietet eine REST-API.

Open Source (MIT). Läuft auf jedem PHP-8.1-Webspace. Keine Datenbank nötig –
die Daten liegen als Dateien.


INSTALLATION (Selbst-Hosting)
-----------------------------
1. Den Inhalt dieses Ordners per FTP auf den Webspace laden.
2. Den Document-Root der Domain auf diesen Ordner zeigen lassen
   (er enthält index.php). Die Ordner config/ und daten/ sind per .htaccess
   gegen direkten Zugriff geschützt.
3. Schreibrechte für den Ordner daten/ sicherstellen.
4. Die Domain im Browser aufrufen.

Voraussetzungen: PHP 8.1+, Apache mit mod_rewrite, ext-dom, ext-curl.


ERSTER START — EINRICHTUNG
--------------------------
Beim ersten Aufruf führt die App durch die Einrichtung: Benutzername und
Passwort festlegen. Es gibt KEIN voreingestelltes Passwort (das Passwort wird
sicher als bcrypt-Hash gespeichert). Passwort später ändern: in der App oben
rechts auf „Zugangsdaten".


EIGENE FIRMENDATEN
------------------
- In der App unter „⚙ Einstellungen": Absender + Bankverbindung einmal eintragen
  (wird in neue Rechnungen übernommen und von der REST-API als Standard genutzt).
- config/api_keys.php : API-Keys für externe Anbindungen (über „🔑 API" in der App).

Diese Dateien werden bei einem Update NICHT überschrieben.


PROJEKT
-------
Webseite: https://xrechnung.brosemedien.de
Lizenz:   MIT (siehe LICENSE), Drittbestandteile siehe LIZENZEN.txt
