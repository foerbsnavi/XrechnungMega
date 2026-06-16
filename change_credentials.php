<?php
// Plattform-Modus: Zugangsdaten verwaltet der Nutzer im Account-Profil.
if (is_file(__DIR__ . '/../core.php')) { header('Location: /app/?p=profil'); exit; }

require __DIR__ . '/auth_check.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));

$credsFile = __DIR__ . '/config/credentials.php';

$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        http_response_code(403); exit('Ungültiges Token. Bitte Seite neu laden.');
    }
    $currentPass  = $_POST['current_password']  ?? '';
    $newUser      = trim($_POST['new_username']  ?? '');
    $newPass      = $_POST['new_password']       ?? '';
    $newPassAgain = $_POST['new_password_again'] ?? '';

    $creds = require $credsFile;

    // Aktuelles Passwort prüfen (bcrypt)
    if (!password_verify((string)$currentPass, (string)($creds['password_hash'] ?? ''))) {
        $error = 'Aktuelles Passwort ist falsch.';
    } elseif ($newUser === '') {
        $error = 'Benutzername darf nicht leer sein.';
    } elseif ($newPass !== '' && $newPass !== $newPassAgain) {
        $error = 'Neues Passwort und Bestätigung stimmen nicht überein.';
    } elseif ($newPass !== '' && strlen($newPass) < 8) {
        $error = 'Neues Passwort muss mindestens 8 Zeichen lang sein.';
    } else {
        $finalUser = $newUser;
        $finalHash = ($newPass !== '') ? password_hash($newPass, PASSWORD_DEFAULT) : (string)$creds['password_hash'];

        $content = "<?php\n"
            . "// Login-Zugangsdaten für XrechnungMega (Passwort als bcrypt-Hash).\n"
            . "return [\n"
            . "    'username'      => " . var_export($finalUser, true) . ",\n"
            . "    'password_hash' => " . var_export($finalHash, true) . ",\n"
            . "];\n";

        if (file_put_contents($credsFile, $content, LOCK_EX) !== false) {
            // Session gültig halten (kein erneuter Login nötig)
            $success = 'Zugangsdaten wurden erfolgreich gespeichert.';
        } else {
            $error = 'Fehler: Datei konnte nicht geschrieben werden. Bitte Schreibrechte prüfen.';
        }
    }
}

// Aktuellen Benutzernamen für Vorausfüllung laden
$creds = is_file($credsFile) ? (array)(require $credsFile) : [];
$currentUser = (string)($creds['username'] ?? '');
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>XRechnungMega – Zugangsdaten</title>
  <link rel="stylesheet" href="style.css?v=<?php echo @filemtime(__DIR__."/style.css"); ?>">
  <style>
    body { background:#f3f4f6; margin:0; font-family:system-ui,sans-serif; }

    .topbar {
      background:#fff;
      border-bottom:1px solid #e5e7eb;
      padding:.75rem 1.5rem;
      display:flex;
      align-items:center;
      justify-content:space-between;
    }
    .topbar .logo { display:flex; align-items:center; gap:.6rem; text-decoration:none; }
    .topbar .logo img { height:32px; }
    .topbar .logo span { font-size:1.1rem; font-weight:700; color:#1c1c1c; }
    .topbar-actions { display:flex; gap:.5rem; }
    .btn-nav {
      font-size:.875rem;
      color:#6b7280;
      text-decoration:none;
      padding:.4rem .9rem;
      border:1px solid #d1d5db;
      border-radius:6px;
      background:transparent;
      cursor:pointer;
      transition:background .15s;
    }
    .btn-nav:hover { background:#f3f4f6; }

    .container { max-width:480px; margin:2.5rem auto; padding:0 1rem; }

    h1 { font-size:1.25rem; font-weight:700; margin:0 0 1.5rem; color:#1c1c1c; }

    .card {
      background:#fff;
      border-radius:12px;
      box-shadow:0 2px 12px rgba(0,0,0,.07);
      padding:2rem;
    }

    .form-group { margin-bottom:1.1rem; }
    .form-group label {
      display:block;
      font-size:.875rem;
      font-weight:500;
      color:#374151;
      margin-bottom:.375rem;
    }
    .form-group input {
      width:100%;
      padding:.625rem .875rem;
      border:1.5px solid #d1d5db;
      border-radius:8px;
      font-size:1rem;
      box-sizing:border-box;
      transition:border-color .15s;
      outline:none;
    }
    .form-group input:focus { border-color:#ea6b17; }
    .form-group .hint {
      font-size:.78rem;
      color:#9ca3af;
      margin-top:.3rem;
    }

    .divider {
      border:none;
      border-top:1px solid #e5e7eb;
      margin:1.5rem 0;
    }

    .btn-save {
      width:100%;
      padding:.7rem;
      background:#ea6b17;
      color:#fff;
      border:none;
      border-radius:8px;
      font-size:1rem;
      font-weight:600;
      cursor:pointer;
      transition:background .15s;
    }
    .btn-save:hover { background:#c95e13; }

    .msg {
      border-radius:8px;
      padding:.7rem 1rem;
      font-size:.875rem;
      margin-bottom:1.25rem;
    }
    .msg-success { background:#f0fdf4; color:#16a34a; border:1px solid #bbf7d0; }
    .msg-error   { background:#fef2f2; color:#dc2626; border:1px solid #fecaca; }

    .section-label {
      font-size:.8rem;
      font-weight:600;
      color:#9ca3af;
      text-transform:uppercase;
      letter-spacing:.05em;
      margin-bottom:1rem;
    }
  </style>
</head>
<body>
  <div class="topbar">
    <a href="index.php" class="logo">
      <img src="bro.png" alt="Logo">
      <span>XRechnungMega</span>
    </a>
    <div class="topbar-actions">
      <a href="index.php" class="btn-nav">← Dashboard</a>
      <a href="apikeys.php" class="btn-nav">API-Keys</a>
      <a href="logout.php" class="btn-nav">Abmelden</a>
    </div>
  </div>

  <div class="container">
    <h1>Zugangsdaten ändern</h1>

    <?php if ($success): ?>
      <div class="msg msg-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="msg msg-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="card">
      <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8') ?>">

        <div class="section-label">Sicherheitsbestätigung</div>
        <div class="form-group">
          <label for="current_password">Aktuelles Passwort</label>
          <input type="password" id="current_password" name="current_password"
                 autocomplete="current-password" required>
          <div class="hint">Zur Bestätigung deiner Identität erforderlich.</div>
        </div>

        <hr class="divider">

        <div class="section-label">Neuer Benutzername</div>
        <div class="form-group">
          <label for="new_username">Benutzername</label>
          <input type="text" id="new_username" name="new_username"
                 value="<?= htmlspecialchars($currentUser) ?>"
                 autocomplete="off" required>
        </div>

        <hr class="divider">

        <div class="section-label">Neues Passwort <span style="font-weight:400;text-transform:none;letter-spacing:0">(leer lassen = unverändert)</span></div>
        <div class="form-group">
          <label for="new_password">Neues Passwort</label>
          <input type="password" id="new_password" name="new_password"
                 autocomplete="new-password" minlength="8">
          <div class="hint">Mindestens 8 Zeichen. Leer lassen, um das Passwort nicht zu ändern.</div>
        </div>
        <div class="form-group">
          <label for="new_password_again">Passwort bestätigen</label>
          <input type="password" id="new_password_again" name="new_password_again"
                 autocomplete="new-password">
        </div>

        <hr class="divider">

        <button type="submit" class="btn-save">Zugangsdaten speichern</button>
      </form>
    </div>
  </div>
</body>
</html>
