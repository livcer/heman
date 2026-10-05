<?php
/**
 * Réservation des salles — site Héman
 *
 * Point d'entrée unique du calendrier (page Location de salles et page admin).
 *
 *   GET  reservations.php?debut=AAAA-MM-JJ&fin=AAAA-MM-JJ
 *        → créneaux en attente et validés de la période.
 *          Visiteur : salle, date et horaires seulement. Admin connecté : tout.
 *   POST reservations.php  (JSON, champ "action")
 *        demande      → un visiteur demande un créneau (statut « attente »)
 *        connexion    → ouverture de la session admin
 *        deconnexion  → fermeture de la session admin
 *        valider      → admin : le créneau passe en « validee »
 *        refuser      → admin : le créneau est retiré du calendrier
 *        bloquer      → admin : ajoute directement un créneau validé (cours, stage…),
 *                       éventuellement répété chaque semaine jusqu'à une date
 *        supprimer    → admin : retire un créneau validé (annulation), ou la suite
 *                       d'une série à partir de ce créneau
 *
 * Les données sont stockées dans donnees/reservations.php : un fichier PHP qui
 * s'arrête dès sa première ligne, donc illisible depuis le web, doublé d'un
 * donnees/.htaccess. Aucune base de données à créer.
 */

require __DIR__ . '/config.php';
require __DIR__ . '/smtp.php';

date_default_timezone_set('Europe/Paris');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

define('FICHIER_DONNEES', __DIR__ . '/donnees/reservations.php');
// Première ligne du fichier de données : bloque toute lecture depuis le web
define('ENTETE_DONNEES', "<?php http_response_code(404); exit; ?>\n");

session_set_cookie_params(array(
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Strict',
));
session_name('heman_admin');
session_start();

/* =====================================================================
   Outils
   ===================================================================== */

function repondre($donnees, $code = 200)
{
    http_response_code($code);
    echo json_encode($donnees, JSON_UNESCAPED_UNICODE);
    exit;
}

function erreur($message, $code = 422)
{
    repondre(array('ok' => false, 'erreur' => $message), $code);
}

function est_admin()
{
    return !empty($_SESSION['admin']);
}

function exiger_admin()
{
    if (!est_admin()) {
        erreur('Session expirée : merci de vous reconnecter.', 401);
    }
}

function texte($v, $max = 200, $multiligne = false)
{
    $v = is_string($v) || is_numeric($v) ? trim((string) $v) : '';
    $v = str_replace("\0", '', $v);
    if (!$multiligne) {
        $v = str_replace(array("\r", "\n"), ' ', $v);
    }
    return function_exists('mb_substr') ? mb_substr($v, 0, $max) : substr($v, 0, $max);
}

function date_valide($d)
{
    if (!is_string($d) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
        return false;
    }
    $o = DateTime::createFromFormat('!Y-m-d', $d);
    return $o && $o->format('Y-m-d') === $d;
}

/** "14:30" → 870 */
function minutes($h)
{
    if (!is_string($h) || !preg_match('/^(\d{1,2}):(\d{2})$/', $h, $m)) {
        return -1;
    }
    $total = intval($m[1]) * 60 + intval($m[2]);
    return ($total >= 0 && $total <= 1440) ? $total : -1;
}

/** 870 → "14h30" */
function heure_lisible($min)
{
    $h = intdiv($min, 60);
    $m = $min % 60;
    return $h . 'h' . ($m ? str_pad((string) $m, 2, '0', STR_PAD_LEFT) : '');
}

function date_lisible($d)
{
    $jours = array('dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi');
    $mois = array('', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet',
        'août', 'septembre', 'octobre', 'novembre', 'décembre');
    $o = DateTime::createFromFormat('!Y-m-d', $d);
    return $jours[intval($o->format('w'))] . ' ' . intval($o->format('j')) . ' '
        . $mois[intval($o->format('n'))] . ' ' . $o->format('Y');
}

/**
 * Ouvre le fichier de données sous verrou exclusif, passe la liste à $traitement
 * et réécrit le fichier si $traitement renvoie une nouvelle liste.
 * Le verrou évite que deux demandes simultanées obtiennent le même créneau.
 */
