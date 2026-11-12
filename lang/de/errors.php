<?php

// The error pages (resources/views/errors)
return [
    'home' => 'Zur Startseite',
    'reload' => 'Seite neu laden',
    'retry' => 'Erneut versuchen',
    '404' => [
        'stamp' => 'Zurück an den Absender',
        'title' => 'Seite nicht gefunden',
        'text' => 'Diese Adresse führt zu keiner Seite. Sie wurde vielleicht falsch abgeschrieben, oder das Ziel existiert nicht mehr.',
    ],
    '403' => [
        'stamp' => 'Zugang beschränkt',
        'title' => 'Zugriff verweigert',
        'text' => 'Diese Seite ist für Ihr Konto nicht freigegeben.',
    ],
    '419' => [
        'stamp' => 'Abgelaufen',
        'title' => 'Die Seite ist abgelaufen',
        'text' => 'Sie war zu lange geöffnet. Laden Sie sie neu und versuchen Sie es erneut.',
    ],
    '500' => [
        'stamp' => 'Beim Transport beschädigt',
        'title' => 'Ein Fehler ist aufgetreten',
        'text' => 'Der Server ist auf einen Fehler gestoßen. Versuchen Sie es gleich noch einmal; wenn es wieder passiert, wenden Sie sich an Ihren Administrator.',
    ],
    '503' => [
        'stamp' => 'Bald zurück',
        'title' => 'Wartungsarbeiten',
        'text' => 'OpenTransfer wird gerade aktualisiert und ist in wenigen Minuten zurück.',
    ],
];
