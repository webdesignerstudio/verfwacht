<?php
/**
 * smtp_mailer.php — Generieke SMTP-verzender zonder externe dependencies
 * Onderdeel van webdesignerstudio/smtpmailersystem
 *
 * Ondersteunt: SSL (poort 465) en STARTTLS (poort 587)
 * Beveiligingen: newline-sanitatie in headers, base64-encoded inhoud
 */

/**
 * Strip newlines uit header-waarden om header injection te voorkomen.
 */
function saniteer_header(string $waarde): string {
    return str_replace(["\r", "\n", "\r\n"], '', $waarde);
}

/**
 * Verstuur een e-mail via SMTP.
 *
 * @param array  $smtp      Config-array (host, poort, gebruiker, wachtwoord, afzender, encryptie)
 * @param string $aan       Ontvanger e-mailadres
 * @param string $onderwerp Onderwerp
 * @param string $inhoud    HTML of plaintext body
 * @param string $reply_to  Reply-To adres (optioneel)
 * @param bool   $html      Verstuur als HTML
 * @return true|string      true bij succes, foutmelding als string bij mislukking
 */
function smtp_verstuur(
    array $smtp,
    string $aan,
    string $onderwerp,
    string $inhoud,
    string $reply_to = '',
    bool $html = false
): true|string {

    $host      = $smtp['host']      ?? '';
    $poort     = intval($smtp['poort'] ?? 465);
    $gebruiker = $smtp['gebruiker'] ?? '';
    $wachtwoord= $smtp['wachtwoord']?? '';
    $afzender  = !empty($smtp['afzender']) ? $smtp['afzender'] : 'Website';
    $encryptie = $smtp['encryptie'] ?? ($poort === 465 ? 'ssl' : 'tls');
    $mailer_id = $smtp['mailer_id'] ?? 'WebMail/2.0';

    if (empty($host) || empty($gebruiker) || empty($wachtwoord)) {
        return 'SMTP niet geconfigureerd. Vul de gegevens in via het beheerportaal.';
    }

    // Newline-sanitatie op alle header-waarden
    $aan        = saniteer_header($aan);
    $onderwerp  = saniteer_header($onderwerp);
    $reply_to   = saniteer_header($reply_to);
    $afzender   = saniteer_header($afzender);
    $gebruiker  = saniteer_header($gebruiker);

    // Verbinding openen
    $socket_host = ($encryptie === 'ssl') ? "ssl://{$host}" : $host;
    $timeout     = 20;

    $conn = @fsockopen($socket_host, $poort, $errno, $errstr, $timeout);
    if (!$conn) {
        error_log("[SMTP] Verbinding mislukt met {$host}:{$poort} — {$errstr} ({$errno})");
        return 'Kan geen verbinding maken met de e-mailserver. Controleer de SMTP-instellingen.';
    }

    stream_set_timeout($conn, $timeout);

    $lees = function() use ($conn): string {
        $reactie = '';
        while (($r = fgets($conn, 515)) !== false) {
            $reactie .= $r;
            if (substr($r, 3, 1) === ' ') break;
        }
        return $reactie;
    };

    $stuur = function(string $cmd) use ($conn): void {
        fwrite($conn, $cmd . "\r\n");
    };

    // Handshake
    $r = $lees();
    if (strpos($r, '220') !== 0) { fclose($conn); error_log("[SMTP] Verwelkoming mislukt: {$r}"); return 'De e-mailserver reageerde onverwacht. Controleer de host en poort.'; }

    $stuur("EHLO " . gethostname());
    $r = $lees();
    if (strpos($r, '250') !== 0) { fclose($conn); error_log("[SMTP] EHLO mislukt: {$r}"); return 'De e-mailserver reageerde onverwacht. Controleer de host en poort.'; }

    // STARTTLS voor poort 587
    if ($encryptie === 'tls') {
        $stuur("STARTTLS");
        $r = $lees();
        if (strpos($r, '220') !== 0) { fclose($conn); error_log("[SMTP] STARTTLS mislukt: {$r}"); return 'Beveiligde verbinding kon niet worden opgestart. Probeer poort 465 (SSL).'; }
        stream_socket_enable_crypto($conn, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        $stuur("EHLO " . gethostname());
        $lees();
    }

    // Authenticatie
    $stuur("AUTH LOGIN");
    $r = $lees();
    if (strpos($r, '334') !== 0) { fclose($conn); error_log("[SMTP] AUTH LOGIN mislukt: {$r}"); return 'Authenticatie mislukt. Controleer gebruikersnaam en wachtwoord.'; }

    $stuur(base64_encode($gebruiker));
    $r = $lees();
    if (strpos($r, '334') !== 0) { fclose($conn); error_log("[SMTP] Gebruikersnaam geweigerd: {$r}"); return 'Authenticatie mislukt. Controleer gebruikersnaam en wachtwoord.'; }

    $stuur(base64_encode($wachtwoord));
    $r = $lees();
    if (strpos($r, '235') !== 0) { fclose($conn); error_log("[SMTP] Wachtwoord incorrect: {$r}"); return 'Wachtwoord of gebruikersnaam is onjuist.'; }

    // Afzender & ontvanger
    $stuur("MAIL FROM:<{$gebruiker}>");
    $r = $lees();
    if (strpos($r, '250') !== 0) { fclose($conn); error_log("[SMTP] MAIL FROM geweigerd: {$r}"); return 'Het afzenderadres werd geweigerd. Controleer het e-mailadres.'; }

    $stuur("RCPT TO:<{$aan}>");
    $r = $lees();
    if (strpos($r, '250') !== 0 && strpos($r, '251') !== 0) {
        fclose($conn);
        error_log("[SMTP] RCPT TO geweigerd voor {$aan}: {$r}");
        return 'Het ontvangeradres werd geweigerd. Controleer het e-mailadres.';
    }

    // DATA sectie
    $stuur("DATA");
    $r = $lees();
    if (strpos($r, '354') !== 0) { fclose($conn); error_log("[SMTP] DATA geweigerd: {$r}"); return 'De e-mail kon niet worden verzonden. Probeer het later opnieuw.'; }

    // Headers bouwen
    $datum        = date('r');
    $content_type = $html ? 'text/html; charset=UTF-8' : 'text/plain; charset=UTF-8';
    $msg_id       = '<' . time() . '.' . rand(1000, 9999) . '@' . $host . '>';
    $naam_encoded = '=?UTF-8?B?' . base64_encode($afzender) . '?=';
    $onderwerp_encoded = '=?UTF-8?B?' . base64_encode($onderwerp) . '?=';

    $headers  = "Date: {$datum}\r\n";
    $headers .= "From: {$naam_encoded} <{$gebruiker}>\r\n";
    $headers .= "To: {$aan}\r\n";
    if (!empty($reply_to)) {
        $headers .= "Reply-To: {$reply_to}\r\n";
    }
    $headers .= "Subject: {$onderwerp_encoded}\r\n";
    $headers .= "Message-ID: {$msg_id}\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: {$content_type}\r\n";
    $headers .= "Content-Transfer-Encoding: base64\r\n";
    $headers .= "X-Mailer: {$mailer_id}\r\n";
    $headers .= "\r\n";

    // Inhoud in chunks van 76 tekens (RFC 2045)
    $body = chunk_split(base64_encode($inhoud));

    $volledige = $headers . $body . "\r\n.";
    fwrite($conn, $volledige . "\r\n");
    $r = $lees();
    if (strpos($r, '250') !== 0) { fclose($conn); error_log("[SMTP] E-mail geweigerd: {$r}"); return 'De e-mail werd door de server geweigerd. Controleer de inhoud en afzender.'; }

    $stuur("QUIT");
    fclose($conn);

    return true;
}


/**
 * Bouw HTML e-mail voor offerte-aanvragen (naar de eigenaar van de website).
 *
 * @param string $naam
 * @param string $telefoon
 * @param string $email
 * @param string $dienst_label
 * @param string $bericht
 * @param array  $cfg          site-config waarden (kleuren, bedrijfsnaam etc.)
 */
function bouw_offerte_html(
    string $naam,
    string $telefoon,
    string $email,
    string $dienst_label,
    string $bericht,
    array $cfg = []
): string {
    $bedrijf   = htmlspecialchars($cfg['bedrijfsnaam'] ?? 'Website');
    $locatie   = htmlspecialchars($cfg['locatie']      ?? '');
    $primair   = htmlspecialchars($cfg['kleur_primair']   ?? '#000000');
    $header_bg = htmlspecialchars($cfg['kleur_header_bg'] ?? '#111111');
    $header_fg = htmlspecialchars($cfg['kleur_header_fg'] ?? '#ffffff');
    $tel_fg    = htmlspecialchars($cfg['kleur_tel_fg']    ?? $primair);

    $dienst_label_safe = htmlspecialchars($dienst_label, ENT_QUOTES, 'UTF-8');
    $naam_safe         = htmlspecialchars($naam, ENT_QUOTES, 'UTF-8');
    $telefoon_safe     = htmlspecialchars($telefoon, ENT_QUOTES, 'UTF-8');
    $email_safe        = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
    $bericht_safe      = htmlspecialchars($bericht, ENT_QUOTES, 'UTF-8');

    $dienst_rij = '';
    if (!empty($dienst_label_safe)) {
        $dienst_rij = <<<ROW
            <tr><td style="padding:10px 0;border-bottom:1px solid #f0f0f0;">
              <p style="margin:0;font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#999;font-weight:700;">Dienst</p>
              <p style="margin:4px 0 0;font-size:14px;color:#111;">{$dienst_label_safe}</p>
            </td></tr>
ROW;
    }

    $bericht_rij = '';
    if (!empty($bericht_safe)) {
        $bericht_rij = <<<ROW
            <tr><td style="padding:10px 0;">
              <p style="margin:0;font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#999;font-weight:700;">Bericht</p>
              <p style="margin:8px 0 0;font-size:14px;color:#333;line-height:1.7;background:#f9f9f9;padding:16px;border-radius:8px;border-left:3px solid {$primair};">{$bericht_safe}</p>
            </td></tr>
ROW;
    }

    return <<<HTML
<!DOCTYPE html>
<html lang="nl">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f4f4f4;font-family:Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4;padding:30px 15px;">
    <tr><td align="center">
      <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.08);">
        <tr><td style="background:{$header_bg};padding:30px 40px;">
          <h1 style="margin:0;color:{$header_fg};font-size:22px;font-weight:800;letter-spacing:-0.5px;">Nieuwe Aanvraag</h1>
          <p style="margin:6px 0 0;color:rgba(255,255,255,0.75);font-size:13px;">Via de website van {$bedrijf}</p>
        </td></tr>
        <tr><td style="padding:35px 40px;">
          <table width="100%" cellpadding="0" cellspacing="0">
            <tr><td style="padding:10px 0;border-bottom:1px solid #f0f0f0;">
              <p style="margin:0;font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#999;font-weight:700;">Naam</p>
              <p style="margin:4px 0 0;font-size:16px;font-weight:700;color:#111;">{$naam_safe}</p>
            </td></tr>
            <tr><td style="padding:10px 0;border-bottom:1px solid #f0f0f0;">
              <p style="margin:0;font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#999;font-weight:700;">Telefoon</p>
              <p style="margin:4px 0 0;font-size:16px;font-weight:700;color:{$tel_fg};">{$telefoon_safe}</p>
            </td></tr>
            <tr><td style="padding:10px 0;border-bottom:1px solid #f0f0f0;">
              <p style="margin:0;font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#999;font-weight:700;">E-mail</p>
              <p style="margin:4px 0 0;font-size:14px;color:#111;">{$email_safe}</p>
            </td></tr>
            {$dienst_rij}
            {$bericht_rij}
          </table>
          <table width="100%" cellpadding="0" cellspacing="0" style="margin-top:25px;">
            <tr>
              <td align="center">
                <a href="tel:{$telefoon_safe}" style="display:inline-block;background:{$primair};color:#fff;text-decoration:none;padding:13px 28px;border-radius:8px;font-weight:800;font-size:14px;">Bel {$naam_safe} terug</a>
              </td>
            </tr>
          </table>
        </td></tr>
        <tr><td style="background:#f9f9f9;padding:20px 40px;border-top:1px solid #f0f0f0;">
          <p style="margin:0;font-size:11px;color:#aaa;text-align:center;">{$bedrijf} &mdash; {$locatie}</p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
}


