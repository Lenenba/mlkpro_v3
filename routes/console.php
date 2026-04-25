<?php

use App\Jobs\ComputeInterestScoresJob;
use App\Jobs\ReconcileDeliveryReportsJob;
use App\Mail\DemoWorkspaceAccessMail;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\PlatformSupportTicket;
use App\Models\Product;
use App\Models\Property;
use App\Models\Quote;
use App\Models\QuoteProduct;
use App\Models\QuoteTax;
use App\Models\Request as LeadRequest;
use App\Models\ReservationSetting;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Tax;
use App\Models\User;
use App\Models\Work;
use App\Notifications\ActionEmailNotification;
use App\Notifications\InviteUserNotification;
use App\Notifications\LeadCallRequestReceivedNotification;
use App\Notifications\LeadFollowUpNotification;
use App\Notifications\LeadFormOwnerNotification;
use App\Notifications\LeadQuoteRequestReceivedNotification;
use App\Notifications\PlatformAdminDigestNotification;
use App\Notifications\SendQuoteNotification;
use App\Notifications\SupplierStockRequestNotification;
use App\Notifications\TwoFactorCodeNotification;
use App\Notifications\UpcomingBillingReminderNotification;
use App\Notifications\WelcomeEmailNotification;
use App\Services\Campaigns\CampaignAutomationService;
use App\Services\Campaigns\VipService;
use App\Services\Capacity\CapacityReportService;
use App\Services\DailyAgendaService;
use App\Services\Demo\DemoWorkspacePurgeService;
use App\Services\ExpenseRecurringService;
use App\Services\Observability\ObservabilityReportService;
use App\Services\PlanEntitlementSyncService;
use App\Services\PlatformAdminNotifier;
use App\Services\ProspectFollowUpReminderService;
use App\Services\Prospects\ProspectCustomerMigrationAnalysisService;
use App\Services\Prospects\ProspectCustomerMigrationService;
use App\Services\Prospects\ProspectCustomerMigrationVerificationService;
use App\Services\ProspectStaleReminderService;
use App\Services\PublicCopySyncService;
use App\Services\QueueHealthService;
use App\Services\ReservationAvailabilityService;
use App\Services\ReservationNotificationService;
use App\Services\ReservationQueueService;
use App\Services\SaleNotificationService;
use App\Services\SmsNotificationService;
use App\Services\StripePlanEnvSyncService;
use App\Services\StripePlanPriceProvisioner;
use App\Services\SupportAssignmentService;
use App\Services\SupportSettingsService;
use App\Services\UpcomingBillingReminderService;
use App\Services\WorkBillingService;
use App\Support\LocalePreference;
use App\Support\NotificationDispatcher;
use App\Support\SchemaAudit\ManualSelectContractAudit;
use Database\Seeders\LaunchResetSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

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

    if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
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
        if (! $this->confirm('User already exists. Update role and password?', false)) {
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
            'App: '.(string) config('app.name'),
            'Date: '.now()->toDateTimeString(),
        ]);
    }

    Mail::raw($body, fn ($m) => $m->to($to)->subject($subject));

    $this->info("OK: email envoye a {$to}");

    return 0;
})->purpose('Send a test email using current mailer');

