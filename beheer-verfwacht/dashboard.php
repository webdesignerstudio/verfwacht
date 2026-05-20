<?php
require_once __DIR__ . '/helpers.php';
secure_session_start();

require_auth();
check_timeout();

// CSRF-token aanmaken als het nog niet bestaat
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// Site config & SMTP config laden
$cfg        = include __DIR__ . '/../site-config.php';
$config_pad = __DIR__ . '/../smtp_config.php';
$smtp       = [];
if (file_exists($config_pad)) {
    $smtp = include $config_pad;
}

$bedrijf    = htmlspecialchars($cfg['bedrijfsnaam'] ?? 'Website');
$website    = htmlspecialchars($cfg['website_url']  ?? '../index.html');
$dash_kleur = htmlspecialchars($cfg['dash_kleur']   ?? '#000000');

// Statusberichten
$bericht = '';
$bericht_type = '';

$meldingen = [
    'opgeslagen=smtp'        => ['success', '✓ SMTP-instellingen opgeslagen. De formulieren gebruiken nu uw e-mailserver.'],
    'opgeslagen=bevestiging' => ['success', '✓ Bevestigingsmail opgeslagen.'],
    'test_ok'                => ['success', null],  // dynamisch
    'test_fout'              => ['error',   null],  // dynamisch
    'fout=validatie'         => ['error',   '✗ Vul alle verplichte velden in (host, gebruikersnaam, ontvanger).'],
    'fout=schrijf'           => ['error',   '✗ Kon het configuratiebestand niet schrijven. Controleer de schrijfrechten.'],
    'fout=rename'            => ['error',   '✗ Kon het configuratiebestand niet opslaan (rename mislukt).'],
    'fout=csrf'              => ['error',   '✗ Beveiligingsfout: ongeldige token. Probeer opnieuw.'],
    'fout=smtp_eerst'        => ['error',   '✗ Sla eerst de SMTP-instellingen op voordat u de bevestigingsmail aanpast.'],
    'fout=geen_smtp'         => ['error',   '✗ Geen SMTP-instellingen gevonden. Sla deze eerst op.'],
];

