/**
 * Calendrier de réservation des salles — site Héman
 *
 * Un seul composant pour deux usages :
 *   - mode "public" (location-salles.html) : le visiteur clique sur un créneau
 *     libre et envoie une demande, affichée en semi-transparent jusqu'à validation ;
 *   - mode "admin"  (admin-reservations.html) : validation, refus, blocage
 *     de créneaux et annulation.
 *
 * Ce fichier ne dépend de rien au chargement : il expose une fabrique
 * window.HemanReservation(React) appelée une fois React disponible.
 * Les données passent par reservations.php.
 */
(function () {
  'use strict';

  var API = 'reservations.php';
  var LIGNE = 22;            // hauteur en px d'une demi-heure
  var HEURE_DEFILEMENT = 8;  // heure visible à l'ouverture du calendrier

  var COULEURS = {
    heman1:  { fond: '#7b2cbf', texte: '#FFFFFF' },
    heman2:  { fond: '#13b8ad', texte: '#1A1225' },
    galilee: { fond: '#0b4f6c', texte: '#FFFFFF' }
  };
  var COULEURS_SECOURS = [
    { fond: '#4d0780', texte: '#FFFFFF' },
    { fond: '#f5c310', texte: '#1A1225' }
  ];

  var JOURS_COURTS = ['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.'];
  var JOURS = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
  var MOIS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet',
    'août', 'septembre', 'octobre', 'novembre', 'décembre'];

  /* ------------------------------------------------------------------
     Dates : on manipule des chaînes AAAA-MM-JJ, en heure locale
     ------------------------------------------------------------------ */

  function versDate(s) {
    var p = s.split('-');
    return new Date(+p[0], +p[1] - 1, +p[2]);
  }
  function versChaine(d) {
    var m = d.getMonth() + 1, j = d.getDate();
    return d.getFullYear() + '-' + (m < 10 ? '0' : '') + m + '-' + (j < 10 ? '0' : '') + j;
  }
  function ajouterJours(s, n) {
    var d = versDate(s);
    d.setDate(d.getDate() + n);
    return versChaine(d);
  }
  function lundiDe(s) {
    var d = versDate(s);
    var decalage = (d.getDay() + 6) % 7;
    d.setDate(d.getDate() - decalage);
    return versChaine(d);
  }
  function aujourdhui() {
    return versChaine(new Date());
  }
  function ecartJours(a, b) {
    return Math.round((versDate(b) - versDate(a)) / 86400000);
  }
  function dateLongue(s) {
    var d = versDate(s);
    return JOURS[d.getDay()] + ' ' + d.getDate() + ' ' + MOIS[d.getMonth()] + ' ' + d.getFullYear();
  }
  function heure(min) {
    var h = Math.floor(min / 60), m = min % 60;
    return h + 'h' + (m ? (m < 10 ? '0' : '') + m : '');
  }
  function heureChamp(min) {
    var h = Math.floor(min / 60), m = min % 60;
    return (h < 10 ? '0' : '') + h + ':' + (m < 10 ? '0' : '') + m;
  }
  function duree(min) {
    var h = Math.floor(min / 60), m = min % 60;
    return h + ' h' + (m ? ' ' + m : '');
  }
  function euros(n) {
    return n.toFixed(2).replace('.00', '').replace('.', ',') + ' €';
  }
  function titrePeriode(vue, debut) {
    if (vue === 'jour') {
      var t = dateLongue(debut);
      return t.charAt(0).toUpperCase() + t.slice(1);
    }
    var a = versDate(debut), b = versDate(ajouterJours(debut, 6));
    if (a.getMonth() === b.getMonth()) {
      return 'Semaine du ' + a.getDate() + ' au ' + b.getDate() + ' ' + MOIS[b.getMonth()] + ' ' + b.getFullYear();
    }
    return 'Semaine du ' + a.getDate() + ' ' + MOIS[a.getMonth()] + ' au ' + b.getDate() + ' ' + MOIS[b.getMonth()] + ' ' + b.getFullYear();
  }

  /* ------------------------------------------------------------------
     Échanges avec reservations.php
     ------------------------------------------------------------------ */

  function appel(methode, url, corps) {
    var options = { method: methode, credentials: 'same-origin', headers: {} };
    if (corps) {
      options.headers['Content-Type'] = 'application/json';
      options.body = JSON.stringify(corps);
    }
    return fetch(url, options).then(function (r) {
      return r.text().then(function (t) {
        var json = null;
        try { json = JSON.parse(t); } catch (e) { json = null; }
        if (!json) {
          throw new Error(location.protocol === 'file:'
            ? 'Le calendrier ne fonctionne qu’en ligne (il a besoin du serveur PHP).'
            : 'Le service de réservation ne répond pas (HTTP ' + r.status + ').');
        }
        if (!r.ok || json.ok !== true) {
          var err = new Error(json.erreur || 'Erreur inconnue.');
          err.statut = r.status;
          throw err;
        }
        return json;
      });
    });
  }

  /* ------------------------------------------------------------------
     Styles : injectés une seule fois, préfixe hr-
     ------------------------------------------------------------------ */

  var CSS = [
    '.hr{font-family:Poppins,system-ui,sans-serif;color:#1A1225;}',
    '.hr *{box-sizing:border-box;}',
    '.hr button{font-family:inherit;}',
    '.hr-barre{display:flex;flex-wrap:wrap;align-items:center;gap:12px 16px;margin-bottom:16px;}',
    '.hr-nav{display:flex;gap:6px;align-items:center;}',
    '.hr-btn{border:0;cursor:pointer;border-radius:9999px;font-weight:600;font-size:.9rem;padding:10px 18px;background:#eee6f5;color:#4d0780;transition:background .15s,transform .15s;}',
    '.hr-btn:hover{background:#e0d2ee;}',
    '.hr-btn:disabled{opacity:.5;cursor:default;}',
    '.hr-btn-rond{width:40px;height:40px;padding:0;font-size:1.2rem;line-height:1;}',
    '.hr-btn-plein{background:#13b8ad;color:#1A1225;box-shadow:0 4px 14px rgba(19,184,173,.3);}',
    '.hr-btn-plein:hover{background:#10a69c;}',
    '.hr-btn-violet{background:#4d0780;color:#FFFFFF;}',
    '.hr-btn-violet:hover{background:#3b0563;}',
    '.hr-btn-danger{background:#fde8e8;color:#a3141e;}',
    '.hr-btn-danger:hover{background:#f9d0d0;}',
    '.hr-titre{font-family:Montserrat,system-ui,sans-serif;font-weight:700;font-size:1.15rem;flex:1 1 220px;}',
    '.hr-bascule{display:inline-flex;background:#eee6f5;border-radius:9999px;padding:4px;}',
    '.hr-bascule button{border:0;background:transparent;padding:8px 16px;border-radius:9999px;font-weight:600;font-size:.86rem;color:#4d0780;cursor:pointer;}',
    '.hr-bascule button[aria-pressed=true]{background:#4d0780;color:#FFFFFF;}',
    '.hr-legende{display:flex;flex-wrap:wrap;gap:10px 18px;margin-bottom:14px;font-size:.84rem;color:#514860;align-items:center;}',
    '.hr-legende span{display:inline-flex;align-items:center;gap:8px;}',
    '.hr-pastille{width:16px;height:16px;border-radius:5px;display:inline-block;}',
    '.hr-cadre{background:#FFFFFF;border-radius:16px;box-shadow:0 8px 30px rgba(77,7,128,.12);overflow:hidden;position:relative;}',
    '.hr-defile{max-height:620px;overflow:auto;position:relative;}',
    '.hr-grille{display:grid;position:relative;}',
    '.hr-entete{position:sticky;top:0;z-index:5;background:#FFFFFF;border-bottom:1px solid #e6dcef;}',
    '.hr-coin{position:sticky;left:0;z-index:6;background:#FFFFFF;}',
    '.hr-jour{text-align:center;padding:10px 4px 6px;font-weight:700;font-family:Montserrat,system-ui,sans-serif;font-size:.86rem;border-left:1px solid #e6dcef;}',
    '.hr-jour-auj{color:#7b2cbf;}',
    '.hr-salle-tete{text-align:center;padding:6px 2px 8px;font-size:.74rem;font-weight:600;border-left:1px solid #f1eaf7;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}',
    '.hr-salle-tete i{display:block;height:4px;border-radius:2px;margin:0 auto 5px;width:70%;}',
    '.hr-salle-tete small{display:block;font-weight:400;color:#7a6f88;font-size:.7rem;}',
    '.hr-heures{position:sticky;left:0;z-index:4;background:#FFFFFF;border-right:1px solid #e6dcef;}',
    '.hr-heure{position:absolute;right:8px;font-size:.72rem;color:#7a6f88;transform:translateY(-50%);}',
    '.hr-col{position:relative;border-left:1px solid #f1eaf7;}',
    '.hr-col-jour{border-left:1px solid #d9cbe6;}',
    '.hr-case{position:absolute;left:0;right:0;border:0;padding:0;background:transparent;cursor:pointer;}',
    '.hr-case:hover,.hr-case:focus-visible{background:rgba(19,184,173,.16);outline:none;}',
    '.hr-case:hover::after,.hr-case:focus-visible::after{content:"+";position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:#0d8a81;font-weight:700;}',
    '.hr-ferme{position:absolute;left:0;right:0;top:0;background:repeating-linear-gradient(135deg,#f6f2fa 0 6px,#efe8f6 6px 12px);pointer-events:none;}',
    '.hr-bloc{position:absolute;left:2px;right:2px;border-radius:7px;padding:3px 6px;overflow:hidden;font-size:.72rem;line-height:1.25;z-index:2;border:2px solid transparent;text-align:left;}',
    '.hr-bloc b{display:block;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}',
    '.hr-bloc span{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}',
    '.hr-bloc-attente{opacity:.42;border-style:dashed;}',
    '.hr-bloc-etroit{padding:2px 3px;left:1px;right:1px;}',
    'button.hr-bloc{cursor:pointer;font-family:inherit;}',
    'button.hr-bloc:hover{filter:brightness(1.08);box-shadow:0 4px 12px rgba(26,18,37,.25);}',
    '.hr-maintenant{position:absolute;left:0;right:0;height:2px;background:#e0245e;z-index:3;pointer-events:none;}',
    '.hr-message{padding:14px 18px;border-radius:12px;margin-bottom:14px;font-size:.94rem;line-height:1.6;}',
    '.hr-ok{background:rgba(19,184,173,.14);color:#0b4f4b;}',
    '.hr-erreur{background:#fde8e8;color:#8a1019;}',
    '.hr-chargement{position:absolute;top:10px;right:14px;font-size:.78rem;color:#7a6f88;z-index:7;}',
    '.hr-voile{position:fixed;inset:0;background:rgba(26,18,37,.55);z-index:1000;display:flex;align-items:flex-start;justify-content:center;padding:4vh 16px;overflow:auto;}',
    '.hr-modale{background:#FFFFFF;border-radius:18px;width:100%;max-width:560px;padding:clamp(22px,4vw,34px);box-shadow:0 20px 60px rgba(26,18,37,.35);position:relative;}',
    '.hr-modale h3{font-family:Montserrat,system-ui,sans-serif;font-size:1.35rem;font-weight:700;margin:0 0 6px;}',
    '.hr-fermer{position:absolute;top:14px;right:14px;width:38px;height:38px;border-radius:9999px;border:0;background:#f1eaf7;color:#4d0780;font-size:1.3rem;cursor:pointer;}',
    '.hr-form{display:grid;gap:14px;margin-top:18px;}',
    '.hr-ligne{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;}',
    '.hr-champ{display:grid;gap:6px;font-size:.84rem;font-weight:600;color:#3a2f48;}',
    '.hr-champ input,.hr-champ select,.hr-champ textarea{font-family:inherit;font-size:.95rem;font-weight:400;padding:11px 13px;border:1.5px solid #d9cbe6;border-radius:10px;background:#FFFFFF;color:#1A1225;width:100%;}',
    '.hr-champ textarea{min-height:84px;resize:vertical;}',
    '.hr-champ input:focus,.hr-champ select:focus,.hr-champ textarea:focus{border-color:#7b2cbf;outline:none;box-shadow:0 0 0 3px rgba(123,44,191,.15);}',
    '.hr-recap{display:flex;justify-content:space-between;align-items:center;gap:12px;background:#f6f2fa;border-radius:12px;padding:12px 16px;font-size:.92rem;}',
    '.hr-recap strong{font-family:Montserrat,system-ui,sans-serif;font-size:1.25rem;color:#4d0780;}',
    '.hr-case-a-cocher{display:flex;gap:10px;align-items:flex-start;font-size:.86rem;line-height:1.5;color:#514860;}',
    '.hr-case-a-cocher input{margin-top:4px;width:18px;height:18px;accent-color:#7b2cbf;flex:0 0 auto;}',
    '.hr-piege{position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;}',
    '.hr-actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:6px;}',
    '.hr-fiche{display:grid;grid-template-columns:auto 1fr;gap:6px 14px;margin-top:16px;font-size:.92rem;}',
    '.hr-fiche dt{color:#7a6f88;}',
    '.hr-fiche dd{margin:0;word-break:break-word;}',
    '.hr-attente{display:grid;gap:10px;margin-bottom:22px;}',
    '.hr-attente-ligne{display:flex;flex-wrap:wrap;gap:10px 16px;align-items:center;background:#FFFFFF;border-radius:14px;padding:14px 18px;box-shadow:0 4px 16px rgba(77,7,128,.08);border-left:6px solid #ccc;}',
    '.hr-attente-ligne .hr-qui{flex:1 1 240px;font-size:.9rem;line-height:1.5;}',
    '.hr-attente-ligne .hr-qui strong{font-family:Montserrat,system-ui,sans-serif;}',
    '.hr-alerte{color:#a3141e;font-weight:600;}',
    '.hr-connexion{max-width:420px;margin:0 auto;background:#FFFFFF;border-radius:18px;padding:32px;box-shadow:0 8px 30px rgba(77,7,128,.12);}',
    '@media (max-width:640px){.hr-titre{order:-1;flex-basis:100%;}.hr-defile{max-height:70vh;}}'
  ].join('\n');

  function injecterStyles() {
    if (typeof document === 'undefined' || document.getElementById('hr-styles')) return;
    var s = document.createElement('style');
    s.id = 'hr-styles';
    s.textContent = CSS;
    document.head.appendChild(s);
  }

  /* ------------------------------------------------------------------
     Fabrique
     ------------------------------------------------------------------ */

  var cache = null;

  window.HemanReservation = function (React) {
    if (cache && cache.React === React) return cache.Composant;

    var h = React.createElement;
    var useState = React.useState, useEffect = React.useEffect,
      useRef = React.useRef, useCallback = React.useCallback;

    function couleur(salles, id) {
      if (COULEURS[id]) return COULEURS[id];
      var i = 0;
      for (var k = 0; k < salles.length; k++) if (salles[k].id === id) i = k;
      return COULEURS_SECOURS[i % COULEURS_SECOURS.length];
    }

    function chevauche(reservations, salle, date, debut, fin, ignorer) {
      for (var i = 0; i < reservations.length; i++) {
        var r = reservations[i];
        if (r.id === ignorer || r.salle !== salle || r.date !== date) continue;
        if (debut < r.fin && r.debut < fin) return r;
      }
      return null;
    }

    /* ---------------- Fenêtre modale ---------------- */

    function Modale(props) {
      useEffect(function () {
        function touche(e) { if (e.key === 'Escape') props.onFermer(); }
        document.addEventListener('keydown', touche);
        var ancien = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        return function () {
          document.removeEventListener('keydown', touche);
          document.body.style.overflow = ancien;
        };
      }, []);
      return h('div', {
        className: 'hr-voile',
        onMouseDown: function (e) { if (e.target === e.currentTarget) props.onFermer(); }
      },
        h('div', { className: 'hr-modale', role: 'dialog', 'aria-modal': 'true', 'aria-label': props.titre },
          h('button', { type: 'button', className: 'hr-fermer', 'aria-label': 'Fermer', onClick: props.onFermer }, '×'),
          props.children));
    }

    function Champ(props) {
      return h('label', { className: 'hr-champ' }, props.libelle, props.children);
    }

    /* ---------------- Formulaire de demande / blocage ---------------- */

    function FormulaireCreneau(props) {
      var admin = props.admin, salles = props.salles, regles = props.regles;
      var c = props.creneau;
      var _s = useState(c.salle), salle = _s[0], setSalle = _s[1];
      var _d = useState(c.date), date = _d[0], setDate = _d[1];
      var _db = useState(c.debut), debut = _db[0], setDebut = _db[1];
      var _f = useState(c.fin), fin = _f[0], setFin = _f[1];
      var _e = useState(false), envoi = _e[0], setEnvoi = _e[1];
      var _er = useState(''), erreur = _er[0], setErreur = _er[1];
      var _ex = useState(null), existants = _ex[0], setExistants = _ex[1];

      // Créneaux occupés pour la date choisie (elle peut sortir de la période affichée)
      useEffect(function () {
        var actif = true;
        appel('GET', API + '?debut=' + date + '&fin=' + date).then(function (j) {
          if (actif) setExistants(j.reservations);
        }).catch(function () { if (actif) setExistants(null); });
        return function () { actif = false; };
      }, [date]);

      var pas = regles.pas, dureeMin = regles.dureeMin;
      var debuts = [], fins = [];
      for (var m = 0; m <= 1440 - dureeMin; m += pas) debuts.push(m);
      for (var n = debut + dureeMin; n <= 1440; n += pas) fins.push(n);

      var infoSalle = salles.filter(function (s) { return s.id === salle; })[0] || salles[0];
      var montant = infoSalle.tarif * (fin - debut) / 60;
      var liste = existants || props.reservations;
      var conflit = chevauche(liste, salle, date, debut, fin);
      var dateMin = ajouterJours(regles.aujourdhui, regles.joursMin);
      var dateMax = ajouterJours(regles.aujourdhui, regles.joursMax);
      var dateHorsDelai = !admin && (date < dateMin || date > dateMax);

      function changerDebut(v) {
        var d = +v;
        setDebut(d);
        if (fin < d + dureeMin) setFin(Math.min(1440, d + Math.max(dureeMin, fin - debut)));
      }

      function envoyer(e) {
        e.preventDefault();
        if (conflit || dateHorsDelai) return;
        var form = e.currentTarget;
        var lire = function (nom) { var el = form.elements[nom]; return el ? (el.type === 'checkbox' ? el.checked : el.value) : ''; };
        var charge = {
          action: admin ? 'bloquer' : 'demande',
          salle: salle, date: date, debut: heureChamp(debut), fin: heureChamp(fin)
        };
        if (admin) {
          charge.libelle = lire('libelle');
        } else {
          charge.nom = lire('nom');
          charge.email = lire('email');
          charge.tel = lire('tel');
          charge.structure = lire('structure');
          charge.activite = lire('activite');
          charge.participants = lire('participants');
          charge.message = lire('message');
          charge.reglement = lire('reglement');
          charge._piege = lire('site_web');
        }
        setEnvoi(true);
        setErreur('');
        appel('POST', API, charge).then(function (j) {
          props.onEnvoye(j.reservation || null, { salle: salle, date: date, debut: debut, fin: fin, email: charge.email });
        }).catch(function (err) {
          setEnvoi(false);
          setErreur(err.message);
          if (err.statut === 409) props.onConflit();
        });
      }

      return h(Modale, { titre: admin ? 'Bloquer un créneau' : 'Demande de réservation', onFermer: props.onFermer },
        h('h3', null, admin ? 'Bloquer un créneau' : 'Demander ce créneau'),
        h('p', { style: { margin: 0, color: '#514860', fontSize: '.92rem', lineHeight: 1.6 } },
          admin
            ? 'Pour un cours, un stage ou une location prise par téléphone : le créneau est réservé immédiatement.'
            : 'Votre demande apparaîtra en semi-transparent dans le calendrier jusqu’à sa validation par Héman. Vous recevrez la réponse par e-mail.'),
        h('form', { className: 'hr-form', onSubmit: envoyer, noValidate: false },
          h('div', { className: 'hr-ligne' },
            h(Champ, { libelle: 'Salle' },
              h('select', { value: salle, onChange: function (e) { setSalle(e.target.value); } },
                salles.map(function (s) { return h('option', { key: s.id, value: s.id }, s.nom + ' — ' + s.tarif + ' €/h'); }))),
            h(Champ, { libelle: 'Date' },
              h('input', { type: 'date', required: true, value: date, min: admin ? undefined : dateMin, max: admin ? undefined : dateMax,
                onChange: function (e) { if (e.target.value) setDate(e.target.value); } }))),
          h('div', { className: 'hr-ligne' },
            h(Champ, { libelle: 'Début' },
              h('select', { value: debut, onChange: function (e) { changerDebut(e.target.value); } },
                debuts.map(function (v) { return h('option', { key: v, value: v }, heure(v)); }))),
            h(Champ, { libelle: 'Fin' },
              h('select', { value: fin, onChange: function (e) { setFin(+e.target.value); } },
                fins.map(function (v) { return h('option', { key: v, value: v }, v === 1440 ? 'minuit' : heure(v)); })))),
          h('div', { className: 'hr-recap' },
            h('span', null, infoSalle.nom + ' · ' + duree(fin - debut)),
            admin ? null : h('strong', null, euros(montant))),
          dateHorsDelai ? h('div', { className: 'hr-message hr-erreur', role: 'alert' },
            date < dateMin
              ? 'Les réservations pour le jour même ne sont pas possibles : choisissez une date à partir de demain.'
              : 'Les réservations sont ouvertes jusqu’à ' + regles.joursMax + ' jours à l’avance.') : null,
          conflit ? h('div', { className: 'hr-message hr-erreur', role: 'alert' },
            'Ce créneau chevauche ' + (conflit.statut === 'attente' ? 'une demande en attente' : 'une réservation') +
            ' (' + heure(conflit.debut) + ' – ' + heure(conflit.fin) + '). Choisissez un autre horaire ou une autre salle.') : null,

          admin
            ? h(Champ, { libelle: 'Libellé (visible par l’équipe uniquement)' },
                h('input', { name: 'libelle', required: true, maxLength: 120, placeholder: 'Cours de hip-hop, stage, location par téléphone…' }))
            : [
              h('div', { className: 'hr-ligne', key: 'l1' },
                h(Champ, { libelle: 'Nom et prénom *' }, h('input', { name: 'nom', required: true, maxLength: 100, autoComplete: 'name' })),
                h(Champ, { libelle: 'Structure (facultatif)' }, h('input', { name: 'structure', maxLength: 120, placeholder: 'Compagnie, association…', autoComplete: 'organization' }))),
              h('div', { className: 'hr-ligne', key: 'l2' },
                h(Champ, { libelle: 'E-mail *' }, h('input', { name: 'email', type: 'email', required: true, maxLength: 150, autoComplete: 'email' })),
                h(Champ, { libelle: 'Téléphone *' }, h('input', { name: 'tel', type: 'tel', required: true, maxLength: 30, autoComplete: 'tel' }))),
              h('div', { className: 'hr-ligne', key: 'l3' },
                h(Champ, { libelle: 'Activité *' }, h('input', { name: 'activite', required: true, maxLength: 120, placeholder: 'Répétition, cours, casting, yoga…' })),
                h(Champ, { libelle: 'Nombre de personnes' }, h('input', { name: 'participants', type: 'number', min: 1, max: 200, inputMode: 'numeric' }))),
              h(Champ, { libelle: 'Message (facultatif)', key: 'msg' }, h('textarea', { name: 'message', maxLength: 2000 })),
              h('div', { className: 'hr-piege', 'aria-hidden': 'true', key: 'piege' },
                h('label', null, 'Site web', h('input', { name: 'site_web', tabIndex: -1, autoComplete: 'off' }))),
              h('label', { className: 'hr-case-a-cocher', key: 'regl' },
                h('input', { type: 'checkbox', name: 'reglement', required: true }),
                h('span', null, 'J’ai lu et j’accepte le ',
                  h('a', { href: '#reglement', onClick: props.onFermer }, 'règlement intérieur'),
                  ' : la réservation n’est définitive qu’une fois réglée, et toute séance décommandée moins de 48 h à l’avance reste due.'))
            ],

          erreur ? h('div', { className: 'hr-message hr-erreur', role: 'alert' }, erreur) : null,
          h('div', { className: 'hr-actions' },
            h('button', { type: 'submit', className: 'hr-btn hr-btn-plein', disabled: envoi || !!conflit || dateHorsDelai },
              envoi ? 'Envoi…' : (admin ? 'Bloquer le créneau' : 'Envoyer la demande')),
            h('button', { type: 'button', className: 'hr-btn', onClick: props.onFermer }, 'Annuler'))));
    }

    /* ---------------- Fiche d'une réservation (admin) ---------------- */

    function FicheReservation(props) {
      var r = props.reservation, salles = props.salles;
      var infoSalle = salles.filter(function (s) { return s.id === r.salle; })[0] || { nom: r.salle };
      var _m = useState(''), motif = _m[0], setMotif = _m[1];
      var _rf = useState(false), refus = _rf[0], setRefus = _rf[1];
      var _e = useState(false), envoi = _e[0], setEnvoi = _e[1];
      var _er = useState(''), erreur = _er[0], setErreur = _er[1];

      function agir(action, extra) {
        setEnvoi(true);
        setErreur('');
        var corps = { action: action, id: r.id };
        if (extra) for (var k in extra) corps[k] = extra[k];
        appel('POST', API, corps).then(function () {
          props.onFait();
        }).catch(function (err) {
          setEnvoi(false);
          setErreur(err.message);
        });
      }

      var lignes = [
        ['Salle', infoSalle.nom],
        ['Date', dateLongue(r.date)],
        ['Horaire', heure(r.debut) + ' – ' + (r.fin === 1440 ? 'minuit' : heure(r.fin))],
        ['Statut', r.statut === 'attente' ? 'En attente de validation' : (r.interne ? 'Créneau bloqué par l’équipe' : 'Validée')]
      ];
      if (!r.interne) {
        lignes.push(['Montant', r.prix || '']);
        lignes.push(['Nom', r.nom]);
        lignes.push(['E-mail', h('a', { href: 'mailto:' + r.email }, r.email)]);
        lignes.push(['Téléphone', h('a', { href: 'tel:' + String(r.tel).replace(/\s/g, '') }, r.tel)]);
        if (r.structure) lignes.push(['Structure', r.structure]);
        lignes.push(['Activité', r.activite]);
        if (r.participants) lignes.push(['Personnes', r.participants]);
        if (r.message) lignes.push(['Message', h('span', { style: { whiteSpace: 'pre-wrap' } }, r.message)]);
        if (r.creeLe) lignes.push(['Demandé le', new Date(r.creeLe).toLocaleString('fr-FR')]);
      } else {
        lignes.push(['Libellé', r.nom]);
      }

      var conflitValide = r.statut === 'attente'
        ? chevauche(props.reservations.filter(function (x) { return x.statut === 'validee'; }), r.salle, r.date, r.debut, r.fin, r.id)
        : null;

      return h(Modale, { titre: 'Réservation', onFermer: props.onFermer },
        h('h3', null, r.interne ? r.nom : (r.statut === 'attente' ? 'Demande à traiter' : 'Réservation validée')),
        h('dl', { className: 'hr-fiche' }, lignes.map(function (l, i) {
          return [h('dt', { key: 't' + i }, l[0]), h('dd', { key: 'd' + i }, l[1])];
        })),
        conflitValide ? h('p', { className: 'hr-message hr-erreur', style: { marginTop: 16 } },
          'Attention : chevauche une réservation validée (' + heure(conflitValide.debut) + ' – ' + heure(conflitValide.fin) + ').') : null,
        refus ? h('div', { className: 'hr-form' },
          h(Champ, { libelle: 'Motif envoyé au demandeur (facultatif)' },
            h('textarea', { value: motif, maxLength: 500, onChange: function (e) { setMotif(e.target.value); }, placeholder: 'Ex. : la salle est prise pour un stage ce jour-là.' }))) : null,
        erreur ? h('div', { className: 'hr-message hr-erreur', role: 'alert', style: { marginTop: 14 } }, erreur) : null,
        h('div', { className: 'hr-actions', style: { marginTop: 20 } },
          r.statut === 'attente' && !refus ? [
            h('button', { key: 'v', type: 'button', className: 'hr-btn hr-btn-plein', disabled: envoi || !!conflitValide, onClick: function () { agir('valider'); } }, 'Valider'),
            h('button', { key: 'r', type: 'button', className: 'hr-btn hr-btn-danger', disabled: envoi, onClick: function () { setRefus(true); } }, 'Refuser…')
          ] : null,
          r.statut === 'attente' && refus ? [
            h('button', { key: 'c', type: 'button', className: 'hr-btn hr-btn-danger', disabled: envoi, onClick: function () { agir('refuser', { motif: motif }); } }, 'Confirmer le refus'),
            h('button', { key: 'a', type: 'button', className: 'hr-btn', onClick: function () { setRefus(false); } }, 'Retour')
          ] : null,
          r.statut === 'validee' ? h('button', {
            type: 'button', className: 'hr-btn hr-btn-danger', disabled: envoi,
            onClick: function () {
              if (window.confirm('Supprimer ce créneau du calendrier ?' + (r.interne ? '' : ' Aucun e-mail n’est envoyé : prévenez le client vous-même.'))) agir('supprimer');
            }
          }, r.interne ? 'Supprimer le créneau' : 'Annuler la réservation') : null));
    }

    /* ---------------- Grille du calendrier ---------------- */

    function Grille(props) {
      var salles = props.salles, dates = props.dates, admin = props.admin;
      var reservations = props.reservations;
      var defile = useRef(null);
      var hauteur = 48 * LIGNE;
      var nbCol = dates.length * salles.length;
      var semaine = dates.length > 1;
      var largeurCol = semaine ? 'minmax(46px,1fr)' : 'minmax(92px,1fr)';
      var colonnes = '56px repeat(' + nbCol + ',' + largeurCol + ')';
      var auj = aujourdhui();
      var premierJourOuvert = ajouterJours(props.regles.aujourdhui, props.regles.joursMin);

      useEffect(function () {
        // Une demi-heure de marge pour que l'étiquette de l'heure reste visible sous l'en-tête
        if (defile.current) defile.current.scrollTop = (HEURE_DEFILEMENT * 2 - 1) * LIGNE;
      }, []);

      // Ligne rouge « maintenant », mise à jour chaque minute
      var _n = useState(Date.now()), setMaintenant = _n[1];
      useEffect(function () {
        var t = setInterval(function () { setMaintenant(Date.now()); }, 60000);
        return function () { clearInterval(t); };
      }, []);
      var maintenant = new Date();
      var minutesMaintenant = maintenant.getHours() * 60 + maintenant.getMinutes();

      var entete = [h('div', { key: 'coin', className: 'hr-entete hr-coin', style: { gridRow: semaine ? '1 / span 2' : '1', gridColumn: 1 } })];
      dates.forEach(function (d, i) {
        var dt = versDate(d);
        if (semaine) {
          entete.push(h('div', {
            key: 'j' + d,
            className: 'hr-entete hr-jour' + (d === auj ? ' hr-jour-auj' : ''),
            style: { gridRow: 1, gridColumn: (2 + i * salles.length) + ' / span ' + salles.length }
          }, JOURS_COURTS[dt.getDay()] + ' ' + dt.getDate()));
        }
        salles.forEach(function (s, k) {
          var col = couleur(salles, s.id);
          entete.push(h('div', {
            key: 's' + d + s.id,
            className: 'hr-entete hr-salle-tete',
            title: s.nom + ' — ' + s.tarif + ' €/h',
            style: { gridRow: semaine ? 2 : 1, gridColumn: 2 + i * salles.length + k, top: semaine ? 36 : 0 }
          },
            h('i', { style: { background: col.fond } }),
            semaine ? s.nom.replace('Héman ', 'H') : s.nom,
            semaine ? null : h('small', null, s.tarif + ' €/h')));
        });
      });

      var corpsRang = semaine ? 3 : 2;
      var etiquettes = [];
      for (var hh = 1; hh < 24; hh++) {
        etiquettes.push(h('span', { key: hh, className: 'hr-heure', style: { top: hh * 2 * LIGNE } }, hh + 'h'));
      }
      var colonnesCorps = [
        h('div', { key: 'heures', className: 'hr-heures', style: { gridRow: corpsRang, gridColumn: 1, height: hauteur, position: 'sticky' } }, etiquettes)
      ];

      var fondGrille = 'repeating-linear-gradient(to bottom, transparent 0, transparent ' + (LIGNE - 1) + 'px, #f5f0f9 ' + (LIGNE - 1) + 'px, #f5f0f9 ' + LIGNE + 'px),' +
        'repeating-linear-gradient(to bottom, transparent 0, transparent ' + (2 * LIGNE - 1) + 'px, #e6dcef ' + (2 * LIGNE - 1) + 'px, #e6dcef ' + (2 * LIGNE) + 'px)';

      dates.forEach(function (d, i) {
        var ferme = !admin && d < premierJourOuvert;
        salles.forEach(function (s, k) {
          var col = couleur(salles, s.id);
          var enfants = [];
          if (ferme) {
            enfants.push(h('div', { key: 'f', className: 'hr-ferme', style: { height: hauteur }, title: 'Réservation impossible pour cette date' }));
          } else {
            for (var m = 0; m < 1440; m += 30) {
              enfants.push(h('button', {
                key: m, type: 'button', className: 'hr-case',
                style: { top: (m / 30) * LIGNE, height: LIGNE },
                'aria-label': (admin ? 'Bloquer ' : 'Demander ') + s.nom + ', ' + dateLongue(d) + ', ' + heure(m),
                onClick: (function (debut) { return function () { props.onCase(s.id, d, debut); }; })(m)
              }));
            }
          }
          reservations.forEach(function (r) {
            if (r.salle !== s.id || r.date !== d) return;
            var attente = r.statut === 'attente';
            var top = (r.debut / 30) * LIGNE, haut = ((r.fin - r.debut) / 30) * LIGNE - 2;
            var horaire = heure(r.debut) + '–' + (r.fin === 1440 ? '0h' : heure(r.fin));
            var titre = admin ? (r.nom || '') : (attente ? 'En attente' : 'Réservé');
            var style = {
              top: top + 1, height: haut, background: col.fond, color: col.texte,
              borderColor: attente ? col.fond : 'transparent'
            };
            var classe = 'hr-bloc' + (attente ? ' hr-bloc-attente' : '') + (semaine ? ' hr-bloc-etroit' : '');
            // Vue semaine : colonnes étroites, on affiche début et fin sur deux lignes
            var fin = r.fin === 1440 ? '0h' : heure(r.fin);
            var contenu = semaine
              ? (admin
                ? [h('b', { key: 'b' }, titre), h('span', { key: 's' }, heure(r.debut) + '–' + fin)]
                : [h('b', { key: 'b' }, heure(r.debut)), h('span', { key: 's' }, fin)])
              : [h('b', { key: 'b' }, titre), h('span', { key: 's' }, horaire), admin && !r.interne ? h('span', { key: 'a' }, r.activite) : null];
            var info = s.nom + ', ' + horaire + ' — ' + (attente ? 'en attente de validation' : 'réservé') + (admin && r.nom ? ' — ' + r.nom : '');
            if (admin) {
              enfants.push(h('button', { key: r.id, type: 'button', className: classe, style: style, title: info, 'aria-label': info,
                onClick: function () { props.onBloc(r); } }, contenu));
            } else {
              enfants.push(h('div', { key: r.id, className: classe, style: style, title: info, role: 'img', 'aria-label': info }, contenu));
            }
          });
          if (d === auj) {
            enfants.push(h('div', { key: 'now', className: 'hr-maintenant', style: { top: (minutesMaintenant / 30) * LIGNE } }));
          }
          colonnesCorps.push(h('div', {
            key: d + s.id,
            className: 'hr-col' + (k === 0 ? ' hr-col-jour' : ''),
            style: { gridRow: corpsRang, gridColumn: 2 + i * salles.length + k, height: hauteur, backgroundImage: fondGrille }
          }, enfants));
        });
      });

      return h('div', { className: 'hr-cadre' },
        props.chargement ? h('div', { className: 'hr-chargement' }, 'Mise à jour…') : null,
        h('div', { className: 'hr-defile', ref: defile },
          h('div', { className: 'hr-grille', style: { gridTemplateColumns: colonnes, minWidth: semaine ? 56 + nbCol * 46 : 56 + nbCol * 92 } },
            entete, colonnesCorps)));
    }

    /* ---------------- Composant principal ---------------- */

    function Calendrier(props) {
      var admin = props.mode === 'admin';
      var largeurInitiale = typeof window !== 'undefined' ? window.innerWidth : 1200;

      var _v = useState(largeurInitiale < 760 ? 'jour' : 'semaine'), vue = _v[0], setVue = _v[1];
      var _r = useState(function () {
        var demain = ajouterJours(aujourdhui(), admin ? 0 : 1);
        return largeurInitiale < 760 ? demain : lundiDe(demain);
      }), reference = _r[0], setReference = _r[1];
      var _d = useState(null), donnees = _d[0], setDonnees = _d[1];
      var _c = useState(false), chargement = _c[0], setChargement = _c[1];
      var _e = useState(''), erreur = _e[0], setErreur = _e[1];
      var _sel = useState(null), selection = _sel[0], setSelection = _sel[1];
      var _bl = useState(null), bloc = _bl[0], setBloc = _bl[1];
      var _ok = useState(''), succes = _ok[0], setSucces = _ok[1];
      var _co = useState(admin ? null : true), connecte = _co[0], setConnecte = _co[1];

      var debutPeriode = vue === 'semaine' ? lundiDe(reference) : reference;
      var finPeriode = vue === 'semaine' ? ajouterJours(debutPeriode, 6) : reference;

      var charger = useCallback(function () {
        setChargement(true);
        return appel('GET', API + '?debut=' + debutPeriode + '&fin=' + finPeriode).then(function (j) {
          setDonnees(j);
          setErreur('');
          setChargement(false);
          if (admin) setConnecte(!!j.admin);
        }).catch(function (err) {
          setErreur(err.message);
          setChargement(false);
        });
      }, [debutPeriode, finPeriode, admin]);

      useEffect(function () {
        charger();
        var t = setInterval(charger, 60000);
        return function () { clearInterval(t); };
      }, [charger]);

      useEffect(injecterStyles, []);

      var dates = [];
      for (var i = 0; i < (vue === 'semaine' ? 7 : 1); i++) dates.push(ajouterJours(debutPeriode, i));

      function deplacer(sens) {
        setReference(ajouterJours(reference, sens * (vue === 'semaine' ? 7 : 1)));
      }
      function changerVue(v) {
        if (v === vue) return;
        if (v === 'jour') {
          // On garde le jour le plus pertinent de la semaine affichée
          var cible = ajouterJours(aujourdhui(), admin ? 0 : 1);
          setReference(cible >= debutPeriode && cible <= ajouterJours(debutPeriode, 6) ? cible : debutPeriode);
        }
        setVue(v);
      }
      function allerA(date) {
        setReference(vue === 'semaine' ? lundiDe(date) : date);
      }

      /* ---- Écran de connexion (admin) ---- */
      if (admin && connecte === false) {
        return h(EcranConnexion, { onConnecte: function () { charger(); } });
      }

      var salles = donnees ? donnees.salles : [];
      var regles = donnees ? donnees.regles : null;
      var reservations = donnees ? donnees.reservations : [];

      var peutReculer = admin || debutPeriode > (regles ? regles.aujourdhui : '');

      var enfants = [];

      enfants.push(h('div', { key: 'barre', className: 'hr-barre' },
        h('div', { className: 'hr-nav' },
          h('button', { type: 'button', className: 'hr-btn hr-btn-rond', 'aria-label': vue === 'semaine' ? 'Semaine précédente' : 'Jour précédent', disabled: !peutReculer, onClick: function () { deplacer(-1); } }, '‹'),
          h('button', { type: 'button', className: 'hr-btn', onClick: function () { allerA(ajouterJours(aujourdhui(), admin ? 0 : 1)); } }, admin ? 'Aujourd’hui' : 'Demain'),
          h('button', { type: 'button', className: 'hr-btn hr-btn-rond', 'aria-label': vue === 'semaine' ? 'Semaine suivante' : 'Jour suivant', onClick: function () { deplacer(1); } }, '›')),
        h('div', { className: 'hr-titre', 'aria-live': 'polite' }, titrePeriode(vue, debutPeriode)),
        h('div', { className: 'hr-bascule', role: 'group', 'aria-label': 'Affichage' },
          h('button', { type: 'button', 'aria-pressed': vue === 'jour', onClick: function () { changerVue('jour'); } }, 'Jour'),
          h('button', { type: 'button', 'aria-pressed': vue === 'semaine', onClick: function () { changerVue('semaine'); } }, 'Semaine')),
        admin ? h('button', { type: 'button', className: 'hr-btn', onClick: function () {
          appel('POST', API, { action: 'deconnexion' }).then(function () { setConnecte(false); }, function () { setConnecte(false); });
        } }, 'Se déconnecter') : null));

      if (salles.length) {
        enfants.push(h('div', { key: 'legende', className: 'hr-legende' },
          salles.map(function (s) {
            return h('span', { key: s.id }, h('i', { className: 'hr-pastille', style: { background: couleur(salles, s.id).fond } }), s.nom + ' · ' + s.tarif + ' €/h');
          }),
          h('span', { key: 'att' }, h('i', { className: 'hr-pastille', style: { background: '#7b2cbf', opacity: 0.42, border: '2px dashed #7b2cbf' } }), 'En attente de validation'),
          h('span', { key: 'val' }, h('i', { className: 'hr-pastille', style: { background: '#7b2cbf' } }), 'Réservé')));
      }

      if (succes) {
        enfants.push(h('div', { key: 'ok', className: 'hr-message hr-ok', role: 'status' }, succes,
          h('button', { type: 'button', className: 'hr-btn', style: { marginLeft: 12, padding: '4px 12px' }, onClick: function () { setSucces(''); } }, 'OK')));
      }
      if (erreur) {
        enfants.push(h('div', { key: 'err', className: 'hr-message hr-erreur', role: 'alert' }, erreur));
      }

      if (admin && donnees && donnees.enAttente) {
        enfants.push(h(ListeAttente, {
          key: 'attente', demandes: donnees.enAttente, salles: salles,
          onVoir: function (r) { allerA(r.date); setBloc(r); }
        }));
      }

      if (!admin && salles.length) {
        enfants.push(h('p', { key: 'aide', style: { margin: '0 0 14px', fontSize: '.9rem', color: '#514860' } },
          'Cliquez sur un horaire libre dans la colonne de la salle souhaitée pour faire votre demande.'));
      }

      if (donnees) {
        enfants.push(h(Grille, {
          key: 'grille', salles: salles, regles: regles, dates: dates, admin: admin,
          reservations: reservations, chargement: chargement,
          onCase: function (salle, date, debut) {
            setSucces('');
            setSelection({ salle: salle, date: date, debut: Math.min(debut, 1440 - regles.dureeMin), fin: Math.min(1440, Math.max(debut + 60, debut + regles.dureeMin)) });
          },
          onBloc: function (r) { setBloc(r); }
        }));
      } else if (!erreur) {
        enfants.push(h('div', { key: 'attente-chargement', className: 'hr-cadre', style: { padding: 40, textAlign: 'center', color: '#7a6f88' } }, 'Chargement du calendrier…'));
      }

      if (selection && regles) {
        enfants.push(h(FormulaireCreneau, {
          key: 'form', admin: admin, salles: salles, regles: regles, creneau: selection, reservations: reservations,
          onFermer: function () { setSelection(null); },
          onConflit: function () { charger(); },
          onEnvoye: function (nouvelle, c) {
            setSelection(null);
            if (nouvelle) {
              // Affichage immédiat en semi-transparent, sans attendre le rechargement
              setDonnees(function (prec) { return prec ? Object.assign({}, prec, { reservations: prec.reservations.concat([nouvelle]) }) : prec; });
            }
            var nomSalle = (salles.filter(function (s) { return s.id === c.salle; })[0] || {}).nom;
            setSucces(admin
              ? 'Créneau bloqué : ' + nomSalle + ', ' + dateLongue(c.date) + ', ' + heure(c.debut) + ' – ' + heure(c.fin) + '.'
              : 'Merci ! Votre demande pour ' + nomSalle + ', le ' + dateLongue(c.date) + ' de ' + heure(c.debut) + ' à ' + heure(c.fin) +
                ', est enregistrée. Elle apparaît en semi-transparent jusqu’à notre validation ; un e-mail de confirmation vous a été envoyé' + (c.email ? ' à ' + c.email : '') + '.');
            allerA(c.date);
            charger();
          }
        }));
      }

      if (bloc && admin) {
        enfants.push(h(FicheReservation, {
          key: 'fiche-' + bloc.id, reservation: bloc, salles: salles, reservations: reservations,
          onFermer: function () { setBloc(null); },
          onFait: function () { setBloc(null); charger(); }
        }));
      }

      return h('div', { className: 'hr' }, enfants);
    }

    /* ---------------- Demandes en attente (admin) ---------------- */

    function ListeAttente(props) {
      var demandes = props.demandes, salles = props.salles;
      return h('section', { className: 'hr-attente', 'aria-label': 'Demandes en attente' },
        h('h2', { style: { fontFamily: 'Montserrat, system-ui, sans-serif', fontSize: '1.2rem', margin: '4px 0 2px' } },
          demandes.length ? demandes.length + ' demande' + (demandes.length > 1 ? 's' : '') + ' en attente' : 'Aucune demande en attente'),
        demandes.map(function (r) {
          var s = salles.filter(function (x) { return x.id === r.salle; })[0] || { nom: r.salle };
          var passee = r.date < aujourdhui();
          return h('div', { key: r.id, className: 'hr-attente-ligne', style: { borderLeftColor: couleur(salles, r.salle).fond } },
            h('div', { className: 'hr-qui' },
              h('strong', null, s.nom + ' — ' + dateLongue(r.date) + ', ' + heure(r.debut) + ' – ' + (r.fin === 1440 ? 'minuit' : heure(r.fin))),
              h('br'),
              r.nom + (r.structure ? ' (' + r.structure + ')' : '') + ' · ' + r.activite + ' · ' + (r.prix || ''),
              passee ? h('span', { className: 'hr-alerte' }, ' · date passée') : null),
            h('button', { type: 'button', className: 'hr-btn hr-btn-violet', onClick: function () { props.onVoir(r); } }, 'Voir et traiter'));
        }));
    }

    /* ---------------- Connexion (admin) ---------------- */

    function EcranConnexion(props) {
      var _e = useState(''), erreur = _e[0], setErreur = _e[1];
      var _v = useState(false), envoi = _v[0], setEnvoi = _v[1];
      useEffect(injecterStyles, []);
      return h('div', { className: 'hr' },
        h('form', {
          className: 'hr-connexion',
          onSubmit: function (e) {
            e.preventDefault();
            var mdp = e.currentTarget.elements.motDePasse.value;
            setEnvoi(true);
            setErreur('');
            appel('POST', API, { action: 'connexion', motDePasse: mdp }).then(function () {
              props.onConnecte();
            }).catch(function (err) {
              setEnvoi(false);
              setErreur(err.message);
            });
          }
        },
          h('h1', { style: { fontFamily: 'Montserrat, system-ui, sans-serif', fontSize: '1.5rem', margin: '0 0 6px' } }, 'Réservations des salles'),
          h('p', { style: { margin: '0 0 18px', color: '#514860', fontSize: '.92rem' } }, 'Espace réservé à l’équipe Héman.'),
          h('div', { className: 'hr-form', style: { marginTop: 0 } },
            h(Champ, { libelle: 'Mot de passe' }, h('input', { name: 'motDePasse', type: 'password', required: true, autoComplete: 'current-password', autoFocus: true })),
            erreur ? h('div', { className: 'hr-message hr-erreur', role: 'alert' }, erreur) : null,
            h('button', { type: 'submit', className: 'hr-btn hr-btn-violet', disabled: envoi }, envoi ? 'Connexion…' : 'Se connecter'))));
    }

    cache = { React: React, Composant: Calendrier };
    return Calendrier;
  };
})();
