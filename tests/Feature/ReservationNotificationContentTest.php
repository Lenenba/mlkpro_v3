<?php

use App\Models\Customer;
use App\Models\Reservation;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\User;
use App\Notifications\ActionEmailNotification;
use App\Notifications\EmailMirrorNotification;
use App\Notifications\ReservationDatabaseNotification;
use App\Services\ReservationNotificationService;
use App\Support\EmailMirrorNotifier;
use App\Support\Notifications\UserNotificationCenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

afterEach(function () {
    app()->setLocale((string) config('app.locale'));
});

it('localizes created reservation notifications for each audience and recipient', function () {
    $ownerRole = Role::query()->firstOrCreate(
        ['name' => 'owner'],
        ['description' => 'Account owner role']
    );
    $employeeRole = Role::query()->firstOrCreate(
        ['name' => 'employee'],
        ['description' => 'Employee role']
    );
    $clientRole = Role::query()->firstOrCreate(
        ['name' => 'client'],
        ['description' => 'Client role']
    );
    $owner = User::factory()->withRole($ownerRole->id)->create([
        'name' => 'Propriétaire Horizon',
        'email' => 'owner.notification-content@example.com',
        'locale' => 'fr',
        'company_name' => 'Studio Horizon',
        'company_timezone' => 'UTC',
    ]);
    $teamUser = User::factory()->withRole($employeeRole->id)->create([
        'name' => 'English Specialist',
        'email' => 'team.notification-content@example.com',
        'locale' => 'en',
    ]);
    $teamMember = TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $teamUser->id,
        'role' => 'member',
    ]);
    $clientUser = User::factory()->withRole($clientRole->id)->create([
        'name' => 'Lucía Cliente',
        'email' => 'client.notification-content@example.com',
        'locale' => 'es',
    ]);
    $customer = Customer::factory()->create([
        'user_id' => $owner->id,
        'portal_user_id' => $clientUser->id,
        'first_name' => 'Lucía',
        'last_name' => 'Cliente',
        'company_name' => 'Atelier Solstice',
        'email' => $clientUser->email,
    ]);
    $reservation = Reservation::factory()->create([
        'account_id' => $owner->id,
        'team_member_id' => $teamMember->id,
        'client_id' => $customer->id,
        'client_user_id' => $clientUser->id,
        'created_by_user_id' => $clientUser->id,
        'source' => Reservation::SOURCE_CLIENT,
        'status' => Reservation::STATUS_PENDING,
        'starts_at' => '2026-09-10 14:00:00',
        'ends_at' => '2026-09-10 15:00:00',
    ]);

    Notification::fake();

    app(ReservationNotificationService::class)->handleCreated($reservation, $clientUser);

    Notification::assertSentToTimes($owner, ActionEmailNotification::class, 1);
    Notification::assertSentToTimes($teamUser, ActionEmailNotification::class, 1);
    Notification::assertSentToTimes($clientUser, ActionEmailNotification::class, 1);
    Notification::assertSentToTimes($owner, ReservationDatabaseNotification::class, 1);
    Notification::assertSentToTimes($teamUser, ReservationDatabaseNotification::class, 1);
    Notification::assertSentToTimes($clientUser, ReservationDatabaseNotification::class, 1);

    $ownerEmail = Notification::sent($owner, ActionEmailNotification::class)->sole();
    $teamEmail = Notification::sent($teamUser, ActionEmailNotification::class)->sole();
    $clientEmail = Notification::sent($clientUser, ActionEmailNotification::class)->sole();
    $ownerDatabase = Notification::sent($owner, ReservationDatabaseNotification::class)->sole();
    $teamDatabase = Notification::sent($teamUser, ReservationDatabaseNotification::class)->sole();
    $clientDatabase = Notification::sent($clientUser, ReservationDatabaseNotification::class)->sole();

    expect($ownerEmail->title)
        ->toBe('Nouvelle demande de réservation')
        ->and($ownerEmail->intro)->toBe('Atelier Solstice a soumis une nouvelle demande de réservation.')
        ->and($ownerEmail->actionLabel)->toBe('Ouvrir la réservation')
        ->and($ownerEmail->actionUrl)->toBe(route('reservation.index'))
        ->and($ownerEmail->shouldMirrorInApp())->toBeFalse();

    expect($teamEmail->title)
        ->toBe('New reservation request')
        ->and($teamEmail->intro)->toBe('Atelier Solstice submitted a new reservation request.')
        ->and($teamEmail->actionLabel)->toBe('Open reservation')
        ->and($teamEmail->actionUrl)->toBe(route('reservation.index'))
        ->and($teamEmail->shouldMirrorInApp())->toBeFalse();

    expect($clientEmail->title)
        ->toBe('Tu solicitud se ha enviado')
        ->and($clientEmail->intro)->toBe('Tu solicitud de reserva se ha enviado correctamente a Studio Horizon.')
        ->and($clientEmail->actionLabel)->toBe('Abrir la reserva')
        ->and($clientEmail->actionUrl)->toBe(route('client.reservations.index'))
        ->and($clientEmail->shouldMirrorInApp())->toBeFalse()
        ->and(mb_strtolower((string) $clientEmail->intro))->not->toContain('un client')
        ->and(mb_strtolower((string) $clientEmail->intro))->not->toContain('a client');

    expect($ownerDatabase->payload)->toMatchArray([
        'title' => 'Nouvelle demande de réservation',
        'message' => 'Atelier Solstice a soumis une nouvelle demande de réservation.',
        'title_key' => 'reservation_notifications.lifecycle.internal.created.client_source.title',
        'message_key' => 'reservation_notifications.lifecycle.internal.created.client_source.message',
        'content_version' => 2,
        'audience' => 'internal',
        'event' => 'created',
        'action_url' => route('reservation.index'),
        'reservation_id' => $reservation->id,
        'parameters' => [
            'actor' => 'Lucía Cliente',
            'client' => 'Atelier Solstice',
            'company' => 'Studio Horizon',
            'hours' => 0,
        ],
    ]);

    expect($teamDatabase->payload)->toMatchArray([
        'title' => 'New reservation request',
        'message' => 'Atelier Solstice submitted a new reservation request.',
        'title_key' => 'reservation_notifications.lifecycle.internal.created.client_source.title',
        'message_key' => 'reservation_notifications.lifecycle.internal.created.client_source.message',
        'content_version' => 2,
        'audience' => 'internal',
        'event' => 'created',
        'action_url' => route('reservation.index'),
        'reservation_id' => $reservation->id,
    ]);

    expect($clientDatabase->payload)->toMatchArray([
        'title' => 'Tu solicitud se ha enviado',
        'message' => 'Tu solicitud de reserva se ha enviado correctamente a Studio Horizon.',
        'title_key' => 'reservation_notifications.lifecycle.client.created.client_source.title',
        'message_key' => 'reservation_notifications.lifecycle.client.created.client_source.message',
        'content_version' => 2,
        'audience' => 'client',
        'event' => 'created',
        'action_url' => route('client.reservations.index'),
        'reservation_id' => $reservation->id,
    ]);
    expect(mb_strtolower((string) $clientDatabase->payload['message']))
        ->not->toContain('un client')
        ->not->toContain('a client');
});

