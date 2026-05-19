<?php
/**
 * opslaan-bevestiging.php — Slaat ALLEEN de bevestigingsmail-teksten op.
 * Raakt de SMTP-verbindingsinstellingen NOOIT aan.
 * Gebruikt atomic write (tempfile → rename) om dataverlies te voorkomen.
 */
require_once __DIR__ . '/helpers.php';
secure_session_start();

require_auth();
check_timeout();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php'); exit;
}

verify_csrf('dashboard.php?fout=csrf');

$config_pad = __DIR__ . '/../smtp_config.php';

// Bestaande config laden — zodat we SMTP-velden BEWAREN
$bestaand = [];
if (file_exists($config_pad)) {
    $bestaand = include $config_pad;
}

// Als er nog geen smtp_config bestaat, kunnen we niet opslaan (SMTP is vereist)
if (empty($bestaand['host'])) {
    header('Location: dashboard.php?fout=smtp_eerst'); exit;
}

// Alleen bevestigingsmail-velden uit POST halen
$bev_onderwerp = trim($_POST['bev_onderwerp'] ?? '');
$bev_bericht   = trim($_POST['bev_bericht']   ?? '');

// Merge: bewaar bestaande als nieuw veld leeg is
$nieuwe_config = [
    // === SMTP-velden ALTIJD overnemen uit bestaand — NOOIT overschrijven ===
    'host'       => $bestaand['host']       ?? '',
    'poort'      => $bestaand['poort']      ?? 465,
    'gebruiker'  => $bestaand['gebruiker']  ?? '',
    'wachtwoord' => $bestaand['wachtwoord'] ?? '',
    'afzender'   => $bestaand['afzender']   ?? '',
    'ontvanger'  => $bestaand['ontvanger']  ?? '',
    'encryptie'  => $bestaand['encryptie']  ?? 'ssl',

    // === Bevestigingsmail-velden (dit endpoint) ===
    'bev_onderwerp' => !empty($bev_onderwerp) ? $bev_onderwerp : ($bestaand['bev_onderwerp'] ?? ''),
    'bev_bericht'   => !empty($bev_bericht)   ? $bev_bericht   : ($bestaand['bev_bericht']   ?? ''),
];

if (atomic_write_config($config_pad, $nieuwe_config)) {
    header('Location: dashboard.php?opgeslagen=bevestiging');
} else {
    header('Location: dashboard.php?fout=schrijf');
}
exit;
