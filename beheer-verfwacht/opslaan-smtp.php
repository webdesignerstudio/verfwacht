<?php
/**
 * opslaan-smtp.php — Slaat ALLEEN de SMTP-verbindingsinstellingen op.
 * Raakt de bevestigingsmail-velden NOOIT aan.
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

// Bestaande config laden — zodat we bevestigingsmail-velden BEWAREN
$bestaand = [];
if (file_exists($config_pad)) {
    $bestaand = include $config_pad;
}

// Alleen SMTP-velden uit POST halen
$host       = trim($_POST['host']       ?? '');
$poort      = intval($_POST['poort']    ?? 465);
$gebruiker  = trim($_POST['gebruiker']  ?? '');
$wachtwoord = $_POST['wachtwoord']      ?? '';
$afzender   = trim($_POST['afzender']   ?? '');
$ontvanger  = trim($_POST['ontvanger']  ?? '');

// Intelligente merge: leeg POST-veld → behoud bestaande waarde
// Uitzondering: wachtwoord — leeg = "niet gewijzigd"
$nieuwe_config = [
    // === SMTP-velden (dit endpoint) ===
    'host'       => !empty($host)      ? $host      : ($bestaand['host']      ?? ''),
    'poort'      => $poort ?: ($bestaand['poort'] ?? 465),
    'gebruiker'  => !empty($gebruiker) ? $gebruiker : ($bestaand['gebruiker'] ?? ''),
    'wachtwoord' => !empty($wachtwoord)? $wachtwoord: ($bestaand['wachtwoord']?? ''),
    'afzender'   => !empty($afzender)  ? $afzender  : ($bestaand['afzender']  ?? ''),
    'ontvanger'  => !empty($ontvanger) ? $ontvanger : ($bestaand['ontvanger'] ?? ''),
    'encryptie'  => ($poort === 465) ? 'ssl' : 'tls',

    // === Bevestigingsmail-velden ALTIJD overnemen uit bestaand — NOOIT overschrijven ===
    'bev_onderwerp' => $bestaand['bev_onderwerp'] ?? '',
    'bev_bericht'   => $bestaand['bev_bericht']   ?? '',
];

// Minimale validatie
if (empty($nieuwe_config['host']) || empty($nieuwe_config['gebruiker']) || empty($nieuwe_config['ontvanger'])) {
    header('Location: dashboard.php?fout=validatie'); exit;
}

if (atomic_write_config($config_pad, $nieuwe_config)) {
    header('Location: dashboard.php?opgeslagen=smtp');
} else {
    header('Location: dashboard.php?fout=schrijf');
}
exit;
