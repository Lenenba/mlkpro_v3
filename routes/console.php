<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;
use Database\Seeders\LaunchSeeder;
use App\Models\Role;
use App\Models\User;
use App\Models\Work;
use App\Models\Sale;
use App\Models\ActivityLog;
use App\Models\PlatformSupportTicket;
use App\Models\Request as LeadRequest;
use App\Notifications\ActionEmailNotification;
use App\Notifications\LeadFollowUpNotification;
use App\Support\NotificationDispatcher;
use App\Services\Demo\DemoAccountService;
use App\Services\Demo\DemoResetService;
use App\Services\Demo\DemoSeedService;
use App\Services\DailyAgendaService;
use App\Services\PlatformAdminNotifier;
use App\Services\SmsNotificationService;
use App\Services\WorkBillingService;
use App\Services\SaleNotificationService;
use App\Services\ReservationNotificationService;
use App\Services\SupportAssignmentService;
use App\Services\SupportSettingsService;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Artisan::command('workflow:auto-validate', function (WorkBillingService $billingService) {
    $cutoff = now()->subHours(48);
    $works = Work::query()
        ->where('status', Work::STATUS_PENDING_REVIEW)
        ->where('updated_at', '<=', $cutoff)
        ->get();

    $count = 0;
    foreach ($works as $work) {
        $work->status = Work::STATUS_AUTO_VALIDATED;
        $work->save();
        $billingService->createInvoiceFromWork($work);
        $count += 1;
    }

    $this->info("Auto-validated {$count} jobs.");
})->purpose('Auto validate jobs after 48h')->hourly();

Artisan::command('superadmin:create {email} {password}', function () {
    $email = (string) $this->argument('email');
    $password = (string) $this->argument('password');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->error('Invalid email address.');
        return;
    }

    if (strlen($password) < 8) {
        $this->error('Password must be at least 8 characters.');
        return;
    }

    $role = Role::firstOrCreate(
        ['name' => 'superadmin'],
        ['description' => 'Superadmin role']
    );

    $user = User::where('email', $email)->first();
    if ($user) {
        if (!$this->confirm('User already exists. Update role and password?', false)) {
            $this->info('No changes made.');
            return;
        }

        $user->update([
            'name' => $user->name ?: 'Super Admin',
            'password' => Hash::make($password),
            'role_id' => $role->id,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ]);

        $this->info('Superadmin user updated.');
        return;
    }

    User::create([
        'name' => 'Super Admin',
        'email' => $email,
        'password' => Hash::make($password),
        'role_id' => $role->id,
        'email_verified_at' => now(),
    ]);

    $this->info('Superadmin user created.');
})->purpose('Create or update a superadmin user');


Artisan::command('mail:test {to} {--subject=Test} {--body=}', function (): int {
    /** @var string $to */
    $to = (string) $this->argument('to');
    $subject = (string) $this->option('subject');
    $body = (string) $this->option('body');

    if (trim($to) === '') {
        $this->error('Destinataire manquant.');
        return 1;
    }

    if (trim($body) === '') {
        $body = implode("\n", [
            'Test email',
            'App: ' . (string) config('app.name'),
            'Date: ' . now()->toDateTimeString(),
        ]);
    }

    Mail::raw($body, fn($m) => $m->to($to)->subject($subject));

    $this->info("OK: email envoye a {$to}");

    return 0;
})->purpose('Send a test email using current mailer');

