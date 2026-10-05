# Site Héman — mise en ligne chez Infomaniak

Domaine temporaire : **heman.website**
`heman.fr` reste sur l'ancien site jusqu'à la validation du client.

---

## 1. Créer le site dans l'hébergement

Manager Infomaniak → **Hébergement Web 1** → **Ajouter un site**.

**Outil de création** : choisissez **« Créez un projet vierge »** (carte Avancé, la quatrième).
**Technologie** : **PHP** — indispensable, les formulaires en dépendent.
**Domaine** : `heman.website`

## 2. Activer le certificat SSL

Vérifiez dans **Hébergement → SSL** que le domaine est couvert. Sans cela, les navigateurs
affichent un avertissement de sécurité.

## 3. Déposer les fichiers

Le dossier du site se trouve dans `sites/heman.website`.
Déposez-y **le contenu** de ce dossier, pas le dossier lui-même.

### Par FileZilla (recommandé)

Créez un accès dans **FTP / SSH → Ajouter un utilisateur**, puis dans FileZilla :

- **Hôte** : `ftp.hosting-ik.com`
- **Identifiant** et **Mot de passe** : ceux de l'utilisateur créé
- **Port** : 21 (FTP) ou 22 (SFTP)

Sélectionnez tout le contenu à gauche, glissez-le dans `sites/heman.website` à droite.

### Par le navigateur

Manager → **Se connecter au WebFTP**, ouvrez `sites/heman.website`, envoyez les fichiers.
Le WebFTP ne transfère pas les dossiers : créez `assets` à la main, puis déposez les images dedans.

## 4. Renseigner le mot de passe d'envoi

**Étape obligatoire, sinon les formulaires ne fonctionnent pas.**

Ouvrez `config.php` sur le serveur (WebFTP → clic droit → Éditer) et remplacez :

    define('SMTP_PASS', 'A_REMPLIR_SUR_LE_SERVEUR');

par le mot de passe d'appareil de `noreply@heman.website`, généré dans
Infomaniak → Mail Service → noreply → **Appareil connecté**.

Ne renseignez ce mot de passe que sur le serveur : il ne doit figurer dans aucune copie locale.

## 5. Vérifier

Ouvrez **heman.website**. Puis testez un formulaire de contact avec votre propre adresse :
vous devez recevoir l'accusé de réception, et `contact@heman.fr` la demande.

Si rien n'arrive, vérifiez dans Infomaniak → Hébergement → **Journaux d'erreurs** :
les échecs SMTP y sont consignés avec la mention « Héman/SMTP ».

## 6. Envoyer au client

Transmettez l'adresse **heman.website**.

---

## Circuit des formulaires

| Formulaire | Destinataire |
|---|---|
| Contact (accueil et page Contact) | contact@heman.fr |
| Boutique | contact@schimea.com |
| Location de salle | contact@heman.fr (lien e-mail direct) |
| Presse | oriental.danikarp@gmail.com (lien e-mail direct) |

Les demandes partent de `noreply@heman.website`, avec l'adresse du visiteur en
« répondre à » : un clic sur Répondre écrit directement à la personne.

Le visiteur reçoit un accusé de réception annonçant une réponse sous 24 heures.
Héman ne reçoit pas de copie des demandes boutique.

Un champ invisible piège les robots : les envois automatisés sont ignorés
sans message d'erreur.

## Points à savoir

**Le site n'est pas indexé par les moteurs de recherche.** Un fichier `robots.txt` et une
instruction dans chaque page le demandent. Indispensable tant que `heman.fr` est en ligne :
sans cela Google verrait deux sites au contenu identique et pénaliserait le référencement.
Ces protections seront retirées au moment de la bascule.

**Une connexion internet est nécessaire** pour afficher les pages.

**Zones encore incomplètes**, à signaler au client avant sa relecture :

- photos des disciplines
- coloris, prix et liste définitive des supports de la boutique
- biographies de Marion et de Josélotte
- mentions légales : SIRET, numéro RNA, représentant légal

---

## Plus tard : basculer sur heman.fr

1. Ajouter `heman.fr` comme alias du site dans Infomaniak, et modifier les DNS chez Ionos
   pour pointer vers Infomaniak. Ne touchez qu'aux enregistrements A et CNAME : les
   enregistrements MX doivent rester inchangés pour que les boîtes Ionos continuent de fonctionner.
2. Créer `noreply@heman.fr` et mettre à jour `SMTP_USER` dans `config.php`.
3. Me demander une version sans les protections d'indexation.
4. Rediriger `heman.website` vers `heman.fr`.

Prévoyez cette bascule hors période d'inscriptions.
