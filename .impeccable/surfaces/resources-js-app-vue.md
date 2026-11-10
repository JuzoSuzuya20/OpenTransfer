---
version: 1
slug: "resources-js-app-vue"
primary_target: "resources/js/App.vue"
related_targets: ["resources/js/components/uploader.vue","resources/js/components/downloader.vue","resources/js/components/auth.vue"]
---

# Application OpenTransfer (toutes les pages)

Mode : Operate. Public : salariés qui envoient, destinataires extérieurs, déposants invités, admins.
Tâches : envoyer un partage, le suivre, télécharger un partage reçu, déposer sur invitation, administrer.
Contraintes : logique d'authentification et URL de callback SSO inchangées ; aucune ressource externe ;
six langues ; sombre et clair selon le système ; données d'une instance Erugo conservées.

## Direction contract

THESIS: Un outil d'envoi qui se lit comme un tableau de bord de suivi : chaque carte porte son titre en petites
capitales violettes et une barre d'accent de 3 px en pied ; il refuse la colonne unique centrée de 500 px
et les réglages cachés derrière une fenêtre.

OWN-WORLD: Fonds en paliers neutres (sombre : page #161616, carte #1e1e1e, champ #242424, secondaire #2a2a2a,
actif #353535 ; clair : page #f6f6f6, carte #ffffff, champ #eeeeee). Accent #6a6af4, texte d'accent #8585f6
(clair : #000091). Statuts succès #27a658, avertissement #fc5d00, erreur #ff5655 sur fonds teintés.
Plus Jakarta Sans 400–800, chiffres tabulaires en 800. Rayons 6 / 8 / 4 px, bordures 1 px.

STORY: L'expéditeur voit d'abord ce qui est en ligne, dépose ses fichiers, règle l'accès à côté et crée le partage ;
il retrouve ensuite ses partages groupés par semaine avec leur statut et leurs téléchargements.
Le destinataire voit qui lui envoie quoi, combien et jusqu'à quand, puis télécharge.

FIRST VIEWPORT: En-tête pleine largeur (marque, recherche Ctrl+K, « + Nouveau partage », menu du compte),
onglets soulignés en dessous ; conteneur de 1200 px ; trois cartes de chiffres ; puis deux colonnes :
« Fichiers à envoyer » (zone de dépôt avec Parcourir / Choisir un dossier, lignes de fichiers à progression)
et « Accès » (nom, message, expiration, téléchargements, mot de passe, destinataires, « Créer le partage »).

FORM: Coque d'application en barre supérieure avec onglets, pages à adresse propre ; forme imposée par la
maquette validée par l'utilisateur, première et seule forme retenue. seed: none (direction pinned by the approved mockup)

SIGNATURE: La barre d'accent de 3 px au pied de chaque carte, avec le titre de carte en petites capitales d'accent ;
les chiffres en 800 tabulaires qui changent de couleur selon le statut.
