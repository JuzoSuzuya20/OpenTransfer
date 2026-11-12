<?php

// The error pages (resources/views/errors)
return [
    'home' => 'Terug naar het begin',
    'reload' => 'Pagina opnieuw laden',
    'retry' => 'Opnieuw proberen',
    '404' => [
        'stamp' => 'Retour afzender',
        'title' => 'Pagina niet gevonden',
        'text' => 'Dit adres leidt naar geen enkele pagina. Het is misschien verkeerd overgenomen, of wat het aanwees bestaat niet meer.',
    ],
    '403' => [
        'stamp' => 'Beperkte toegang',
        'title' => 'Toegang geweigerd',
        'text' => 'Deze pagina is niet open voor je account.',
    ],
    '419' => [
        'stamp' => 'Verlopen',
        'title' => 'De pagina is verlopen',
        'text' => 'Ze stond te lang open. Laad haar opnieuw en probeer het nog eens.',
    ],
    '500' => [
        'stamp' => 'Beschadigd onderweg',
        'title' => 'Er ging iets mis',
        'text' => 'De server liep tegen een fout aan. Probeer het zo meteen opnieuw; blijft het gebeuren, waarschuw dan je beheerder.',
    ],
    '503' => [
        'stamp' => 'Zo terug',
        'title' => 'Onderhoud',
        'text' => 'OpenTransfer wordt bijgewerkt en is over een paar minuten terug.',
    ],
];
