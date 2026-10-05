<?php
/**
 * Client SMTP minimal partagé par envoi.php et reservations.php — site Héman
 * Nécessite config.php (SMTP_HOTE, SMTP_PORT, SMTP_USER, SMTP_PASS, SMTP_NOM).
 */

/* =====================================================================
   Client SMTP minimal — authentification et STARTTLS, sans dépendance
   ===================================================================== */

/** Lit la réponse du serveur et vérifie le code attendu. */
function smtp_lire($socket, $attendu)
{
    $reponse = '';
    while (($ligne = fgets($socket, 515)) !== false) {
        $reponse .= $ligne;
        // Dernière ligne d'une réponse multiligne : "250 " et non "250-"
        if (isset($ligne[3]) && $ligne[3] === ' ') { break; }
    }
    return substr($reponse, 0, 3) == $attendu;
}

/** Envoie une commande et contrôle le code de retour. */
function smtp_dire($socket, $commande, $attendu)
{
    fwrite($socket, $commande . "\r\n");
    return smtp_lire($socket, $attendu);
}

/**
 * Envoie un e-mail texte via la boîte d'expédition configurée.
 * $repondreA / $repondreNom : adresse du visiteur, pour répondre en un clic.
 */
function smtp_envoyer($a, $objet, $corps, $repondreA = '', $repondreNom = '')
{
    $socket = @stream_socket_client(
        'tcp://' . SMTP_HOTE . ':' . SMTP_PORT,
        $errno, $errstr, 20
    );
    if (!$socket) {
        error_log('Héman/SMTP : connexion impossible — ' . $errstr);
        return false;
    }
    stream_set_timeout($socket, 20);

    $hote = isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : 'heman.website';
    $ok = smtp_lire($socket, 220)
        && smtp_dire($socket, 'EHLO ' . $hote, 250)
        && smtp_dire($socket, 'STARTTLS', 220);

    if ($ok) {
        $ok = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
    }
    if ($ok) {
        $ok = smtp_dire($socket, 'EHLO ' . $hote, 250)
            && smtp_dire($socket, 'AUTH LOGIN', 334)
            && smtp_dire($socket, base64_encode(SMTP_USER), 334)
            && smtp_dire($socket, base64_encode(SMTP_PASS), 235);
    }
    if (!$ok) {
        error_log('Héman/SMTP : authentification refusée.');
        fclose($socket);
        return false;
    }

    $ok = smtp_dire($socket, 'MAIL FROM:<' . SMTP_USER . '>', 250)
        && smtp_dire($socket, 'RCPT TO:<' . $a . '>', 250)
        && smtp_dire($socket, 'DATA', 354);
    if (!$ok) {
        error_log('Héman/SMTP : destinataire refusé — ' . $a);
        fclose($socket);
        return false;
    }

    $entetes = array(
        'From: =?UTF-8?B?' . base64_encode(SMTP_NOM) . '?= <' . SMTP_USER . '>',
        'To: <' . $a . '>',
        'Subject: =?UTF-8?B?' . base64_encode($objet) . '?=',
        'Date: ' . date('r'),
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    );
    if ($repondreA !== '') {
        $entetes[] = 'Reply-To: =?UTF-8?B?' . base64_encode($repondreNom) . '?= <' . $repondreA . '>';
    }

    // Un point seul en début de ligne terminerait le message : on le double
    $texte = str_replace("\r\n.", "\r\n..", $corps);

    fwrite($socket, implode("\r\n", $entetes) . "\r\n\r\n" . $texte . "\r\n.\r\n");
    $ok = smtp_lire($socket, 250);

    smtp_dire($socket, 'QUIT', 221);
    fclose($socket);

    return $ok;
}