Artisan::command('mail:preview-pack {to}
    {--only=* : Optional preview key(s). Can be repeated or comma-separated}
    {--group= : Optional preview group, for example client-facing}
    {--list : List available preview keys without sending}', function (): int {
    $to = trim((string) $this->argument('to'));

    if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $this->error('Adresse email invalide.');

        return 1;
    }

    $available = [
        'demo-access' => 'Demo workspace access email',
        'action-generic' => 'Generic action email',
        'invite-user' => 'Invite user email',
        'two-factor' => 'Two-factor code email',
        'reset-password' => 'Reset password email',
        'welcome' => 'Welcome / onboarding email',
        'quote' => 'Quote email',
        'lead-call' => 'Lead call request received email',
        'lead-quote' => 'Lead quote received email',
        'lead-owner-quote' => 'Lead owner notification: quote created',
        'lead-owner-call' => 'Lead owner notification: call requested',
        'lead-owner-email-failed' => 'Lead owner notification: quote email failed',
        'lead-follow-up' => 'Lead follow-up overdue email',
        'lead-unassigned' => 'Lead unassigned reminder email',
        'supplier-stock' => 'Supplier stock request email',
        'admin-digest' => 'Platform admin digest email',
        'billing-upcoming' => 'Upcoming billing reminder email',
        'client-invoice-new' => 'Client email: new invoice available',
        'client-payment-confirmed' => 'Client email: payment confirmed',
        'client-order-update' => 'Client email: order status update',
        'client-deposit-request' => 'Client email: deposit requested',
        'client-deposit-reminder' => 'Client email: deposit reminder',
        'client-reservation-reminder' => 'Client email: reservation reminder',
        'client-review-request' => 'Client email: review request',
        'client-job-validation' => 'Client email: job ready for validation',
        'client-service-today' => 'Client email: service scheduled today',
    ];

    $groups = [
        'client-facing' => [
            'quote',
            'lead-call',
            'lead-quote',
            'client-invoice-new',
            'client-payment-confirmed',
            'client-order-update',
            'client-deposit-request',
            'client-deposit-reminder',
            'client-reservation-reminder',
            'client-review-request',
            'client-job-validation',
            'client-service-today',
        ],
    ];

    $selected = collect((array) $this->option('only'))
        ->flatMap(fn ($value) => array_map('trim', explode(',', (string) $value)))
        ->filter(fn ($value) => $value !== '')
        ->map(fn ($value) => strtolower((string) $value))
        ->unique()
        ->values();

    $group = strtolower(trim((string) $this->option('group')));
    if ($group !== '') {
        if (! array_key_exists($group, $groups)) {
            $this->error('Unknown preview group: '.$group);
            $this->line('Use --list to see the available groups.');

            return 1;
        }

        $selected = $selected
            ->merge($groups[$group])
            ->unique()
            ->values();
    }

    if ((bool) $this->option('list')) {
        $this->table(
            ['Key', 'Email'],
            collect($available)->map(fn ($label, $key) => [$key, $label])->values()->all()
        );

        $this->newLine();
        $this->table(
            ['Group', 'Includes'],
            collect($groups)->map(fn ($keys, $groupName) => [$groupName, implode(', ', $keys)])->values()->all()
        );

        return 0;
    }

    $unknown = $selected
        ->reject(fn ($key) => array_key_exists($key, $available))
        ->values();

    if ($unknown->isNotEmpty()) {
        $this->error('Unknown preview key(s): '.$unknown->implode(', '));
        $this->line('Use --list to see the available keys.');

        return 1;
    }

    $owner = new User;
    $owner->forceFill([
        'id' => 9101,
        'name' => 'Preview Owner',
        'email' => 'owner-preview@example.test',
        'company_name' => 'Malikia Pro',
        'company_logo' => '/images/presets/company-1.svg',
        'company_type' => 'services',
        'currency_code' => 'USD',
    ]);

    $previewUser = new User;
    $previewUser->forceFill([
        'id' => 9102,
        'name' => 'Preview Recipient',
        'email' => $to,
        'locale' => $owner->locale ?? 'fr',
        'company_name' => 'Malikia Pro',
        'company_logo' => '/images/presets/company-1.svg',
        'currency_code' => 'USD',
    ]);

    $customer = new Customer;
    $customer->forceFill([
        'id' => 9201,
        'user_id' => $owner->id,
        'portal_access' => false,
        'first_name' => 'Camille',
        'last_name' => 'Laurent',
        'company_name' => 'Atelier Horizon',
        'email' => $to,
        'phone' => '+1 514 555 0192',
        'logo' => Customer::DEFAULT_LOGO_PATH,
    ]);
    $customer->setRelation('user', $owner);

    $property = new Property;
    $property->forceFill([
        'id' => 9301,
        'customer_id' => $customer->id,
        'type' => 'physical',
        'is_default' => true,
        'street1' => '125 Rue du Parc',
        'city' => 'Montreal',
        'state' => 'QC',
        'zip' => 'H2X 1Y4',
        'country' => 'CA',
    ]);
    $customer->setRelation('properties', collect([$property]));

    $lead = new LeadRequest;
    $lead->forceFill([
        'id' => 9401,
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => LeadRequest::STATUS_QUALIFIED,
        'service_type' => 'Field services',
        'title' => 'Multi-site rollout request',
        'description' => 'The prospect wants a realistic workspace to validate the sales, quote, and operations handoff before rollout.',
        'contact_name' => 'Camille Laurent',
        'contact_email' => $to,
        'contact_phone' => '+1 514 555 0192',
        'city' => 'Montreal',
        'country' => 'CA',
    ]);
    $lead->setRelation('user', $owner);
    $lead->setRelation('customer', $customer);

    $tax = new Tax;
    $tax->forceFill([
        'id' => 9501,
        'name' => 'GST + QST',
        'rate' => 14.975,
    ]);

    $quoteTax = new QuoteTax;
    $quoteTax->forceFill([
        'id' => 9502,
        'quote_id' => 9601,
        'tax_id' => $tax->id,
        'rate' => 14.975,
        'amount' => 104.83,
    ]);
    $quoteTax->setRelation('tax', $tax);

    $productA = new Product;
    $productA->forceFill([
        'id' => 9602,
        'user_id' => $owner->id,
        'name' => 'Operational audit',
        'price' => 350.00,
        'stock' => 9,
        'minimum_stock' => 3,
        'sku' => 'SERV-AUDIT-01',
        'item_type' => Product::ITEM_TYPE_SERVICE,
    ]);
    $productAPivot = new QuoteProduct;
    $productAPivot->forceFill([
        'quote_id' => 9601,
        'product_id' => $productA->id,
        'quantity' => 1,
        'price' => 350.00,
        'currency_code' => 'USD',
        'total' => 350.00,
    ]);
    $productA->setRelation('pivot', $productAPivot);

    $productB = new Product;
    $productB->forceFill([
        'id' => 9603,
        'user_id' => $owner->id,
        'name' => 'Deployment planning',
        'price' => 350.00,
        'stock' => 7,
        'minimum_stock' => 2,
        'sku' => 'SERV-DEPLOY-01',
        'item_type' => Product::ITEM_TYPE_SERVICE,
    ]);
    $productBPivot = new QuoteProduct;
    $productBPivot->forceFill([
        'quote_id' => 9601,
        'product_id' => $productB->id,
        'quantity' => 2,
        'price' => 350.00,
        'currency_code' => 'USD',
        'total' => 700.00,
    ]);
    $productB->setRelation('pivot', $productBPivot);

    $quote = new Quote;
    $quote->forceFill([
        'id' => 9601,
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'property_id' => $property->id,
        'request_id' => $lead->id,
        'number' => 'Q-2026-0142',
        'job_title' => 'Operational handoff setup',
        'status' => 'sent',
        'subtotal' => 700.00,
        'total' => 804.83,
        'initial_deposit' => 150.00,
        'messages' => 'Review the scope and validate the quote from the secure portal.',
    ]);
    $quote->setRelation('customer', $customer);
    $quote->setRelation('property', $property);
    $quote->setRelation('products', collect([$productA, $productB]));
    $quote->setRelation('taxes', collect([$quoteTax]));

    $stockProduct = new Product;
    $stockProduct->forceFill([
        'id' => 9701,
        'user_id' => $owner->id,
        'name' => 'Premium paint kit',
        'price' => 89.00,
        'stock' => 3,
        'minimum_stock' => 8,
        'sku' => 'PAINT-KIT-01',
        'item_type' => Product::ITEM_TYPE_PRODUCT,
    ]);

    $digestItems = [
        [
            'title' => '3 demos are ready for review',
            'category' => 'sales',
            'created_at' => now()->subHour()->toDateTimeString(),
            'intro' => 'Review access kits and send the best-fit workspace to prospects.',
        ],
        [
            'title' => '2 quote deliveries need attention',
            'category' => 'operations',
            'created_at' => now()->subMinutes(35)->toDateTimeString(),
            'intro' => 'One quote email failed and one follow-up is overdue.',
        ],
        [
            'title' => 'Low stock request pending confirmation',
            'category' => 'stock',
            'created_at' => now()->subMinutes(10)->toDateTimeString(),
            'intro' => 'A supplier restock confirmation is still pending for a critical product.',
        ],
    ];

    $sendPreview = function (string $key) use ($selected, $to, $owner, $previewUser, $customer, $lead, $quote, $stockProduct, $digestItems): bool {
        if ($selected->isNotEmpty() && ! $selected->contains($key)) {
            return false;
        }

        match ($key) {
            'demo-access' => Mail::to($to)->send(new DemoWorkspaceAccessMail(
                companyName: $owner->company_name ?: config('app.name'),
                companyLogo: $owner->company_logo_url,
                recipientName: 'Camille Laurent',
                prospectCompany: 'Atelier Horizon',
                workspaceName: 'Atelier Horizon Demo',
                tagline: 'Concu pour les equipes terrain.',
                loginUrl: url('/login'),
                accessEmail: 'demo@atelier-horizon.test',
                accessPassword: 'Demo1234!',
                expiresAt: now()->addDays(7)->format('d/m/Y'),
                templateName: 'Field Services',
                moduleLabels: ['CRM', 'Quotes', 'Scheduling', 'Invoices'],
                scenarioLabels: ['Discovery handoff', 'Quote validation', 'Ops walkthrough'],
                extraCredentials: [
                    ['role_label' => 'Sales manager', 'email' => 'sales@atelier-horizon.test', 'password' => 'Sales123!'],
                    ['role_label' => 'Operations lead', 'email' => 'ops@atelier-horizon.test', 'password' => 'Ops123!'],
                ],
                suggestedFlow: "1. Log in with the main access.\n2. Review the quote and customer record.\n3. Switch to operations and validate the follow-up flow.",
                replyToAddress: config('mail.from.address'),
                preferredLocale: LocalePreference::forUser($owner),
            )),
            'action-generic' => $customer->notifyNow(new ActionEmailNotification(
                title: 'Preview action email',
                intro: 'This is the reusable action template used for platform updates and admin alerts.',
                details: [
                    ['label' => 'Module', 'value' => 'Mail previews'],
                    ['label' => 'Environment', 'value' => (string) config('app.name')],
                    ['label' => 'Generated', 'value' => now()->format('Y-m-d H:i')],
                ],
                actionUrl: url('/dashboard'),
                actionLabel: 'Open dashboard',
                subject: 'Preview - action email',
                note: 'Use this preview to validate cards, spacing, and CTA styling.',
            )),
            'invite-user' => $previewUser->notifyNow(new InviteUserNotification(
                token: Str::random(64),
                companyName: $owner->company_name,
                companyLogo: $owner->company_logo_url,
                context: 'member',
            )),
            'two-factor' => $previewUser->notifyNow(new TwoFactorCodeNotification(
                code: '482913',
                expiresAt: now()->addMinutes(10),
            )),
            'reset-password' => $previewUser->notifyNow(new ResetPassword(
                token: Str::random(64),
            )),
            'welcome' => $previewUser->notifyNow(new WelcomeEmailNotification($owner)),
            'quote' => Notification::route('mail', $to)->notifyNow(new SendQuoteNotification($quote)),
            'lead-call' => Notification::route('mail', $to)->notifyNow(new LeadCallRequestReceivedNotification($owner, $lead)),
            'lead-quote' => Notification::route('mail', $to)->notifyNow(new LeadQuoteRequestReceivedNotification($owner, $lead, $quote, true)),
            'lead-owner-quote' => $previewUser->notifyNow(new LeadFormOwnerNotification('lead_quote_created', $lead, $quote, true), ['mail']),
            'lead-owner-call' => $previewUser->notifyNow(new LeadFormOwnerNotification('lead_call_requested', $lead, null, true), ['mail']),
            'lead-owner-email-failed' => $previewUser->notifyNow(new LeadFormOwnerNotification('lead_email_failed', $lead, $quote, true), ['mail']),
            'lead-follow-up' => $previewUser->notifyNow(new LeadFollowUpNotification($lead, 'follow_up_overdue', 24), ['mail']),
            'lead-unassigned' => $previewUser->notifyNow(new LeadFollowUpNotification($lead, 'unassigned', 24), ['mail']),
            'supplier-stock' => Notification::route('mail', $to)->notifyNow(new SupplierStockRequestNotification(
                product: $stockProduct,
                owner: $owner,
                customMessage: 'Merci de confirmer la disponibilite et le prochain delai de reapprovisionnement.',
            )),
            'admin-digest' => Notification::route('mail', $to)->notifyNow(new PlatformAdminDigestNotification('daily', $digestItems)),
            'billing-upcoming' => $previewUser->notifyNow(new UpcomingBillingReminderNotification([
                'companyName' => 'Atelier Horizon',
                'companyLogo' => $owner->company_logo_url,
                'recipientName' => 'Camille Laurent',
                'billingDate' => now()->addDays(3)->toDateString(),
                'billingDateLabel' => now()->addDays(3)->format('d/m/Y'),
                'daysUntilBilling' => 3,
                'planName' => 'Team Growth',
                'billingPeriod' => 'monthly',
                'seatQuantity' => 5,
                'currencyCode' => 'USD',
                'formattedTotal' => '$79.00',
                'formattedSubtotal' => '$69.00',
                'formattedTax' => '$10.00',
                'lineItems' => [
                    ['label' => 'Team Growth plan', 'quantity' => 1, 'formatted_amount' => '$64.00'],
                    ['label' => '5 active seats', 'quantity' => 5, 'formatted_amount' => '$15.00'],
                ],
                'lineItemCount' => 2,
                'manageBillingUrl' => url('/settings/billing'),
                'supportEmail' => config('mail.from.address'),
                'reminderKey' => 'preview-billing-upcoming',
            ])),
            'client-invoice-new' => $customer->notifyNow(new ActionEmailNotification(
                title: 'New invoice available',
                intro: 'A new invoice has been generated for your job.',
                details: [
                    ['label' => 'Invoice', 'value' => 'INV-2026-0041'],
                    ['label' => 'Job', 'value' => 'Operational handoff setup'],
                    ['label' => 'Total', 'value' => '$804.83'],
                ],
                actionUrl: url('/public/invoices/preview'),
                actionLabel: 'Pay invoice',
                subject: 'New invoice available',
                note: 'Review the balance and pay securely using the link provided.',
            )),
            'client-payment-confirmed' => $customer->notifyNow(new ActionEmailNotification(
                title: 'Payment confirmed',
                intro: 'Your payment has been received.',
                details: [
                    ['label' => 'Invoice', 'value' => 'INV-2026-0041'],
                    ['label' => 'Amount paid', 'value' => '$804.83'],
                    ['label' => 'Method', 'value' => 'Visa ending in 4242'],
                ],
                actionUrl: url('/public/invoices/preview'),
                actionLabel: 'View invoice',
                subject: 'Payment confirmation',
                note: 'Thank you. A copy of your invoice remains available from your secure link.',
            )),
            'client-order-update' => $customer->notifyNow(new ActionEmailNotification(
                title: 'Mise a jour de commande',
                intro: 'Livraison: En cours de livraison.',
                details: [
                    ['label' => 'Commande', 'value' => 'ORD-2026-0019'],
                    ['label' => 'Statut', 'value' => 'Payee'],
                    ['label' => 'Livraison', 'value' => 'En cours de livraison'],
                    ['label' => 'ETA', 'value' => now()->addHours(2)->format('D M j, Y g:i A')],
                ],
                actionUrl: url('/portal/orders/preview'),
                actionLabel: 'Voir la commande',
                subject: 'Mise a jour de commande',
                note: 'Consultez le suivi et les prochaines etapes depuis votre espace client.',
            )),
            'client-deposit-request' => $customer->notifyNow(new ActionEmailNotification(
                title: 'Acompte requis',
                intro: 'Un acompte de $150.00 est requis pour commencer la preparation.',
                details: [
                    ['label' => 'Commande', 'value' => 'ORD-2026-0019'],
                    ['label' => 'Acompte requis', 'value' => '$150.00'],
                    ['label' => 'Total', 'value' => '$804.83'],
                ],
                actionUrl: url('/portal/orders/preview'),
                actionLabel: 'Payer maintenant',
                subject: 'Acompte requis',
            )),
            'client-deposit-reminder' => $customer->notifyNow(new ActionEmailNotification(
                title: 'Rappel acompte',
                intro: 'Rappel: un acompte de $150.00 est requis pour commencer la preparation.',
                details: [
                    ['label' => 'Commande', 'value' => 'ORD-2026-0019'],
                    ['label' => 'Acompte requis', 'value' => '$150.00'],
                    ['label' => 'Total', 'value' => '$804.83'],
                ],
                actionUrl: url('/portal/orders/preview'),
                actionLabel: 'Payer maintenant',
                subject: 'Rappel acompte',
            )),
            'client-reservation-reminder' => $customer->notifyNow(new ActionEmailNotification(
                title: 'Reservation reminder',
                intro: 'Reminder: your reservation starts in 24 hour(s).',
                details: [
                    ['label' => 'Service', 'value' => 'On-site consultation'],
                    ['label' => 'When', 'value' => now()->addDay()->format('Y-m-d H:i')],
                    ['label' => 'Team member', 'value' => 'Jordan Peters'],
                    ['label' => 'Client', 'value' => 'Atelier Horizon'],
                    ['label' => 'Status', 'value' => 'confirmed'],
                ],
                actionUrl: url('/client/reservations'),
                actionLabel: 'Open reservations',
                subject: 'Reservation reminder',
            )),
            'client-review-request' => $customer->notifyNow(new ActionEmailNotification(
                title: 'How was your service?',
                intro: 'Your reservation is completed. Share your rating and feedback.',
                details: [
                    ['label' => 'Service', 'value' => 'On-site consultation'],
                    ['label' => 'When', 'value' => now()->subDay()->format('Y-m-d H:i')],
                    ['label' => 'Team member', 'value' => 'Jordan Peters'],
                    ['label' => 'Client', 'value' => 'Atelier Horizon'],
                    ['label' => 'Status', 'value' => 'completed'],
                ],
                actionUrl: url('/client/reservations'),
                actionLabel: 'Leave a review',
                subject: 'How was your service?',
            )),
            'client-job-validation' => $customer->notifyNow(new ActionEmailNotification(
                title: 'Job ready for validation',
                intro: 'A job is ready for your validation.',
                details: [
                    ['label' => 'Job', 'value' => 'Operational handoff setup'],
                    ['label' => 'Status', 'value' => 'pending_review'],
                    ['label' => 'Customer', 'value' => 'Atelier Horizon'],
                ],
                actionUrl: url('/public/works/preview'),
                actionLabel: 'Review job',
                subject: 'Job ready for validation',
            )),
            'client-service-today' => $customer->notifyNow(new ActionEmailNotification(
                title: 'Intervention aujourd hui',
                intro: 'Bonjour, notre technicien arrive aujourd hui vers 14:30. Technicien: Jordan Peters.',
                details: [
                    ['label' => 'Tache', 'value' => 'Operational handoff setup'],
                    ['label' => 'Heure estimee', 'value' => '14:30'],
                    ['label' => 'Technicien', 'value' => 'Jordan Peters'],
                ],
                actionUrl: url('/public/works/preview'),
                actionLabel: 'Voir le suivi',
                subject: 'Intervention aujourd hui',
            )),
            default => null,
        };

        $this->info("Sent {$key} to {$to}");

        return true;
    };

    $results = [];
    $failures = 0;

    foreach (array_keys($available) as $key) {
        try {
            if (! $sendPreview($key)) {
                continue;
            }

            $results[] = [$key, 'sent', $available[$key]];
        } catch (\Throwable $exception) {
            $failures += 1;
            $results[] = [$key, 'failed', $exception->getMessage()];
            $this->error("Failed {$key}: ".$exception->getMessage());
        }
    }

    if ($results === []) {
        $this->warn('No emails were selected. Use --list to see the available keys.');

        return 1;
    }

    $this->newLine();
    $this->table(['Key', 'Status', 'Details'], $results);

    if ($failures > 0) {
        $this->warn("Completed with {$failures} failure(s).");

        return 1;
    }

    $this->info('Preview pack sent successfully.');

    return 0;
})->purpose('Send a preview pack of the current transactional emails to a test inbox');