it('presents semantic reservation notifications in the active locale and preserves legacy fallbacks', function () {
    $ownerRole = Role::query()->firstOrCreate(
        ['name' => 'owner'],
        ['description' => 'Account owner role']
    );
    $clientRole = Role::query()->firstOrCreate(
        ['name' => 'client'],
        ['description' => 'Client role']
    );
    $owner = User::factory()->withRole($ownerRole->id)->create([
        'locale' => 'fr',
    ]);
    $client = User::factory()->withRole($clientRole->id)->create([
        'locale' => 'fr',
    ]);
    $semanticParameters = [
        'actor' => 'Lucía Cliente',
        'client' => 'Atelier Solstice',
        'company' => 'Studio Horizon',
        'hours' => 0,
    ];
    $owner->notifyNow(new ReservationDatabaseNotification([
        'title' => 'Nouvelle demande de réservation',
        'message' => 'Atelier Solstice a soumis une nouvelle demande de réservation.',
        'title_key' => 'reservation_notifications.lifecycle.internal.created.client_source.title',
        'message_key' => 'reservation_notifications.lifecycle.internal.created.client_source.message',
        'parameters' => $semanticParameters,
        'content_version' => 2,
        'audience' => 'internal',
        'event' => 'created',
    ]));
    $semanticNotification = $owner->notifications()
        ->where('type', ReservationDatabaseNotification::class)
        ->latest()
        ->firstOrFail();
    $center = app(UserNotificationCenter::class);

    app()->setLocale('fr');
    $frenchContent = $center->present($semanticNotification, $owner);
    app()->setLocale('en');
    $englishContent = $center->present($semanticNotification, $owner);
    $spanishApiContent = $center->present($semanticNotification, $owner, 'es');

    expect($frenchContent['title'])
        ->toBe('Nouvelle demande de réservation')
        ->and($frenchContent['message'])->toBe('Atelier Solstice a soumis une nouvelle demande de réservation.');
    expect($englishContent['title'])
        ->toBe('New reservation request')
        ->and($englishContent['message'])->toBe('Atelier Solstice submitted a new reservation request.');
    expect($spanishApiContent['title'])
        ->toBe('Nueva solicitud de reserva')
        ->and($spanishApiContent['message'])->toBe('Atelier Solstice ha enviado una nueva solicitud de reserva.');

    $owner->notifyNow(new ReservationDatabaseNotification([
        'title' => 'Stored raw title',
        'message' => 'Stored raw message',
        'event' => 'legacy_internal',
    ]));
    $rawNotification = $owner->notifications()
        ->where('type', ReservationDatabaseNotification::class)
        ->whereKeyNot($semanticNotification->id)
        ->firstOrFail();
    $rawContent = $center->present($rawNotification, $owner);

    expect($rawContent['title'])
        ->toBe('Stored raw title')
        ->and($rawContent['message'])->toBe('Stored raw message');

    $client->notifyNow(new ReservationDatabaseNotification([
        'title' => 'Nouvelle demande de réservation',
        'message' => 'Un client a soumis une nouvelle demande de réservation.',
        'event' => 'created',
    ]));
    $legacyClientNotification = $client->notifications()
        ->where('type', ReservationDatabaseNotification::class)
        ->latest()
        ->firstOrFail();

    app()->setLocale('fr');
    $legacyClientContent = $center->present($legacyClientNotification, $client);

    expect($legacyClientContent['title'])
        ->toBe('Mise à jour de votre réservation')
        ->and($legacyClientContent['message'])->toBe('Une mise à jour concernant votre réservation est disponible.')
        ->and(mb_strtolower($legacyClientContent['message']))->not->toContain('un client');

    $client->notifyNow(new ReservationDatabaseNotification([
        'title' => 'File appelée',
        'message' => 'C est votre tour maintenant.',
        'event' => 'queue_called',
    ]));
    $legacyQueueNotification = $client->notifications()
        ->where('type', ReservationDatabaseNotification::class)
        ->where('data->event', 'queue_called')
        ->firstOrFail();
    app()->setLocale('en');
    $legacyQueueContent = $center->present($legacyQueueNotification, $client);

    expect($legacyQueueContent['title'])
        ->toBe('It is your turn')
        ->and($legacyQueueContent['message'])->toBe('It is your turn. Please come to the service point.');

    $client->notifyNow(new EmailMirrorNotification(
        'Nouvelle demande de réservation',
        'Un client a soumis une nouvelle demande de réservation.',
        route('client.reservations.index'),
        data: [
            'source' => 'email',
            'notification' => ActionEmailNotification::class,
            'action_url' => route('client.reservations.index'),
        ]
    ));
    $legacyMirror = $client->notifications()
        ->where('type', EmailMirrorNotification::class)
        ->latest()
        ->firstOrFail();
    app()->setLocale('en');
    $legacyMirrorContent = $center->present($legacyMirror, $client);

    expect($legacyMirrorContent['title'])
        ->toBe('Reservation confirmation')
        ->and($legacyMirrorContent['message'])->toBe('Your reservation confirmation was sent by email.');
});