Artisan::command('sms:test {to} {--message=}', function (SmsNotificationService $smsService): int {
    $to = trim((string) $this->argument('to'));
    $message = trim((string) $this->option('message'));
    $sid = trim((string) config('services.twilio.sid'));
    $token = trim((string) config('services.twilio.token'));
    $from = trim((string) config('services.twilio.from'));

    if ($to === '') {
        $this->error('Numero destinataire manquant.');
        return 1;
    }

    if (!preg_match('/^\+[1-9]\d{7,14}$/', $to)) {
        $this->error('Numero invalide. Utilisez le format E.164, ex: +15145551234');
        return 1;
    }

    if ($sid === '' || $token === '' || $from === '') {
        $this->error('Configuration Twilio incomplete. Definissez TWILIO_SID, TWILIO_AUTH_TOKEN, TWILIO_FROM.');
        return 1;
    }

    if ($message === '') {
        $message = implode(' | ', [
            'Test SMS',
            (string) config('app.name'),
            now()->toDateTimeString(),
        ]);
    }

    $this->line("Envoi SMS vers {$to} depuis {$from}...");
    $result = $smsService->sendWithResult($to, $message);
    $sent = (bool) ($result['ok'] ?? false);

    if (!$sent) {
        $reason = (string) ($result['reason'] ?? 'unknown');
        $status = (string) ($result['status'] ?? '-');
        $code = (string) ($result['code'] ?? '-');
        $errorMessage = trim((string) ($result['message'] ?? $result['error'] ?? 'Unknown error'));
        $moreInfo = trim((string) ($result['more_info'] ?? ''));

        $this->error('Echec envoi SMS.');
        $this->line("reason={$reason} status={$status} code={$code}");
        if ($errorMessage !== '') {
            $this->line("message={$errorMessage}");
        }
        if ($moreInfo !== '') {
            $this->line("more_info={$moreInfo}");
        }

        if ($reason === 'twilio_error' && $code === '21608') {
            $this->line('Hint: compte Twilio trial -> ajoutez/verifiez ce numero destinataire dans Twilio Verified Caller IDs.');
        }
        if ($reason === 'twilio_error' && $code === '21606') {
            $this->line('Hint: TWILIO_FROM doit etre un numero Twilio actif SMS.');
        }
        if ($reason === 'twilio_error' && $code === '21211') {
            $this->line('Hint: numero destinataire invalide. Utilisez le format E.164 (+1...).');
        }

        return 1;
    }

    $this->info("OK: SMS envoye a {$to}");
    return 0;
})->purpose('Send a test SMS using Twilio credentials');

Artisan::command('mailgun:test {to}
    {--from= : Override from address}
    {--from-name= : Override from name}
    {--subject=Test Mailgun}
    {--text= : Plain text body}
    {--html= : HTML body}
    {--domain= : Mailgun domain}
    {--endpoint= : Mailgun API endpoint}
    {--secret= : Mailgun API key}', function (): int {
    $to = trim((string) $this->argument('to'));
    if ($to === '') {
        $this->error('Destinataire manquant.');
        return 1;
    }

    $domain = trim((string) ($this->option('domain') ?: env('MAILGUN_DOMAIN')));
    $secret = trim((string) ($this->option('secret') ?: env('MAILGUN_SECRET')));
    $endpoint = trim((string) ($this->option('endpoint') ?: env('MAILGUN_ENDPOINT', 'api.mailgun.net')));
    $endpoint = preg_replace('#^https?://#', '', $endpoint);
    $endpoint = rtrim($endpoint, '/');

    if ($domain === '' || $secret === '') {
        $this->error('MAILGUN_DOMAIN et MAILGUN_SECRET sont requis.');
        return 1;
    }

    $fromAddress = trim((string) ($this->option('from') ?: config('mail.from.address', '')));
    $fromName = trim((string) ($this->option('from-name') ?: config('mail.from.name', '')));
    if ($fromAddress === '') {
        $fromAddress = 'postmaster@' . $domain;
    }
    $from = $fromName !== '' ? "{$fromName} <{$fromAddress}>" : $fromAddress;

    $subject = (string) $this->option('subject');
    $text = trim((string) $this->option('text'));
    $html = trim((string) $this->option('html'));

    if ($text === '' && $html === '') {
        $text = implode("\n", [
            'Mailgun test email',
            'App: ' . (string) config('app.name'),
            'Date: ' . now()->toDateTimeString(),
        ]);
    }

    $payload = [
        'from' => $from,
        'to' => $to,
        'subject' => $subject,
    ];
    if ($text !== '') {
        $payload['text'] = $text;
    }
    if ($html !== '') {
        $payload['html'] = $html;
    }

    $response = Http::withBasicAuth('api', $secret)
        ->asForm()
        ->post("https://{$endpoint}/v3/{$domain}/messages", $payload);

    if ($response->successful()) {
        $this->info('OK: email envoye via Mailgun.');
        return 0;
    }

    $this->error('Echec Mailgun (' . $response->status() . ')');
    $this->line($response->body());

    return 1;
})->purpose('Send a test email using Mailgun API');

