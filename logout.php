<?php
// Plattform-Modus: Abmeldung läuft über die Account-App.
if (is_file(__DIR__ . '/../core.php')) { header('Location: /app/?p=logout'); exit; }

session_start();
$_SESSION = [];
session_destroy();
header('Location: login.php');
exit;
