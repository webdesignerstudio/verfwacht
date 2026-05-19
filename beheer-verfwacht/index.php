<?php
require_once __DIR__ . '/helpers.php';
secure_session_start();

$cfg = include __DIR__ . '/../site-config.php';
$dash_kleur = htmlspecialchars($cfg['dash_kleur'] ?? '#000000');
$bedrijf    = htmlspecialchars($cfg['bedrijfsnaam'] ?? 'Website');
$website    = htmlspecialchars($cfg['website_url']  ?? '../index.html');

// Als al ingelogd, doorsturen naar dashboard
if (!empty($_SESSION['beheer_auth'])) {
    header('Location: dashboard.php');
    exit;
}

// === BRUTE-FORCE LOCKOUT ===
// Max 5 mislukte pogingen per IP, 15 minuten blokkering
$ip          = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$lock_dir    = sys_get_temp_dir() . '/beheerlock';
if (!is_dir($lock_dir)) {
    @mkdir($lock_dir, 0700, true);
}
$lock_file   = $lock_dir . '/' . md5($ip) . '.json';
$lock_data   = ['count' => 0, 'geblokkeerd_tot' => 0];
if (file_exists($lock_file)) {
    $gelezen = json_decode(@file_get_contents($lock_file), true);
    if (is_array($gelezen)) {
        $lock_data = $gelezen;
    }
}

$geblokkeerd = time() < ($lock_data['geblokkeerd_tot'] ?? 0);
$fout = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$geblokkeerd) {
    $ingevoerd = $_POST['wachtwoord'] ?? '';
    $hash      = $cfg['beheer_hash'] ?? '';

    if (!empty($hash) && password_verify($ingevoerd, $hash)) {
        // Succes: reset lockout, vernieuw sessie-ID (voorkomt session fixation)
        $lock_data = ['count' => 0, 'geblokkeerd_tot' => 0];
        @file_put_contents($lock_file, json_encode($lock_data));

        session_regenerate_id(true);
        $_SESSION['beheer_auth'] = true;
        $_SESSION['beheer_time'] = time();
        header('Location: dashboard.php');
        exit;
    } else {
        // Mislukt: teller verhogen
        $lock_data['count'] = ($lock_data['count'] ?? 0) + 1;
        if ($lock_data['count'] >= 5) {
            $lock_data['geblokkeerd_tot'] = time() + 900; // 15 minuten
            $lock_data['count']           = 0;
            $geblokkeerd                  = true;
        }
        @file_put_contents($lock_file, json_encode($lock_data));

        if ($geblokkeerd) {
            $fout = 'Te veel mislukte pogingen. Probeer het over 15 minuten opnieuw.';
        } else {
            $pogingen_over = 5 - $lock_data['count'];
            $fout = "Onjuist wachtwoord. Nog {$pogingen_over} poging(en) over.";
        }
    }
}

if ($geblokkeerd && empty($fout)) {
    $fout = 'Te veel mislukte pogingen. Probeer het over 15 minuten opnieuw.';
}

$uitgelogd = !empty($_GET['uitgelogd']);
$timeout   = !empty($_GET['timeout']);
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Beheer — <?= $bedrijf ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root { --accent: <?= $dash_kleur ?>; }
        body {
            font-family: 'Inter', sans-serif;
            background: #0f0f0f;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }
        .login-card {
            background: #1a1a1a;
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 16px;
            padding: 3rem;
            width: 100%;
            max-width: 420px;
            box-shadow: 0 25px 50px rgba(0,0,0,0.5);
        }
        .logo-area { text-align: center; margin-bottom: 2.5rem; }
        .logo-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 64px; height: 64px;
            background: color-mix(in srgb, var(--accent) 15%, transparent);
            border-radius: 16px;
            margin-bottom: 1.25rem;
        }
        .logo-badge svg { width: 32px; height: 32px; color: var(--accent); }
        .logo-area h1 { font-size: 1.3rem; font-weight: 800; color: #fff; margin-bottom: 0.35rem; }
        .logo-area p  { font-size: 0.8rem; color: rgba(255,255,255,0.4); }
        label {
            display: block;
            font-size: 0.78rem;
            font-weight: 700;
            color: rgba(255,255,255,0.55);
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 0.5rem;
        }
        input[type="password"] {
            width: 100%;
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 8px;
            padding: 0.85rem 1rem;
            font-size: 1rem;
            color: #fff;
            font-family: inherit;
            outline: none;
            transition: border-color 0.2s;
            margin-bottom: 1.25rem;
        }
        input[type="password"]:focus { border-color: var(--accent); }
        .btn-login {
            width: 100%;
            background: var(--accent);
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 0.9rem;
            font-size: 0.9rem;
            font-weight: 800;
            font-family: inherit;
            cursor: pointer;
            transition: opacity 0.2s, transform 0.1s;
            letter-spacing: 0.5px;
        }
        .btn-login:hover { opacity: 0.88; }
        .btn-login:active { transform: scale(0.98); }
        .btn-login:disabled { opacity: 0.4; cursor: not-allowed; }
        .melding {
            border-radius: 8px;
            padding: 0.75rem 1rem;
            font-size: 0.85rem;
            margin-bottom: 1.25rem;
        }
        .melding.fout    { background: rgba(220,53,69,0.1);  border: 1px solid rgba(220,53,69,0.3);  color: #ff6b6b; }
        .melding.succes  { background: rgba(34,197,94,0.1);  border: 1px solid rgba(34,197,94,0.25); color: #4ade80; }
        .melding.info    { background: rgba(255,200,0,0.08); border: 1px solid rgba(255,200,0,0.2);  color: #fbbf24; }
        .terug { text-align: center; margin-top: 1.5rem; }
        .terug a { font-size: 0.8rem; color: rgba(255,255,255,0.3); text-decoration: none; transition: color 0.2s; }
        .terug a:hover { color: rgba(255,255,255,0.6); }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="logo-area">
            <div class="logo-badge">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                </svg>
            </div>
            <h1>Beheer Portaal</h1>
            <p><?= $bedrijf ?> — Intern gebruik</p>
        </div>

        <?php if ($fout): ?>
            <div class="melding fout"><?= htmlspecialchars($fout) ?></div>
        <?php elseif ($uitgelogd): ?>
            <div class="melding succes">U bent uitgelogd.</div>
        <?php elseif ($timeout): ?>
            <div class="melding info">Sessie verlopen wegens inactiviteit. Log opnieuw in.</div>
        <?php endif; ?>

        <form method="POST">
            <label for="wachtwoord">Wachtwoord</label>
            <input type="password" id="wachtwoord" name="wachtwoord"
                   placeholder="••••••••••" autofocus
                   <?= $geblokkeerd ? 'disabled' : '' ?>>
            <button type="submit" class="btn-login" <?= $geblokkeerd ? 'disabled' : '' ?>>
                Inloggen →
            </button>
        </form>

        <div class="terug">
            <a href="<?= $website ?>">← Terug naar de website</a>
        </div>
    </div>
</body>
</html>
