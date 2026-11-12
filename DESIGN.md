---
name: OpenTransfer
description: Partage de fichiers auto-hébergé — une identité, sombre ou claire selon le système
colors:
  page: "#161616"
  card: "#1e1e1e"
  field: "#242424"
  raised: "#2a2a2a"
  active: "#353535"
  line: "#353535"
  line-soft: "#2a2a2a"
  line-field: "#3a3a3a"
  line-strong: "#6a6a6a"
  text: "#cecece"
  heading: "#ffffff"
  muted: "#929292"
  faint: "#6a6a6a"
  accent: "#6a6af4"
  accent-hover: "#7c7cf6"
  accent-fill: "#6262ee"
  accent-fill-hover: "#5b5be6"
  accent-text: "#8585f6"
  on-accent: "#ffffff"
  accent-soft: "#23233f"
  success: "#27a658"
  success-soft: "#19271d"
  warning: "#fc5d00"
  warning-soft: "#361e19"
  danger: "#ff5655"
  danger-soft: "#391c1c"
  danger-text: "#ff9c9c"
  page-light: "#f6f6f6"
  card-light: "#ffffff"
  field-light: "#f6f6f6"
  raised-light: "#eeeeee"
  line-light: "#dddddd"
  text-light: "#3a3a3a"
  heading-light: "#161616"
  muted-light: "#666666"
  accent-light: "#000091"
  accent-hover-light: "#1212ff"
  success-light: "#18753c"
  warning-light: "#b34000"
  danger-light: "#ce0500"
typography:
  page-title:
    fontFamily: "Plus Jakarta Sans Variable, Plus Jakarta Sans, system-ui, sans-serif"
    fontSize: "1.625rem"
    fontWeight: 800
    lineHeight: 1.2
  share-title:
    fontFamily: "Plus Jakarta Sans Variable, Plus Jakarta Sans, system-ui, sans-serif"
    fontSize: "2.125rem"
    fontWeight: 800
    lineHeight: 1.2
  figure:
    fontFamily: "Plus Jakarta Sans Variable, Plus Jakarta Sans, system-ui, sans-serif"
    fontSize: "1.875rem"
    fontWeight: 800
    lineHeight: 1.15
    fontFeature: "tnum"
  card-title:
    fontFamily: "Plus Jakarta Sans Variable, Plus Jakarta Sans, system-ui, sans-serif"
    fontSize: "0.75rem"
    fontWeight: 700
    letterSpacing: "0.06em"
  body:
    fontFamily: "Plus Jakarta Sans Variable, Plus Jakarta Sans, system-ui, sans-serif"
    fontSize: "0.875rem"
    fontWeight: 400
    lineHeight: 1.5
  label:
    fontFamily: "Plus Jakarta Sans Variable, Plus Jakarta Sans, system-ui, sans-serif"
    fontSize: "0.8125rem"
    fontWeight: 600
  button:
    fontFamily: "Plus Jakarta Sans Variable, Plus Jakarta Sans, system-ui, sans-serif"
    fontSize: "0.84375rem"
    fontWeight: 700
  meta:
    fontFamily: "Plus Jakarta Sans Variable, Plus Jakarta Sans, system-ui, sans-serif"
    fontSize: "0.75rem"
    fontWeight: 400
rounded:
  pill: "3px"
  button: "4px"
  card: "6px"
  zone: "8px"
spacing:
  xs: "6px"
  sm: "10px"
  md: "16px"
  lg: "20px"
  xl: "24px"
  page: "32px"
components:
  button-primary:
    backgroundColor: "{colors.accent-fill}"
    textColor: "{colors.on-accent}"
    typography: "{typography.button}"
    rounded: "{rounded.button}"
    padding: "0 14px"
    height: "36px"
  button-primary-hover:
    backgroundColor: "{colors.accent-hover}"
  button-secondary:
    backgroundColor: "{colors.raised}"
    textColor: "{colors.text}"
    rounded: "{rounded.button}"
    height: "36px"
  button-secondary-hover:
    backgroundColor: "{colors.active}"
    textColor: "{colors.heading}"
  button-ghost:
    textColor: "{colors.text}"
    rounded: "{rounded.button}"
    height: "36px"
  button-cta:
    backgroundColor: "{colors.accent-fill}"
    textColor: "{colors.on-accent}"
    rounded: "{rounded.button}"
    padding: "0 18px"
    height: "42px"
  button-row:
    textColor: "{colors.text}"
    rounded: "{rounded.button}"
    padding: "0 10px"
    height: "30px"
  card:
    backgroundColor: "{colors.card}"
    textColor: "{colors.text}"
    rounded: "{rounded.card}"
    padding: "20px 20px 23px"
  field:
    backgroundColor: "{colors.field}"
    textColor: "{colors.text}"
    rounded: "{rounded.card}"
    padding: "8px 14px"
    height: "40px"
  pill-success:
    backgroundColor: "{colors.success-soft}"
    textColor: "{colors.success}"
    rounded: "{rounded.pill}"
    padding: "1px 6px"
  pill-warning:
    backgroundColor: "{colors.warning-soft}"
    textColor: "{colors.warning}"
    rounded: "{rounded.pill}"
    padding: "1px 6px"
  pill-danger:
    backgroundColor: "{colors.danger-soft}"
    textColor: "{colors.danger}"
    rounded: "{rounded.pill}"
    padding: "1px 6px"
