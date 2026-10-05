<?php
/**
 * Réception et envoi des formulaires du site Héman
 *
 * Reçoit un POST JSON depuis les pages du site, envoie la demande au bon
 * service, puis un accusé de réception au visiteur.
 * Aucune bibliothèque externe : client SMTP minimal écrit ci-dessous.
 */

require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(array('ok' => false, 'erreur' => 'Méthode non autorisée.'));
    exit;
}

$brut = file_get_contents('php://input');
$data = json_decode($brut, true);
if (!is_array($data)) {
    $data = $_POST;
}

/** Nettoie une valeur reçue : pas de retour à la ligne dans les en-têtes. */
function propre($v, $multiligne = false)
{
    $v = is_string($v) ? trim($v) : '';
    $v = str_replace(array("\0"), '', $v);
    if (!$multiligne) {
        $v = str_replace(array("\r", "\n"), ' ', $v);
    }
    return $v;
}

$type    = propre(isset($data['type']) ? $data['type'] : 'contact');
$nom     = propre(isset($data['nom']) ? $data['nom'] : '');
$email   = propre(isset($data['email']) ? $data['email'] : '');
$tel     = propre(isset($data['tel']) ? $data['tel'] : '');
$sujet   = propre(isset($data['sujet']) ? $data['sujet'] : '');
$message = propre(isset($data['message']) ? $data['message'] : '', true);
$extra   = isset($data['details']) && is_array($data['details']) ? $data['details'] : array();
$piege   = propre(isset($data['_piege']) ? $data['_piege'] : '');

// Champ invisible rempli : c'est un robot, on répond succès sans rien envoyer
if ($piege !== '') {
    error_log('Héman/Formulaire : champ anti-robot rempli — aucun envoi.');
    echo json_encode(array('ok' => true, 'piege' => true));
    exit;
}

if ($nom === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(array('ok' => false, 'erreur' => 'Nom ou e-mail manquant ou invalide.'));
    exit;
}

$destinataire = isset($DESTINATAIRES[$type]) ? $DESTINATAIRES[$type] : DESTINATAIRE_DEFAUT;

$libelles = array(
    'contact'     => 'Demande de contact',
    'location'    => 'Demande de location de salle',
    'inscription' => 'Demande d\'inscription',
    'boutique'    => 'Commande boutique',
    'presse'      => 'Demande presse',
);
$libelle = isset($libelles[$type]) ? $libelles[$type] : 'Demande';

// --- Corps du message destiné au service ---
$lignes = array();
$lignes[] = $libelle . ' — ' . SITE_NOM;
$lignes[] = str_repeat('-', 52);
$lignes[] = 'Nom       : ' . $nom;
$lignes[] = 'E-mail    : ' . $email;
if ($tel !== '')   { $lignes[] = 'Téléphone : ' . $tel; }
if ($sujet !== '') { $lignes[] = 'Objet     : ' . $sujet; }
foreach ($extra as $cle => $val) {
    if (is_array($val)) { $val = implode(', ', $val); }
    $lignes[] = str_pad(propre((string) $cle), 10) . ': ' . propre((string) $val, true);
}
if ($message !== '') {
    $lignes[] = '';
    $lignes[] = 'Message :';
    $lignes[] = $message;
}
$lignes[] = '';
$lignes[] = str_repeat('-', 52);
$lignes[] = 'Reçu le ' . date('d/m/Y à H:i') . ' via ' . SITE_URL;

$corps = implode("\r\n", $lignes);
$objet = '[' . $libelle . '] ' . $nom;

$envoye = smtp_envoyer($destinataire, $objet, $corps, $email, $nom);

// --- Accusé de réception au visiteur ---
if ($envoye && ACCUSE_RECEPTION) {
    $ar = array(
        'Bonjour ' . $nom . ',',
        '',
        'Nous avons bien reçu votre demande et nous vous répondrons sous ' . DELAI_REPONSE . '.',
        '',
        'Récapitulatif de votre demande :',
        str_repeat('-', 52),
        $message !== '' ? $message : $libelle,
        str_repeat('-', 52),
        '',
        'Merci de ne pas répondre à ce message : il est envoyé automatiquement.',
        'Pour nous joindre : contact@heman.fr — 06 80 77 85 48',
        '',
        'À bientôt,',
        SITE_NOM,
        SITE_URL,
    );
    smtp_envoyer($email, 'Votre demande a bien été reçue — Héman', implode("\r\n", $ar));
}

if (!$envoye) {
    http_response_code(500);
    echo json_encode(array('ok' => false, 'erreur' => 'L\'envoi a échoué. Merci de nous écrire directement à contact@heman.fr.'));
    exit;
}

echo json_encode(array('ok' => true));


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
