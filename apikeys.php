<?php
header('X-Content-Type-Options: nosniff');
require __DIR__ . '/auth_check.php';
require __DIR__ . '/config.php';   // definiert XR_MODE (Plattform-Erkennung)

$platform = defined('XR_MODE') && XR_MODE === 'platform';

// Plattform: API-Verwaltung nur im Mega-Plan (bzw. Admin). Sonst Upgrade-Hinweis.
if ($platform) {
    $me = function_exists('current_user') ? current_user() : null;
    if (!plan_allows_api($me)) {
        $plan = plan_label((string)($me['plan'] ?? 'Basic'));
        $tb = function_exists('app_topbar_html') ? app_topbar_html('rechnungen') : '';
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!doctype html><html lang="de"><head><meta charset="utf-8">'
           . '<meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">'
           . '<title>API-Schlüssel</title>'
           . '<link rel="stylesheet" href="style.css?v=' . (int)@filemtime(__DIR__ . '/style.css') . '">'
           . '<link rel="stylesheet" href="/app/assets/topbar.css"></head><body>' . $tb
           . '<main class="page-main"><div class="fd-head"><h1 style="margin:0;">API-Schlüssel</h1>'
           . '<a class="fd-back" href="index.php">← Zur Übersicht</a></div>'
           . '<p class="fd-intro">Die REST-API mit eigenen API-Schlüsseln ist dem <strong>Mega-Plan</strong> vorbehalten '
           . '(dein Plan: <strong>' . htmlspecialchars($plan) . '</strong>). Ein Upgrade kannst du im '
           . '<a href="/app/?p=profil">Profil</a> anfragen. Im kostenlosen '
           . '<a href="https://xrechnung.brosemedien.de/download">Selbst-Hosting</a> steht die API ohne Limit zur Verfügung.</p>'
           . '</main></body></html>';
        exit;
    }
    $uid = (string)$me['id'];
    $keysFile = user_dir($uid) . '/api_keys.json';   // pro Nutzer (JSON)
} else {
    $keysFile = __DIR__ . '/config/api_keys.php';     // global (PHP-Array)
}

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));

function keys_load(string $file, bool $platform): array {
    if (!is_file($file)) return [];
    if ($platform) { $j = json_decode((string)@file_get_contents($file), true); return is_array($j) ? $j : []; }
    return (array)(require $file);
}
function keys_save(string $file, array $keys, bool $platform): void {
    if ($platform) {
        $dir = dirname($file);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        @file_put_contents($file, json_encode($keys, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        return;
    }
    $lines = ["<?php\nreturn [\n"];
    foreach ($keys as $k => $v) {
        $name    = addslashes((string)($v['name'] ?? ''));
        $active  = ($v['active'] ?? false) ? 'true' : 'false';
        $created = addslashes((string)($v['erstellt'] ?? date('Y-m-d')));
        $lines[] = "    " . var_export($k, true) . " => [\n        'name'     => '$name',\n        'active'   => $active,\n        'erstellt' => '$created',\n    ],\n";
    }
    $lines[] = "];\n";
    @file_put_contents($file, implode('', $lines), LOCK_EX);
}

$keys = keys_load($keysFile, $platform);
$msg = ''; $msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        http_response_code(403); exit('Ungültiges Token. Bitte Seite neu laden.');
    }
    $act = (string)($_POST['act'] ?? '');
    if ($act === 'add') {
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') { $msg = 'Projektname darf nicht leer sein.'; $msgType = 'error'; }
        else {
            $newKey = 'xrm_' . bin2hex(random_bytes(22));
            $keys[$newKey] = ['name' => $name, 'active' => true, 'erstellt' => date('Y-m-d')];
            keys_save($keysFile, $keys, $platform);
            if ($platform) apikeys_index_set($newKey, ['uid' => $uid, 'enabled' => true, 'name' => $name]);
            $_SESSION['xr_new_key'] = $newKey;   // einmalig anzeigen, NICHT über die URL
            $msg = 'Neuer Key erstellt — bitte unten kopieren.'; $msgType = 'success';
        }
    } elseif ($act === 'toggle') {
        $k = (string)($_POST['key'] ?? '');
        if (isset($keys[$k])) {
            $keys[$k]['active'] = !($keys[$k]['active'] ?? true);
            keys_save($keysFile, $keys, $platform);
            if ($platform) { $gi = apikeys_index_get($k); if (!$gi || ($gi['uid'] ?? '') === $uid) apikeys_index_set($k, ['uid' => $uid, 'enabled' => $keys[$k]['active'], 'name' => $keys[$k]['name'] ?? '']); }
            $msg = $keys[$k]['active'] ? 'Key aktiviert.' : 'Key deaktiviert.'; $msgType = 'success';
        }
    } elseif ($act === 'delete') {
        $k = (string)($_POST['key'] ?? '');
        if (isset($keys[$k])) {
            $nm = (string)($keys[$k]['name'] ?? '');
            unset($keys[$k]);
            keys_save($keysFile, $keys, $platform);
            if ($platform) { $gi = apikeys_index_get($k); if ($gi && ($gi['uid'] ?? '') === $uid) apikeys_index_set($k, null); }
            $msg = 'Key für "' . htmlspecialchars($nm) . '" gelöscht.'; $msgType = 'success';
        }
    }
    header('Location: apikeys.php' . ($msg ? '?msg=' . urlencode($msg) . '&t=' . urlencode($msgType) : ''));
    exit;
}