Artisan::command('platform:notifications-digest {--frequency=daily}', function (PlatformAdminNotifier $notifier): int {
    $frequency = (string) $this->option('frequency');
    $count = $notifier->sendDigest($frequency);

    $this->info("Sent {$count} {$frequency} notifications.");

    return 0;
})->purpose('Send admin notification digests');

Artisan::command('platform:notifications-scan', function (PlatformAdminNotifier $notifier): int {
    $count = $notifier->scanTrialEnding();

    $this->info("Logged {$count} churn risk notifications.");

    return 0;
})->purpose('Scan for churn risk and log notifications');

Artisan::command('agenda:process', function (DailyAgendaService $service): int {
    $result = $service->process();
    $this->info('Agenda processed: ' . json_encode($result));
    return 0;
})->purpose('Auto-start tasks/jobs and send alerts');

Artisan::command('orders:deposit-reminders', function (SaleNotificationService $notifier): int {
    $cutoff = now()->subHours(24);

    $sales = Sale::query()
        ->where('source', 'portal')
        ->where('status', '!=', Sale::STATUS_CANCELED)
        ->where('deposit_amount', '>', 0)
        ->with(['customer.portalUser'])
        ->withSum(['payments as payments_sum_amount' => fn($query) => $query->where('status', 'completed')], 'amount')
        ->get();

    $count = 0;
    foreach ($sales as $sale) {
        $depositAmount = (float) ($sale->deposit_amount ?? 0);
        $amountPaid = (float) $sale->amount_paid;
        $depositDue = max(0, round($depositAmount - $amountPaid, 2));
        if ($depositDue <= 0) {
            continue;
        }

        $lastReminder = ActivityLog::query()
            ->where('subject_type', $sale->getMorphClass())
            ->where('subject_id', $sale->id)
            ->where('action', 'sale_deposit_reminder_sent')
            ->latest('created_at')
            ->first();

        if ($lastReminder && $lastReminder->created_at && $lastReminder->created_at->greaterThan($cutoff)) {
            continue;
        }

        $notifier->notifyDepositReminder($sale, $depositDue);
        ActivityLog::record(null, $sale, 'sale_deposit_reminder_sent', [
            'deposit_amount' => $depositDue,
        ]);
        $count += 1;
    }

    $this->info("Sent {$count} deposit reminders.");

    return 0;
})->purpose('Send deposit reminders for unpaid portal orders');

