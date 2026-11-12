<?php

// The error pages (resources/views/errors)
return [
    'home' => 'Retour à l\'accueil',
    'reload' => 'Recharger la page',
    'retry' => 'Réessayer',
    '404' => [
        'stamp' => 'Retour à l\'expéditeur',
        'title' => 'Page introuvable',
        'text' => 'Cette adresse ne mène à aucune page. Elle a peut-être été mal recopiée, ou ce qu\'elle désignait n\'existe plus.',
    ],
    '403' => [
        'stamp' => 'Accès réservé',
        'title' => 'Accès refusé',
        'text' => 'Cette page n\'est pas ouverte à votre compte.',
    ],
    '419' => [
        'stamp' => 'Expiré',
        'title' => 'La page a expiré',
        'text' => 'Elle est restée ouverte trop longtemps. Rechargez-la, puis réessayez.',
    ],
    '500' => [
        'stamp' => 'Avarié en transit',
        'title' => 'Une erreur est survenue',
        'text' => 'Le serveur a rencontré une erreur. Réessayez dans un instant ; si cela continue, prévenez votre administrateur.',
    ],
    '503' => [
        'stamp' => 'De retour bientôt',
        'title' => 'Maintenance en cours',
        'text' => 'OpenTransfer est en cours de mise à jour. Il revient dans quelques minutes.',
    ],
];
