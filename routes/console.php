<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schedule;
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
use App\Services\WorkBillingService;
use App\Services\SaleNotificationService;
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

Schedule::command('platform:notifications-digest --frequency=daily')->dailyAt('08:00');
Schedule::command('platform:notifications-digest --frequency=weekly')->weeklyOn(1, '08:00');
Schedule::command('platform:notifications-scan')->dailyAt('07:30');
Schedule::command('agenda:process')->everyFiveMinutes();
Schedule::command('orders:deposit-reminders')->everyFourHours();
Schedule::command('leads:follow-up-reminders --hours=24')->hourly();
Schedule::command('support:sla-reminders')->hourly();