if (empty($msg) && isset($_GET['msg'])) {
    $msg = (string)$_GET['msg']; $msgType = (string)($_GET['t'] ?? 'success');
}

$csrf = htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8');
$apiBase = $platform ? 'https://xrechnung.brosemedien.de/app/xrechnung/api.php' : 'https://ihre-domain.de/api.php';
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title>API-Keys – XrechnungMega</title>
  <link rel="stylesheet" href="style.css?v=<?php echo @filemtime(__DIR__."/style.css"); ?>">
  <?php if ($platform): ?><link rel="stylesheet" href="/app/assets/topbar.css"><?php endif; ?>
  <style>
    .api-table { width:100%; border-collapse:collapse; margin-top:1rem; }
    .api-table th, .api-table td { text-align:left; padding:.6rem .75rem; border-bottom:1px solid #e5e7eb; font-size:.875rem; }
    .api-table th { background:#f9fafb; font-weight:600; color:#374151; }
    .api-table tr:hover td { background:#f9fafb; }
    .badge-active   { background:#bbf7d0; color:#166534; padding:.2rem .6rem; border-radius:999px; font-size:.75rem; font-weight:600; }
    .badge-inactive { background:#fecaca; color:#991b1b; padding:.2rem .6rem; border-radius:999px; font-size:.75rem; font-weight:600; }
    .key-mono { font-family:monospace; font-size:.8rem; color:#374151; background:#f3f4f6; padding:.2rem .4rem; border-radius:4px; user-select:all; cursor:pointer; }
    .btn-sm { padding:.3rem .7rem; font-size:.8rem; border-radius:5px; border:1px solid #d1d5db; cursor:pointer; background:#fff; }
    .btn-sm:hover { background:#f3f4f6; }
    .btn-danger { border-color:#fca5a5; color:#dc2626; }
    .btn-danger:hover { background:#fef2f2; }
    .alert { padding:.75rem 1rem; border-radius:8px; margin-bottom:1.25rem; font-size:.875rem; }
    .alert-success { background:#f0fdf4; color:#166534; border:1px solid #bbf7d0; }
    .alert-error   { background:#fef2f2; color:#dc2626; border:1px solid #fecaca; }
    .add-form { display:flex; gap:.5rem; align-items:center; margin-top:1.25rem; flex-wrap:wrap; }
    .add-form input[type=text] { padding:.5rem .75rem; border:1.5px solid #d1d5db; border-radius:7px; font-size:.875rem; min-width:220px; }
    .add-form input[type=text]:focus { outline:2px solid #1f4e63; border-color:#1f4e63; }
    .hint { font-size:.8rem; color:#6b7280; margin-top:.5rem; }
    .section-title { font-size:1.1rem; font-weight:700; margin:0 0 .25rem; }
    .section-sub { color:#6b7280; font-size:.875rem; margin:0 0 1rem; }
    .endpoint-box { background:#f8f9fa; border:1px solid #e5e7eb; border-radius:8px; padding:1rem 1.25rem; margin-top:1.5rem; }
    .endpoint-box h3 { margin:0 0 .75rem; font-size:.95rem; font-weight:600; }
    .endpoint-table { width:100%; border-collapse:collapse; font-size:.8rem; }
    .endpoint-table td { padding:.35rem .5rem; border-bottom:1px solid #e5e7eb; vertical-align:top; }
    .endpoint-table tr:last-child td { border-bottom:none; }
    .method { font-family:monospace; font-weight:700; padding:.15rem .4rem; border-radius:4px; font-size:.75rem; }
    .m-get    { background:#dbeafe; color:#1d4ed8; }
    .m-post   { background:#dcfce7; color:#166534; }
    .m-put    { background:#fef9c3; color:#854d0e; }
    .m-delete { background:#fecaca; color:#dc2626; }
    .api-keys-pre { font-size:.78rem; overflow-x:auto; margin:0; }
  </style>
</head>
<body>
<?php if ($platform && function_exists('app_topbar_html')) echo app_topbar_html('rechnungen'); ?>
<main class="page-main">
  <div class="fd-head"><h1 style="margin:0;font-size:1.3rem;">API-Schlüssel</h1>
    <a class="fd-back" href="index.php">← Zur Übersicht</a></div>
  <p class="section-sub">Externe Projekte und Programme nutzen einen aktiven Key, um die XrechnungMega-API anzusprechen — jeder Key greift nur auf deine eigenen Rechnungen zu.</p>

  <?php if ($msg): ?>
    <div class="alert alert-<?= $msgType === 'error' ? 'error' : 'success' ?>">
      <?= htmlspecialchars($msg) ?>
    </div>
  <?php endif; ?>
  <?php if (!empty($_SESSION['xr_new_key'])): $nk = htmlspecialchars((string)$_SESSION['xr_new_key'], ENT_QUOTES, 'UTF-8'); unset($_SESSION['xr_new_key']); ?>
    <div class="alert alert-success">Dein neuer Key (jetzt kopieren — wird nicht erneut angezeigt):<br>
      <span class="key-mono" title="Klicken zum Kopieren" onclick="navigator.clipboard.writeText(this.textContent).then(()=>this.style.background='#bbf7d0').catch(()=>{}); setTimeout(()=>this.style.background='',1200)"><?= $nk ?></span></div>
  <?php endif; ?>

  <?php if (empty($keys)): ?>
    <p style="color:#6b7280;font-size:.875rem;">Noch keine API-Keys vorhanden.</p>
  <?php else: ?>
  <table class="api-table">
    <thead>
      <tr><th>Projekt</th><th>API-Key</th><th>Erstellt</th><th>Status</th><th>Aktionen</th></tr>
    </thead>
    <tbody>
      <?php foreach ($keys as $key => $info): ?>
      <tr>
        <td><strong><?= htmlspecialchars((string)($info['name'] ?? '')) ?></strong></td>
        <td><span class="key-mono" title="Klicken zum Kopieren" onclick="navigator.clipboard.writeText(this.textContent).then(()=>this.style.background='#bbf7d0').catch(()=>{}); setTimeout(()=>this.style.background='',1200)"><?= htmlspecialchars((string)$key) ?></span></td>
        <td><?= htmlspecialchars((string)($info['erstellt'] ?? '–')) ?></td>
        <td><?php if ($info['active'] ?? false): ?><span class="badge-active">Aktiv</span><?php else: ?><span class="badge-inactive">Inaktiv</span><?php endif; ?></td>
        <td style="display:flex;gap:.4rem;flex-wrap:wrap;">
          <form method="post" style="display:inline">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" name="act" value="toggle">
            <input type="hidden" name="key" value="<?= htmlspecialchars((string)$key) ?>">
            <button class="btn-sm" type="submit"><?= ($info['active'] ?? false) ? 'Deaktivieren' : 'Aktivieren' ?></button>
          </form>
          <form method="post" style="display:inline" onsubmit="return confirm('Key wirklich löschen?')">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" name="act" value="delete">
            <input type="hidden" name="key" value="<?= htmlspecialchars((string)$key) ?>">
            <button class="btn-sm btn-danger" type="submit">Löschen</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

  <form method="post" class="add-form">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <input type="hidden" name="act" value="add">
    <input type="text" name="name" placeholder="Projektname (z. B. Shop Alpha)" required>
    <button type="submit" class="btn-sm" style="background:#1f4e63;color:#fff;border-color:#1f4e63;padding:.5rem 1rem;">+ Neuen Key generieren</button>
  </form>
  <p class="hint">Der Key wird zufällig generiert. Nach dem Erstellen den Key kopieren und im Projekt hinterlegen — er wird danach nicht erneut angezeigt.</p>

  <div class="endpoint-box">
    <h3>API-Endpunkte</h3>
    <p class="hint" style="margin:0 0 .75rem;">Basis-URL: <code><?= htmlspecialchars($apiBase) ?></code><br>
    Auth-Header: <code>Authorization: Bearer &lt;api_key&gt;</code></p>
    <table class="endpoint-table">
      <tr><td><span class="method m-get">GET</span></td><td><code>?action=list</code></td><td>Alle Rechnungen auflisten</td></tr>
      <tr><td><span class="method m-get">GET</span></td><td><code>?action=get&amp;id=2026_001</code></td><td>Rechnung vollständig abrufen (JSON)</td></tr>
      <tr><td><span class="method m-post">POST</span></td><td><code>?action=create</code></td><td>Neue Rechnung erstellen (JSON-Body)</td></tr>
      <tr><td><span class="method m-put">PUT</span></td><td><code>?action=update&amp;id=2026_001</code></td><td>Rechnung aktualisieren (JSON-Body)</td></tr>
      <tr><td><span class="method m-delete">DELETE</span></td><td><code>?action=delete&amp;id=2026_001</code></td><td>Rechnung löschen</td></tr>
      <tr><td><span class="method m-get">GET</span></td><td><code>?action=download&amp;id=2026_001</code></td><td>XML herunterladen</td></tr>
      <tr><td><span class="method m-post">POST</span></td><td><code>?action=status&amp;id=2026_001</code></td><td>Status setzen · Body: <code>{"status":"Bezahlt"}</code></td></tr>
    </table>
    <p class="hint" style="margin:.75rem 0 0;">PDF-Abruf: <code><?= htmlspecialchars(str_replace('api.php','api_pdf.php',$apiBase)) ?>?type=xr|pferd&amp;id=2026_001</code></p>
    <p class="hint" style="margin:.4rem 0 0;">Status-Werte: <code>Offen</code> · <code>Erinnerung gesendet</code> · <code>Bezahlt</code> · <code>Problem</code> · <code>Entwurf</code></p>
  </div>

  <div class="endpoint-box" style="margin-top:1rem;">
    <h3>Beispiel: Rechnung erstellen (POST ?action=create)</h3>
    <pre class="api-keys-pre"><?php echo htmlspecialchars(json_encode([
  'rechnungsnummer'  => '2026_005',
  'rechnungsdatum'   => '2026-03-12',
  'faelligkeitsdatum'=> '2026-03-26',
  'typ'              => '380',
  'buyer_reference'  => 'BESTELLUNG-42',
  'empfaenger' => [
    'name'    => 'Kunde GmbH',
    'adresse' => 'Kundenstraße 5',
    'plzOrt'  => '54321 Kundenstadt',
    'email'   => 'buchhaltung@kunde.de',
  ],
  'positionen' => [[
    'beschreibung' => 'Projektmanagement März 2026',
    'menge'        => 8,
    'einzelpreis'  => 150.00,
    'einheit'      => 'HUR',
  ]],
  'status' => 'Offen',
  '(Absender + Bank aus deinen Einstellungen)' => '→ optional im Body überschreibbar',
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)); ?></pre>
  </div>

</main>
</body>
</html>