Artisan::command('leads:follow-up-reminders {--hours=24}', function (): int {
    $hours = (int) $this->option('hours');
    if ($hours <= 0) {
        $hours = 24;
    }

    $cutoff = now()->subHours($hours);
    $openStatuses = [
        LeadRequest::STATUS_NEW,
        LeadRequest::STATUS_CONTACTED,
        LeadRequest::STATUS_QUALIFIED,
        LeadRequest::STATUS_QUOTE_SENT,
    ];

    $unassignedLeads = LeadRequest::query()
        ->whereIn('status', $openStatuses)
        ->whereNull('assigned_team_member_id')
        ->where('created_at', '<=', $cutoff)
        ->with(['assignee.user'])
        ->get();

    $overdueLeads = LeadRequest::query()
        ->whereIn('status', $openStatuses)
        ->whereNotNull('next_follow_up_at')
        ->where('next_follow_up_at', '<=', $cutoff)
        ->whereNotNull('assigned_team_member_id')
        ->with(['assignee.user'])
        ->get();

    $sent = 0;

    foreach ($unassignedLeads as $lead) {
        $lastReminder = ActivityLog::query()
            ->where('subject_type', $lead->getMorphClass())
            ->where('subject_id', $lead->id)
            ->where('action', 'lead_unassigned_reminder_sent')
            ->latest('created_at')
            ->first();

        if ($lastReminder && $lastReminder->created_at && $lastReminder->created_at->greaterThan($cutoff)) {
            continue;
        }

        $recipients = collect([
            User::query()->find($lead->user_id),
            $lead->assignee?->user,
        ])->filter()->unique('id');

        foreach ($recipients as $recipient) {
            NotificationDispatcher::send($recipient, new LeadFollowUpNotification($lead, 'unassigned', $hours), [
                'lead_id' => $lead->id,
            ]);
        }

        ActivityLog::record(null, $lead, 'lead_unassigned_reminder_sent', [
            'hours' => $hours,
        ]);

        $sent += 1;
    }

    foreach ($overdueLeads as $lead) {
        $lastReminder = ActivityLog::query()
            ->where('subject_type', $lead->getMorphClass())
            ->where('subject_id', $lead->id)
            ->where('action', 'lead_follow_up_reminder_sent')
            ->latest('created_at')
            ->first();

        if ($lastReminder && $lastReminder->created_at && $lastReminder->created_at->greaterThan($cutoff)) {
            continue;
        }

        $recipients = collect([
            User::query()->find($lead->user_id),
            $lead->assignee?->user,
        ])->filter()->unique('id');

        foreach ($recipients as $recipient) {
            NotificationDispatcher::send($recipient, new LeadFollowUpNotification($lead, 'follow_up_overdue', $hours), [
                'lead_id' => $lead->id,
            ]);
        }

        ActivityLog::record(null, $lead, 'lead_follow_up_reminder_sent', [
            'hours' => $hours,
            'next_follow_up_at' => optional($lead->next_follow_up_at)->toDateTimeString(),
        ]);

        $sent += 1;
    }

    $this->info("Sent {$sent} lead reminders.");

    return 0;
})->purpose('Send reminders for unassigned or overdue lead follow-ups');