it('honors the email mirror flag and updates the queued mirror in place', function () {
    $ownerRole = Role::query()->firstOrCreate(
        ['name' => 'owner'],
        ['description' => 'Account owner role']
    );
    $owner = User::factory()->withRole($ownerRole->id)->create([
        'notification_settings' => [
            'channels' => [
                'in_app' => true,
                'push' => false,
            ],
            'categories' => [
                'emails_mirror' => true,
            ],
        ],
    ]);
    $notMirrored = new ActionEmailNotification(
        'Non mirrored email',
        'This email must not create an in-app mirror.',
        actionUrl: route('reservation.index'),
        mirrorInApp: false
    );

    EmailMirrorNotifier::recordQueued($notMirrored, $owner);

    expect($owner->notifications()->where('type', EmailMirrorNotification::class)->count())->toBe(0);

    $mirrored = new ActionEmailNotification(
        'Reservation email',
        'A reservation email is waiting to be sent.',
        actionUrl: route('reservation.index'),
        mirrorInApp: true
    );

    EmailMirrorNotifier::recordQueued($mirrored, $owner);

    $queuedMirror = $owner->notifications()
        ->where('type', EmailMirrorNotification::class)
        ->sole();

    expect(data_get($queuedMirror->data, 'data.email_status'))
        ->toBe('queued')
        ->and(data_get($queuedMirror->data, 'data.notification'))->toBe(ActionEmailNotification::class);

    $result = EmailMirrorNotifier::recordStatus($mirrored, $owner, 'sent');
    $queuedMirror->refresh();

    expect($owner->notifications()->where('type', EmailMirrorNotification::class)->count())->toBe(1)
        ->and(data_get($queuedMirror->data, 'data.email_status'))->toBe('sent')
        ->and(array_key_exists('email_status', $queuedMirror->data))->toBeFalse()
        ->and($result['recipients']->pluck('id')->all())->toBe([$owner->id]);

    $preferenceChanged = new ActionEmailNotification(
        'Second reservation email',
        'This email is waiting while preferences change.',
        actionUrl: route('reservation.index')
    );
    EmailMirrorNotifier::recordQueued($preferenceChanged, $owner);
    $secondMirror = $owner->notifications()
        ->where('type', EmailMirrorNotification::class)
        ->where('data->title', 'Second reservation email')
        ->sole();
    $settings = $owner->notification_settings;
    data_set($settings, 'categories.emails_mirror', false);
    $owner->update(['notification_settings' => $settings]);

    $disabledResult = EmailMirrorNotifier::recordStatus($preferenceChanged, $owner, 'failed');
    $secondMirror->refresh();

    expect(data_get($secondMirror->data, 'data.email_status'))
        ->toBe('failed')
        ->and($disabledResult['recipients'])->toBeEmpty();
});
