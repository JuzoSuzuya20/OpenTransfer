<?php

// The error pages (resources/views/errors)
return [
    'home' => 'Back to home',
    'reload' => 'Reload the page',
    'retry' => 'Try again',
    '404' => [
        'stamp' => 'Return to sender',
        'title' => 'Page not found',
        'text' => 'This address leads to no page. It may have been mistyped, or what it pointed to no longer exists.',
    ],
    '403' => [
        'stamp' => 'Restricted',
        'title' => 'Access denied',
        'text' => 'This page is not open to your account.',
    ],
    '419' => [
        'stamp' => 'Expired',
        'title' => 'The page has expired',
        'text' => 'It stayed open too long. Reload it, then try again.',
    ],
    '500' => [
        'stamp' => 'Damaged in transit',
        'title' => 'Something went wrong',
        'text' => 'The server ran into an error. Try again in a moment; if it keeps happening, tell your administrator.',
    ],
    '503' => [
        'stamp' => 'Back soon',
        'title' => 'Under maintenance',
        'text' => 'OpenTransfer is being updated. It will be back in a few minutes.',
    ],
];
