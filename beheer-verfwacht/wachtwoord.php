<?php
require_once __DIR__ . '/helpers.php';
secure_session_start();

require_auth();
check_timeout();

$cfg        = include __DIR__ . '/../site-config.php';
$bedrijf    = htmlspecialchars($cfg['bedrijfsnaam'] ?? 'Website');
$website    = htmlspecialchars($cfg['website_url']  ?? '../index.html');
$dash_kleur = htmlspecialchars($cfg['dash_kleur']   ?? '#000000');

// Statusberichten
$bericht = '';
$bericht_type = '';

if (!empty($_GET['opgeslagen'])) {
    $bericht_type = 'success';
    $bericht = 'Wachtwoord succesvol gewijzigd.';
} elseif (!empty($_GET['fout'])) {
    $bericht_type = 'error';
    switch ($_GET['fout']) {
        case 'csrf':      $bericht = 'Beveiligingsfout: ongeldige token. Probeer opnieuw.'; break;
        case 'oud':       $bericht = 'Huidig wachtwoord is onjuist.'; break;
        case 'te_kort':   $bericht = 'Nieuw wachtwoord moet minimaal 8 tekens bevatten.'; break;
        case 'match':     $bericht = 'De nieuwe wachtwoorden komen niet overeen.'; break;
        case 'schrijf':   $bericht = 'Kon het configuratiebestand niet schrijven. Controleer de schrijfrechten.'; break;
        default:          $bericht = 'Er is iets misgegaan.';
    }
}

// CSRF-token aanmaken als het nog niet bestaat
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Wachtwoord wijzigen — <?= $bedrijf ?></title>
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
        .main { max-width: 520px; margin: 0 auto; padding: 3rem 2rem; }
        .page-header { margin-bottom: 2.5rem; }
        .page-header h1 { font-size: 1.6rem; font-weight: 800; margin-bottom: 0.4rem; }
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
        .kaart { background: #1a1a1a; border: 1px solid rgba(255,255,255,0.07); border-radius: 14px; overflow: hidden; }
        .kaart-body { padding: 2rem; }

        /* FORMULIER */
        .form-group { display: flex; flex-direction: column; gap: 0.5rem; margin-bottom: 1.25rem; }
        label {
            font-size: 0.75rem; font-weight: 700;
            color: rgba(255,255,255,0.5);
            text-transform: uppercase; letter-spacing: 0.8px;
        }
        input[type="password"] {
            width: 100%;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.09);
            border-radius: 8px;
            padding: 0.8rem 1rem;
            font-size: 0.9rem; color: #fff;
            font-family: inherit; outline: none;
            transition: border-color 0.2s, background 0.2s;
        }
        input[type="password"]:focus {
            border-color: var(--accent);
            background: color-mix(in srgb, var(--accent) 4%, transparent);
        }
        input[type="password"]::placeholder { color: rgba(255,255,255,0.2); }
        .hint { font-size: 0.72rem; color: rgba(255,255,255,0.3); line-height: 1.5; margin-top: 0.25rem; }

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
        .btn-secundair {
            background: rgba(255,255,255,0.06);
            color: rgba(255,255,255,0.6);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 8px; padding: 0.8rem 1.5rem;
            font-size: 0.88rem; font-weight: 700;
            font-family: inherit; cursor: pointer;
            transition: all 0.2s; text-decoration: none; display: inline-block;
        }
        .btn-secundair:hover { background: rgba(255,255,255,0.1); color: #fff; }
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
        <a href="<?= $website ?>" target="_blank">Website bekijken ↗</a>
        <a href="logout.php" class="btn-logout">Uitloggen</a>
    </div>
</div>

<div class="main">
    <div class="page-header">
        <h1>Wachtwoord wijzigen</h1>
        <p>Kies een sterk wachtwoord voor het beheerportaal. Het oude wachtwoord is vereist ter verificatie.</p>
    </div>

    <?php if ($bericht): ?>
        <div class="status-balk <?= $bericht_type ?>">
            <?= htmlspecialchars($bericht) ?>
        </div>
    <?php endif; ?>

    <div class="kaart">
        <div class="kaart-body">
            <form method="POST" action="opslaan-wachtwoord.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

                <div class="form-group">
                    <label for="wachtwoord_oud">Huidig wachtwoord</label>
                    <input type="password" id="wachtwoord_oud" name="wachtwoord_oud" required autofocus>
                </div>

                <div class="form-group">
                    <label for="wachtwoord_nieuw">Nieuw wachtwoord</label>
                    <input type="password" id="wachtwoord_nieuw" name="wachtwoord_nieuw" required minlength="8">
                    <span class="hint">Minimaal 8 tekens.</span>
                </div>

                <div class="form-group">
                    <label for="wachtwoord_bevestig">Bevestig nieuw wachtwoord</label>
                    <input type="password" id="wachtwoord_bevestig" name="wachtwoord_bevestig" required minlength="8">
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn-primary">Wachtwoord opslaan</button>
                    <a href="dashboard.php" class="btn-secundair">Annuleren</a>
                </div>
            </form>
        </div>
    </div>
</div>

</body>
</html>