---

# Design System: OpenTransfer

## Overview

**Creative North Star: « Le bordereau numérique »**

Chaque écran se lit comme un bordereau de transmission : qui envoie quoi, combien, jusqu'à quand, et où en est
l'envoi. Les chiffres passent devant le texte, les états se voient par la couleur, rien n'est caché derrière un survol
ou une fenêtre. La structure et les contrôles reprennent ceux de DPM.lol (en-tête avec recherche Ctrl+K, onglets
soulignés, cartes à titre en petites capitales et barre d'accent, filtres segmentés, boutons compacts), rendus plus
sobres et colorés avec la palette du DSFR, sans rien qui évoque l'État : ni Marianne, ni bloc-marque, ni police Marianne.

L'interface est compacte et nette : des contrôles de 30 à 42 px, des coins de 4 px, un texte de 14 px. La densité
sert à tout voir d'un coup d'œil, pas à entasser.

**Key Characteristics:**
- Une seule identité, sombre ou claire selon le réglage du système ; aucune personnalisation des couleurs.
- Le titre de chaque carte en petites capitales d'accent, et une barre d'accent de 3 px à son pied.
- Les chiffres en 800 à chasse fixe, colorés selon l'état qu'ils décrivent.
- Le bleu `#6a6af4` (sombre) ou `#000091` (clair) réservé à l'action principale, à la sélection et aux repères.

## Colors

Des fonds neutres en paliers, un seul accent bleu, trois couleurs d'état sur fonds teintés.

- **Paliers de fond** : page, carte, champ, élevé, actif, du plus profond au plus clair (sombre) ou du gris au blanc (clair).
- **Accent** : bouton principal, onglet actif (soulignement), option choisie des filtres, anneau de focus, barres de progression.
  Le texte d'accent (`accent-text`) sert aux titres de carte et aux liens, car l'accent plein est trop peu contrasté pour du texte.
  Ce qui porte du texte blanc (boutons principaux, bouton de l'en-tête) prend l'accent plein (`accent-fill`, `#6262ee` en sombre) :
  `#6a6af4` ne donne au blanc que 4,2:1, `#6262ee` atteint 4,67:1. L'accent garde les liserés, onglets et barres.
- **États** : succès (envoyé, actif), avertissement (expire bientôt, durée restante), erreur (expiré, mot de passe refusé).
  Chacun a sa couleur, un fond teinté et une ligne ; la couleur ne porte jamais seule l'information, un libellé l'accompagne.
- Les couleurs vivent en jetons `--ot-*` dans `resources/js/style.scss`, redéfinis sous `prefers-color-scheme: dark`.

## Typography

Plus Jakarta Sans, auto-hébergée (aucune requête vers Google Fonts), de 400 à 800. Corps en 14 px, interligne 1,5.
Titres de page en 26 px/800, titre d'un partage reçu jusqu'à 34 px/800, chiffres en 30 px/800 à chasse fixe,
titres de carte en 12 px/700 capitales espacées de 0,06 em, libellés de champ en 13 px/600, méta en 12 px.

## Layout

- En-tête pleine largeur : marque, recherche, « + Nouveau partage », menu du compte ; onglets soulignés en dessous.
- Contenu dans un conteneur de 1200 px (720 px pour la page du destinataire, 420 px pour la connexion), marges de 24 px,
  16 px sur téléphone.
- Les écrans de travail se composent en colonnes souples (`flex-wrap`) : la plus large prend la liste ou les fichiers,
  la plus étroite le formulaire ou le résumé ; elles passent l'une sous l'autre sous ~960 px.
- Rythme : 20 px entre cartes, 16 px dans une carte, 8 px entre lignes d'une liste.
- Chaque section a son adresse (`/my-shares`, `/admin/users`…), jamais un panneau par-dessus la page.

## Elevation & Depth

À plat, par paliers de fond et bordures de 1 px. L'ombre ne sert qu'à ce qui flotte au-dessus de la page :
menus déroulants et fenêtres (`--ot-shadow`).

## Motion

Ce qui arrive décélère (`--ot-ease-out`), ce qui part accélère et part plus vite qu'il n'est venu (`--ot-ease-in`,
`--ot-dur-exit`) ; l'interface répond à la main par un petit ressort qui dépasse un peu (`--ot-ease-spring`) :
appui d'un bouton, coche, chevron, soulignement de l'onglet courant, option d'un filtre. Rien ne bouge à vitesse
constante, pas même ce qui tourne en attendant (`--ot-ease-loop`). Durées : 120 ms pour un retour, 200 ms pour un
changement d'état, 320 ms pour une fenêtre ou une page. Transitions partagées dans `styles/_motion.scss` :
`ot-pop` (menus, listes), `ot-dialog` (fenêtres), `ot-page` (changement de page, de section d'administration,
d'étape de connexion ou d'installation), `ot-list` (lignes qui arrivent l'une après l'autre, partent de côté, les
autres se resserrent en ressort ; `pinLeaving` garde la ligne sortante à sa place), `ot-toast`, `ot-check-pop`
(coche, œil, lien copié). Ce qui s'ouvre sous son bouton monte en place ; barres et jauges se remplissent une fois.
Un seul moment signé : le partage créé, son bordereau tamponné (léger dépassement, une pointe de biais) puis son lien
encré d'un anneau d'accent. `--ot-motion` multiplie tout déplacement ; il vaut 0 quand le système demande moins de
mouvement : les fondus et changements de couleur restent, rien ne se déplace.

## Shapes

Coins de 4 px pour les boutons et filtres, 6 px pour les cartes et champs, 8 px pour la zone de dépôt et les menus,
3 px pour les pastilles, cercle pour les avatars à initiales. Bordures de 1 px partout ; le pointillé est réservé à la
zone de dépôt et aux champs en lecture seule.

## Components

- **Boutons** : principal à l'accent, secondaire sombre sans bordure, fantôme à liseré fin, boutons à icône carrés.
  36 px par défaut, 30 px dans les lignes et barres d'outils (`ot-act`), 42 px en petites capitales pour l'action
  principale d'un formulaire (`cta`). Icône à gauche, 8 px d'écart.
- **Carte** (`ot-card`) : fond de carte, bordure, titre en petites capitales d'accent, barre d'accent de 3 px au pied.
- **Chiffre** (`ot-stat`, `ot-figure`) : titre de carte, nombre en 800, précision en texte atténué à côté.
- **Ligne de liste** : fond de champ ; la ligne d'un fichier porte à gauche un liseré de 3 px selon son envoi
  (en attente, en cours, envoyé), la ligne d'un partage teinte son fond selon son état (actif, expire bientôt, expiré).
- **Filtres segmentés** (`ot-segmented`) : groupe sur fond de champ, option choisie teintée de l'accent.
- **Pastille** (`ot-pill`) : 12 px/700, fond teinté de l'état.
- **Liste de choix** (`OtSelect`) : jamais de `<select>` natif. Bouton à l'allure d'un champ, liste en surplomb de
  la page (rien ne la coupe), option courante cochée ; clavier d'un select natif (flèches, début/fin, pages, lettres
  tapées, Entrée, Échap). Variante compacte (`size="sm"`) pour la langue.
- **Champ numérique** (`OtNumber`) : jamais les flèches du navigateur ; boutons − et + dans le champ, qui
  répètent tant qu'on appuie, hors de l'ordre de tabulation (le clavier garde les flèches). Sans boutons pour ce qui
  se tape (port, difficulté).
- **Questions** : jamais `confirm()` ni `alert()` du navigateur. Une question passe par `useConfirmDialog`
  (carte centrée, focus dedans, Échap annule ; ton `danger` pour l'irréversible, le focus va alors sur « Annuler »),
  une erreur par une notification.
- **Notification** : petite carte en bas à droite, icône sur la teinte de l'état, liseré de 3 px au pied ; pas de
  barre de décompte.
- **Recherche** (Ctrl+K) : carte de fenêtre, résultats groupés (pages, administration, réglages), résultat actif
  teinté de l'accent.
- **Écrans hors application** (connexion et ses étapes, installation, attente) : titre et explication centrés, action
  principale pleine largeur, liens de retour centrés dessous.
- **Champ** : 40 px, bordure de 1 px, survol plus marqué, focus en bordure et anneau d'accent ; erreur sous le champ,
  dans un encadré teinté à liseré gauche.
- **Fenêtre** (`user-form-overlay`) : carte centrée de 520 px au plus, sans flou, sur un voile de la couleur de la page.

## Do's and Don'ts

- Do : montrer des chiffres que l'application connaît vraiment ; un chiffre qui n'existe pas n'est pas estimé.
- Do : garder chaque action visible ; un bouton n'apparaît pas au survol.
- Do : écrire tout nouveau texte dans les sept langues (`resources/js/i18n/*.json`, et `lang/` pour les pages rendues
  par le serveur) ; `TranslationFilesTest` refuse une langue incomplète.
- Do : passer par les jetons `--ot-*` ; les anciens noms de variables ne servent qu'aux écrans pas encore refaits.
- Don't : réintroduire des thèmes ou des couleurs personnalisables ; l'identité est unique.
- Don't : flou, dégradés décoratifs, texte en dégradé, tuiles à icône à la place d'un chiffre.
- Don't : un panneau ou une fenêtre pour ce qui peut être une page ou un champ du formulaire.
- Don't : rien qui évoque l'État (Marianne, bloc-marque, bandeau officiel).
