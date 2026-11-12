<?php

// The error pages (resources/views/errors)
return [
    'home' => 'Volver al inicio',
    'reload' => 'Recargar la página',
    'retry' => 'Reintentar',
    '404' => [
        'stamp' => 'Devuelto al remitente',
        'title' => 'Página no encontrada',
        'text' => 'Esta dirección no lleva a ninguna página. Puede que esté mal copiada, o que lo que indicaba ya no exista.',
    ],
    '403' => [
        'stamp' => 'Acceso reservado',
        'title' => 'Acceso denegado',
        'text' => 'Esta página no está abierta a su cuenta.',
    ],
    '419' => [
        'stamp' => 'Caducada',
        'title' => 'La página ha caducado',
        'text' => 'Ha estado abierta demasiado tiempo. Recárguela y vuelva a intentarlo.',
    ],
    '500' => [
        'stamp' => 'Dañado en el transporte',
        'title' => 'Se ha producido un error',
        'text' => 'El servidor ha encontrado un error. Vuelva a intentarlo en un momento; si sigue ocurriendo, avise a su administrador.',
    ],
    '503' => [
        'stamp' => 'Volvemos pronto',
        'title' => 'En mantenimiento',
        'text' => 'OpenTransfer se está actualizando. Vuelve en unos minutos.',
    ],
];
