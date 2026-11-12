<?php

// The error pages (resources/views/errors)
return [
    'home' => 'Torna alla home',
    'reload' => 'Ricarica la pagina',
    'retry' => 'Riprova',
    '404' => [
        'stamp' => 'Ritorno al mittente',
        'title' => 'Pagina non trovata',
        'text' => 'Questo indirizzo non porta a nessuna pagina. Forse è stato copiato male, oppure ciò a cui puntava non esiste più.',
    ],
    '403' => [
        'stamp' => 'Accesso riservato',
        'title' => 'Accesso negato',
        'text' => 'Questa pagina non è aperta al tuo account.',
    ],
    '419' => [
        'stamp' => 'Scaduta',
        'title' => 'La pagina è scaduta',
        'text' => 'È rimasta aperta troppo a lungo. Ricaricala, poi riprova.',
    ],
    '500' => [
        'stamp' => 'Danneggiato in transito',
        'title' => 'Si è verificato un errore',
        'text' => 'Il server ha incontrato un errore. Riprova tra un attimo; se continua, avvisa il tuo amministratore.',
    ],
    '503' => [
        'stamp' => 'Torniamo presto',
        'title' => 'Manutenzione in corso',
        'text' => 'OpenTransfer è in aggiornamento. Torna tra pochi minuti.',
    ],
];
