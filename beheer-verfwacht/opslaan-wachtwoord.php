<?php
/**
 * opslaan-wachtwoord.php — Wijzigt het beheer-wachtwoord.
 * Vereist het oude wachtwoord voor verificatie.
 */
require_once __DIR__ . '/helpers.php';
secure_session_start();

require_auth();
check_timeout();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: wachtwoord.php');
    exit;
}

verify_csrf('wachtwoord.php?fout=csrf');

$cfg_pad = __DIR__ . '/../site-config.php';
$cfg = [];
if (file_exists($cfg_pad)) {
    $cfg = include $cfg_pad;
    if (!is_array($cfg)) {
        $cfg = [];
    }
}

$hash = $cfg['beheer_hash'] ?? '';
$oud     = $_POST['wachtwoord_oud']     ?? '';
$nieuw   = $_POST['wachtwoord_nieuw']   ?? '';
$bevestig = $_POST['wachtwoord_bevestig'] ?? '';

// Validatie
if (empty($hash) || !password_verify($oud, $hash)) {
    header('Location: wachtwoord.php?fout=oud');
    exit;
}
if (strlen($nieuw) < 8) {
    header('Location: wachtwoord.php?fout=te_kort');
    exit;
}
if ($nieuw !== $bevestig) {
    header('Location: wachtwoord.php?fout=match');
    exit;
}

// Nieuwe hash genereren
$cfg['beheer_hash'] = password_hash($nieuw, PASSWORD_BCRYPT, ['cost' => 12]);

if (atomic_write_config($cfg_pad, $cfg, 'Site Configuratie')) {
    header('Location: wachtwoord.php?opgeslagen=1');
} else {
    header('Location: wachtwoord.php?fout=schrijf');
}
exit;