function avec_donnees($traitement)
{
    $dossier = dirname(FICHIER_DONNEES);
    if (!is_dir($dossier) && !@mkdir($dossier, 0750, true)) {
        error_log('Héman/Réservations : impossible de créer ' . $dossier);
        erreur('Le service de réservation est indisponible.', 500);
    }
    $f = @fopen(FICHIER_DONNEES, 'c+');
    if (!$f) {
        error_log('Héman/Réservations : impossible d\'ouvrir ' . FICHIER_DONNEES);
        erreur('Le service de réservation est indisponible.', 500);
    }
    flock($f, LOCK_EX);
    $brut = stream_get_contents($f);
    if (strpos($brut, ENTETE_DONNEES) === 0) {
        $brut = substr($brut, strlen(ENTETE_DONNEES));
    }
    $liste = trim($brut) !== '' ? json_decode($brut, true) : array();
    if (!is_array($liste)) {
        error_log('Héman/Réservations : fichier de données illisible.');
        flock($f, LOCK_UN);
        fclose($f);
        erreur('Le service de réservation est indisponible.', 500);
    }

    $resultat = $traitement($liste);

    if (is_array($resultat) && array_key_exists('liste', $resultat)) {
        ftruncate($f, 0);
        rewind($f);
        fwrite($f, ENTETE_DONNEES . json_encode(array_values($resultat['liste']), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        fflush($f);
    }
    flock($f, LOCK_UN);
    fclose($f);
    return is_array($resultat) && array_key_exists('retour', $resultat) ? $resultat['retour'] : null;
}

/** Créneau qui chevauche [debut, fin) dans la même salle, parmi les créneaux actifs. */
function conflit($liste, $salle, $date, $debut, $fin, $ignorerId = '', $statuts = array('attente', 'validee'))
{
    foreach ($liste as $r) {
        if ($r['id'] === $ignorerId || $r['salle'] !== $salle || $r['date'] !== $date) {
            continue;
        }
        if (!in_array($r['statut'], $statuts, true)) {
            continue;
        }
        if ($debut < $r['fin'] && $r['debut'] < $fin) {
            return $r;
        }
    }
    return null;
}

/** Vérifie salle, date et horaires reçus ; renvoie [salle, date, debut, fin]. */
function lire_creneau($data, $controleDelais)
{
    global $SALLES;
    $salle = texte(isset($data['salle']) ? $data['salle'] : '', 20);
    $date  = texte(isset($data['date']) ? $data['date'] : '', 10);
    $debut = minutes(texte(isset($data['debut']) ? $data['debut'] : '', 5));
    $fin   = minutes(texte(isset($data['fin']) ? $data['fin'] : '', 5));

    if (!isset($SALLES[$salle])) {
        erreur('Salle inconnue.');
    }
    if (!date_valide($date)) {
        erreur('Date invalide.');
    }
    if ($debut < 0 || $fin < 0 || $debut % RESA_PAS_MINUTES || $fin % RESA_PAS_MINUTES) {
        erreur('Horaires invalides.');
    }
    if ($fin - $debut < RESA_DUREE_MIN) {
        erreur('La durée minimale est de ' . (RESA_DUREE_MIN / 60) . ' heure.');
    }
    if ($controleDelais) {
        $aujourdhui = new DateTime('today');
        $jour = DateTime::createFromFormat('!Y-m-d', $date);
        $ecart = intval($aujourdhui->diff($jour)->format('%r%a'));
        if ($ecart < RESA_JOURS_AVANCE_MIN) {
            erreur('Les réservations pour le jour même ne sont pas possibles : choisissez une date à partir de demain.');
        }
        if ($ecart > RESA_JOURS_AVANCE_MAX) {
            erreur('Les réservations sont ouvertes jusqu\'à ' . RESA_JOURS_AVANCE_MAX . ' jours à l\'avance.');
        }
    }
    return array($salle, $date, $debut, $fin);
}

function prix($r)
{
    global $SALLES;
    $p = $SALLES[$r['salle']]['tarif'] * ($r['fin'] - $r['debut']) / 60;
    return str_replace(',00', '', number_format($p, 2, ',', ' ')) . ' €';
}

function resume_creneau($r)
{
    global $SALLES;
    return $SALLES[$r['salle']]['nom'] . ' — ' . date_lisible($r['date'])
        . ', de ' . heure_lisible($r['debut']) . ' à ' . heure_lisible($r['fin']);
}

/** Ce que voit un visiteur : aucune donnée personnelle. */
function vue_publique($r)
{
    return array(
        'id'     => $r['id'],
        'salle'  => $r['salle'],
        'date'   => $r['date'],
        'debut'  => $r['debut'],
        'fin'    => $r['fin'],
        'statut' => $r['statut'],
    );
}

function vue_admin($r)
{
    $v = $r;
    unset($v['ip']);
    $v['prix'] = prix($r);
    return $v;
}

function signature()
{
    return array(
        '',
        'Pour nous joindre : contact@heman.fr — 06 80 77 85 48',
        '',
        'À bientôt,',
        SITE_NOM,
        SITE_URL,
    );
}

/* =====================================================================
   Lecture du calendrier
   ===================================================================== */

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $debut = isset($_GET['debut']) ? $_GET['debut'] : '';
    $fin   = isset($_GET['fin']) ? $_GET['fin'] : '';
    if (!date_valide($debut) || !date_valide($fin) || $fin < $debut) {
        erreur('Période invalide.');
    }
    $admin = est_admin();
    $liste = avec_donnees(function ($liste) use ($debut, $fin, $admin) {
        $sortie = array();
        foreach ($liste as $r) {
            if ($r['date'] < $debut || $r['date'] > $fin) {
                continue;
            }
            if ($r['statut'] !== 'attente' && $r['statut'] !== 'validee') {
                continue;
            }
            $sortie[] = $admin ? vue_admin($r) : vue_publique($r);
        }
        return array('retour' => $sortie);
    });

    $salles = array();
    foreach ($SALLES as $id => $s) {
        $salles[] = array('id' => $id, 'nom' => $s['nom'], 'tarif' => $s['tarif']);
    }
    $reponse = array(
        'ok'           => true,
        'admin'        => $admin,
        'salles'       => $salles,
        'regles'       => array(
            'pas'       => RESA_PAS_MINUTES,
            'dureeMin'  => RESA_DUREE_MIN,
            'joursMin'  => RESA_JOURS_AVANCE_MIN,
            'joursMax'  => RESA_JOURS_AVANCE_MAX,
            'aujourdhui' => date('Y-m-d'),
        ),
        'reservations' => $liste,
    );
    if ($admin) {
        // Toutes les demandes en attente, quelle que soit la semaine affichée
        $reponse['enAttente'] = avec_donnees(function ($liste) {
            $sortie = array();
            foreach ($liste as $r) {
                if ($r['statut'] === 'attente') {
                    $sortie[] = vue_admin($r);
                }
            }
            usort($sortie, function ($a, $b) {
                return strcmp($a['date'] . sprintf('%04d', $a['debut']), $b['date'] . sprintf('%04d', $b['debut']));
            });
            return array('retour' => $sortie);
        });
    }
    repondre($reponse);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    erreur('Méthode non autorisée.', 405);
}

// Les appels du calendrier sont en JSON : un formulaire tiers ne peut pas
// envoyer ce type de contenu vers ce site sans être bloqué par le navigateur.
$type = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
if (stripos($type, 'application/json') !== 0) {
    erreur('Format de requête invalide.', 415);
}
$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    erreur('Requête invalide.', 400);
}
$action = isset($data['action']) ? $data['action'] : '';

