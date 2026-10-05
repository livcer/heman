<?php
/**
 * Configuration des envois d'e-mails — site Héman
 *
 * Ce fichier contient le mot de passe de la boîte d'expédition.
 * Il ne doit JAMAIS être versionné ni partagé.
 * Renseignez la ligne SMTP_PASS directement sur le serveur.
 */

// --- Boîte d'expédition (Infomaniak) ---
define('SMTP_HOTE', 'mail.infomaniak.com');
define('SMTP_PORT', 587);                       // 587 = STARTTLS
define('SMTP_USER', 'noreply@heman.website');
define('SMTP_PASS', 'A_REMPLIR_SUR_LE_SERVEUR'); // mot de passe d'appareil Infomaniak
define('SMTP_NOM', 'Site Héman');

// --- Destinataires par type de demande ---
$DESTINATAIRES = array(
    'contact'     => 'contact@heman.fr',
    'location'    => 'contact@heman.fr',
    'inscription' => 'inscription@heman.fr',
    'boutique'    => 'contact@schimea.com',
    'presse'      => 'oriental.danikarp@gmail.com',
);

// Destinataire utilisé si le type reçu est inconnu
define('DESTINATAIRE_DEFAUT', 'contact@heman.fr');

// --- Accusé de réception envoyé au visiteur ---
define('ACCUSE_RECEPTION', true);
define('DELAI_REPONSE', '24 heures');

// --- Adresse publique du site (liens dans les e-mails) ---
define('SITE_URL', 'https://heman.website');
define('SITE_NOM', 'Héman — Centre de Danses Urbaines');

// --- Réservation des salles (calendrier de la page Location de salles) ---
// Mot de passe de la page admin-reservations.html.
// Comme SMTP_PASS : à renseigner UNIQUEMENT sur le serveur.
// Tant qu'il vaut 'A_REMPLIR_SUR_LE_SERVEUR', la connexion admin est refusée.
define('ADMIN_MOT_DE_PASSE', 'A_REMPLIR_SUR_LE_SERVEUR');

// Salles réservables : identifiant => nom affiché et tarif horaire (€)
$SALLES = array(
    'heman1'  => array('nom' => 'Héman 1', 'tarif' => 25),
    'heman2'  => array('nom' => 'Héman 2', 'tarif' => 25),
    'galilee' => array('nom' => 'Galilée', 'tarif' => 35),
);

// Créneaux : pas de 30 minutes, durée minimale d'une heure
define('RESA_PAS_MINUTES', 30);
define('RESA_DUREE_MIN', 60);
// Délai minimal : pas de réservation pour le jour même (règlement intérieur)
define('RESA_JOURS_AVANCE_MIN', 1);
// Horizon de réservation
define('RESA_JOURS_AVANCE_MAX', 180);
