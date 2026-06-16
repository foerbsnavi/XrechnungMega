<?php
// XrechnungMega — Globale Einstellungen (Firmendaten). Modusbewusst:
//   platform  → app_daten/{uid}/firmendaten.json
//   standalone→ config/firmendaten.json (+ api_defaults.php für die REST-API)
// Die hinterlegten Daten füllen den Absender neuer Rechnungen vor (vorlage.xml)
// und dienen der REST-API als Standard-Absender.

header('X-Content-Type-Options: nosniff');
require __DIR__ . '/auth_check.php';   // platform: Account-Session · standalone: Login
require __DIR__ . '/config.php';        // DATA_ROOT, TEMPLATE_FILE, FIRMENDATEN_FILE

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));

// Defensive: FIRMENDATEN_FILE wird in config.php nur bei gültigem DATA_ROOT
// gesetzt (authentifizierter Zustand). auth_check sollte das garantieren.
if (!defined('FIRMENDATEN_FILE')) { http_response_code(500); exit('Konfiguration unvollständig.'); }

$FELDER = ['name','adresse','plz','ort','land','telefon','email','ustid',
           'bank_inhaber','iban','bic','bank'];

function fd_load(string $file): array {
  if (!is_file($file)) return [];
  $j = json_decode((string)@file_get_contents($file), true);
  return is_array($j) ? $j : [];
}
function fd_save(string $file, array $d): bool {
  $dir = dirname($file);
  if (!is_dir($dir) && !@mkdir($dir, 0775, true)) return false;
  return @file_put_contents($file, json_encode($d, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), LOCK_EX) !== false;
}
// Absender-/Bankblock der Vorlage aktualisieren, damit neue Rechnungen vorbefüllt sind
function fd_update_vorlage(string $tpl, array $d): void {
  if (!is_file($tpl)) return;
  libxml_use_internal_errors(true);
  $dom = new DOMDocument();
  if (!$dom->load($tpl, LIBXML_NONET)) return;
  $xp = new DOMXPath($dom);
  $xp->registerNamespace('inv','urn:oasis:names:specification:ubl:schema:xsd:Invoice-2');
  $xp->registerNamespace('cac','urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
  $xp->registerNamespace('cbc','urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
  $set = function(string $q, string $v) use ($xp): void {
    $n = $xp->query($q)->item(0);
    if ($n) $n->nodeValue = $v;
  };
  $S = '/inv:Invoice/cac:AccountingSupplierParty/cac:Party';
  $set("$S/cbc:EndpointID", $d['email']);
  $set("$S/cac:PartyName/cbc:Name", $d['name']);
  $set("$S/cac:PostalAddress/cbc:StreetName", $d['adresse']);
  $set("$S/cac:PostalAddress/cbc:CityName", $d['ort']);
  $set("$S/cac:PostalAddress/cbc:PostalZone", $d['plz']);
  $set("$S/cac:PostalAddress/cac:Country/cbc:IdentificationCode", $d['land'] !== '' ? $d['land'] : 'DE');
  $set("$S/cac:PartyTaxScheme/cbc:CompanyID", $d['ustid']);
  $set("$S/cac:PartyLegalEntity/cbc:RegistrationName", $d['name']);
  $set("$S/cac:Contact/cbc:Name", $d['name']);
  $set("$S/cac:Contact/cbc:Telephone", $d['telefon']);
  $set("$S/cac:Contact/cbc:ElectronicMail", $d['email']);
  $P = '/inv:Invoice/cac:PaymentMeans/cac:PayeeFinancialAccount';
  $set("$P/cbc:ID", $d['iban']);
  $set("$P/cbc:Name", $d['bank_inhaber'] !== '' ? $d['bank_inhaber'] : $d['name']);
  $set("$P/cac:FinancialInstitutionBranch/cbc:ID", $d['bic']);
  $set("$P/cac:FinancialInstitutionBranch/cbc:Name", $d['bank']);
  @file_put_contents($tpl, $dom->saveXML(), LOCK_EX);
}
// Standalone: REST-API-Standardabsender mitschreiben
function fd_write_api_defaults(string $file, array $d): void {
  $arr = [
    'absender' => [
      'name' => $d['name'], 'adresse' => $d['adresse'],
      'plzOrt' => trim($d['plz'] . ' ' . $d['ort']),
      'telefon' => $d['telefon'], 'email' => $d['email'], 'ustid' => $d['ustid'],
    ],
    'bankverbindung' => [
      'iban' => $d['iban'], 'bic' => $d['bic'],
      'name' => $d['bank_inhaber'] !== '' ? $d['bank_inhaber'] : $d['name'], 'bank' => $d['bank'],
    ],
    'payment_code' => '58',
  ];
  @file_put_contents($file, "<?php\n// Auto-generiert von einstellungen.php — nicht von Hand bearbeiten.\nreturn "
    . var_export($arr, true) . ";\n", LOCK_EX);
}

$msg = ''; $msgType = '';
$data = fd_load(FIRMENDATEN_FILE);
foreach ($FELDER as $f) if (!isset($data[$f])) $data[$f] = '';
if ($data['land'] === '') $data['land'] = 'DE';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $token = (string)($_POST['csrf'] ?? '');
  if (empty($_SESSION['csrf']) || $token === '' || !hash_equals($_SESSION['csrf'], $token)) {
    http_response_code(403); exit('Ungültiges Token. Bitte Seite neu laden.');
  }
  foreach ($FELDER as $f) $data[$f] = trim((string)($_POST[$f] ?? ''));
  if ($data['land'] === '') $data['land'] = 'DE';

  // Leichte Validierung (nicht blockierend, außer Pflichtfelder)
  $fehler = [];
  if ($data['name'] === '')  $fehler[] = 'Name / Firma ist ein Pflichtfeld.';
  if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) $fehler[] = 'Die E-Mail-Adresse ist ungültig.';
  if ($data['iban'] !== '' && !preg_match('/^[A-Z]{2}[0-9A-Z ]{12,40}$/', strtoupper($data['iban']))) $fehler[] = 'Die IBAN sieht ungültig aus.';
  if ($data['bic'] !== '' && !preg_match('/^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}([A-Z0-9]{3})?$/', strtoupper($data['bic']))) $fehler[] = 'Der BIC sieht ungültig aus.';
  if ($data['ustid'] !== '' && !preg_match('/^[A-Z]{2}[0-9A-Z]{2,14}$/', strtoupper(str_replace(' ', '', $data['ustid'])))) $fehler[] = 'Die USt-IdNr. sieht ungültig aus.';

  if ($fehler) {
    $msg = implode(' ', $fehler); $msgType = 'error';
  } else {
    if (fd_save(FIRMENDATEN_FILE, $data)) {
      if (defined('TEMPLATE_FILE')) fd_update_vorlage(TEMPLATE_FILE, $data);
      if (defined('XR_MODE') && XR_MODE === 'standalone') {
        fd_write_api_defaults(__DIR__ . '/config/api_defaults.php', $data);
      }
      $msg = 'Firmendaten gespeichert — neue Rechnungen werden damit vorbefüllt.'; $msgType = 'success';
    } else {
      $msg = 'Konnte die Daten nicht speichern. Bitte Schreibrechte prüfen.'; $msgType = 'error';
    }
  }
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$platform = defined('XR_MODE') && XR_MODE === 'platform';
$v = (string)(@filemtime(__DIR__ . '/style.css') ?: '1');
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<title>Einstellungen — XrechnungMega</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<link rel="stylesheet" href="style.css?v=<?= h($v) ?>">
<?php if ($platform): ?><link rel="stylesheet" href="/app/assets/topbar.css"><?php endif; ?>
</head>
<body>
<?php if ($platform && function_exists('app_topbar_html')) echo app_topbar_html('rechnungen'); ?>
<main class="page-main">
  <div class="fd-head noprint">
    <h1 style="margin:0;">Einstellungen</h1>
    <a class="fd-back" href="index.php">← Zur Übersicht</a>
  </div>
  <p class="fd-intro">Hinterlege hier deine Firmendaten. Sie werden als Absender in neue Rechnungen übernommen<?= $platform ? '' : ' und von der REST-API als Standard verwendet' ?>.</p>

  <?php if ($msg): ?>
    <div class="fd-msg fd-msg-<?= $msgType === 'error' ? 'error' : 'ok' ?>" role="<?= $msgType === 'error' ? 'alert' : 'status' ?>"><?= h($msg) ?></div>
  <?php endif; ?>

  <form method="post" class="fd-form" autocomplete="on">
    <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">

    <fieldset class="fd-card">
      <legend>Absender (deine Firmendaten)</legend>
      <div class="fd-grid">
        <label class="fd-feld fd-col2"><span>Name / Firma <em>*</em></span>
          <input type="text" name="name" value="<?= h($data['name']) ?>" required placeholder="z. B. Mustermann GmbH"></label>
        <label class="fd-feld fd-col2"><span>Straße &amp; Hausnummer</span>
          <input type="text" name="adresse" value="<?= h($data['adresse']) ?>" placeholder="Musterstraße 1"></label>
        <label class="fd-feld"><span>PLZ</span>
          <input type="text" name="plz" value="<?= h($data['plz']) ?>" placeholder="12345"></label>
        <label class="fd-feld"><span>Ort</span>
          <input type="text" name="ort" value="<?= h($data['ort']) ?>" placeholder="Musterstadt"></label>
        <label class="fd-feld"><span>Land (Code)</span>
          <input type="text" name="land" value="<?= h($data['land']) ?>" maxlength="2" placeholder="DE"></label>
        <label class="fd-feld"><span>Telefon</span>
          <input type="text" name="telefon" value="<?= h($data['telefon']) ?>" placeholder="+49 …"></label>
        <label class="fd-feld"><span>E-Mail</span>
          <input type="email" name="email" value="<?= h($data['email']) ?>" placeholder="rechnung@firma.de"></label>
        <label class="fd-feld fd-col2"><span>USt-IdNr.</span>
          <input type="text" name="ustid" value="<?= h($data['ustid']) ?>" placeholder="DE123456789"></label>
      </div>
    </fieldset>

    <fieldset class="fd-card">
      <legend>Bankverbindung</legend>
      <div class="fd-grid">
        <label class="fd-feld fd-col2"><span>Kontoinhaber</span>
          <input type="text" name="bank_inhaber" value="<?= h($data['bank_inhaber']) ?>" placeholder="(leer = Name/Firma)"></label>
        <label class="fd-feld fd-col2"><span>IBAN</span>
          <input type="text" name="iban" value="<?= h($data['iban']) ?>" placeholder="DE00 0000 0000 0000 0000 00"></label>
        <label class="fd-feld"><span>BIC</span>
          <input type="text" name="bic" value="<?= h($data['bic']) ?>" placeholder="ABCDDEFFXXX"></label>
        <label class="fd-feld"><span>Bank</span>
          <input type="text" name="bank" value="<?= h($data['bank']) ?>" placeholder="Musterbank"></label>
      </div>
    </fieldset>

    <div class="fd-actions">
      <button type="submit" class="fd-save">Firmendaten speichern</button>
    </div>
  </form>
</main>
</body>
</html>