Artisan::command('mail:preview-client-pack {to}', function (): int {
    return (int) $this->call('mail:preview-pack', [
        'to' => (string) $this->argument('to'),
        '--group' => 'client-facing',
    ]);
})->purpose('Send the company-to-client email previews to a test inbox');

Artisan::command('billing:upcoming-reminders
    {--days= : Optional comma-separated override like 7,3,1}
    {--tenant_id= : Limit processing to one account owner}
    {--dry-run : Preview reminders without sending emails}', function (
    UpcomingBillingReminderService $reminderService
): int {
    $daysOption = trim((string) $this->option('days'));
    $days = $daysOption !== ''
        ? array_map('trim', explode(',', $daysOption))
        : [];
    $tenantId = $this->option('tenant_id');
    $dryRun = (bool) $this->option('dry-run');

    $result = $reminderService->process(
        $days,
        is_numeric($tenantId) ? (int) $tenantId : null,
        $dryRun
    );

    $this->info(sprintf(
        'Upcoming billing reminders: provider=%s scanned=%d sent=%d already_sent=%d skipped=%d failed=%d',
        (string) ($result['provider'] ?? 'unknown'),
        (int) ($result['scanned'] ?? 0),
        (int) ($result['sent'] ?? 0),
        (int) ($result['already_sent'] ?? 0),
        (int) ($result['skipped'] ?? 0),
        (int) ($result['failed'] ?? 0),
    ));

    $candidates = collect($result['candidates'] ?? []);
    if ($candidates->isNotEmpty()) {
        $this->table(
            ['Company', 'Email', 'Plan', 'Billing date', 'Days before', 'Estimated total'],
            $candidates
                ->map(fn (array $candidate) => [
                    (string) ($candidate['company_name'] ?? ''),
                    (string) ($candidate['email'] ?? ''),
                    (string) ($candidate['plan_name'] ?? ''),
                    (string) ($candidate['billing_date'] ?? ''),
                    (string) ($candidate['days_before'] ?? ''),
                    (string) ($candidate['formatted_total'] ?? ''),
                ])
                ->all()
        );
    }

    if (! ($result['enabled'] ?? true)) {
        $this->warn('Upcoming billing reminders are disabled in config(billing.upcoming_reminders.enabled).');

        return 0;
    }

    if ((bool) ($result['missing_table'] ?? false)) {
        $this->warn('billing_cycle_reminder_logs table is missing. Run migrations before sending reminders.');

        return 1;
    }

    if (($result['provider'] ?? null) !== 'stripe') {
        $this->warn('Upcoming billing reminders currently rely on Stripe subscription previews.');

        return 0;
    }

    return (int) ($result['failed'] ?? 0) > 0 ? 1 : 0;
})->purpose('Send billing reminder emails ahead of the next Stripe subscription renewal');

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

    if (! preg_match('/^\+[1-9]\d{7,14}$/', $to)) {
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

    if (! $sent) {
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
        $fromAddress = 'postmaster@'.$domain;
    }
    $from = $fromName !== '' ? "{$fromName} <{$fromAddress}>" : $fromAddress;

    $subject = (string) $this->option('subject');
    $text = trim((string) $this->option('text'));
    $html = trim((string) $this->option('html'));

    if ($text === '' && $html === '') {
        $text = implode("\n", [
            'Mailgun test email',
            'App: '.(string) config('app.name'),
            'Date: '.now()->toDateTimeString(),
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

    $this->error('Echec Mailgun ('.$response->status().')');
    $this->line($response->body());

    return 1;
})->purpose('Send a test email using Mailgun API');

Artisan::command('billing:stripe-plan-prices
    {--dry-run : Show what would happen without changing Stripe, .env, or the database}
    {--live : Required when STRIPE_SECRET is a live key}
    {--no-env : Do not update the local .env file}
    {--no-db : Do not sync plan_prices in the database}
    {--solo : Provision the 3 owner-only solo plans}
    {--plans= : Optional comma-separated list of plan codes}
    {--currencies= : Optional comma-separated list of currency codes}', function (
    StripePlanPriceProvisioner $provisioner
): int {
    $parseCsv = static function (?string $value): array {
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        return collect(explode(',', $value))
            ->map(fn ($part) => trim((string) $part))
            ->filter(fn ($part) => $part !== '')
            ->values()
            ->all();
    };

    $writeEnvPath = null;
    if (! (bool) $this->option('no-env')) {
        $defaultEnvPath = base_path('.env');
        $writeEnvPath = file_exists($defaultEnvPath) ? $defaultEnvPath : null;
    }

    try {
        $result = $provisioner->execute([
            'dry_run' => (bool) $this->option('dry-run'),
            'live' => (bool) $this->option('live'),
            'plans' => $parseCsv((string) $this->option('plans')),
            'solo_only' => (bool) $this->option('solo'),
            'currencies' => array_map('strtoupper', $parseCsv((string) $this->option('currencies'))),
            'write_env' => $writeEnvPath,
            'sync_db' => ! (bool) $this->option('no-db'),
        ]);
    } catch (\Throwable $exception) {
        $this->error($exception->getMessage());

        return 1;
    }

    $rows = collect($result['items'])
        ->map(fn (array $item) => [
            strtoupper((string) $item['plan_code']),
            (string) $item['currency_code'],
            (string) $item['action'],
            number_format((float) $item['amount'], 2, '.', ''),
            (string) ($item['stripe_price_id'] ?? '-'),
            (string) $item['env_key'],
        ])
        ->all();

    if ($rows !== []) {
        $this->table(['Plan', 'Currency', 'Action', 'Amount', 'Stripe price', 'Env key'], $rows);
    }

    if ($result['resolved'] !== []) {
        $this->newLine();
        $this->info('Resolved environment values:');
        foreach ($result['resolved'] as $envKey => $priceId) {
            $this->line($envKey.'='.$priceId);
        }
    }

    if ($result['dry_run']) {
        $this->newLine();
        $this->warn('Dry run: no Stripe price, .env file, or database row was changed.');

        return 0;
    }

    if ($result['env_updated']) {
        $this->info('Local .env file updated.');
    } elseif ((bool) $this->option('no-env')) {
        $this->warn('Skipped .env update because --no-env was used.');
    }

    if ($result['db_synced']) {
        $this->info('plan_prices synchronized in the database.');
    } elseif ((bool) $this->option('no-db')) {
        $this->warn('Skipped database synchronization because --no-db was used.');
    }

    return 0;
})->purpose('Create or reuse Stripe multi-currency plan prices, then optionally sync .env and plan_prices');

Artisan::command('billing:stripe-sync-env
    {--dry-run : Show the Stripe matches without changing the local .env file or the database}
    {--live : Required when STRIPE_SECRET is a live key}
    {--no-env : Do not update the local .env file}
    {--no-db : Do not sync plan_prices in the database}
    {--solo : Include the 3 owner-only solo plans}
    {--plans= : Optional comma-separated list of plan codes}
    {--currencies= : Optional comma-separated list of currency codes}', function (
    StripePlanEnvSyncService $syncService
): int {
    $parseCsv = static function (?string $value): array {
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        return collect(explode(',', $value))
            ->map(fn ($part) => trim((string) $part))
            ->filter(fn ($part) => $part !== '')
            ->values()
            ->all();
    };

    $writeEnvPath = null;
    if (! (bool) $this->option('no-env')) {
        $defaultEnvPath = base_path('.env');
        $writeEnvPath = file_exists($defaultEnvPath) ? $defaultEnvPath : null;
    }

    try {
        $result = $syncService->execute([
            'dry_run' => (bool) $this->option('dry-run'),
            'live' => (bool) $this->option('live'),
            'plans' => $parseCsv((string) $this->option('plans')),
            'solo_only' => (bool) $this->option('solo'),
            'currencies' => array_map('strtoupper', $parseCsv((string) $this->option('currencies'))),
            'write_env' => $writeEnvPath,
            'sync_db' => ! (bool) $this->option('no-db'),
        ]);
    } catch (\Throwable $exception) {
        $this->error($exception->getMessage());

        return 1;
    }

    $rows = collect($result['items'])
        ->map(fn (array $item) => [
            strtoupper((string) $item['plan_code']),
            (string) $item['currency_code'],
            (string) $item['action'],
            number_format((float) $item['amount'], 2, '.', ''),
            (string) ($item['stripe_price_id'] ?? '-'),
            (string) $item['env_key'],
        ])
        ->all();

    if ($rows !== []) {
        $this->table(['Plan', 'Currency', 'Match', 'Amount', 'Stripe price', 'Env key'], $rows);
    }

    if ($result['resolved'] !== []) {
        $this->newLine();
        $this->info('Resolved environment values:');
        foreach ($result['resolved'] as $envKey => $value) {
            $this->line($envKey.'='.$value);
        }
    }

    if ($result['dry_run']) {
        $this->newLine();
        $this->warn('Dry run: Stripe was read-only; no .env file or database row was changed.');

        return 0;
    }

    if ($result['env_updated']) {
        $this->info('Local .env file updated.');
    } elseif ((bool) $this->option('no-env')) {
        $this->warn('Skipped .env update because --no-env was used.');
    }

    if ($result['db_synced']) {
        $this->info('plan_prices synchronized in the database.');
    } elseif ((bool) $this->option('no-db')) {
        $this->warn('Skipped database synchronization because --no-db was used.');
    }

    return 0;
})->purpose('Read existing Stripe plan prices and sync .env and plan_prices without creating new Stripe prices');

