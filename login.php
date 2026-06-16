<?php
// XrechnungMega — Standalone-Login (Self-Hosting). Im Plattform-Modus läuft die
// Anmeldung über die Account-App; hier nur Selbst-Hosting.
if (is_file(__DIR__ . '/../core.php')) { header('Location: /app/?p=login'); exit; }

session_start();
header('X-Content-Type-Options: nosniff');

if (!empty($_SESSION['xr_auth'])) { header('Location: index.php'); exit; }

$credsFile = __DIR__ . '/config/credentials.php';
$creds = is_file($credsFile) ? (array)(require $credsFile) : [];
$hash  = (string)($creds['password_hash'] ?? '');

// Erststart: noch kein Passwort gesetzt → Einrichtung erzwingen (kein Default-Passwort).
if ($hash === '' || $hash === 'SETUP') { header('Location: setup.php'); exit; }

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));

// ── Brute-Force-Schutz (pro IP, exponentielle Sperre) ──
$attFile = __DIR__ . '/config/login_attempts.json';
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '?');
$att = is_file($attFile) ? (json_decode((string)@file_get_contents($attFile), true) ?: []) : [];
$entry = is_array($att[$ip] ?? null) ? $att[$ip] : ['fails' => 0, 'lock_until' => 0];
$wait = (int)$entry['lock_until'] - time();

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        http_response_code(403); exit('Ungültiges Token. Bitte Seite neu laden.');
    }
    if ($wait > 0) {
        $error = 'Zu viele Fehlversuche. Bitte warte ' . $wait . ' Sekunden.';
    } else {
        $user = trim((string)($_POST['username'] ?? ''));
        $pass = (string)($_POST['password'] ?? '');
        $userOk = hash_equals((string)($creds['username'] ?? ''), $user);
        $passOk = password_verify($pass, $hash);
        if ($userOk && $passOk) {
            // Veralteten Hash transparent auf den aktuellen Algorithmus heben
            if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
                $creds['password_hash'] = password_hash($pass, PASSWORD_DEFAULT);
                @file_put_contents($credsFile, "<?php\nreturn " . var_export($creds, true) . ";\n", LOCK_EX);
            }
            unset($att[$ip]);
            @file_put_contents($attFile, json_encode($att), LOCK_EX);
            session_regenerate_id(true);
            $_SESSION['xr_auth'] = true;
            header('Location: index.php'); exit;
        }
        // Fehlschlag: zählen + exponentiell sperren (5s, 10s, 20s … max 1h)
        $entry['fails'] = (int)$entry['fails'] + 1;
        $entry['lock_until'] = time() + min(5 * (2 ** max(0, $entry['fails'] - 1)), 3600);
        $att[$ip] = $entry;
        @file_put_contents($attFile, json_encode($att), LOCK_EX);
        $wait = max(1, $entry['lock_until'] - time());
        $error = 'Benutzername oder Passwort falsch. Konto ' . $wait . ' Sekunden gesperrt.';
        usleep(300000);
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
  <title>XrechnungMega – Anmelden</title>
  <link rel="stylesheet" href="style.css?v=<?php echo @filemtime(__DIR__."/style.css"); ?>">
  <style>
    body{display:flex;align-items:center;justify-content:center;min-height:100vh;background:#f3f4f6;margin:0}
    .login-box{background:#fff;border-radius:12px;box-shadow:0 4px 24px rgba(0,0,0,.10);padding:2.5rem 2rem;width:100%;max-width:380px}
    .login-box .logo{display:flex;align-items:center;gap:.6rem;justify-content:center;margin-bottom:1.5rem}
    .login-box .logo img{height:40px}
    .login-box .logo span{font-size:1.25rem;font-weight:700;color:#1c1c1c}
    .login-box .feld{margin-bottom:1rem}
    .login-box label{display:block;font-size:.85rem;color:#374151;margin-bottom:.3rem}
    .login-box input{width:100%;padding:.6rem .8rem;border:1.5px solid #d1d5db;border-radius:8px;font-size:1rem;box-sizing:border-box}
    .login-box input:focus{outline:2px solid #1f4e63;border-color:#1f4e63}
    .login-box button{width:100%;padding:.7rem;background:#1f4e63;color:#fff;border:none;border-radius:8px;font-size:1rem;font-weight:600;cursor:pointer}
    .login-box button:hover{background:#163a4b}
    .login-box button:disabled{opacity:.6;cursor:not-allowed}
    .err{background:#fef2f2;color:#dc2626;border:1px solid #fecaca;border-radius:8px;padding:.6rem .8rem;font-size:.875rem;margin-bottom:1rem}
  </style>
</head>
<body>
  <div class="login-box">
    <div class="logo"><img src="bro.png" alt=""><span>XrechnungMega</span></div>
    <?php if ($error): ?><div class="err" role="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form id="loginForm" method="post">
      <input type="hidden" name="csrf" value="<?= $csrf ?>">
      <div class="feld"><label for="u">Benutzername</label><input id="u" type="text" name="username" autocomplete="username" required autofocus></div>
      <div class="feld"><label for="p">Passwort</label><input id="p" type="password" name="password" autocomplete="current-password" required></div>
      <button type="submit">Anmelden</button>
    </form>
  </div>
  <script>
  (function(){ var left=<?= json_encode(max(0,(int)$wait)) ?>; if(left<=0)return;
    var f=document.getElementById('loginForm'), b=f.querySelector('button'), t=b.textContent;
    function r(){b.textContent='Gesperrt — '+left+'s';} f.querySelectorAll('input,button').forEach(e=>e.disabled=true); r();
    var iv=setInterval(function(){left--; if(left<=0){clearInterval(iv); f.querySelectorAll('input,button').forEach(e=>e.disabled=false); b.textContent=t; return;} r();},1000);
  })();
  </script>
</body>
</html>
