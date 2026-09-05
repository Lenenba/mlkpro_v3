<?php

return [
    'lifecycle' => [
        'internal' => [
            'created' => [
                'client_source' => [
                    'title' => 'Nueva solicitud de reserva',
                    'message' => ':client ha enviado una nueva solicitud de reserva.',
                ],
                'staff_source' => [
                    'title' => 'Reserva creada',
                    'message' => ':actor ha creado una reserva para :client.',
                ],
            ],
            'rescheduled' => [
                'title' => 'Reserva reprogramada',
                'message' => ':actor ha reprogramado la reserva de :client.',
            ],
            'cancelled' => [
                'title' => 'Reserva cancelada',
                'message' => ':actor ha cancelado la reserva de :client.',
            ],
            'completed' => [
                'title' => 'Reserva completada',
                'message' => 'La reserva de :client se ha marcado como completada.',
            ],
            'reminder' => [
                'title' => 'Próxima reserva',
                'message' => 'La reserva de :client comienza dentro de :hours hora(s).',
            ],
            'review_submitted' => [
                'title' => 'Nueva reseña recibida',
                'message' => ':client ha enviado una reseña sobre una reserva completada.',
            ],
        ],
        'client' => [
            'created' => [
                'client_source' => [
                    'title' => 'Tu solicitud se ha enviado',
                    'message' => 'Tu solicitud de reserva se ha enviado correctamente a :company.',
                ],
                'staff_source' => [
                    'title' => 'Tu reserva se ha creado',
                    'message' => ':company ha creado una reserva para ti.',
                ],
            ],
            'rescheduled' => [
                'self' => [
                    'title' => 'Tu reserva se ha reprogramado',
                    'message' => 'Has reprogramado correctamente tu reserva.',
                ],
                'staff' => [
                    'title' => 'Tu reserva se ha reprogramado',
                    'message' => ':company ha cambiado la hora de tu reserva.',
                ],
            ],
            'cancelled' => [
                'self' => [
                    'title' => 'Tu reserva se ha cancelado',
                    'message' => 'Has cancelado correctamente tu reserva.',
                ],
                'staff' => [
                    'title' => 'Tu reserva se ha cancelado',
                    'message' => ':company ha cancelado tu reserva.',
                ],
            ],
            'completed' => [
                'title' => 'Tu reserva ha finalizado',
                'message' => 'Tu servicio ha finalizado. Gracias por elegir :company.',
            ],
            'reminder' => [
                'title' => 'Recordatorio de tu reserva',
                'message' => 'Tu reserva comienza dentro de :hours hora(s).',
            ],
            'review_request' => [
                'title' => '¿Cómo fue tu servicio?',
                'message' => 'Tu reserva ha finalizado. Comparte tu valoración y tus comentarios.',
            ],
        ],
    ],
    'queue' => [
        'client' => [
            'queue_ticket_created' => [
                'title' => 'Tu turno está confirmado',
                'message' => 'Tu lugar en la fila de espera está confirmado.',
            ],
            'queue_eta_10m' => [
                'title' => 'Tu turno se acerca',
                'message' => 'Tu turno está previsto dentro de aproximadamente :minutes minutos.',
            ],
            'queue_pre_call' => [
                'title' => 'Prepárate',
                'message' => 'Estás a punto de ser la siguiente persona. Prepárate, por favor.',
            ],
            'queue_called' => [
                'title' => 'Es tu turno',
                'message' => 'Es tu turno. Dirígete al punto de servicio, por favor.',
            ],
            'queue_grace_expired' => [
                'title' => 'Tu plazo de atención ha vencido',
                'message' => 'El plazo para presentarte ha vencido y tu turno se ha marcado como perdido.',
            ],
            'queue_status_changed' => [
                'title' => 'Tu estado se ha actualizado',
                'message' => 'Tu estado en la fila ha cambiado de «:from» a «:to».',
            ],
        ],
        'internal' => [
            'queue_called' => [
                'title' => 'Cliente llamado',
                'message' => 'Se ha llamado a :client al punto de servicio.',
            ],
            'queue_grace_expired' => [
                'title' => 'Plazo de atención vencido',
                'message' => 'El plazo de atención de :client ha vencido y su turno se ha marcado como perdido.',
            ],
        ],
    ],
    'details' => [
        'source' => 'Origen',
        'reason' => 'Motivo',
        'rating' => 'Valoración',
        'feedback' => 'Comentario',
        'no_feedback' => 'No se proporcionaron comentarios',
        'service' => 'Servicio',
        'when' => 'Fecha y hora',
        'team_member' => 'Miembro del equipo',
        'client' => 'Cliente',
        'status' => 'Estado',
        'queue' => 'Fila de espera',
        'type' => 'Tipo',
        'position' => 'Posición',
        'from_status' => 'Estado anterior',
        'to_status' => 'Nuevo estado',
        'call_expires_at' => 'Fin del plazo de atención',
    ],
    'actions' => [
        'open_reservation' => 'Abrir la reserva',
        'open_reservations' => 'Abrir las reservas',
    ],
    'fallback' => [
        'user' => 'Un usuario',
        'team_member' => 'Miembro del equipo',
        'reservation' => 'Reserva',
        'client' => 'Cliente',
        'company' => 'Tu proveedor de servicios',
    ],
    'legacy' => [
        'client_reservation' => [
            'title' => 'Tu reserva se ha actualizado',
            'message' => 'Hay una actualización disponible sobre tu reserva.',
        ],
        'email_confirmation' => [
            'title' => 'Confirmación de reserva',
            'message' => 'La confirmación de tu reserva se ha enviado por correo electrónico.',
        ],
    ],
];