Artisan::command('support:sla-reminders', function (
    SupportSettingsService $settingsService,
    SupportAssignmentService $assignmentService
): int {
    $reminders = $settingsService->reminderConfig();
    $dueSoonHours = (int) ($reminders['due_soon_hours'] ?? 2);
    $cooldownHours = (int) ($reminders['cooldown_hours'] ?? 6);
    $unassignedHours = (int) ($reminders['unassigned_hours'] ?? 24);

    $now = now();
    $cooldownCutoff = $now->copy()->subHours(max(1, $cooldownHours));
    $openStatuses = ['open', 'assigned', 'pending'];

    $dueSoonTickets = PlatformSupportTicket::query()
        ->whereIn('status', $openStatuses)
        ->whereNotNull('sla_due_at')
        ->whereBetween('sla_due_at', [$now, $now->copy()->addHours(max(1, $dueSoonHours))])
        ->with(['assignedTo', 'account'])
        ->get();

    $overdueTickets = PlatformSupportTicket::query()
        ->whereIn('status', $openStatuses)
        ->whereNotNull('sla_due_at')
        ->where('sla_due_at', '<=', $now)
        ->with(['assignedTo', 'account'])
        ->get();

    $unassignedTickets = PlatformSupportTicket::query()
        ->where('status', 'open')
        ->whereNull('assigned_to_user_id')
        ->where('created_at', '<=', $now->copy()->subHours(max(1, $unassignedHours)))
        ->with('account')
        ->get();

    $agents = $assignmentService->agents();
    $sent = 0;

    foreach ($dueSoonTickets as $ticket) {
        $lastReminder = ActivityLog::query()
            ->where('subject_type', $ticket->getMorphClass())
            ->where('subject_id', $ticket->id)
            ->where('action', 'support_ticket.sla_due_soon_sent')
            ->latest('created_at')
            ->first();

        if ($lastReminder && $lastReminder->created_at && $lastReminder->created_at->greaterThan($cooldownCutoff)) {
            continue;
        }

        $recipients = collect([$ticket->assignedTo])->filter()->unique('id');
        if ($recipients->isEmpty()) {
            $recipients = $agents;
        }

        foreach ($recipients as $recipient) {
            NotificationDispatcher::send($recipient, new ActionEmailNotification(
                'Support SLA due soon',
                "Ticket #{$ticket->id} is due soon.",
                [
                    ['label' => 'Ticket', 'value' => "#{$ticket->id} - {$ticket->title}"],
                    ['label' => 'Company', 'value' => $ticket->account?->company_name ?? $ticket->account?->email],
                    ['label' => 'SLA due', 'value' => optional($ticket->sla_due_at)->toDateTimeString()],
                ],
                route('superadmin.support.show', $ticket->id),
                'View support request'
            ), [
                'ticket_id' => $ticket->id,
            ]);
            $sent += 1;
        }

        ActivityLog::record(null, $ticket, 'support_ticket.sla_due_soon_sent', [
            'sla_due_at' => optional($ticket->sla_due_at)->toDateTimeString(),
        ]);
    }

    foreach ($overdueTickets as $ticket) {
        $lastReminder = ActivityLog::query()
            ->where('subject_type', $ticket->getMorphClass())
            ->where('subject_id', $ticket->id)
            ->where('action', 'support_ticket.sla_overdue_sent')
            ->latest('created_at')
            ->first();

        if ($lastReminder && $lastReminder->created_at && $lastReminder->created_at->greaterThan($cooldownCutoff)) {
            continue;
        }

        $recipients = collect([$ticket->assignedTo])->filter()->unique('id');
        if ($recipients->isEmpty()) {
            $recipients = $agents;
        }

        foreach ($recipients as $recipient) {
            NotificationDispatcher::send($recipient, new ActionEmailNotification(
                'Support SLA overdue',
                "Ticket #{$ticket->id} is overdue.",
                [
                    ['label' => 'Ticket', 'value' => "#{$ticket->id} - {$ticket->title}"],
                    ['label' => 'Company', 'value' => $ticket->account?->company_name ?? $ticket->account?->email],
                    ['label' => 'SLA due', 'value' => optional($ticket->sla_due_at)->toDateTimeString()],
                ],
                route('superadmin.support.show', $ticket->id),
                'View support request'
            ), [
                'ticket_id' => $ticket->id,
            ]);
            $sent += 1;
        }

        ActivityLog::record(null, $ticket, 'support_ticket.sla_overdue_sent', [
            'sla_due_at' => optional($ticket->sla_due_at)->toDateTimeString(),
        ]);
    }

    foreach ($unassignedTickets as $ticket) {
        $lastReminder = ActivityLog::query()
            ->where('subject_type', $ticket->getMorphClass())
            ->where('subject_id', $ticket->id)
            ->where('action', 'support_ticket.unassigned_reminder_sent')
            ->latest('created_at')
            ->first();

        if ($lastReminder && $lastReminder->created_at && $lastReminder->created_at->greaterThan($cooldownCutoff)) {
            continue;
        }

        foreach ($agents as $recipient) {
            NotificationDispatcher::send($recipient, new ActionEmailNotification(
                'Support ticket unassigned',
                "Ticket #{$ticket->id} is waiting for assignment.",
                [
                    ['label' => 'Ticket', 'value' => "#{$ticket->id} - {$ticket->title}"],
                    ['label' => 'Company', 'value' => $ticket->account?->company_name ?? $ticket->account?->email],
                ],
                route('superadmin.support.show', $ticket->id),
                'View support request'
            ), [
                'ticket_id' => $ticket->id,
            ]);
            $sent += 1;
        }

        ActivityLog::record(null, $ticket, 'support_ticket.unassigned_reminder_sent', [
            'hours' => $unassignedHours,
        ]);
    }

    $this->info("Sent {$sent} support reminders.");

    return 0;
})->purpose('Send SLA and assignment reminders for support tickets');

