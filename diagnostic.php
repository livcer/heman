<?php
/**
 * Diagnostic des envois d'e-mails — site Héman
 *
 * À déposer à côté de envoi.php, puis ouvrir dans le navigateur :
 *   https://heman.website/diagnostic.php
 * Pour envoyer un e-mail de test :
 *   https://heman.website/diagnostic.php?test=votre.adresse@exemple.fr
 *
 * À SUPPRIMER du serveur une fois les tests terminés.
 */

header('Content-Type: text/html; charset=utf-8');
$etapes = array();
function etape($libelle, $ok, $detail = '') {
    global $etapes;
    $etapes[] = array($libelle, $ok, $detail);
    return $ok;
}

// --- 1. Fichiers présents ---
$aConfig = file_exists(__DIR__ . '/config.php');
etape('config.php présent', $aConfig, __DIR__ . '/config.php');
etape('envoi.php présent', file_exists(__DIR__ . '/envoi.php'));

if (!$aConfig) {
    $etapes[] = array('Arrêt : config.php manquant', false, 'Téléversez config.php dans le même dossier que envoi.php.');
} else {
    require __DIR__ . '/config.php';

    // --- 2. Mot de passe renseigné ---
    $mdp = SMTP_PASS;
    etape('Mot de passe SMTP renseigné',
        $mdp !== '' && $mdp !== 'A_REMPLIR_SUR_LE_SERVEUR',
        $mdp === 'A_REMPLIR_SUR_LE_SERVEUR'
            ? 'La ligne SMTP_PASS contient encore le texte de remplacement.'
            : 'Longueur : ' . strlen($mdp) . ' caractères.');

    etape('Compte d\'expédition', true, SMTP_USER . ' via ' . SMTP_HOTE . ':' . SMTP_PORT);

    // --- 3. Fonctions PHP requises ---
    etape('stream_socket_client disponible', function_exists('stream_socket_client'));
    etape('OpenSSL disponible (STARTTLS)', extension_loaded('openssl'));

    // --- 4. Connexion, STARTTLS, authentification ---
    $socket = @stream_socket_client('tcp://' . SMTP_HOTE . ':' . SMTP_PORT, $errno, $errstr, 15);
    if (!etape('Connexion au serveur SMTP', (bool) $socket, $socket ? '' : $errstr . ' (code ' . $errno . ')')) {
        $etapes[] = array('Arrêt : port 587 probablement bloqué par l\'hébergeur', false, 'Essayez SMTP_PORT 465 ou demandez à Infomaniak l\'ouverture du port sortant.');
    } else {
        stream_set_timeout($socket, 15);
        $lire = function ($attendu) use ($socket) {
            $r = '';
            while (($l = fgets($socket, 515)) !== false) {
                $r .= $l;
                if (isset($l[3]) && $l[3] === ' ') { break; }
            }
            return array(substr($r, 0, 3) == $attendu, trim($r));
        };
        $dire = function ($cmd, $attendu) use ($socket, $lire) {
            fwrite($socket, $cmd . "\r\n");
            return $lire($attendu);
        };
        $hote = isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : 'heman.website';

        list($ok, $d) = $lire(220);            etape('Bannière du serveur', $ok, $d);
        list($ok, $d) = $dire('EHLO ' . $hote, 250); etape('EHLO accepté', $ok, $d);
        list($ok, $d) = $dire('STARTTLS', 220);      etape('STARTTLS accepté', $ok, $d);
        if ($ok) {
            $ok = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            etape('Chiffrement TLS établi', (bool) $ok);
        }
        if ($ok) {
            list($ok, $d) = $dire('EHLO ' . $hote, 250); etape('EHLO après TLS', $ok, $d);
            list($ok, $d) = $dire('AUTH LOGIN', 334);    etape('AUTH LOGIN proposé', $ok, $d);
            list($ok, $d) = $dire(base64_encode(SMTP_USER), 334); etape('Identifiant accepté', $ok, $d);
            list($ok, $d) = $dire(base64_encode(SMTP_PASS), 235);
            etape('Mot de passe accepté', $ok, $ok ? $d : $d . ' — vérifiez le mot de passe d\'appareil de ' . SMTP_USER);
        }
        @fwrite($socket, "QUIT\r\n");
        @fclose($socket);
    }
}

