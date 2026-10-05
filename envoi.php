<?php
/**
 * Réception et envoi des formulaires du site Héman
 *
 * Reçoit un POST JSON depuis les pages du site, envoie la demande au bon
 * service, puis un accusé de réception au visiteur.
 * Aucune bibliothèque externe : client SMTP minimal dans smtp.php.
 */

require __DIR__ . '/config.php';
require __DIR__ . '/smtp.php';

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