Artisan::command('billing:sync-plan-entitlements
    {--plans= : Optional comma-separated list of plan keys}
    {--reset-tenant-overrides : Clear company feature and limit overrides for tenants on the selected plans}
    {--dry-run : Preview the synchronized payload without writing it}', function (
    PlanEntitlementSyncService $service
): int {
    $parseCsv = static function (?string $value): array {
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        return collect(explode(',', $value))
            ->map(fn ($part) => trim((string) $part))
            ->filter(fn ($part) => $part !== '')
            ->values()
            ->all();
    };

    try {
        $summary = $service->sync([
            'plans' => $parseCsv((string) $this->option('plans')),
            'dry_run' => (bool) $this->option('dry-run'),
            'reset_tenant_overrides' => (bool) $this->option('reset-tenant-overrides'),
        ]);
    } catch (\Throwable $exception) {
        $this->error($exception->getMessage());

        return 1;
    }

    $plans = $summary['plans'] ?? [];
    $limitPayload = is_array($summary['plan_limits'] ?? null) ? $summary['plan_limits'] : [];
    $modulePayload = is_array($summary['plan_modules'] ?? null) ? $summary['plan_modules'] : [];

    $rows = collect($plans)
        ->map(function (string $planKey) use ($limitPayload, $modulePayload): array {
            $limits = array_filter(
                $limitPayload[$planKey] ?? [],
                static fn ($value): bool => is_numeric($value)
            );
            $modules = array_filter(
                $modulePayload[$planKey] ?? [],
                static fn ($enabled): bool => (bool) $enabled
            );

            return [
                $planKey,
                (string) (config('billing.plans.'.$planKey.'.name') ?? $planKey),
                (string) count($modules),
                (string) count($limits),
            ];
        })
        ->all();

    if ($rows !== []) {
        $this->table(['Plan', 'Name', 'Enabled modules', 'Numeric limits'], $rows);
    }

    if ($summary['dry_run'] ?? false) {
        $this->warn('Dry run: plan_modules and plan_limits were not written.');
    } else {
        $this->info(sprintf(
            'Synchronized %d plan(s) into plan_modules and plan_limits.',
            count($plans)
        ));
    }

    if ($summary['reset_tenant_overrides'] ?? false) {
        $tenantSummary = is_array($summary['tenant_overrides'] ?? null) ? $summary['tenant_overrides'] : [];

        $this->newLine();
        $this->line(sprintf(
            'Tenant overrides: %d matched, %d updated, %d feature override(s) cleared, %d limit override(s) cleared.',
            (int) ($tenantSummary['matched'] ?? 0),
            (int) ($tenantSummary['updated'] ?? 0),
            (int) ($tenantSummary['feature_overrides_cleared'] ?? 0),
            (int) ($tenantSummary['limit_overrides_cleared'] ?? 0),
        ));
    }

    return 0;
})->purpose('Sync plan modules and usage limits from config billing definitions');

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
    $this->info('Agenda processed: '.json_encode($result));

    return 0;
})->purpose('Auto-start tasks/jobs and send alerts');

