<?php
require_once __DIR__ . '/helpers.php';
secure_session_start();

require_auth();
check_timeout();

$config_pad = __DIR__ . '/../smtp_config.php';
$cfg        = include __DIR__ . '/../site-config.php';

if (!file_exists($config_pad)) {
    header('Location: dashboard.php?fout=geen_smtp'); exit;
}

$smtp = include $config_pad;
require_once __DIR__ . '/../smtp_mailer.php';

$smtp['mailer_id'] = $cfg['mailer_id'] ?? 'WebMail/2.0';

$ontvanger = $smtp['ontvanger'] ?? ($cfg['email_fallback'] ?? '');
$bedrijf   = htmlspecialchars($cfg['bedrijfsnaam'] ?? 'Website');

$onderwerp = 'Testmail — ' . $bedrijf;
$inhoud    = bouw_bevestiging_html(
    naam:          'Beheerder',
    dienst_label:  'Testmail',
    bericht_tekst: 'Dit is een testmail om te bevestigen dat uw SMTP-instellingen correct zijn geconfigureerd.',
    bericht:       '',
    cfg:           $cfg
);

$result = smtp_verstuur(
    smtp:      $smtp,
    aan:       $ontvanger,
    onderwerp: $onderwerp,
    inhoud:    $inhoud,
    reply_to:  '',
    html:      true
);

if ($result === true) {
    header('Location: dashboard.php?test_ok=' . urlencode($ontvanger));
} else {
    header('Location: dashboard.php?test_fout=' . urlencode($result));
}
exit;
