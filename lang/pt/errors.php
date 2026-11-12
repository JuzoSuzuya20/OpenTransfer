<?php

// The error pages (resources/views/errors)
return [
    'home' => 'Voltar ao início',
    'reload' => 'Recarregar a página',
    'retry' => 'Tentar novamente',
    '404' => [
        'stamp' => 'Devolvido ao remetente',
        'title' => 'Página não encontrada',
        'text' => 'Este endereço não leva a nenhuma página. Pode ter sido mal copiado, ou o que indicava já não existe.',
    ],
    '403' => [
        'stamp' => 'Acesso reservado',
        'title' => 'Acesso negado',
        'text' => 'Esta página não está aberta à sua conta.',
    ],
    '419' => [
        'stamp' => 'Expirada',
        'title' => 'A página expirou',
        'text' => 'Ficou aberta demasiado tempo. Recarregue-a e tente novamente.',
    ],
    '500' => [
        'stamp' => 'Danificado no transporte',
        'title' => 'Ocorreu um erro',
        'text' => 'O servidor encontrou um erro. Tente novamente dentro de instantes; se continuar, avise o seu administrador.',
    ],
    '503' => [
        'stamp' => 'Voltamos em breve',
        'title' => 'Em manutenção',
        'text' => 'O OpenTransfer está a ser atualizado. Volta dentro de alguns minutos.',
    ],
];