Artisan::command('orders:deposit-reminders', function (SaleNotificationService $notifier): int {
    $cutoff = now()->subHours(24);

    $sales = Sale::query()
        ->where('source', 'portal')
        ->where('status', '!=', Sale::STATUS_CANCELED)
        ->where('deposit_amount', '>', 0)
        ->with(['customer.portalUser'])
        ->withSum(['payments as payments_sum_amount' => fn ($query) => $query->where('status', 'completed')], 'amount')
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

Artisan::command('prospects:follow-up-reminders
    {--date= : Optional reference datetime for testing}
    {--dry-run : Preview reminders without sending notifications}', function (
    ProspectFollowUpReminderService $reminderService
): int {
    $dateOption = trim((string) $this->option('date'));
    $referenceTime = $dateOption !== '' ? \Illuminate\Support\Carbon::parse($dateOption) : now();
    $dryRun = (bool) $this->option('dry-run');

    $summary = $reminderService->process($referenceTime, $dryRun);

    $this->info(sprintf(
        'Prospect follow-up reminders: scanned=%d due_today=%d overdue=%d sent=%d skipped=%d dry_run=%s',
        (int) ($summary['scanned'] ?? 0),
        (int) ($summary['due_today'] ?? 0),
        (int) ($summary['overdue'] ?? 0),
        (int) ($summary['sent'] ?? 0),
        (int) ($summary['skipped'] ?? 0),
        $dryRun ? 'yes' : 'no',
    ));

    return 0;
})->purpose('Send reminders for due today and overdue prospect follow-up tasks');

Artisan::command('prospects:stale-reminders
    {--date= : Optional reference datetime for testing}
    {--dry-run : Preview reminders without sending notifications}', function (
    ProspectStaleReminderService $reminderService
): int {
    $dateOption = trim((string) $this->option('date'));
    $referenceTime = $dateOption !== '' ? \Illuminate\Support\Carbon::parse($dateOption) : now();
    $dryRun = (bool) $this->option('dry-run');

    $summary = $reminderService->process($referenceTime, $dryRun);

    $this->info(sprintf(
        'Prospect stale reminders: scanned=%d stale=%d sent=%d skipped=%d dry_run=%s',
        (int) ($summary['scanned'] ?? 0),
        (int) ($summary['stale'] ?? 0),
        (int) ($summary['sent'] ?? 0),
        (int) ($summary['skipped'] ?? 0),
        $dryRun ? 'yes' : 'no',
    ));

    return 0;
})->purpose('Send reminders for stale prospects without recent activity');

Artisan::command('prospects:migration-dry-run
    {--account_id= : Optional tenant account id to analyze}
    {--sample=10 : Maximum number of ambiguous sample rows to print}', function (
    ProspectCustomerMigrationAnalysisService $analysisService
): int {
    $accountIdOption = $this->option('account_id');
    $accountId = is_numeric($accountIdOption) ? (int) $accountIdOption : null;
    $sampleLimit = max(1, (int) $this->option('sample'));

    $summary = $analysisService->analyze($accountId, $sampleLimit);

    $this->info('Prospect migration dry run');
    $this->line('Scope: '.($accountId ? 'account '.$accountId : 'all accounts'));
    $this->line('Scanned customers: '.(int) $summary['scanned']);
    $this->line('Real customers: '.(int) $summary['real_count']);
    $this->line('Eligible prospects: '.(int) $summary['eligible_count']);
    $this->line('Ambiguous / a qualifier: '.(int) $summary['ambiguous_count']);
    $this->newLine();
    $this->line('Reason counts:');

    foreach ($summary['reason_counts'] as $reason => $count) {
        $this->line('- '.$reason.': '.$count);
    }

    if (! empty($summary['ambiguous_samples'])) {
        $this->newLine();
        $this->line('Ambiguous samples:');

        foreach ($summary['ambiguous_samples'] as $sample) {
            $signals = collect($sample['signals'] ?? [])
                ->map(fn ($value, $key) => $key.'='.(is_bool($value) ? ($value ? 'true' : 'false') : $value))
                ->implode(', ');

            $this->line(sprintf(
                '- #%d %s [%s] %s',
                (int) ($sample['id'] ?? 0),
                (string) ($sample['name'] ?? 'Customer'),
                (string) ($sample['reason'] ?? 'ambiguous'),
                $signals
            ));
        }
    }

    return 0;
})->purpose('Analyze legacy customers that could be reclassified as prospects without writing data');

Artisan::command('prospects:migration-run
    {--account_id= : Optional tenant account id to migrate}
    {--chunk=100 : Chunk size used while migrating}
    {--force : Confirm that real writes should be executed}', function (
    ProspectCustomerMigrationService $migrationService
): int {
    if (! $this->option('force')) {
        $this->warn('Use --force to execute the real prospect migration.');

        return 1;
    }

    $accountIdOption = $this->option('account_id');
    $accountId = is_numeric($accountIdOption) ? (int) $accountIdOption : null;
    $chunkSize = max(1, (int) $this->option('chunk'));

    $summary = $migrationService->execute($accountId, $chunkSize);

    $this->info('Prospect migration completed');
    $this->line('Scope: '.($accountId ? 'account '.$accountId : 'all accounts'));
    $this->line('Batch: '.(string) ($summary['batch_id'] ?? 'n/a'));
    $this->line('Scanned customers: '.(int) ($summary['scanned'] ?? 0));
    $this->line('Eligible customers: '.(int) ($summary['eligible_count'] ?? 0));
    $this->line('Migrated customers: '.(int) ($summary['migrated_count'] ?? 0));
    $this->line('Created prospects: '.(int) ($summary['created_prospects_count'] ?? 0));
    $this->line('Existing requests detached: '.(int) ($summary['reclassified_existing_requests_count'] ?? 0));
    $this->line('Rewired quotes: '.(int) ($summary['rewired_quotes_count'] ?? 0));
    $this->line('Failed customers: '.(int) ($summary['failed_count'] ?? 0));
    $this->line('Summary journal: '.(string) ($summary['summary_path'] ?? 'n/a'));
    $this->line('Mappings CSV: '.(string) ($summary['mapping_path'] ?? 'n/a'));

    if (! empty($summary['failures'])) {
        $this->newLine();
        $this->line('Failures:');

        foreach ($summary['failures'] as $failure) {
            $this->line(sprintf(
                '- #%d %s [%s] %s',
                (int) ($failure['customer_id'] ?? 0),
                (string) ($failure['customer_name'] ?? 'Customer'),
                (string) ($failure['reason'] ?? 'eligible'),
                (string) ($failure['message'] ?? 'Unknown error')
            ));
        }
    }

    return (int) ($summary['failed_count'] ?? 0) > 0 ? 2 : 0;
})->purpose('Execute the real prospect migration and write a per-batch journal for rollback and verification');