/* =====================================================================
   Demande d'un visiteur
   ===================================================================== */

if ($action === 'demande') {
    $piege = texte(isset($data['_piege']) ? $data['_piege'] : '');
    if ($piege !== '') {
        error_log('Héman/Réservations : champ anti-robot rempli — demande ignorée.');
        repondre(array('ok' => true));
    }

    list($salle, $date, $debut, $fin) = lire_creneau($data, true);

    $nom         = texte(isset($data['nom']) ? $data['nom'] : '', 100);
    $email       = texte(isset($data['email']) ? $data['email'] : '', 150);
    $tel         = texte(isset($data['tel']) ? $data['tel'] : '', 30);
    $structure   = texte(isset($data['structure']) ? $data['structure'] : '', 120);
    $activite    = texte(isset($data['activite']) ? $data['activite'] : '', 120);
    $participants = texte(isset($data['participants']) ? $data['participants'] : '', 10);
    $message     = texte(isset($data['message']) ? $data['message'] : '', 2000, true);
    $reglement   = !empty($data['reglement']);

    if ($nom === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        erreur('Merci d\'indiquer votre nom et une adresse e-mail valide.');
    }
    if ($tel === '') {
        erreur('Merci d\'indiquer un numéro de téléphone.');
    }
    if ($activite === '') {
        erreur('Merci de préciser l\'activité prévue.');
    }
    if (!$reglement) {
        erreur('Merci d\'accepter le règlement intérieur.');
    }

    $ip = hash('sha256', (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '') . SMTP_USER);

    $nouvelle = avec_donnees(function ($liste) use ($salle, $date, $debut, $fin, $nom, $email, $tel, $structure, $activite, $participants, $message, $ip) {
        // Garde-fou : 6 demandes par heure et par connexion au maximum
        $recentes = 0;
        foreach ($liste as $r) {
            if (isset($r['ip']) && $r['ip'] === $ip && strtotime($r['creeLe']) > time() - 3600) {
                $recentes++;
            }
        }
        if ($recentes >= 6) {
            erreur('Vous avez envoyé beaucoup de demandes : merci de patienter ou de nous écrire à contact@heman.fr.', 429);
        }
        if (conflit($liste, $salle, $date, $debut, $fin)) {
            erreur('Ce créneau vient d\'être demandé par quelqu\'un d\'autre. Choisissez un autre horaire ou une autre salle.', 409);
        }
        $r = array(
            'id'           => bin2hex(random_bytes(8)),
            'salle'        => $salle,
            'date'         => $date,
            'debut'        => $debut,
            'fin'          => $fin,
            'statut'       => 'attente',
            'nom'          => $nom,
            'email'        => $email,
            'tel'          => $tel,
            'structure'    => $structure,
            'activite'     => $activite,
            'participants' => $participants,
            'message'      => $message,
            'creeLe'       => date('c'),
            'ip'           => $ip,
        );
        $liste[] = $r;
        return array('liste' => $liste, 'retour' => $r);
    });

    // --- E-mail à Héman ---
    $lignes = array(
        'Nouvelle demande de location de salle — à valider',
        str_repeat('-', 52),
        'Créneau      : ' . resume_creneau($nouvelle),
        'Montant      : ' . prix($nouvelle),
        '',
        'Nom          : ' . $nom,
        'E-mail       : ' . $email,
        'Téléphone    : ' . $tel,
    );
    if ($structure !== '')    { $lignes[] = 'Structure    : ' . $structure; }
    $lignes[] = 'Activité     : ' . $activite;
    if ($participants !== '') { $lignes[] = 'Participants : ' . $participants; }
    if ($message !== '') {
        $lignes[] = '';
        $lignes[] = 'Message :';
        $lignes[] = $message;
    }
    $lignes[] = '';
    $lignes[] = str_repeat('-', 52);
    $lignes[] = 'Valider ou refuser : ' . SITE_URL . '/admin-reservations.html';

    $destinataire = isset($DESTINATAIRES['location']) ? $DESTINATAIRES['location'] : DESTINATAIRE_DEFAUT;
    if (!smtp_envoyer($destinataire, '[Location de salle — à valider] ' . resume_creneau($nouvelle), implode("\r\n", $lignes), $email, $nom)) {
        error_log('Héman/Réservations : e-mail à Héman non envoyé pour la demande ' . $nouvelle['id']);
    }

    // --- Accusé de réception au visiteur ---
    $ar = array_merge(array(
        'Bonjour ' . $nom . ',',
        '',
        'Nous avons bien reçu votre demande de location :',
        '',
        '   ' . resume_creneau($nouvelle),
        '   Montant : ' . prix($nouvelle),
        '',
        'Ce créneau est réservé pour vous en attendant notre validation.',
        'Nous vous répondrons sous ' . DELAI_REPONSE . '.',
        '',
        'Rappel du règlement : la réservation n\'est définitive qu\'une fois réglée,',
        'et toute séance décommandée moins de 48 heures à l\'avance reste due.',
        '',
        'Merci de ne pas répondre à ce message : il est envoyé automatiquement.',
    ), signature());
    smtp_envoyer($email, 'Demande de location reçue — Héman', implode("\r\n", $ar));

    repondre(array('ok' => true, 'reservation' => vue_publique($nouvelle)));
}

