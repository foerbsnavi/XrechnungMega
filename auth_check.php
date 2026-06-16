<?php
// XrechnungMega — Zugangsschutz. Im PLATTFORM-Modus über die Account-Session
// (Account-App), sonst über den STANDALONE-Passwort-Login.
if (is_file(__DIR__ . '/../core.php')) {
    require_once __DIR__ . '/../core.php';
    $u = function_exists('current_user') ? current_user() : null;
    if (!is_array($u)) { header('Location: /app/?p=login'); exit; }
    if ((string)($u['status'] ?? '') !== 'ok') { header('Location: /app/?p=verify'); exit; }
    if (!function_exists('user_freigeschaltet') || !user_freigeschaltet($u)) { header('Location: /app/?p=warten'); exit; }
    return;
}
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['xr_auth'])) {
    header('Location: login.php');
    exit;
}