Artisan::command('prospects:migration-verify
    {--account_id= : Optional tenant account id to verify}
    {--batch_id= : Optional migration batch id to verify}
    {--sample=10 : Maximum number of sample rows to print per segment}', function (
    ProspectCustomerMigrationVerificationService $verificationService
): int {
    $accountIdOption = $this->option('account_id');
    $accountId = is_numeric($accountIdOption) ? (int) $accountIdOption : null;
    $batchId = trim((string) ($this->option('batch_id') ?? '')) ?: null;
    $sampleLimit = max(1, (int) $this->option('sample'));

    try {
        $report = $verificationService->verify($batchId, $accountId, $sampleLimit);
    } catch (\RuntimeException $exception) {
        $this->error($exception->getMessage());

        return 1;
    }

    $this->info('Prospect migration verification');
    $this->line('Source batch: '.(string) ($report['batch_id'] ?? 'n/a'));
    $this->line('Scope: '.($report['account_id'] ? 'account '.$report['account_id'] : 'all accounts'));
    $this->line('Migrated customers checked: '.(int) ($report['migrated_customers_checked'] ?? 0));
    $this->line('Verified customers: '.(int) ($report['verified_customers'] ?? 0));
    $this->line('Customers with issues: '.(int) ($report['customers_with_issues'] ?? 0));
    $this->line('Source migration failures: '.(int) ($report['source_failed_count'] ?? 0));
    $this->line('Remaining eligible customers: '.(int) ($report['remaining_eligible_count'] ?? 0));
    $this->line('Remaining ambiguous / a qualifier: '.(int) ($report['remaining_ambiguous_count'] ?? 0));
    $this->line('Verification report: '.(string) ($report['report_path'] ?? 'n/a'));
    $this->line('Segments CSV: '.(string) ($report['segment_path'] ?? 'n/a'));

    if (! empty($report['issue_counts'])) {
        $this->newLine();
        $this->line('Issue counts:');

        foreach ((array) $report['issue_counts'] as $issueCode => $count) {
            $this->line('- '.$issueCode.': '.$count);
        }
    }

    if (! empty($report['source_failure_samples'])) {
        $this->newLine();
        $this->line('Source migration failures:');

        foreach ((array) $report['source_failure_samples'] as $failure) {
            $this->line(sprintf(
                '- #%d %s [%s] %s',
                (int) ($failure['customer_id'] ?? 0),
                (string) ($failure['customer_name'] ?? 'Customer'),
                (string) ($failure['reason'] ?? 'eligible'),
                (string) ($failure['message'] ?? 'Unknown error')
            ));
        }
    }

    if (! empty($report['consistency_issue_samples'])) {
        $this->newLine();
        $this->line('Consistency issues:');

        foreach ((array) $report['consistency_issue_samples'] as $issue) {
            $this->line(sprintf(
                '- #%d %s [%s] %s',
                (int) ($issue['legacy_customer_id'] ?? 0),
                (string) ($issue['customer_name'] ?? 'Customer'),
                (string) ($issue['reason'] ?? 'migration'),
                implode(', ', (array) ($issue['issue_codes'] ?? []))
            ));
        }
    }

    if (! empty($report['remaining_eligible_samples'])) {
        $this->newLine();
        $this->line('Remaining eligible samples:');

        foreach ((array) $report['remaining_eligible_samples'] as $sample) {
            $signals = collect($sample['signals'] ?? [])
                ->map(fn ($value, $key) => $key.'='.(is_bool($value) ? ($value ? 'true' : 'false') : $value))
                ->implode(', ');

            $this->line(sprintf(
                '- #%d %s [%s] %s',
                (int) ($sample['id'] ?? 0),
                (string) ($sample['name'] ?? 'Customer'),
                (string) ($sample['reason'] ?? 'eligible'),
                $signals
            ));
        }
    }

    if (! empty($report['qualification_samples'])) {
        $this->newLine();
        $this->line('Qualification samples:');

        foreach ((array) $report['qualification_samples'] as $sample) {
            $signals = collect($sample['signals'] ?? [])
                ->map(fn ($value, $key) => $key.'='.(is_bool($value) ? ($value ? 'true' : 'false') : $value))
                ->implode(', ');

            $this->line(sprintf(
                '- #%d %s [%s] %s',
                (int) ($sample['id'] ?? 0),
                (string) ($sample['name'] ?? 'Customer'),
                (string) ($sample['reason'] ?? 'ambiguous'),
                $signals
            ));
        }
    }

    return (
        (int) ($report['customers_with_issues'] ?? 0) > 0
        || (int) ($report['source_failed_count'] ?? 0) > 0
        || (int) ($report['remaining_eligible_count'] ?? 0) > 0
    ) ? 2 : 0;
})->purpose('Verify a migration batch, surface remaining legacy segments, and write a post-migration validation report');

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

Artisan::command('campaigns:automations {--account_id=}', function (CampaignAutomationService $automationService): int {
    $accountId = $this->option('account_id');
    $result = $automationService->process($accountId ? (int) $accountId : null);

    $this->info('Campaign automations processed: '.json_encode($result));

    return 0;
})->purpose('Process active marketing automation rules');

Artisan::command('campaigns:vip-auto-sync {--account_id=} {--dry-run}', function (VipService $vipService): int {
    $accountId = $this->option('account_id');
    $dryRun = (bool) $this->option('dry-run');

    $result = $vipService->runAutomationForTenants(
        $accountId ? (int) $accountId : null,
        $dryRun
    );

    $this->info('VIP automation processed: '.json_encode($result));

    return 0;
})->purpose('Synchronize VIP status automatically from paid purchases');

Artisan::command('campaigns:interest-scores {--account_id=}', function (): int {
    $accountId = $this->option('account_id');
    ComputeInterestScoresJob::dispatch($accountId ? (int) $accountId : null)
        ->onQueue((string) config('campaigns.queues.maintenance', 'campaigns-maintenance'));

    $this->info('Customer interest score recomputation queued.');

    return 0;
})->purpose('Queue interest score recomputation');

Artisan::command('campaigns:reconcile-delivery', function (): int {
    ReconcileDeliveryReportsJob::dispatch()
        ->onQueue((string) config('campaigns.queues.maintenance', 'campaigns-maintenance'));

    $this->info('Campaign delivery reconciliation queued.');

    return 0;
})->purpose('Queue campaign delivery status reconciliation');

Artisan::command('demo:seed {type=service} {--tenant_id=}', function (): int {
    $this->warn('Legacy demo CLI seeding is disabled.');
    $this->line('Use Super Admin > Demo Workspaces to provision a demo tenant.');
    $this->line('Use app:launch-reset only for the minimal platform baseline.');

    return 1;
})->purpose('Deprecated: use the Demo Workspace module instead of legacy demo seeding');

Artisan::command('demo:reset {--tenant_id=}', function (): int {
    $this->warn('Legacy demo CLI reset is disabled.');
    $this->line('Reset and reprovision demos from Super Admin > Demo Workspaces.');

    return 1;
})->purpose('Deprecated: reset demo tenants from the Demo Workspace module');

Artisan::command('demo:purge-expired', function (DemoWorkspacePurgeService $purgeService): int {
    $count = $purgeService->purgeExpired();

    $this->info("Purged {$count} expired demo workspace(s).");

    return 0;
})->purpose('Delete expired demo workspaces and their tenant data');

Artisan::command('reservations:notifications', function (ReservationNotificationService $notificationService): int {
    $result = $notificationService->processScheduledNotifications();

    $reminders = (int) ($result['reminders_sent'] ?? 0);
    $reviews = (int) ($result['review_requests_sent'] ?? 0);

    $this->info("Reservation notifications processed. reminders={$reminders}, reviews={$reviews}");

    return 0;
})->purpose('Send reservation reminders and review requests');

Artisan::command('reservations:queue-alerts', function (
    ReservationQueueService $queueService,
    ReservationAvailabilityService $availabilityService
): int {
    $accountIds = ReservationSetting::query()
        ->whereNull('team_member_id')
        ->where('business_preset', 'salon')
        ->where('queue_mode_enabled', true)
        ->pluck('account_id')
        ->map(fn ($id) => (int) $id)
        ->filter(fn (int $id) => $id > 0)
        ->unique()
        ->values();

    $processed = 0;
    foreach ($accountIds as $accountId) {
        $settings = $availabilityService->resolveSettings((int) $accountId, null);
        if (! ($settings['queue_mode_enabled'] ?? false)) {
            continue;
        }

        $queueService->refreshMetrics((int) $accountId, $settings);
        $processed += 1;
    }

    $this->info("Queue alerts processed for {$processed} account(s).");

    return 0;
})->purpose('Refresh queue metrics and dispatch queue ETA alerts');

