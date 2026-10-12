<?php
/**
 * Créneaux fixes des salles — site Héman
 *
 * Stages et événements de l'école, programmés directement dans le site.
 * Ils apparaissent comme « Réservé » dans le calendrier de location et
 * bloquent les demandes sur ces horaires. Ils ne sont pas enregistrés dans
 * donnees/reservations.php et ne se suppriment pas depuis la page admin :
 * pour en changer un, modifier cette liste.
 *
 * salle : heman1, heman2 ou galilee — date : AAAA-MM-JJ — debut / fin : HH:MM
 */

// Les stages de Lorna et de Richard (novembre 2026 – juin 2027) sont saisis
// directement dans la page admin : ils n'ont pas à figurer ici en double.
$CRENEAUX_FIXES = array(
    // Exemple :
    // array('salle' => 'heman2', 'date' => '2027-01-17', 'debut' => '14:00', 'fin' => '16:00', 'libelle' => 'Stage …'),
);
