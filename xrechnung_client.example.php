<?php
/**
 * XrechnungMega – generischer Referenz-Client (Drop-in für neue Projekte)
 * =======================================================================
 * ENTWURF (windows, 2026-07-19) – abgestimmt mit linux-1s entkoppeltem
 * erbekatalog-Client; nach Live-E2E + Foerbs GO finalisiert.
 *
 * Eine schlanke, projekt-neutrale Anbindung an die XrechnungMega-REST-API.
 * Enthält KEINE Geschäftslogik (keine Preise/Nummernkreise/Proration) – die
 * gehört in dein Projekt. Kopiere diese Datei, setze URL + Key, fertig.
 *
 * Voraussetzung auf der XrechnungMega-Seite (siehe ANBINDUNG.md):
 *   Konto mit API-Recht + vollständige Firmendaten (Absender + gültige IBAN)
 *   + ein API-Key (mit optionalem Präfix). Sonst schlägt `create` fehl.
 *
 * Konfiguration über Umgebungsvariablen (empfohlen) oder Konstruktor:
 *   XR_API_URL   z. B. https://xrechnung.brosemedien.de/app/xrechnung/api.php
 *   XR_API_KEY   dein Bearer-Key (Geheimnis – nie ins Repo)
 *   XR_WEB_URL   Basis für PDF, i. d. R. das Verzeichnis der api.php
 */
declare(strict_types=1);

final class XrechnungClient
{
    private string $apiUrl;
    private string $webUrl;
    private string $apiKey;
    private int    $timeout;

    public function __construct(?string $apiUrl = null, ?string $apiKey = null, ?string $webUrl = null, int $timeout = 20)
    {
        $this->apiUrl  = rtrim($apiUrl ?? (string)(getenv('XR_API_URL') ?: ''), '/');
        $this->apiKey  = $apiKey ?? (string)(getenv('XR_API_KEY') ?: '');
        $base          = $webUrl ?? (string)(getenv('XR_WEB_URL') ?: preg_replace('~/api\.php$~', '', $this->apiUrl));
        $this->webUrl  = rtrim($base, '/');
        $this->timeout = $timeout;
    }

