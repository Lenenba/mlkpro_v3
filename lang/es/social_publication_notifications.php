<?php

return [
    'email' => [
        'publication' => 'Publicación',
        'details' => 'Resultado por plataforma',
        'action' => 'Ver el historial de Pulse',
        'published' => 'Publicados',
        'failed' => 'Fallidos',
        'accounts' => 'Cuentas',
    ],
    'titles' => [
        'success' => 'Pulse: publicación completada',
        'partial' => 'Pulse: publicación parcialmente completada',
        'failed' => 'Pulse: error de publicación',
        'canceled' => 'Pulse: publicación cancelada',
        'attention' => 'Pulse: publicación por verificar',
    ],
    'summary' => ':published/:total cuentas publicadas · :failed con errores · :canceled canceladas · :unknown por verificar.',
    'statuses' => [
        'published' => 'Publicado',
        'failed' => 'Error',
        'canceled' => 'Cancelado',
        'unknown' => 'Resultado sin confirmar',
        'reconnect_required' => 'Es necesario reconectar la cuenta',
        'scheduled' => 'Programado, aún no publicado',
        'submitted' => 'Enviado, pendiente de confirmación',
        'queued' => 'En espera',
        'sending' => 'En curso',
        'publishing' => 'En curso',
        'pending' => 'Pendiente',
        'not_submitted' => 'Sin enviar',
        'remote_approval_required' => 'Se requiere aprobación de la plataforma',
    ],
    'next_steps' => [
        'partial' => 'Consulte el historial de Pulse para corregir los errores y reintentar las cuentas con errores.',
        'failed' => 'Consulte los errores en el historial de Pulse antes de reintentar.',
        'canceled' => 'Ninguna cuenta publicó este contenido. Consulte los detalles en el historial de Pulse.',
        'attention' => 'Verifique el estado en el historial de Pulse y reconecte la cuenta si es necesario. No vuelva a publicar mientras el resultado sea incierto.',
    ],
];