Artisan::command('notifications:retry-failed
    {--notification=App\\Notifications\\InviteUserNotification : Fully-qualified notification class filter}
    {--max=25 : Maximum failed jobs to retry in one run}
    {--within-hours=24 : Only retry jobs failed within this time window}
    {--cooldown=30 : Cooldown (minutes) by payload fingerprint before a new retry}
    {--all-errors : Retry even for non-transient errors}
    {--dry-run : Show eligible jobs without retrying}', function (): int {
    if (! Schema::hasTable('failed_jobs')) {
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
        if (! str_contains($payload, $sendQueuedRaw) && ! str_contains($payload, $sendQueuedEscaped)) {
            continue;
        }

        if ($notificationClass !== '') {
            $rawNeedle = $notificationClass;
            $escapedNeedle = str_replace('\\', '\\\\', $notificationClass);
            if (! str_contains($payload, $rawNeedle) && ! str_contains($payload, $escapedNeedle)) {
                continue;
            }
        }

        $exception = strtolower((string) $job->exception);
        $isTransient = collect($transientMarkers)->contains(
            fn (string $marker) => str_contains($exception, $marker)
        );

        if (! $allErrors && ! $isTransient) {
            continue;
        }

        $fingerprint = sha1((string) $job->payload);
        $lockKey = 'notifications:failed-retry:'.$fingerprint;
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
        $this->info('Dry run: '.$eligible->count().' failed notification jobs are eligible for retry.');
        foreach ($eligible as $job) {
            $this->line("- id={$job->id} uuid={$job->uuid} failed_at={$job->failed_at}");
        }

        return 0;
    }

    $retried = 0;
    $failedToRetry = 0;
    foreach ($eligible as $job) {
        $lockKey = 'notifications:failed-retry:'.$job->fingerprint;
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
    if (! (bool) $this->option('force')) {
        $confirmed = $this->confirm(
            'This will run migrate:fresh, seed only the minimal platform baseline (without demo companies), clear caches, and optimize. Continue?',
            false
        );
        if (! $confirmed) {
            $this->warn('Operation cancelled.');

            return 1;
        }
    }

    $steps = [
        ['migrate:fresh', ['--force' => true], 'Database refreshed.'],
        ['db:seed', ['--class' => LaunchResetSeeder::class, '--force' => true], 'Minimal launch baseline seeded.'],
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
    $this->line('No demo company was created. Use the Demo Workspace module for tenant provisioning.');

    return 0;
})->purpose('Reset database with the minimal platform baseline, clear caches, and optimize');

Artisan::command('queue:health {--json}', function (QueueHealthService $queueHealth): int {
    $summary = $queueHealth->summary();

    if ((bool) $this->option('json')) {
        $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return 0;
    }

    $this->info(sprintf(
        'Queue connection: %s (%s)',
        (string) ($summary['connection'] ?? 'unknown'),
        (string) ($summary['driver'] ?? 'unknown')
    ));
    $this->line('Pending jobs: '.(int) ($summary['pending_jobs'] ?? 0));
    $this->line('Oldest queued job (minutes): '.(($summary['oldest_job_minutes'] ?? null) ?? 'n/a'));
    $this->line('Failed jobs (24h): '.(int) ($summary['failed_jobs_24h'] ?? 0));
    $this->line('Failed jobs (7d): '.(int) ($summary['failed_jobs_7d'] ?? 0));

    $pendingByQueue = is_array($summary['pending_by_queue'] ?? null)
        ? $summary['pending_by_queue']
        : [];

    if ($pendingByQueue !== []) {
        $rows = collect($pendingByQueue)
            ->map(fn ($count, $queue) => [(string) $queue, (int) $count])
            ->values()
            ->all();

        $this->table(['Queue', 'Pending'], $rows);
    } elseif (! ($summary['measurable'] ?? false)) {
        $this->warn('Pending backlog is not measurable for the current queue driver.');
    }

    return 0;
})->purpose('Show queue backlog and failed job health');

Artisan::command('observability:report {--json} {--notify}', function (
    ObservabilityReportService $observability,
    PlatformAdminNotifier $notifier
): int {
    $summary = $observability->summary();

    if ((bool) $this->option('notify')) {
        $referenceBucket = now()->format('YmdH');

        foreach ($summary['alerts'] ?? [] as $alert) {
            $details = is_array($alert['details'] ?? null) ? $alert['details'] : [];
            $notifier->notify('operational_health', (string) ($alert['title'] ?? 'Operational alert'), [
                'intro' => (string) ($alert['message'] ?? 'Operational threshold exceeded.'),
                'details' => $details,
                'severity' => (string) ($alert['severity'] ?? 'warning'),
                'reference' => 'observability:'.($alert['code'] ?? 'unknown').':'.$referenceBucket,
            ]);
        }
    }

    if ((bool) $this->option('json')) {
        $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return 0;
    }

    $this->info('Observability status: '.(string) ($summary['status'] ?? 'unknown'));
    $this->line('Queue pending jobs: '.(int) data_get($summary, 'queue.pending_jobs', 0));
    $this->line('Queue oldest job (minutes): '.(data_get($summary, 'queue.oldest_job_minutes') ?? 'n/a'));
    $this->line('Slow queries (24h): '.(int) data_get($summary, 'slow_queries.count_24h', 0));
    $this->line('Errors (1h): '.(int) data_get($summary, 'errors.count_1h', 0));
    $this->line('Errors (24h): '.(int) data_get($summary, 'errors.count_24h', 0));

    $requestRows = collect($summary['requests'] ?? [])
        ->take(5)
        ->map(fn (array $route) => [
            (string) ($route['route_name'] ?? 'unknown'),
            $route['p95_ms'] ?? 'n/a',
            $route['p99_ms'] ?? 'n/a',
            (int) ($route['count_24h'] ?? 0),
            (int) ($route['error_count_24h'] ?? 0),
        ])
        ->all();

    if ($requestRows !== []) {
        $this->table(['Route', 'p95 ms', 'p99 ms', 'Samples', '5xx'], $requestRows);
    }

    $alerts = collect($summary['alerts'] ?? [])
        ->map(fn (array $alert) => [
            (string) ($alert['severity'] ?? 'warning'),
            (string) ($alert['code'] ?? 'unknown'),
            (string) ($alert['title'] ?? 'Alert'),
        ])
        ->all();

    if ($alerts !== []) {
        $this->table(['Severity', 'Code', 'Title'], $alerts);
    } else {
        $this->info('No active observability alerts.');
    }

    return 0;
})->purpose('Show observability summary and optionally notify platform admins');

Artisan::command('capacity:report {--json} {--notify}', function (
    CapacityReportService $capacity,
    PlatformAdminNotifier $notifier
): int {
    $summary = $capacity->summary();

    if ((bool) $this->option('notify')) {
        $referenceBucket = now()->format('YmdH');

        foreach ($summary['scenarios'] ?? [] as $scenario) {
            if (! is_array($scenario) || ! in_array($scenario['status'] ?? null, ['fail', 'insufficient_data'], true)) {
                continue;
            }

            $details = [
                ['label' => 'Scenario', 'value' => (string) ($scenario['label'] ?? 'Unknown scenario')],
                ['label' => 'Routes', 'value' => implode(', ', $scenario['route_names'] ?? [])],
                ['label' => 'Samples', 'value' => (int) data_get($scenario, 'observed.count_24h', 0)],
                ['label' => 'p95', 'value' => data_get($scenario, 'observed.p95_ms') ?? 'n/a'],
                ['label' => 'p99', 'value' => data_get($scenario, 'observed.p99_ms') ?? 'n/a'],
            ];

            $notifier->notify('operational_health', 'Capacity validation: '.(string) ($scenario['label'] ?? 'scenario'), [
                'intro' => collect($scenario['failures'] ?? [])->implode(' '),
                'details' => $details,
                'severity' => ($scenario['status'] ?? null) === 'fail' ? 'warning' : 'info',
                'reference' => 'capacity:scenario:'.($scenario['key'] ?? 'unknown').':'.$referenceBucket,
            ]);
        }

        foreach ($summary['shared_checks'] ?? [] as $check) {
            if (! is_array($check) || ($check['status'] ?? null) !== 'fail') {
                continue;
            }

            $notifier->notify('operational_health', 'Capacity risk: '.(string) ($check['label'] ?? 'shared check'), [
                'intro' => (string) ($check['remediation'] ?? 'Shared capacity constraint exceeded.'),
                'details' => [
                    ['label' => 'Check', 'value' => (string) ($check['label'] ?? 'unknown')],
                    ['label' => 'Observed', 'value' => $check['observed'] ?? 'n/a'],
                    ['label' => 'Target', 'value' => $check['target'] ?? 'n/a'],
                ],
                'severity' => 'warning',
                'reference' => 'capacity:shared:'.($check['key'] ?? 'unknown').':'.$referenceBucket,
            ]);
        }
    }

    if ((bool) $this->option('json')) {
        $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return 0;
    }

    $this->info('Capacity validation status: '.(string) ($summary['status'] ?? 'unknown'));

    $scenarioRows = collect($summary['scenarios'] ?? [])
        ->map(fn (array $scenario) => [
            (string) ($scenario['label'] ?? 'unknown'),
            implode(', ', $scenario['route_names'] ?? []),
            (string) ($scenario['status'] ?? 'unknown'),
            (int) data_get($scenario, 'observed.count_24h', 0),
            data_get($scenario, 'observed.p95_ms') ?? 'n/a',
            data_get($scenario, 'observed.p99_ms') ?? 'n/a',
            (int) data_get($scenario, 'observed.error_count_24h', 0),
        ])
        ->all();

    if ($scenarioRows !== []) {
        $this->table(['Scenario', 'Routes', 'Status', 'Samples', 'p95 ms', 'p99 ms', '5xx'], $scenarioRows);
    }

    $sharedRows = collect($summary['shared_checks'] ?? [])
        ->map(fn (array $check) => [
            (string) ($check['label'] ?? 'unknown'),
            (string) ($check['status'] ?? 'unknown'),
            $check['observed'] ?? 'n/a',
            $check['target'] ?? 'n/a',
        ])
        ->all();

    if ($sharedRows !== []) {
        $this->table(['Shared check', 'Status', 'Observed', 'Target'], $sharedRows);
    }

    $remediation = collect($summary['remediation'] ?? [])
        ->filter(fn ($item) => is_string($item) && trim($item) !== '')
        ->values();

    if ($remediation->isNotEmpty()) {
        $this->newLine();
        $this->info('Remediation priorities:');
        foreach ($remediation as $item) {
            $this->line('- '.$item);
        }
    } else {
        $this->info('No active capacity remediation items.');
    }

    return 0;
})->purpose('Show scenario-based capacity validation and optionally notify platform admins');

Artisan::command('schema:audit-selects {--json}', function (ManualSelectContractAudit $audit): int {
    $failures = $audit->run();
    $payload = [
        'ok' => $failures === [],
        'checked' => $audit->contractCount(),
        'failures' => $failures,
    ];

    if ((bool) $this->option('json')) {
        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $failures === [] ? 0 : 1;
    }

    if ($failures === []) {
        $this->info('Manual select schema audit passed.');
        $this->line('Checked '.$audit->contractCount().' select contracts.');

        return 0;
    }

    $this->error('Manual select schema audit failed.');
    $this->table(
        ['Contract', 'Table', 'Missing columns'],
        collect($failures)
            ->map(fn (array $failure) => [
                (string) ($failure['contract'] ?? 'unknown'),
                (string) ($failure['table'] ?? 'unknown'),
                implode(', ', $failure['missing'] ?? []),
            ])
            ->all()
    );

    return 1;
})->purpose('Verify curated manual select contracts against the live database schema');

Artisan::command('public-copy:sync {--only=* : Targets to sync (pages, welcome, footer, all)} {--user= : Optional user id used for updated_by metadata}', function (PublicCopySyncService $service): int {
    $userOption = $this->option('user');
    $userId = null;

    if ($userOption !== null && $userOption !== '') {
        if (! is_numeric($userOption)) {
            $this->error('The --user option must be a numeric user id.');

            return 1;
        }

        $userId = (int) $userOption;
    }

    try {
        $summary = $service->sync((array) $this->option('only'), $userId);
    } catch (\Throwable $e) {
        $this->error($e->getMessage());

        return 1;
    }

    $this->info('Public copy synchronized from repo source files.');
    $this->line('Targets: '.implode(', ', $summary['targets'] ?? []));
    $this->line('User id: '.(($summary['user_id'] ?? null) !== null ? (string) $summary['user_id'] : 'none'));

    if (is_array($summary['pages'] ?? null)) {
        $this->line(sprintf(
            'Pages: %d product, %d industry, %d solution, %d menus, contact page "%s".',
            (int) ($summary['pages']['product_pages'] ?? 0),
            (int) ($summary['pages']['industry_pages'] ?? 0),
            (int) ($summary['pages']['solution_pages'] ?? 0),
            (int) ($summary['pages']['menus'] ?? 0),
            (string) ($summary['pages']['contact_page'] ?? '')
        ));
    }

    if (is_array($summary['welcome'] ?? null)) {
        $this->line(sprintf(
            'Welcome: page #%d, source sections [%s].',
            (int) ($summary['welcome']['page_id'] ?? 0),
            implode(', ', array_map('strval', $summary['welcome']['source_section_ids'] ?? []))
        ));
    }

    if (is_array($summary['footer'] ?? null)) {
        $this->line(sprintf(
            'Footer: section #%d for locales [%s].',
            (int) ($summary['footer']['section_id'] ?? 0),
            implode(', ', $summary['footer']['locales'] ?? [])
        ));
    }

    return 0;
})->purpose('Rewrite public-facing database copy from the repository source content');

Artisan::command('expenses:generate-recurring {--account= : Optional account owner id scope}', function (ExpenseRecurringService $service): int {
    $accountOption = $this->option('account');
    $accountId = null;

    if ($accountOption !== null && $accountOption !== '') {
        if (! is_numeric($accountOption)) {
            $this->error('The --account option must be numeric.');

            return 1;
        }

        $accountId = (int) $accountOption;
    }

    $summary = $service->generateDueExpenses($accountId);

    $this->info(sprintf(
        'Generated %d recurring expense(s); updated %d template(s).',
        (int) ($summary['generated'] ?? 0),
        (int) ($summary['updated_templates'] ?? 0),
    ));

    return 0;
})->purpose('Generate due expenses from recurring expense templates');

Artisan::command('playbooks:run-scheduled {--account_id= : Optional account owner id scope}', function (\App\Services\Playbooks\PlaybookSchedulerService $schedulerService): int {
    $accountOption = $this->option('account_id');
    $accountId = null;

    if (is_numeric($accountOption)) {
        $accountId = (int) $accountOption;
    }

    $summary = $schedulerService->runDue($accountId);

    $this->info(sprintf(
        'Checked %d playbook(s); reserved %d; executed %d; failed %d; overlap skips %d.',
        (int) ($summary['checked_count'] ?? 0),
        (int) ($summary['reserved_count'] ?? 0),
        (int) ($summary['executed_count'] ?? 0),
        (int) ($summary['failed_count'] ?? 0),
        (int) ($summary['skipped_overlap_count'] ?? 0),
    ));

    return 0;
})->purpose('Run due scheduled playbooks');

Schedule::command('platform:notifications-digest --frequency=daily')->dailyAt('08:00');
Schedule::command('platform:notifications-digest --frequency=weekly')->weeklyOn(1, '08:00');
Schedule::command('platform:notifications-scan')->dailyAt('07:30');
Schedule::command('agenda:process')->everyFiveMinutes();
Schedule::command('billing:upcoming-reminders')
    ->dailyAt((string) config('billing.upcoming_reminders.time', '09:00'))
    ->withoutOverlapping();
Schedule::command('orders:deposit-reminders')->everyFourHours();
Schedule::command('leads:follow-up-reminders --hours=24')->hourly();
Schedule::command('prospects:follow-up-reminders')->hourlyAt(10)->withoutOverlapping();
Schedule::command('prospects:stale-reminders')->hourlyAt(20)->withoutOverlapping();
Schedule::command('support:sla-reminders')->hourly();
Schedule::command('reservations:notifications')->everyFifteenMinutes();
Schedule::command('reservations:queue-alerts')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('campaigns:automations')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('campaigns:vip-auto-sync')->dailyAt('02:35')->withoutOverlapping();
Schedule::command('campaigns:interest-scores')->dailyAt('02:15');
Schedule::command('campaigns:reconcile-delivery')->everyTenMinutes()->withoutOverlapping();
Schedule::command('playbooks:run-scheduled')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('expenses:generate-recurring')->dailyAt('05:15')->withoutOverlapping();
Schedule::command('demo:purge-expired')->dailyAt('03:10')->withoutOverlapping();
Schedule::command('observability:report --notify')->everyTenMinutes()->withoutOverlapping();
Schedule::command('notifications:retry-failed --notification=App\\Notifications\\InviteUserNotification --max=20 --within-hours=24 --cooldown=30')
    ->everyTenMinutes()
    ->withoutOverlapping();