    /** Ein API-Call. Gibt IMMER ein Array zurück ({ok:true,…} oder {ok:false,error}). */
    public function call(string $action, string $method = 'GET', array $body = [], string $id = ''): array
    {
        if ($this->apiKey === '') return ['ok' => false, 'error' => 'XR_API_KEY fehlt.'];
        if ($this->apiUrl === '') return ['ok' => false, 'error' => 'XR_API_URL fehlt.'];

        $url = $this->apiUrl . '?action=' . urlencode($action);
        if ($id !== '') $url .= '&id=' . urlencode($id);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);
        if ($body && in_array($method, ['POST', 'PUT'], true)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        $raw   = curl_exec($ch);
        $errno = curl_errno($ch);
        $err   = curl_error($ch);
        $http  = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($raw === false || $errno !== 0) return ['ok' => false, 'error' => 'Verbindung fehlgeschlagen: ' . $err];
        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) return ['ok' => false, 'error' => 'Ungültige Serverantwort.', '_http' => $http];
        $decoded['_http'] = $http;   // HTTP-Status für robuste Fallunterscheidung (z. B. 409)
        return $decoded;
    }

    public function listInvoices(): array          { return $this->call('list', 'GET'); }
    public function getInvoice(string $id): array   { return $this->call('get', 'GET', [], $id); }
    public function create(array $fields): array    { return $this->call('create', 'POST', $fields); }
    public function setStatus(string $id, string $status): array { return $this->call('status', 'POST', ['status' => $status], $id); }

    /** XML einer Rechnung als String (['ok','data','filename'] oder ['ok'=>false,'error']). */
    public function downloadXml(string $id): array
    {
        return $this->fetchBinary($this->apiUrl . '?action=download&id=' . urlencode($id), 'application/xml', $id . '.xml');
    }

    /** PDF einer Rechnung. $type: 'xr' (rein) oder 'pferd' (Hybrid-ZUGFeRD). */
    public function downloadPdf(string $id, string $type = 'xr'): array
    {
        if (!in_array($type, ['xr', 'pferd'], true)) return ['ok' => false, 'error' => 'type muss xr|pferd sein'];
        $url = $this->webUrl . '/api_pdf.php?type=' . urlencode($type) . '&id=' . urlencode($id);
        return $this->fetchBinary($url, 'application/pdf', $id . ($type === 'pferd' ? '_zugferd.pdf' : '_xrechnung.pdf'));
    }

    private function fetchBinary(string $url, string $ct, string $filename): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => max(30, $this->timeout),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $this->apiKey, 'Accept: ' . $ct],
        ]);
        $raw  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($raw === false) return ['ok' => false, 'error' => 'Verbindung fehlgeschlagen: ' . $err];
        if ($code >= 400) {
            $j = json_decode((string)$raw, true);
            return ['ok' => false, 'error' => is_array($j) ? ($j['error'] ?? "HTTP $code") : "HTTP $code"];
        }
        return ['ok' => true, 'data' => (string)$raw, 'content_type' => $ct, 'filename' => $filename];
    }

    /**
     * Idempotente Rechnung + optionaler Bezahlt-Status — das ROBUSTE Muster.
     *
     * WICHTIG: Rufe das NICHT auf dem kritischen Pfad der Kunden-Freischaltung auf.
     * Schalte die Leistung frei, sobald die Zahlung bestätigt ist; erzeuge die
     * Rechnung als ENTKOPPELTEN, wiederholbaren Seiteneffekt (dieser Aufruf).
     * Idempotent, weil dieselbe $invoiceNr genutzt wird: existiert die Rechnung
     * schon (Server 409 „existiert bereits"), gilt das als Erfolg.
     *
     * @param string $invoiceNr  VOR dem ersten Aufruf in deiner DB persistieren!
     * @param bool   $markPaid   true = zusätzlich auf „Bezahlt" setzen
     * @return array ['ok'=>bool, 'created'=>bool, 'paid_marked'=>bool, 'invoice_nr'=>string(autoritativ), 'error'=>string]
     */
    public function reconcileInvoice(array $createFields, string $invoiceNr, bool $markPaid = false): array
    {
        $createFields['rechnungsnummer'] = $invoiceNr;
        $res = $this->create($createFields);
        // 409 „existiert bereits" ist ein ERFOLG (idempotenter Retry). Primär am
        // HTTP-Status erkennen, Text nur als Fallback (zukunftssicher gegen Wording).
        $alreadyExists = (($res['_http'] ?? 0) === 409)
            || (!empty($res['error']) && stripos((string)$res['error'], 'existiert bereits') !== false);

        if (empty($res['ok']) && !$alreadyExists) {
            return ['ok' => false, 'created' => false, 'paid_marked' => false, 'invoice_nr' => $invoiceNr, 'error' => 'create: ' . (string)($res['error'] ?? 'unbekannt')];
        }
        // M2: die vom Server gespeicherte id ist maßgeblich (der Server kann ein
        // Präfix ergänzt haben!). Bei Erfolg steht sie in 'id'; bei 409 „existiert
        // bereits" liefert der Server sie im Feld 'file' (Dateiname ohne .xml).
        // NUR die gesendete Nummer zu behalten wäre bei Fremd-Präfix falsch →
        // status/download würden dauerhaft auf 404 laufen.
        $authNr = (string)($res['id'] ?? '');
        if ($authNr === '' && !empty($res['file'])) {
            $authNr = preg_replace('/\.xml$/i', '', (string)$res['file']);
        }
        if ($authNr === '') $authNr = $invoiceNr; // Fallback (sollte nicht eintreten)
        $paidMarked = false;

        if ($markPaid) {
            $st = $this->setStatus($authNr, 'Bezahlt');
            if (!empty($st['ok'])) $paidMarked = true;
            else return ['ok' => false, 'created' => true, 'paid_marked' => false, 'invoice_nr' => $authNr, 'error' => 'status: ' . (string)($st['error'] ?? 'unbekannt')];
        }
        return ['ok' => true, 'created' => true, 'paid_marked' => $paidMarked, 'invoice_nr' => $authNr, 'error' => ''];
    }
}

/* ---------------------------------------------------------------------------
   Beispiel:

   $xr = new XrechnungClient();  // liest XR_API_URL / XR_API_KEY aus der Umgebung

   // 1) Kunde hat bezahlt -> Leistung SOFORT freischalten (dein Code), DANN:
   $invoiceNr = deine_naechste_nummer_und_persistiere();  // z. B. 2026_007
   $res = $xr->reconcileInvoice([
       'rechnungsdatum'    => date('Y-m-d'),
       'faelligkeitsdatum' => date('Y-m-d', strtotime('+14 days')),
       'empfaenger' => ['name'=>'Kunde GmbH','email'=>'kunde@example.de',
                        'adresse'=>'Weg 1','plzOrt'=>'12345 Stadt'],
       'positionen' => [['beschreibung'=>'Leistung','menge'=>1,'einzelpreis'=>150.0,'einheit'=>'LS']],
   ], $invoiceNr, markPaid: true);

   if (!$res['ok']) {
       // Fehler NUR loggen + für einen Reconcile-Cron vormerken – NICHT die
       // Kunden-Freischaltung zurücknehmen. $res['invoice_nr'] persistieren.
       error_log('xrechnung reconcile offen: ' . $res['error']);
   }
   --------------------------------------------------------------------------- */
