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

$CRENEAUX_FIXES = array(
    // Stages de Modern Jazz avec Lorna
    array('salle' => 'heman2', 'date' => '2026-11-01', 'debut' => '14:30', 'fin' => '16:00', 'libelle' => 'Stage Modern Jazz — Lorna'),
    array('salle' => 'heman2', 'date' => '2026-12-05', 'debut' => '12:00', 'fin' => '13:30', 'libelle' => 'Stage Modern Jazz — Lorna'),

    // Stages de Jazz Rock avec Richard MP Style
    array('salle' => 'heman2', 'date' => '2026-11-08', 'debut' => '15:30', 'fin' => '17:00', 'libelle' => 'Stage Jazz Rock — Richard MP Style'),
    array('salle' => 'heman2', 'date' => '2026-12-13', 'debut' => '15:30', 'fin' => '17:00', 'libelle' => 'Stage Jazz Rock — Richard MP Style'),
    array('salle' => 'heman2', 'date' => '2027-01-10', 'debut' => '15:30', 'fin' => '17:00', 'libelle' => 'Stage Jazz Rock — Richard MP Style'),
    array('salle' => 'heman2', 'date' => '2027-02-07', 'debut' => '15:30', 'fin' => '17:00', 'libelle' => 'Stage Jazz Rock — Richard MP Style'),
    array('salle' => 'heman2', 'date' => '2027-03-07', 'debut' => '15:30', 'fin' => '17:00', 'libelle' => 'Stage Jazz Rock — Richard MP Style'),
    array('salle' => 'heman2', 'date' => '2027-04-04', 'debut' => '15:30', 'fin' => '17:00', 'libelle' => 'Stage Jazz Rock — Richard MP Style'),
    array('salle' => 'heman2', 'date' => '2027-05-02', 'debut' => '15:30', 'fin' => '17:00', 'libelle' => 'Stage Jazz Rock — Richard MP Style'),
    array('salle' => 'heman2', 'date' => '2027-06-06', 'debut' => '15:30', 'fin' => '17:00', 'libelle' => 'Stage Jazz Rock — Richard MP Style'),
);