Artisan::command('demo:seed {type=service} {--tenant_id=}', function (
    DemoAccountService $accounts,
    DemoSeedService $seeds
): int {
    if (!config('demo.enabled')) {
        $this->error('DEMO_ENABLED is false.');
        return 1;
    }

    $type = (string) $this->argument('type');
    $tenantId = $this->option('tenant_id');

    if ($tenantId) {
        $account = User::query()->find($tenantId);
        if (!$account) {
            $this->error('Tenant not found.');
            return 1;
        }
        $accounts->resolveDemoUser($account, $type);
    } else {
        $account = $accounts->resolveDemoAccount($type);
    }

    $seeds->seed($account, $type);
    $this->info("Demo seeded for {$account->email} ({$type}).");

    return 0;
})->purpose('Seed demo data for a demo tenant');

Artisan::command('demo:reset {--tenant_id=}', function (
    DemoResetService $reset,
    DemoSeedService $seeds
): int {
    if (!config('demo.enabled')) {
        $this->error('DEMO_ENABLED is false.');
        return 1;
    }

    $tenantId = $this->option('tenant_id');
    $accounts = $tenantId
        ? User::query()->whereKey($tenantId)->get()
        : User::query()->where('is_demo', true)->get();

    if ($accounts->isEmpty()) {
        $this->error('No demo tenants found.');
        return 1;
    }

    foreach ($accounts as $account) {
        $reset->reset($account);
        $seeds->seed($account, $account->demo_type ?: DemoAccountService::TYPE_SERVICE);
        $this->info("Demo reset for {$account->email}.");
    }

    return 0;
})->purpose('Reset demo tenant data and tour progress');

Artisan::command('reservations:notifications', function (ReservationNotificationService $notificationService): int {
    $result = $notificationService->processScheduledNotifications();

    $reminders = (int) ($result['reminders_sent'] ?? 0);
    $reviews = (int) ($result['review_requests_sent'] ?? 0);

    $this->info("Reservation notifications processed. reminders={$reminders}, reviews={$reviews}");

    return 0;
})->purpose('Send reservation reminders and review requests');

Artisan::command('notifications:retry-failed
    {--notification=App\\Notifications\\InviteUserNotification : Fully-qualified notification class filter}
    {--max=25 : Maximum failed jobs to retry in one run}
    {--within-hours=24 : Only retry jobs failed within this time window}
    {--cooldown=30 : Cooldown (minutes) by payload fingerprint before a new retry}
    {--all-errors : Retry even for non-transient errors}
    {--dry-run : Show eligible jobs without retrying}', function (): int {
    if (!Schema::hasTable('failed_jobs')) {
        $this->warn('failed_jobs table is missing.');
        return 0;
    }

    $notificationClass = trim((string) $this->option('notification'));
    $max = max(1, min(200, (int) $this->option('max')));
    $withinHours = max(1, (int) $this->option('within-hours'));
    $cooldownMinutes = max(1, (int) $this->option('cooldown'));
    $allErrors = (bool) $this->option('all-errors');
    $dryRun = (bool) $this->option('dry-run');

    $query = DB::table('failed_jobs')
        ->select(['id', 'uuid', 'queue', 'payload', 'exception', 'failed_at'])
        ->where('failed_at', '>=', now()->subHours($withinHours))
        ->orderBy('id');

    // Scan a larger candidate set, then apply in-memory filters and cap to `--max`.
    $candidates = $query->limit($max * 6)->get();
    $eligible = collect();
    $transientMarkers = [
        'timeout',
        'timed out',
        '421',
        'too many connections',
        'connection reset',
        'temporarily unavailable',
        'could not be established',
        'connection refused',
        'failed to authenticate',
    ];

    foreach ($candidates as $job) {
        $payload = (string) $job->payload;
        $sendQueuedRaw = 'Illuminate\\Notifications\\SendQueuedNotifications';
        $sendQueuedEscaped = 'Illuminate\\\\Notifications\\\\SendQueuedNotifications';
        if (!str_contains($payload, $sendQueuedRaw) && !str_contains($payload, $sendQueuedEscaped)) {
            continue;
        }

        if ($notificationClass !== '') {
            $rawNeedle = $notificationClass;
            $escapedNeedle = str_replace('\\', '\\\\', $notificationClass);
            if (!str_contains($payload, $rawNeedle) && !str_contains($payload, $escapedNeedle)) {
                continue;
            }
        }

        $exception = strtolower((string) $job->exception);
        $isTransient = collect($transientMarkers)->contains(
            fn (string $marker) => str_contains($exception, $marker)
        );

        if (!$allErrors && !$isTransient) {
            continue;
        }

        $fingerprint = sha1((string) $job->payload);
        $lockKey = 'notifications:failed-retry:' . $fingerprint;
        if (Cache::has($lockKey)) {
            continue;
        }

        $eligible->push((object) [
            'id' => (int) $job->id,
            'uuid' => (string) $job->uuid,
            'failed_at' => (string) $job->failed_at,
            'fingerprint' => $fingerprint,
        ]);

        if ($eligible->count() >= $max) {
            break;
        }
    }

    if ($eligible->isEmpty()) {
        $this->info('No eligible failed notification jobs to retry.');
        return 0;
    }

    if ($dryRun) {
        $this->info('Dry run: ' . $eligible->count() . ' failed notification jobs are eligible for retry.');
        foreach ($eligible as $job) {
            $this->line("- id={$job->id} uuid={$job->uuid} failed_at={$job->failed_at}");
        }
        return 0;
    }

    $retried = 0;
    $failedToRetry = 0;
    foreach ($eligible as $job) {
        $lockKey = 'notifications:failed-retry:' . $job->fingerprint;
        Cache::put($lockKey, true, now()->addMinutes($cooldownMinutes));

        $exitCode = Artisan::call('queue:retry', [
            'id' => [(string) $job->id],
        ]);

        if ($exitCode === 0) {
            $retried += 1;
            continue;
        }

        Cache::forget($lockKey);
        $failedToRetry += 1;
        $this->warn("Failed to requeue failed job id={$job->id}.");
    }

    $this->info("Retried {$retried} failed notification job(s).");
    if ($failedToRetry > 0) {
        $this->warn("{$failedToRetry} job(s) could not be requeued.");
    }

    return 0;
})->purpose('Retry transient failed notification jobs from failed_jobs');

