# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

- **Salariés qui envoient** : depuis leur poste, ils déposent des fichiers (plans, pièces de marché, photos, dossiers entiers)
  et envoient un lien à des clients ou partenaires extérieurs, puis suivent si le partage a été téléchargé.
- **Extérieurs qui reçoivent** : sans compte, ils ouvrent un lien reçu par e-mail, parfois saisissent un mot de passe,
  et téléchargent. Ils ne connaissent pas l'outil et ne le reverront peut-être jamais.
- **Extérieurs qui déposent** : invités par une demande de fichiers, ils déposent des fichiers sans créer de compte.
- **Admins qui configurent** : une ou deux personnes règlent le SSO, les utilisateurs, la sécurité, les modèles d'e-mails
  et l'apparence (nom, logo).

## Product Purpose

Partage de fichiers auto-hébergé : envoyer des fichiers volumineux à l'extérieur de l'organisation, avec expiration,
limite de téléchargements, mot de passe et suivi, sans confier les fichiers à un service tiers.
Le succès : un envoi fait en moins d'une minute, un destinataire qui télécharge sans aide, un admin qui règle le SSO
une fois et n'y revient pas.

## Positioning

Fork maintenu d'Erugo (abandonné en amont), open source. Ce qui le distingue : un SSO finement réglable
(plusieurs fournisseurs, liaison par identifiant, confiance dans l'e-mail, inscription autorisée ou non),
la sécurité ajoutée par le fork (2FA, passkeys, liste blanche d'IP, chiffrement au repos, anti-robots)
et l'auto-hébergement sur une simple image Docker.

## Operating Context

- Utilisé en production par une entreprise ; d'autres instances peuvent exister (projet public).
- Envoi depuis un navigateur de bureau, surtout ; réception souvent sur mobile, depuis un lien d'e-mail.
- Uploads par morceaux (tusd), reprise d'un envoi interrompu, dossiers avec leur arborescence.
- Langues : allemand, anglais, français, italien, néerlandais, portugais.

## Capabilities and Constraints

- Fonctions existantes à garder : envoi de fichiers et dossiers, destinataires par e-mail, message, expiration,
  limite de téléchargements, mot de passe, demandes de fichiers (invitations), comptes invités, partages de
  l'utilisateur et de tous (admin), statistiques système, réglages (système, sécurité, SSO, e-mails, utilisateurs,
  sauvegardes), profil (2FA, passkeys, comptes liés), palette de commandes (Ctrl+K).
- L'URL de callback SSO `/auth/provider/{uuid}/callback` et la logique d'authentification ne changent pas.
- Une instance Erugo existante doit continuer de fonctionner sans perte de partages, d'utilisateurs ni de réglages.
- Aucune requête de visiteur vers un CDN ou Google Fonts : tout est servi par l'application.
- Décidé le 2026-11-10 : une seule identité visuelle (Piste B) en sombre et en clair, qui suit le réglage du système ;
  les anciens thèmes ne sont plus proposés. Les sections des réglages deviennent de vraies pages avec une adresse.
- Non décidé : le nom et le logo restent personnalisables par l'admin (réglages existants) ; une couleur d'accent
  personnalisable n'a pas été tranchée. Masquer la liste des fichiers d'un partage protégé (point O-03) demande un
  changement côté serveur, pas encore validé.

## Brand Commitments

- Nom : OpenTransfer. Marque : carré arrondi violet `#6a6af4` portant une flèche montante blanche.
- Maquette validée « Piste B » : structure inspirée de DPM.lol, couleurs du DSFR en mode sombre, plus sobre ;
  rien ne doit évoquer une administration ou l'État (ni Marianne, ni bandeau officiel).

## Evidence on Hand

- Maquettes : https://claude.ai/artifact/CRB2RpUixXhfuBWnLf1oq5 (pages « Piste B », écrans Envoyer, Connexion,
  Télécharger, Mes partages).
- Aucune donnée d'usage, aucun témoignage : rien à inventer à ce sujet.

## Product Principles

- Le destinataire passe avant tout : la page de téléchargement se comprend sans rien connaître de l'outil.
- Tout se voit sans chercher : pas d'action cachée au survol, pas de réglage d'envoi derrière une fenêtre.
- La sécurité est visible mais ne bloque pas : mot de passe, expiration et limite se lisent d'un coup d'œil.
- Les chiffres affichés sont vrais : on montre ce que l'application sait, jamais une estimation déguisée.

## Accessibility & Inclusion

Contraste AA, navigation complète au clavier avec focus visible, mouvements réduits respectés,
lisible en largeur de téléphone (destinataires sur mobile).
