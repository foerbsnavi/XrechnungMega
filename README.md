# XrechnungMega

**Elektronische Rechnungen nach dem deutschen Standard XRechnung (EN 16931) erstellen, verwalten und versenden.**

![Lizenz: MIT](https://img.shields.io/badge/Lizenz-MIT-green) ![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-blue) ![Keine Datenbank](https://img.shields.io/badge/Datenbank-keine-orange)

XrechnungMega erzeugt normkonformes Rechnungs-XML (UBL/CII) und dazu PDF-Belege —
auf Wunsch als Hybrid-PDF mit eingebettetem XML (ZUGFeRD-/Factur-X-Prinzip). Es
verwaltet eine Rechnungsübersicht mit Bezahlstatus und bietet eine REST-API.

**Website & Online-Version:** https://xrechnung.brosemedien.de

Kein Framework, **keine Datenbank**, keine Build-Tools — nur PHP und Dateien.
Backup heißt: Ordner kopieren.

---

## Funktionen

* **Normkonforme XRechnung** nach EN 16931 (Profil XRechnung 3.0), UBL → CII
* **PDF-Belege**, auf Wunsch als Hybrid-PDF mit eingebettetem XML
* **Rechnungsübersicht** mit Bezahlstatus und Sofort-Filter
* **REST-API** (Bearer-Token) zum Anlegen, Abrufen und als PDF ziehen
* Läuft auf jedem PHP-8.1-Webspace, ohne Installer und ohne Datenbank

---

## Installation (Selbst-Hosting)

1. **Herunterladen:** [aktuelles ZIP](https://xrechnung.brosemedien.de/files/xrechnung_latest.zip) (oder dieses Repo klonen) und entpacken.
2. **Hochladen:** Den Inhalt per FTP auf den Webspace laden und den Document-Root
   der Domain auf den Ordner zeigen lassen (er enthält die `index.php`).
   Die Ordner `config/` und `daten/` sind per `.htaccess` gegen direkten Zugriff geschützt.
3. **Schreibrechte** für den Ordner `daten/` (und `config/`) sicherstellen.
4. **Aufrufen:** Beim ersten Besuch führt die App durch die **Einrichtung**
   (Benutzername + Passwort festlegen) — es gibt kein voreingestelltes Passwort.

Voraussetzungen: PHP 8.1+, Apache mit mod_rewrite, `ext-dom`, `ext-curl`.

### Eigene Firmendaten

Unter **⚙ Einstellungen** hinterlegst du Absender und Bankverbindung einmal —
sie werden in neue Rechnungen übernommen und dienen der REST-API als Standard.

---

## REST-API (Kurzüberblick)

Basis-URL `…/api.php`, Header `Authorization: Bearer <key>`. Keys verwaltest du
unter **🔑 API**.

| Methode | Endpunkt | Zweck |
|---|---|---|
| GET | `?action=list` | Rechnungen auflisten |
| GET | `?action=get&id=…` | Rechnung abrufen |
| POST | `?action=create` | Rechnung anlegen (JSON-Body) |
| PUT | `?action=update&id=…` | Rechnung aktualisieren |
| DELETE | `?action=delete&id=…` | Rechnung löschen |
| GET | `?action=download&id=…` | XML herunterladen |
| POST | `?action=status&id=…` | Bezahlstatus setzen |

PDF: `…/api_pdf.php?type=xr|pferd&id=…`

---

## Online statt selbst hosten?

Auf https://xrechnung.brosemedien.de gibt es eine gehostete Variante — eine
kleine, persönlich betreute Plattform mit Accounts (Freischaltung nach Absprache).

---

## Lizenz

[MIT](LICENSE) — frei nutzen, anpassen und weitergeben, auch kommerziell.
Drittbestandteile siehe [LIZENZEN.txt](LIZENZEN.txt) (u. a. die optional von
einem externen Server geladene Schrift „Brose"; ohne sie greift ein Helvetica-Fallback).