foreach ($meldingen as $param => [$type, $tekst]) {
    [$key, $val] = array_pad(explode('=', $param, 2), 2, null);
    if ($val !== null && ($_GET[$key] ?? '') === $val) {
        $bericht_type = $type;
        $bericht      = $tekst;
        break;
    }
    if ($val === null && !empty($_GET[$key])) {
        $bericht_type = $type;
        if ($key === 'test_ok') {
            $bericht = '✓ Testmail verzonden naar ' . htmlspecialchars($_GET[$key]);
        } elseif ($key === 'test_fout') {
            $bericht = '✗ Testmail mislukt: ' . htmlspecialchars(urldecode($_GET[$key]));
        }
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Beheer Dashboard — <?= $bedrijf ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root { --accent: <?= $dash_kleur ?>; }
        body { font-family: 'Inter', sans-serif; background: #0f0f0f; color: #fff; min-height: 100vh; }

        /* TOPBAR */
        .topbar {
            background: #1a1a1a;
            border-bottom: 1px solid rgba(255,255,255,0.06);
            padding: 1rem 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .topbar-brand { display: flex; align-items: center; gap: 0.75rem; }
        .brand-icon {
            width: 36px; height: 36px;
            background: color-mix(in srgb, var(--accent) 15%, transparent);
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
        }
        .brand-icon svg { width: 18px; height: 18px; color: var(--accent); }
        .brand-name { font-size: 0.9rem; font-weight: 800; color: #fff; }
        .brand-sub  { font-size: 0.7rem; color: rgba(255,255,255,0.4); }
        .topbar-nav { display: flex; align-items: center; gap: 1rem; }
        .topbar-nav a { font-size: 0.8rem; color: rgba(255,255,255,0.45); text-decoration: none; transition: color 0.2s; }
        .topbar-nav a:hover { color: #fff; }
        .btn-logout {
            font-size: 0.78rem; font-weight: 700;
            background: rgba(255,255,255,0.06);
            color: rgba(255,255,255,0.55);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 6px; padding: 0.4rem 0.85rem;
            cursor: pointer; text-decoration: none; transition: all 0.2s;
        }
        .btn-logout:hover { background: rgba(220,53,69,0.15); color: #ff6b6b; border-color: rgba(220,53,69,0.3); }

        /* MAIN */
        .main { max-width: 760px; margin: 0 auto; padding: 3rem 2rem; }
        .page-header { margin-bottom: 2.5rem; }
        .page-header h1 { font-size: 1.8rem; font-weight: 800; margin-bottom: 0.4rem; }
        .page-header p  { color: rgba(255,255,255,0.45); font-size: 0.9rem; line-height: 1.6; }

        /* STATUSBALK */
        .status-balk {
            border-radius: 10px; padding: 1rem 1.25rem;
            font-size: 0.88rem; font-weight: 600;
            margin-bottom: 2rem;
            display: flex; align-items: center; gap: 0.6rem;
        }
        .status-balk.success { background: rgba(34,197,94,0.1);  border: 1px solid rgba(34,197,94,0.25); color: #4ade80; }
        .status-balk.error   { background: rgba(220,53,69,0.1);  border: 1px solid rgba(220,53,69,0.25); color: #ff6b6b; }

        /* KAART */
        .kaart { background: #1a1a1a; border: 1px solid rgba(255,255,255,0.07); border-radius: 14px; overflow: hidden; margin-bottom: 2rem; }
        .kaart-header {
            padding: 1.5rem 2rem; border-bottom: 1px solid rgba(255,255,255,0.06);
            display: flex; align-items: center; gap: 1rem;
        }
        .kaart-icon {
            width: 44px; height: 44px;
            background: color-mix(in srgb, var(--accent) 10%, transparent);
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center; flex-shrink: 0;
        }
        .kaart-icon svg { width: 22px; height: 22px; color: var(--accent); }
        .kaart-icon.groen { background: rgba(34,197,94,0.1); }
        .kaart-icon.groen svg { color: #4ade80; }
        .kaart-header h2 { font-size: 1.05rem; font-weight: 800; margin-bottom: 0.2rem; }
        .kaart-header p  { font-size: 0.8rem; color: rgba(255,255,255,0.4); }
        .kaart-body { padding: 2rem; }

        /* FORMULIER */
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; }
        .form-group { display: flex; flex-direction: column; gap: 0.5rem; }
        .form-group.full { grid-column: 1 / -1; }
        label {
            font-size: 0.75rem; font-weight: 700;
            color: rgba(255,255,255,0.5);
            text-transform: uppercase; letter-spacing: 0.8px;
        }
        .input-wrap { position: relative; }
        input[type="text"], input[type="email"], input[type="number"],
        input[type="password"], select, textarea {
            width: 100%;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.09);
            border-radius: 8px;
            padding: 0.8rem 1rem;
            font-size: 0.9rem; color: #fff;
            font-family: inherit; outline: none;
            transition: border-color 0.2s, background 0.2s;
            -webkit-appearance: none;
        }
        input:focus, select:focus, textarea:focus {
            border-color: var(--accent);
            background: color-mix(in srgb, var(--accent) 4%, transparent);
        }
        input::placeholder, textarea::placeholder { color: rgba(255,255,255,0.2); }
        select option { background: #1a1a1a; color: #fff; }
        textarea { min-height: 100px; resize: vertical; }
        .hint { font-size: 0.72rem; color: rgba(255,255,255,0.3); line-height: 1.5; }

        /* TOGGLE wachtwoord */
        .pw-toggle {
            position: absolute; right: 0.75rem; top: 50%;
            transform: translateY(-50%);
            background: none; border: none; cursor: pointer;
            color: rgba(255,255,255,0.35); padding: 0; transition: color 0.2s;
        }
        .pw-toggle:hover { color: rgba(255,255,255,0.65); }
        .pw-toggle svg { width: 18px; height: 18px; display: block; }
        input.pw-input { padding-right: 2.75rem; }

        /* ACTIES */
        .form-actions { display: flex; gap: 1rem; margin-top: 2rem; flex-wrap: wrap; align-items: center; }
        .btn-primary {
            background: var(--accent); color: #fff;
            border: none; border-radius: 8px;
            padding: 0.8rem 1.75rem;
            font-size: 0.88rem; font-weight: 800;
            font-family: inherit; cursor: pointer;
            transition: opacity 0.2s, transform 0.1s;
        }
        .btn-primary:hover { opacity: 0.88; }
        .btn-primary:active { transform: scale(0.98); }
        .btn-groen {
            background: #22c55e; color: #fff;
            border: none; border-radius: 8px;
            padding: 0.8rem 1.75rem;
            font-size: 0.88rem; font-weight: 800;
            font-family: inherit; cursor: pointer;
            transition: opacity 0.2s, transform 0.1s;
        }
        .btn-groen:hover { opacity: 0.88; }
        .btn-test {
            background: rgba(255,255,255,0.06);
            color: rgba(255,255,255,0.6);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 8px; padding: 0.8rem 1.5rem;
            font-size: 0.88rem; font-weight: 700;
            font-family: inherit; cursor: pointer;
            transition: all 0.2s; text-decoration: none; display: inline-block;
        }
        .btn-test:hover { background: rgba(255,255,255,0.1); color: #fff; }

        /* STATUS INDICATOR */
        .smtp-status {
            display: inline-flex; align-items: center; gap: 0.5rem;
            font-size: 0.78rem; font-weight: 600;
            padding: 0.4rem 0.85rem; border-radius: 20px;
        }
        .smtp-status.actief   { background: rgba(34,197,94,0.1); color: #4ade80; border: 1px solid rgba(34,197,94,0.2); }
        .smtp-status.inactief { background: rgba(255,200,0,0.1);  color: #fbbf24; border: 1px solid rgba(255,200,0,0.2); }
        .smtp-status::before  { content: ''; width: 7px; height: 7px; border-radius: 50%; background: currentColor; }

        /* INFO BLOK */
        .info-blok {
            background: rgba(255,255,255,0.03);
            border: 1px solid rgba(255,255,255,0.07);
            border-radius: 10px; padding: 1.25rem 1.5rem; margin-top: 2rem;
        }
        .info-blok h4 { font-size: 0.8rem; font-weight: 700; color: rgba(255,255,255,0.55); margin-bottom: 0.75rem; text-transform: uppercase; letter-spacing: 0.8px; }
        .info-blok ul { display: flex; flex-direction: column; gap: 0.4rem; list-style: none; }
        .info-blok ul li { font-size: 0.82rem; color: rgba(255,255,255,0.4); padding-left: 1rem; position: relative; }
        .info-blok ul li::before { content: '→'; position: absolute; left: 0; color: var(--accent); }

        @media (max-width: 600px) {
            .form-grid { grid-template-columns: 1fr; }
            .form-group.full { grid-column: 1; }
        }
    </style>
</head>
<body>

<div class="topbar">
    <div class="topbar-brand">
        <div class="brand-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
            </svg>
        </div>
        <div>
            <div class="brand-name"><?= $bedrijf ?> Beheer</div>
            <div class="brand-sub">Intern portaal — vertrouwelijk</div>
        </div>
    </div>
    <div class="topbar-nav">
        <a href="wachtwoord.php">Wachtwoord wijzigen</a>
        <a href="<?= $website ?>" target="_blank">Website bekijken ↗</a>
        <a href="logout.php" class="btn-logout">Uitloggen</a>
    </div>
</div>

<div class="main">
    <div class="page-header">
        <h1>E-mail Instellingen</h1>
        <p>Vul hier uw SMTP-gegevens in. De formulieren op de website sturen berichten via uw eigen e-mailserver, zodat ze betrouwbaar aankomen en nooit in de spam belanden.</p>
    </div>

    <?php if ($bericht): ?>
        <div class="status-balk <?= $bericht_type ?>">
            <?= htmlspecialchars($bericht) ?>
        </div>
    <?php endif; ?>

    <!-- ===== SMTP FORMULIER ===== -->
    <div class="kaart">
        <div class="kaart-header">
            <div class="kaart-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                    <polyline points="22,6 12,13 2,6"/>
                </svg>
            </div>
            <div>
                <h2>SMTP Configuratie</h2>
                <p>Verbinding met uw e-mailprovider</p>
            </div>
            <div style="margin-left:auto;">
                <?php if (!empty($smtp['host'])): ?>
                    <span class="smtp-status actief">Geconfigureerd</span>
                <?php else: ?>
                    <span class="smtp-status inactief">Niet ingesteld</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="kaart-body">
            <form method="POST" action="opslaan-smtp.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="host">SMTP Server (Host)</label>
                        <input type="text" id="host" name="host"
                               value="<?= htmlspecialchars($smtp['host'] ?? '') ?>"
                               placeholder="bijv. mail.bedrijf.nl">
                        <span class="hint">Vraag dit op bij uw hostingprovider</span>
                    </div>
                    <div class="form-group">
                        <label for="poort">Poort</label>
                        <select id="poort" name="poort">
                            <option value="465" <?= ($smtp['poort'] ?? '465') == '465' ? 'selected' : '' ?>>465 (SSL — aanbevolen)</option>
                            <option value="587" <?= ($smtp['poort'] ?? '') == '587' ? 'selected' : '' ?>>587 (STARTTLS)</option>
                            <option value="25"  <?= ($smtp['poort'] ?? '') == '25'  ? 'selected' : '' ?>>25 (onbeveiligd)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="gebruiker">E-mailadres / Gebruikersnaam</label>
                        <input type="email" id="gebruiker" name="gebruiker"
                               value="<?= htmlspecialchars($smtp['gebruiker'] ?? '') ?>"
                               placeholder="info@bedrijf.nl">
                    </div>
                    <div class="form-group">
                        <label for="wachtwoord">E-mail Wachtwoord</label>
                        <div class="input-wrap">
                            <input type="password" id="wachtwoord" name="wachtwoord"
                                   class="pw-input"
                                   placeholder="<?= !empty($smtp['wachtwoord']) ? '••••••••• (bewaard — leeglaten om te behouden)' : 'Uw e-mailwachtwoord' ?>">
                            <button type="button" class="pw-toggle" onclick="togglePw()" title="Wachtwoord tonen/verbergen">
                                <svg id="pw-oog" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                    <circle cx="12" cy="12" r="3"/>
                                </svg>
                            </button>
                        </div>
                        <span class="hint">Laat leeg om het huidige wachtwoord te behouden.</span>
                    </div>
                    <div class="form-group full">
                        <label for="afzender">Naam afzender (weergegeven in inbox)</label>
                        <input type="text" id="afzender" name="afzender"
                               value="<?= htmlspecialchars($smtp['afzender'] ?? $cfg['bedrijfsnaam'] ?? '') ?>"
                               placeholder="<?= htmlspecialchars($cfg['bedrijfsnaam'] ?? 'Bedrijfsnaam') ?>">
                    </div>
                    <div class="form-group full">
                        <label for="ontvanger">Aanvragen ontvangen op (uw inbox)</label>
                        <input type="email" id="ontvanger" name="ontvanger"
                               value="<?= htmlspecialchars($smtp['ontvanger'] ?? $cfg['email_fallback'] ?? '') ?>"
                               placeholder="<?= htmlspecialchars($cfg['email_fallback'] ?? 'info@bedrijf.nl') ?>">
                        <span class="hint">Alle contactformulier-aanvragen worden naar dit adres gestuurd.</span>
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn-primary">SMTP opslaan</button>
                    <?php if (!empty($smtp['host'])): ?>
                        <a href="test.php" class="btn-test">Testmail versturen</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- ===== BEVESTIGINGSMAIL FORMULIER ===== -->
    <div class="kaart">
        <div class="kaart-header">
            <div class="kaart-icon groen">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                    <polyline points="22 4 12 14.01 9 11.01"/>
                </svg>
            </div>
            <div>
                <h2>Bevestigingsmail</h2>
                <p>Wat de klant ontvangt na een aanvraag</p>
            </div>
        </div>
        <div class="kaart-body">
            <form method="POST" action="opslaan-bevestiging.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <div class="form-grid">
                    <div class="form-group full">
                        <label for="bev_onderwerp">Onderwerp van de mail</label>
                        <input type="text" id="bev_onderwerp" name="bev_onderwerp"
                               value="<?= htmlspecialchars($smtp['bev_onderwerp'] ?? $cfg['bev_onderwerp'] ?? ('Uw aanvraag is ontvangen — ' . ($cfg['bedrijfsnaam'] ?? ''))) ?>">
                    </div>
                    <div class="form-group full">
                        <label for="bev_bericht">Berichttekst (na de aanhef "Beste [Naam],")</label>
                        <textarea id="bev_bericht" name="bev_bericht"><?= htmlspecialchars($smtp['bev_bericht'] ?? $cfg['bev_bericht'] ?? 'Bedankt voor uw aanvraag! Wij nemen zo snel mogelijk contact met u op.') ?></textarea>
                        <span class="hint">Dit is de openingstekst die de klant ziet. Kort en persoonlijk werkt het best.</span>
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn-groen">Bevestigingsmail opslaan</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ===== E-MAIL PREVIEW ===== -->
    <div class="kaart">
        <div class="kaart-header">
            <div class="kaart-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                    <circle cx="12" cy="12" r="3"/>
                </svg>
            </div>
            <div>
                <h2>E-mail Preview</h2>
                <p>Zo zien de mails eruit met de huidige stijl en teksten</p>
            </div>
        </div>
        <div class="kaart-body">
            <p style="font-size:0.88rem;color:rgba(255,255,255,0.5);margin-bottom:1rem;">
                Open een preview in een nieuw tabblad:
            </p>
            <div class="form-actions">
                <a href="preview.php?type=offerte" target="_blank" class="btn-test">Offerte-mail bekijken ↗</a>
                <a href="preview.php?type=bevestiging" target="_blank" class="btn-test">Bevestigingsmail bekijken ↗</a>
            </div>
        </div>
    </div>

    <div class="info-blok">
        <h4>Waar vind ik mijn SMTP-gegevens?</h4>
        <ul>
            <li>Log in op het beheerpaneel van uw hostingprovider (bijv. DirectAdmin, cPanel, Plesk)</li>
            <li>Ga naar E-mail → E-mailaccounts en open de instellingen van uw e-mailadres</li>
            <li>De host is vaak: <code style="background:rgba(255,255,255,0.08);padding:1px 5px;border-radius:4px;">mail.uwdomein.nl</code> of de servernaam van uw provider</li>
            <li>Kies poort 465 (SSL) voor de veiligste verbinding</li>
        </ul>
    </div>
</div>

<script>
function togglePw() {
    const input = document.getElementById('wachtwoord');
    input.type = input.type === 'password' ? 'text' : 'password';
}
</script>
</body>
</html>
