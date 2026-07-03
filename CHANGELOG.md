# Changelog

Alle Versionen von XrechnungMega.

## 1.0.10 (03.07.2026)

- Behoben: Rechnungen mit einem Rabatt (Nachlass) werden jetzt vollständig normkonform nach XRechnung (EN 16931) erzeugt — der Rabatt wird als eigener Abschlag ausgewiesen und die ausgewiesene Steuerbasis stimmt mit dem Rechnungsbetrag überein. Zuvor konnten rabattierte Rechnungen bei der amtlichen Prüfung (KoSIT) abgewiesen werden. Betrifft die Rechnungserstellung über die REST-API im Selbst-Hosting.
- Behoben: Bei Korrektur- und Stornorechnungen stand der Verweis auf die ursprüngliche Rechnung an der falschen Stelle im XML — die Reihenfolge entspricht jetzt dem XRechnung-Standard.

## 1.0.9 (01.07.2026)

- Behoben: Sonderzeichen wie „&“, „<“ oder „>“ in der Positionsbeschreibung – und in weiteren Textfeldern wie Namen, Adressen oder der Rechnungs-Notiz – wurden bisher beim Speichern ohne Hinweis abgeschnitten. Jetzt werden sie korrekt gespeichert und bleiben in XML und PDF vollständig erhalten.
- Sicherheit & Konformität: Die E-Mail-Adressen von Empfänger und Absender werden nun auf ein gültiges Format geprüft, damit erzeugte Rechnungen zuverlässig dem XRechnung-Standard (EN 16931) entsprechen.
- Technische Härtung: Das interne Sicherheits-Token wird nicht mehr über die Adresszeile übertragen, und der Schutz beim Aufräumen alter Rechnungsdateien wurde verschärft.

## 1.0.8 (16.06.2026)

- Neu: XML-Import — über „⬆ XML importieren“ in der Rechnungsübersicht lassen sich zuvor mit XrechnungMega erstellte Rechnungen (XML) wieder einlesen, auch mehrere auf einmal. Bereits vorhandene Rechnungsnummern werden übersprungen, der Plan-Rahmen wird beachtet. Es werden ausschließlich eigene XrechnungMega-Dateien akzeptiert.

## 1.0.7 (16.06.2026)

- Neu: API-Schlüssel können jetzt ein Präfix für Rechnungsnummern tragen (z. B. „EK“ → EK_2026_0001). So lassen sich Rechnungen verschiedener Projekte oder Anbindungen eindeutig kennzeichnen und sortieren. Das Präfix wird automatisch vorangestellt, falls es noch fehlt — bestehende Anbindungen funktionieren unverändert weiter.
- Verbesserung: Das Tool ist jetzt für Mobilgeräte optimiert — Rechnungsübersicht, Editor, Einstellungen und API-Verwaltung lassen sich auch auf dem Smartphone bequem bedienen. Die Desktop-Ansicht sowie die PDF- und Druckausgabe bleiben unverändert.

## 1.0.6 (16.06.2026)

- Sicherheit (Selbst-Hosting): Erst-Einrichtung beim ersten Start statt Standard-Passwort; Passwörter werden als bcrypt-Hash gespeichert; Login mit CSRF- und Brute-Force-Schutz.
- Vorbereitung der Open-Source-Veröffentlichung (README, Changelog, MIT).

## 1.0.5 (16.06.2026)

- Aufgeräumt: Der Werbe-Link im Fußbereich der App wurde entfernt.

## 1.0.4 (16.06.2026)

- Neu: REST-API auch im Online-Account — verfügbar im Mega-Plan, mit eigenen API-Schlüsseln pro Account (Zugriff nur auf die eigenen Rechnungen).
- Plan-Limits aktiv: Basic 10, Pro 100, Mega 1000 Rechnungen; API-Anbindung im Mega-Plan.

## 1.0.3 (16.06.2026)

- Neu: Globale Einstellungen — hinterlege deine Firmendaten (Absender + Bankverbindung) einmal unter ⚙ Einstellungen; sie werden als Absender in neue Rechnungen übernommen.

## 1.0.2 (16.06.2026)

- Technische Verbesserung: Änderungen am Erscheinungsbild werden im Browser jetzt sofort sichtbar (Versionierung der Stildatei).

## 1.0.1 (16.06.2026)

- Oberfläche: Der überflüssige Rand um den gesamten Inhalt wurde entfernt — die App nutzt jetzt die volle Breite, mit angenehmem Innenabstand.

## 1.0.0 (16.06.2026)

- Erste öffentliche Version von XrechnungMega: elektronische Rechnungen nach XRechnung-Standard (EN 16931) erstellen und verwalten, als XML und als PDF ausgeben (inklusive Hybrid-PDF mit eingebettetem XML), Bezahlstatus verwalten und über eine REST-API anbinden.
- Open Source (MIT): das frühere Lizenz-Key-System wurde vollständig entfernt — die App ist frei nutzbar und selbst hostbar.
- Persönliche Beispiel-/Absenderdaten entfernt; Auslieferung mit neutralen Vorlagen.