Artisan::command('app:launch-reset {--force : Skip confirmation prompt}', function (): int {
    if (!(bool) $this->option('force')) {
        $confirmed = $this->confirm(
            'This will run migrate:fresh, reseed LaunchSeeder, clear caches and optimize. Continue?',
            false
        );
        if (!$confirmed) {
            $this->warn('Operation cancelled.');
            return 1;
        }
    }

    $steps = [
        ['migrate:fresh', ['--seed' => true, '--force' => true], 'Database refreshed and first seed...'],
        ['db:seed', ['--class' => LaunchSeeder::class, '--force' => true], 'LaunchSeeder executed.'],
        ['optimize:clear', [], 'Caches cleared.'],
        ['optimize', [], 'Application optimized.'],
    ];

    foreach ($steps as [$command, $arguments, $message]) {
        $this->line("Running: php artisan {$command}");
        $exitCode = $this->call($command, $arguments);
        if ($exitCode !== 0) {
            $this->error("Failed on command: {$command}");
            return $exitCode;
        }
        $this->info($message);
    }

    $this->newLine();
    $this->info('Launch reset completed successfully.');

    return 0;
})->purpose('Reset database for launch demo data, clear caches, and optimize');

Schedule::command('platform:notifications-digest --frequency=daily')->dailyAt('08:00');
Schedule::command('platform:notifications-digest --frequency=weekly')->weeklyOn(1, '08:00');
Schedule::command('platform:notifications-scan')->dailyAt('07:30');
Schedule::command('agenda:process')->everyFiveMinutes();
Schedule::command('orders:deposit-reminders')->everyFourHours();
Schedule::command('leads:follow-up-reminders --hours=24')->hourly();
Schedule::command('support:sla-reminders')->hourly();
Schedule::command('reservations:notifications')->everyFifteenMinutes();
Schedule::command('notifications:retry-failed --notification=App\\Notifications\\InviteUserNotification --max=20 --within-hours=24 --cooldown=30')
    ->everyTenMinutes()
    ->withoutOverlapping();