/* =====================================================================
   Session admin
   ===================================================================== */

if ($action === 'connexion') {
    $mdp = isset($data['motDePasse']) && is_string($data['motDePasse']) ? $data['motDePasse'] : '';
    if (ADMIN_MOT_DE_PASSE === 'A_REMPLIR_SUR_LE_SERVEUR' || ADMIN_MOT_DE_PASSE === '') {
        erreur('Le mot de passe admin n\'est pas encore renseigné dans config.php sur le serveur.', 503);
    }
    if ($mdp === '' || !hash_equals(ADMIN_MOT_DE_PASSE, $mdp)) {
        sleep(2); // ralentit les essais au hasard
        error_log('Héman/Réservations : mot de passe admin incorrect.');
        erreur('Mot de passe incorrect.', 401);
    }
    session_regenerate_id(true);
    $_SESSION['admin'] = true;
    repondre(array('ok' => true));
}

if ($action === 'deconnexion') {
    $_SESSION = array();
    session_destroy();
    repondre(array('ok' => true));
}

/* =====================================================================
   Actions admin
   ===================================================================== */

exiger_admin();

if ($action === 'valider') {
    $id = texte(isset($data['id']) ? $data['id'] : '', 32);
    $r = avec_donnees(function ($liste) use ($id) {
        foreach ($liste as $i => $r) {
            if ($r['id'] !== $id) {
                continue;
            }
            if ($r['statut'] !== 'attente') {
                erreur('Cette demande a déjà été traitée.', 409);
            }
            $autre = conflit($liste, $r['salle'], $r['date'], $r['debut'], $r['fin'], $r['id'], array('validee'));
            if ($autre) {
                erreur('Impossible : ce créneau chevauche une réservation déjà validée.', 409);
            }
            $liste[$i]['statut'] = 'validee';
            $liste[$i]['traiteLe'] = date('c');
            return array('liste' => $liste, 'retour' => $liste[$i]);
        }
        erreur('Demande introuvable.', 404);
    });

    $mail = array_merge(array(
        'Bonjour ' . $r['nom'] . ',',
        '',
        'Bonne nouvelle : votre demande de location est validée.',
        '',
        '   ' . resume_creneau($r),
        '   Montant : ' . prix($r),
        '',
        'Rappel du règlement intérieur :',
        '- la réservation n\'est définitive qu\'une fois réglée, à l\'avance ;',
        '- toute séance décommandée moins de 48 heures à l\'avance reste due ;',
        '- merci de libérer la salle à l\'heure exacte et de la laisser rangée.',
        '',
        'Adresse : ZI des Renouillères, 3 rue Marcel Dassault, 93360 Neuilly-Plaisance',
    ), signature());
    smtp_envoyer($r['email'], 'Votre location est validée — Héman', implode("\r\n", $mail), DESTINATAIRE_DEFAUT, 'Héman');

    repondre(array('ok' => true));
}

