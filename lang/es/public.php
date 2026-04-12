<?php

return [
    'quote' => [
        'status' => [
            'archived_cannot_update' => 'Las cotizaciones archivadas no se pueden actualizar.',
            'already_accepted' => 'Esta cotizacion ya fue aceptada.',
            'already_declined' => 'Esta cotizacion ya fue rechazada.',
        ],
        'actions' => [
            'archived_cannot_accept' => 'Las cotizaciones archivadas no se pueden aceptar.',
            'archived_cannot_decline' => 'Las cotizaciones archivadas no se pueden rechazar.',
            'already_accepted' => 'La cotizacion ya fue aceptada.',
            'already_declined' => 'La cotizacion ya fue rechazada.',
            'accepted' => 'Cotizacion aceptada.',
            'declined' => 'Cotizacion rechazada.',
            'deposit_below_required' => 'El deposito es inferior al monto requerido.',
        ],
    ],
    'invoice' => [
        'messages' => [
            'cannot_pay' => 'Esta factura no se puede pagar.',
            'already_paid' => 'Esta factura ya fue pagada.',
            'handled_by_company' => 'Las acciones de la factura son gestionadas por la empresa.',
            'amount_exceeds_balance_due' => 'El monto supera el saldo pendiente.',
            'stripe_not_configured' => 'Stripe no esta configurado.',
            'stripe_checkout_unavailable' => 'No se puede iniciar el pago con Stripe.',
        ],
    ],
    'work' => [
        'messages' => [
            'handled_by_company' => 'Las acciones del trabajo son gestionadas por la empresa.',
            'already_validated' => 'El trabajo ya fue validado.',
            'not_ready_for_validation' => 'Este trabajo no esta listo para validacion.',
            'validated' => 'Trabajo validado.',
            'already_marked_dispute' => 'El trabajo ya fue marcado como disputado.',
            'cannot_dispute_now' => 'Este trabajo no se puede disputar en este momento.',
            'marked_dispute' => 'Trabajo marcado como disputado.',
            'cannot_be_scheduled' => 'Este trabajo no se puede programar.',
            'add_team_member' => 'Agrega al menos un miembro del equipo antes de confirmar la programacion.',
            'schedule_already_confirmed' => 'La programacion ya fue confirmada.',
            'schedule_in_progress' => 'Programacion en curso. Las tareas se crearan en breve.',
            'schedule_confirmed' => 'Programacion confirmada, las tareas fueron creadas.',
            'cannot_update_now' => 'Este trabajo no se puede actualizar en este momento.',
            'schedule_confirmed_already' => 'Esta programacion ya fue confirmada.',
            'schedule_sent_back' => 'La programacion fue devuelta para actualizaciones.',
        ],
    ],
];
