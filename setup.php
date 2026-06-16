<?php
// XrechnungMega — Erst-Einrichtung (Self-Hosting): einmalig Benutzername +
// Passwort festlegen. Kein voreingestelltes Passwort. Im Plattform-Modus N/A.
if (is_file(__DIR__ . '/../core.php')) { header('Location: /app/?p=login'); exit; }

session_start();
header('X-Content-Type-Options: nosniff');

$credsFile = __DIR__ . '/config/credentials.php';
$creds = is_file($credsFile) ? (array)(require $credsFile) : [];
$hash  = (string)($creds['password_hash'] ?? '');

// Schon eingerichtet → kein Setup mehr (Passwort ändert man über „Zugangsdaten").
if ($hash !== '' && $hash !== 'SETUP') { header('Location: login.php'); exit; }

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        http_response_code(403); exit('Ungültiges Token. Bitte Seite neu laden.');
    }
    $user = trim((string)($_POST['username'] ?? ''));
    $pw1  = (string)($_POST['password'] ?? '');
    $pw2  = (string)($_POST['password2'] ?? '');
    if ($user === '')                 $error = 'Bitte einen Benutzernamen wählen.';
    elseif (strlen($pw1) < 8)         $error = 'Das Passwort muss mindestens 8 Zeichen lang sein.';
    elseif ($pw1 !== $pw2)            $error = 'Die Passwörter stimmen nicht überein.';
    else {
        $out = ['username' => $user, 'password_hash' => password_hash($pw1, PASSWORD_DEFAULT)];
        if (@file_put_contents($credsFile, "<?php\n// Zugangsdaten (Setup). Passwort als bcrypt-Hash.\nreturn "
            . var_export($out, true) . ";\n", LOCK_EX) === false) {
            $error = 'Konnte die Zugangsdaten nicht speichern — Schreibrechte auf config/ prüfen.';
        } else {
            session_regenerate_id(true);
            $_SESSION['xr_auth'] = true;   // direkt eingeloggt
            header('Location: index.php'); exit;
        }
    }
}
$csrf = htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title>XrechnungMega – Einrichtung</title>
  <link rel="stylesheet" href="style.css?v=<?php echo @filemtime(__DIR__."/style.css"); ?>">
  <style>
    body{display:flex;align-items:center;justify-content:center;min-height:100vh;background:#f3f4f6;margin:0}
    .box{background:#fff;border-radius:12px;box-shadow:0 4px 24px rgba(0,0,0,.10);padding:2.5rem 2rem;width:100%;max-width:400px}
    .box .logo{display:flex;align-items:center;gap:.6rem;justify-content:center;margin-bottom:1rem}
    .box .logo img{height:40px}.box .logo span{font-size:1.25rem;font-weight:700;color:#1c1c1c}
    .box h1{font-size:1.1rem;margin:0 0 .3rem;text-align:center}
    .box .sub{font-size:.85rem;color:#6b7280;text-align:center;margin:0 0 1.4rem}
    .box .feld{margin-bottom:1rem}.box label{display:block;font-size:.85rem;color:#374151;margin-bottom:.3rem}
    .box input{width:100%;padding:.6rem .8rem;border:1.5px solid #d1d5db;border-radius:8px;font-size:1rem;box-sizing:border-box}
    .box input:focus{outline:2px solid #1f4e63;border-color:#1f4e63}
    .box button{width:100%;padding:.7rem;background:#1f4e63;color:#fff;border:none;border-radius:8px;font-size:1rem;font-weight:600;cursor:pointer}
    .box button:hover{background:#163a4b}
    .err{background:#fef2f2;color:#dc2626;border:1px solid #fecaca;border-radius:8px;padding:.6rem .8rem;font-size:.875rem;margin-bottom:1rem}
  </style>
</head>
<body>
  <div class="box">
    <div class="logo"><img src="bro.png" alt=""><span>XrechnungMega</span></div>
    <h1>Willkommen — kurz einrichten</h1>
    <p class="sub">Lege einmalig deine Zugangsdaten fest. Danach geht es direkt los.</p>
    <?php if ($error): ?><div class="err" role="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= $csrf ?>">
      <div class="feld"><label for="u">Benutzername</label><input id="u" type="text" name="username" value="admin" autocomplete="username" required autofocus></div>
      <div class="feld"><label for="p">Passwort (mind. 8 Zeichen)</label><input id="p" type="password" name="password" minlength="8" autocomplete="new-password" required></div>
      <div class="feld"><label for="p2">Passwort wiederholen</label><input id="p2" type="password" name="password2" minlength="8" autocomplete="new-password" required></div>
      <button type="submit">Einrichten &amp; loslegen</button>
    </form>
  </div>
</body>
</html>