if ($action === 'refuser') {
    $id = texte(isset($data['id']) ? $data['id'] : '', 32);
    $motif = texte(isset($data['motif']) ? $data['motif'] : '', 500, true);
    $r = avec_donnees(function ($liste) use ($id, $motif) {
        foreach ($liste as $i => $r) {
            if ($r['id'] !== $id) {
                continue;
            }
            if ($r['statut'] !== 'attente') {
                erreur('Cette demande a déjà été traitée.', 409);
            }
            // Conservée pour l'historique, mais plus jamais affichée
            $liste[$i]['statut'] = 'refusee';
            $liste[$i]['motif'] = $motif;
            $liste[$i]['traiteLe'] = date('c');
            return array('liste' => $liste, 'retour' => $liste[$i]);
        }
        erreur('Demande introuvable.', 404);
    });

    $mail = array(
        'Bonjour ' . $r['nom'] . ',',
        '',
        'Nous sommes désolés : nous ne pouvons pas donner suite à votre demande de location.',
        '',
        '   ' . resume_creneau($r),
    );
    if ($motif !== '') {
        $mail[] = '';
        $mail[] = $motif;
    }
    $mail[] = '';
    $mail[] = 'N\'hésitez pas à choisir un autre créneau sur ' . SITE_URL . '/location-salles.html';
    $mail = array_merge($mail, signature());
    smtp_envoyer($r['email'], 'Votre demande de location — Héman', implode("\r\n", $mail), DESTINATAIRE_DEFAUT, 'Héman');

    repondre(array('ok' => true));
}