// Envoi de test : on réutilise la fonction d'envoi de envoi.php sans réexécuter son script
$test = isset($_GET['test']) ? trim($_GET['test']) : '';
$resultatTest = null;
if ($test !== '' && filter_var($test, FILTER_VALIDATE_EMAIL) && file_exists(__DIR__ . '/envoi.php')) {
    $src = file_get_contents(__DIR__ . '/envoi.php');
    $pos = strpos($src, 'function smtp_lire');
    if ($pos !== false && !function_exists('smtp_envoyer')) {
        eval(substr($src, $pos));
    }
    if (function_exists('smtp_envoyer')) {
        $resultatTest = smtp_envoyer(
            $test,
            'Test d\'envoi — site Héman',
            "Ceci est un e-mail de test envoyé depuis diagnostic.php.\r\n"
            . "Si vous le recevez, la boîte d'expédition fonctionne.\r\n\r\n"
            . 'Envoyé le ' . date('d/m/Y à H:i')
        );
        etape('Envoi de l\'e-mail de test à ' . $test, (bool) $resultatTest,
            $resultatTest ? 'Vérifiez la boîte de réception, et les indésirables.' : 'Refusé par le serveur.');
    }
}

$tousOk = true;
foreach ($etapes as $e) { if (!$e[1]) { $tousOk = false; } }
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="robots" content="noindex, nofollow" />
<title>Diagnostic des e-mails — Héman</title>
</head>
<body style="margin:0;padding:40px 20px;background:#FAF8FC;color:#1A1225;font-family:-apple-system,Segoe UI,Helvetica,sans-serif;">
<div style="max-width:760px;margin:0 auto;">
  <p style="margin:0 0 6px;font-size:0.75rem;letter-spacing:0.14em;text-transform:uppercase;color:#514860;">Héman — outil technique</p>
  <h1 style="margin:0 0 24px;font-size:1.6rem;">Diagnostic des envois d'e-mails</h1>

  <div style="padding:14px 18px;border-radius:10px;margin-bottom:26px;background:<?php echo $tousOk ? '#13b8ad' : '#4d0780'; ?>;color:#fff;font-weight:600;">
    <?php echo $tousOk ? 'Toutes les vérifications passent.' : 'Au moins une vérification échoue — voir les lignes en violet ci-dessous.'; ?>
  </div>

  <table style="width:100%;border-collapse:collapse;font-size:0.92rem;">
    <?php foreach ($etapes as $e): ?>
    <tr style="border-bottom:1px solid rgba(26,18,37,0.1);">
      <td style="padding:10px 12px 10px 0;width:26px;color:<?php echo $e[1] ? '#13b8ad' : '#4d0780'; ?>;font-weight:700;"><?php echo $e[1] ? '✓' : '✕'; ?></td>
      <td style="padding:10px 0;"><?php echo htmlspecialchars($e[0], ENT_QUOTES, 'UTF-8'); ?>
        <?php if ($e[2] !== ''): ?><br /><span style="color:#514860;font-family:ui-monospace,monospace;font-size:0.78rem;"><?php echo htmlspecialchars($e[2], ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>

  <?php if ($test === ''): ?>
  <p style="margin-top:28px;color:#514860;">Pour envoyer un e-mail de test, ajoutez votre adresse à l'URL :<br />
    <code style="font-family:ui-monospace,monospace;">diagnostic.php?test=votre.adresse@exemple.fr</code></p>
  <?php endif; ?>

  <p style="margin-top:32px;padding-top:18px;border-top:1px solid rgba(26,18,37,0.12);color:#514860;font-size:0.85rem;">
    Supprimez ce fichier du serveur une fois les tests terminés.
  </p>
</div>
</body>
</html>
