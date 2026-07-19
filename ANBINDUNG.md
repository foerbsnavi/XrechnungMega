# XrechnungMega – Ein neues Projekt anbinden

> **ENTWURF (windows, 2026-07-19)** – wird nach der Live-Verifikation + Foerbs GO finalisiert.
> Diese Anleitung beschreibt, wie ein beliebiges Projekt (Shop, SaaS, interne App)
> über die REST-API automatisch Rechnungen in XrechnungMega erzeugt.

Ziel: Ein neues Projekt soll in **wenigen Schritten** andocken können.

---

## 1. Voraussetzungen auf der XrechnungMega-Seite (EINMALIG)

Diese drei Dinge müssen **vor dem ersten API-Call** erledigt sein — sonst schlägt
das Rechnung-Anlegen fehl:

1. **Zugang mit API-Recht.**
   - *Gehostet* (xrechnung.brosemedien.de): ein Account mit **Mega-Plan** (die REST-API ist dem Mega-Plan vorbehalten).
   - *Selbst-Hosting*: die API ist ohne Limit verfügbar.
2. **Firmendaten vollständig hinterlegen** unter **⚙ Einstellungen**: Absender
   (Name, Adresse, PLZ/Ort, E-Mail) **und Bankverbindung mit gültiger IBAN**.
   → Diese Daten werden automatisch als Absender/Bank in jede API-Rechnung
   übernommen. **Fehlt die IBAN oder ist sie ungültig, bricht *jede*
   Rechnungserstellung** mit `{"ok":false,"error":"…iban…"}`. Das ist der
   häufigste Anbindungsfehler.
3. **API-Key anlegen** unter **🔑 API**: „+ Neuen Key generieren", Projektname
   vergeben, optional ein **Präfix** setzen (z. B. `EK` → Rechnungsnummern
   `EK_2026_0001`). Key **sofort kopieren** (wird nur einmal angezeigt) und im
   Projekt als Geheimnis hinterlegen (Env-Variable, nicht im Code/Repo).

---

## 2. API-Grundlagen

- **Basis-URL:** `https://<deine-instanz>/app/xrechnung/api.php`
- **PDF-Endpunkt:** `…/api_pdf.php?type=xr|pferd&id=<nr>`
- **Auth:** Header `Authorization: Bearer <api_key>` bei **jedem** Call.
- **Antworten:** immer JSON, `{"ok":true,…}` oder `{"ok":false,"error":"…"}`.

| Methode | Endpunkt | Zweck |
|---|---|---|
| POST | `?action=create` | Rechnung anlegen (JSON-Body) |
| GET | `?action=list` | Alle Rechnungen (id, status, betrag, …) |
| GET | `?action=get&id=…` | Eine Rechnung vollständig |
| POST | `?action=status&id=…` | Bezahlstatus setzen (`{"status":"Bezahlt"}`) |
| GET | `?action=download&id=…` | XML herunterladen |
| GET | `api_pdf.php?type=xr\|pferd&id=…` | PDF (rein / Hybrid-ZUGFeRD) |
| PUT | `?action=update&id=…` | Rechnung ändern |
| DELETE | `?action=delete&id=…` | Rechnung löschen |

**Rechnung anlegen – Minimal-Body** (Absender/Bank kommen aus den Einstellungen):

```json
{
  "rechnungsnummer":  "2026_005",
  "rechnungsdatum":   "2026-03-12",
  "faelligkeitsdatum":"2026-03-26",
  "empfaenger": { "name":"Kunde GmbH", "adresse":"Kundenstr. 5",
                  "plzOrt":"54321 Kundenstadt", "email":"buchhaltung@kunde.de" },
  "positionen": [ { "beschreibung":"Leistung März", "menge":1,
                    "einzelpreis":150.00, "einheit":"LS" } ],
  "status": "Offen"
}
```

Pflichtfelder: `rechnungsnummer`, `rechnungsdatum`, `faelligkeitsdatum`,
`empfaenger.name`, `empfaenger.email`, mindestens eine Position — **plus** die
serverseitigen Defaults (Absender-Name/-E-Mail, Bank-IBAN, siehe 1.2).
`einzelpreis` ist **netto**; die MwSt (19 %) rechnet der Server dazu.

**Antwort:** `{"ok":true,"id":"EK_2026_0005","file":"…","status":"…"}`.
→ **Merke dir die zurückgegebene `id`, nicht deine gesendete Nummer** (der Server
kann ein Präfix ergänzt haben). Für status/download/lookup diese `id` nutzen.

---

## 3. Robuste Anbindung – das WICHTIGSTE Muster

Die Rechnungserstellung ist ein **externer** Aufruf und kann fehlschlagen (Netz,
Server-Wartung, unvollständige Firmendaten). Deshalb die eiserne Regel:

> **Der Anspruch des Kunden (Plan/Zugang/Ware) darf NIEMALS am Rechnungserfolg
> hängen.** Zahlung bestätigt → Leistung sofort freischalten. Die Rechnung ist
> ein **entkoppelter, wiederholbarer Seiteneffekt.**

Konkret:

1. **Zahlung `paid` → Leistung sofort aktivieren** und diesen Zustand persistent
   markieren (idempotent, damit Webhook + Browser-Return sich nicht doppeln).
2. **Rechnungsnummer VOR dem `create` persistieren.** Ein Retry nutzt dann
   **dieselbe** Nummer → keine Doppel-Rechnung, keine Nummernlücke.
3. **`create` als separaten Schritt**, dessen Fehler die Aktivierung nicht
   blockiert. Retry ist gefahrlos: existiert die Nummer schon, antwortet der
   Server `409 {"ok":false,"error":"…existiert bereits"}` → als **Erfolg**
   behandeln (oder vorher per `list` prüfen).
4. **`status`-Rückgabe prüfen** (nicht Fire-and-Forget): schlägt „Bezahlt" fehl,
   bleibt die Rechnung „Offen" → beim Reconcile erneut setzen (idempotent).
5. **Reconcile-Cron:** scannt „bezahlt, aber Rechnung unvollständig" und holt
   `create` + `status=Bezahlt` idempotent nach. Fängt transiente Ausfälle ab.

> Ein Client, der die Rechnung blockierend behandelt, riskiert „Geld genommen,
> keine Leistung", sobald der Rechnungsserver kurz hakt. Das gilt für **jedes**
> neue Projekt — deshalb steht dieses Muster hier an erster Stelle.

---

## 4. Referenz-Client (Drop-in)

Kopiere **[`xrechnung_client.example.php`](xrechnung_client.example.php)** in dein
Projekt – eine schlanke `XrechnungClient`-Klasse ohne jede Geschäftslogik:

```php
$xr = new XrechnungClient();               // liest XR_API_URL / XR_API_KEY aus der Umgebung
$res = $xr->reconcileInvoice($felder, $invoiceNr, markPaid: true);
// weitere: ->create() ->listInvoices() ->getInvoice($id)
//          ->setStatus($id,'Bezahlt') ->downloadXml($id) ->downloadPdf($id,'xr'|'pferd')
```

`reconcileInvoice()` setzt das Muster aus Abschnitt 3 direkt um: dieselbe
`$invoiceNr` (409 = „schon da" = Erfolg), autoritative Server-`id` wird
übernommen, `Bezahlt` nur wenn gefordert und mit geprüfter Rückgabe. **Rufe es
nicht blockierend auf** – Leistung freischalten, Rechnung als Seiteneffekt, bei
Fehler nur loggen + per Cron nachziehen.
