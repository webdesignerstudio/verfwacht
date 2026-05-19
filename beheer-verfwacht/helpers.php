<?php
/**
 * beheer/helpers.php — Gedeelde functies voor het beheerportaal
 */

/**
 * Start sessie met veilige cookie flags.
 * Wordt aangeroepen vóór elke session_start() in het beheerportaal.
 */
function secure_session_start(): void {
    if (session_status() === PHP_SESSION_NONE) {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

/**
 * Controleer of gebruiker ingelogd is, anders doorsturen naar login.
 */
function require_auth(): void {
    if (empty($_SESSION['beheer_auth'])) {
        header('Location: index.php');
        exit;
    }
}

/**
 * Controleer sessie-timeout (2 uur). Vernietigt sessie bij timeout.
 */
function check_timeout(int $timeout_seconds = 7200): void {
    if (time() - ($_SESSION['beheer_time'] ?? 0) > $timeout_seconds) {
        session_destroy();
        header('Location: index.php?timeout=1');
        exit;
    }
    $_SESSION['beheer_time'] = time();
}

/**
 * Valideer CSRF-token uit POST. Vernieuwt token bij succes.
 * Bij fout: doorsturen met foutmelding.
 */
function verify_csrf(string $redirect = 'dashboard.php?fout=csrf'): void {
    $csrf = $_POST['csrf_token'] ?? '';
    if (empty($csrf) || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
        header("Location: {$redirect}");
        exit;
    }
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/**
 * Schrijf een PHP config-array atomisch naar een bestand (tempfile → rename).
 * Returnt true bij succes, false bij mislukking.
 */
function atomic_write_config(string $path, array $config, string $header_comment = 'Configuratie'): bool {
    $inhoud  = "<?php\n// {$header_comment} — automatisch gegenereerd op " . date('Y-m-d H:i:s') . "\n";
    $inhoud .= "// OPGELET: Dit bestand bevat gevoelige gegevens. Niet in Git opslaan!\n";
    $inhoud .= "return " . var_export($config, true) . ";\n";

    $tmp = $path . '.tmp.' . getmypid();
    $ok  = @file_put_contents($tmp, $inhoud, LOCK_EX);

    if ($ok === false) {
        return false;
    }
    if (@rename($tmp, $path)) {
        return true;
    }
    @unlink($tmp);
    return false;
}