if ($action === 'bloquer') {
    list($salle, $date, $debut, $fin) = lire_creneau($data, false);
    $libelle = texte(isset($data['libelle']) ? $data['libelle'] : '', 120);
    if ($libelle === '') {
        erreur('Indiquez un libellé (ex. « Cours de hip-hop », « Stage »).');
    }

    // Récurrence facultative : jours de la semaine (1 = lundi … 7 = dimanche) jusqu'à une date
    $dates = array($date);
    $recurrence = isset($data['recurrence']) && is_array($data['recurrence']) ? $data['recurrence'] : null;
    if ($recurrence) {
        $jusquau = texte(isset($recurrence['jusquau']) ? $recurrence['jusquau'] : '', 10);
        $jours = array();
        foreach ((isset($recurrence['jours']) && is_array($recurrence['jours']) ? $recurrence['jours'] : array()) as $j) {
            $j = intval($j);
            if ($j >= 1 && $j <= 7) {
                $jours[$j] = true;
            }
        }
        if (!date_valide($jusquau) || $jusquau < $date) {
            erreur('Indiquez une date de fin de récurrence postérieure au premier créneau.');
        }
        if (!$jours) {
            erreur('Cochez au moins un jour de la semaine.');
        }
        $limite = (new DateTime($date))->modify('+1 year')->format('Y-m-d');
        if ($jusquau > $limite) {
            erreur('Une récurrence ne peut pas dépasser un an.');
        }
        $dates = array();
        $jour = DateTime::createFromFormat('!Y-m-d', $date);
        while ($jour->format('Y-m-d') <= $jusquau) {
            if (isset($jours[intval($jour->format('N'))])) {
                $dates[] = $jour->format('Y-m-d');
            }
            $jour->modify('+1 day');
        }
        if (!$dates) {
            erreur('Aucune date ne correspond aux jours cochés sur cette période.');
        }
    }

    $resultat = avec_donnees(function ($liste) use ($salle, $dates, $debut, $fin, $libelle) {
        $serie = count($dates) > 1 ? bin2hex(random_bytes(8)) : '';
        $crees = array();
        $ignorees = array();
        foreach ($dates as $d) {
            // Une date déjà occupée est sautée, les autres sont créées
            if (conflit($liste, $salle, $d, $debut, $fin)) {
                $ignorees[] = $d;
                continue;
            }
            $r = array(
                'id'       => bin2hex(random_bytes(8)),
                'salle'    => $salle,
                'date'     => $d,
                'debut'    => $debut,
                'fin'      => $fin,
                'statut'   => 'validee',
                'interne'  => true,
                'nom'      => $libelle,
                'email'    => '',
                'tel'      => '',
                'activite' => $libelle,
                'creeLe'   => date('c'),
            );
            if ($serie !== '') {
                $r['serie'] = $serie;
            }
            $liste[] = $r;
            $crees[] = $d;
        }
        if (!$crees) {
            erreur(count($dates) > 1
                ? 'Toutes les dates de la récurrence chevauchent une demande ou une réservation existante.'
                : 'Ce créneau chevauche une demande ou une réservation existante.', 409);
        }
        return array('liste' => $liste, 'retour' => array('crees' => $crees, 'ignorees' => $ignorees));
    });
    repondre(array('ok' => true, 'crees' => count($resultat['crees']), 'ignorees' => $resultat['ignorees']));
}

if ($action === 'supprimer') {
    $id = texte(isset($data['id']) ? $data['id'] : '', 32);
    $toute = !empty($data['serie']);
    $nombre = avec_donnees(function ($liste) use ($id, $toute) {
        $cible = null;
        foreach ($liste as $r) {
            if ($r['id'] === $id && $r['statut'] === 'validee') {
                $cible = $r;
            }
        }
        if (!$cible) {
            erreur('Réservation introuvable.', 404);
        }
        $nombre = 0;
        foreach ($liste as $i => $r) {
            $viser = $r['id'] === $id;
            // Toute la série à partir de ce créneau : les séances passées restent
            if ($toute && !empty($cible['serie']) && isset($r['serie']) && $r['serie'] === $cible['serie']
                && $r['date'] >= $cible['date'] && $r['statut'] === 'validee') {
                $viser = true;
            }
            if ($viser) {
                $liste[$i]['statut'] = 'annulee';
                $liste[$i]['traiteLe'] = date('c');
                $nombre++;
            }
        }
        return array('liste' => $liste, 'retour' => $nombre);
    });
    repondre(array('ok' => true, 'supprimees' => $nombre));
}

erreur('Action inconnue.', 400);
