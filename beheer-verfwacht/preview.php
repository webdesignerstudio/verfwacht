<?php
/**
 * preview.php — Rendert een e-mail preview met demo-data.
 * Type: offerte | bevestiging
 */
require_once __DIR__ . '/helpers.php';
secure_session_start();

require_auth();
check_timeout();

$cfg = include __DIR__ . '/../site-config.php';
$smtp = [];
if (file_exists(__DIR__ . '/../smtp_config.php')) {
    $smtp = include __DIR__ . '/../smtp_config.php';
}

require_once __DIR__ . '/../smtp_mailer.php';

$type = $_GET['type'] ?? 'offerte';

// Demo data
$demo_naam     = 'Jan Jansen';
$demo_telefoon = '06 12345678';
$demo_email    = 'jan@example.com';
$demo_dienst   = $cfg['diensten'][array_key_first($cfg['diensten'] ?? [])] ?? 'Website laten maken';
$demo_bericht  = 'Hallo, ik wil graag een website laten maken voor mijn bedrijf. Kunt u contact met mij opnemen voor een vrijblijvende offerte?';
$demo_bev_tekst = !empty($smtp['bev_bericht']) ? $smtp['bev_bericht'] : 'Bedankt voor uw aanvraag! Wij nemen zo snel mogelijk contact met u op.';

if ($type === 'offerte') {
    $html = bouw_offerte_html($demo_naam, $demo_telefoon, $demo_email, $demo_dienst, $demo_bericht, $cfg);
} else {
    $html = bouw_bevestiging_html($demo_naam, $demo_dienst, $demo_bev_tekst, $demo_bericht, $cfg);
}

header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: SAMEORIGIN');
echo $html;