/**
 * Bouw bevestigingsmail (voor de klant/bezoeker zelf).
 *
 * @param string $naam
 * @param string $dienst_label
 * @param string $bericht_tekst   Aanpasbare tekst via dashboard
 * @param string $bericht         Origineel bericht van de bezoeker (optioneel)
 * @param array  $cfg             site-config waarden
 */
function bouw_bevestiging_html(
    string $naam,
    string $dienst_label,
    string $bericht_tekst,
    string $bericht = '',
    array $cfg = []
): string {
    $bedrijf   = htmlspecialchars($cfg['bedrijfsnaam'] ?? 'Website');
    $telefoon  = htmlspecialchars($cfg['telefoon']     ?? '');
    $locatie   = htmlspecialchars($cfg['locatie']      ?? '');
    $email_fb  = htmlspecialchars($cfg['email_fallback'] ?? '');
    $primair   = htmlspecialchars($cfg['kleur_primair']   ?? '#000000');
    $header_bg = htmlspecialchars($cfg['kleur_header_bg'] ?? '#111111');
    $header_fg = htmlspecialchars($cfg['kleur_header_fg'] ?? '#ffffff');

    $naam_safe        = htmlspecialchars($naam, ENT_QUOTES, 'UTF-8');
    $dienst_label_safe = htmlspecialchars($dienst_label, ENT_QUOTES, 'UTF-8');
    $bericht_tekst_safe = htmlspecialchars($bericht_tekst, ENT_QUOTES, 'UTF-8');
    $bericht_safe     = htmlspecialchars($bericht, ENT_QUOTES, 'UTF-8');

    $bericht_block = '';
    if (!empty($bericht_safe)) {
        $bericht_block = <<<MSG
            <div style="background:#f9f9f9;border-radius:8px;padding:20px;margin:20px 0;border-left:3px solid {$primair};">
              <p style="margin:0 0 6px;font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#999;font-weight:700;">Uw bericht</p>
              <p style="margin:0;font-size:14px;color:#333;line-height:1.6;">{$bericht_safe}</p>
            </div>
MSG;
    }

    $dienst_block = '';
    if (!empty($dienst_label_safe)) {
        $dienst_block = <<<SRV
          <div style="background:#f9f9f9;border-radius:8px;padding:20px;margin:20px 0;border-left:3px solid {$primair};">
            <p style="margin:0 0 6px;font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#999;font-weight:700;">Uw aanvraag</p>
            <p style="margin:0 0 4px;font-size:14px;font-weight:700;color:#111;">{$dienst_label_safe}</p>
          </div>
SRV;
    }

    $tel_link = '';
    if (!empty($telefoon)) {
        $tel_link = <<<TEL
          <p style="font-size:14px;color:#555;line-height:1.7;">Heeft u een dringende vraag? Belt u ons gerust:<br>
            <a href="tel:{$telefoon}" style="color:{$primair};font-weight:800;text-decoration:none;">{$telefoon}</a>
          </p>
TEL;
    }

    return <<<HTML
<!DOCTYPE html>
<html lang="nl">
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f4f4f4;font-family:Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4;padding:30px 15px;">
    <tr><td align="center">
      <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;">
        <tr><td style="background:{$header_bg};padding:30px 40px;">
          <h1 style="margin:0;color:{$header_fg};font-size:20px;font-weight:800;">Uw aanvraag is ontvangen</h1>
          <p style="margin:6px 0 0;color:{$header_fg};font-size:13px;opacity:0.9;">{$bedrijf} &mdash; {$locatie}</p>
        </td></tr>
        <tr><td style="padding:35px 40px;">
          <p style="font-size:15px;color:#333;line-height:1.7;">Beste {$naam_safe},</p>
          <p style="font-size:15px;color:#333;line-height:1.7;">{$bericht_tekst_safe}</p>
          {$dienst_block}
          {$bericht_block}
          {$tel_link}
          <p style="font-size:14px;color:#555;margin-top:20px;">Met vriendelijke groet,<br><strong>{$bedrijf}</strong></p>
        </td></tr>
        <tr><td style="background:#f9f9f9;padding:15px 40px;border-top:1px solid #f0f0f0;">
          <p style="margin:0;font-size:11px;color:#aaa;text-align:center;">{$locatie} &mdash; {$email_fb}</p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
}

