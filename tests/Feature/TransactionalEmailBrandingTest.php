<?php

use App\Mail\DemoWorkspaceAccessMail;
use App\Models\Customer;
use App\Models\MarketingSetting;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Models\Role;
use App\Models\Task;
use App\Models\TeamMember;
use App\Models\User;
use App\Notifications\ActionEmailNotification;
use App\Notifications\InviteUserNotification;
use App\Notifications\LeadCallRequestReceivedNotification;
use App\Notifications\LeadFollowUpNotification;
use App\Notifications\LeadFormOwnerNotification;
use App\Notifications\LeadQuoteRequestReceivedNotification;
use App\Notifications\PlatformAdminDigestNotification;
use App\Notifications\ProspectFollowUpReminderNotification;
use App\Notifications\ResetPasswordLinkNotification;
use App\Notifications\SupplierBulkStockRequestNotification;
use App\Notifications\SupplierStockRequestNotification;
use App\Notifications\TwoFactorCodeNotification;
use App\Notifications\UpcomingBillingReminderNotification;
use App\Notifications\WelcomeEmailNotification;
use App\Services\Campaigns\BrandProfileService;
use Illuminate\Notifications\AnonymousNotifiable;

test('transactional email layout keeps tenant text dominant with a localized platform fallback', function (string $locale, string $attribution) {
    app()->setLocale($locale);

    $html = view('emails.notifications.action', [
        'companyName' => 'Entreprise Sans Logo',
        'companyLogo' => null,
        'title' => 'Mise à jour',
        'intro' => 'Un résumé est disponible.',
        'details' => [],
        'actionUrl' => null,
    ])->render();

    expect($html)->toContain('Entreprise Sans Logo')
        ->and($html)->toContain('/brand/bimi-logo.svg')
        ->and($html)->toContain(__('mail.layout.platform_logo_alt', ['platform' => 'Malikia Pro']))
        ->and(substr_count($html, $attribution))->toBe(1)
        ->and($html)->not->toContain(__('mail.layout.platform_tagline'));
})->with([
    'french' => ['fr', 'Propulse par Malikia Pro'],
    'english' => ['en', 'Powered by Malikia Pro'],
    'spanish' => ['es', 'Impulsado por Malikia Pro'],
]);

test('tenant transactional email uses its primary color for brand accents and readable actions', function () {
    $html = view('emails.notifications.action', [
        'companyName' => 'Entreprise Soleil',
        'companyLogo' => null,
        'companyPrimaryColor' => '#FACC15',
        'companyPrimaryForegroundColor' => '#111827',
        'title' => 'Mise à jour',
        'intro' => 'Un résumé est disponible.',
        'details' => [],
        'actionUrl' => 'https://app.example.test/action',
        'actionLabel' => 'Continuer',
    ])->render();

    expect($html)->toContain('border-top:4px solid #FACC15')
        ->and($html)->toContain('background-color:#FACC15')
        ->and($html)->toContain('bgcolor="#FACC15"')
        ->and($html)->toContain('color:#111827');
});

test('supplier emails use the account owner branding when sent by an employee', function () {
    $owner = User::factory()->create([
        'company_name' => 'Marché Boréal',
        'company_logo' => 'https://assets.example.test/marche-boreal.png',
        'company_branding_settings' => ['primary_color' => '#FACC15'],
    ]);
    $employeeRole = Role::query()->firstOrCreate(
        ['name' => 'employee'],
        ['description' => 'Employee role']
    );
    $employee = User::factory()->create([
        'role_id' => $employeeRole->id,
        'company_name' => null,
        'company_logo' => null,
    ]);
    TeamMember::query()->create([
        'account_id' => $owner->id,
        'user_id' => $employee->id,
        'role' => 'employee',
        'permissions' => ['manage_products'],
        'is_active' => true,
    ]);
    $category = ProductCategory::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'name' => 'Produits tests',
    ]);
    $product = Product::query()->create([
        'user_id' => $owner->id,
        'category_id' => $category->id,
        'name' => 'Produit test',
        'item_type' => Product::ITEM_TYPE_PRODUCT,
        'tracking_type' => 'none',
        'price' => 10,
        'stock' => 1,
        'minimum_stock' => 5,
    ]);

    $notifications = [
        new SupplierStockRequestNotification($product, $employee),
        new SupplierBulkStockRequestNotification(collect([$product]), $employee),
    ];

    foreach ($notifications as $notification) {
        $mail = $notification->toMail($owner);

        expect($mail->viewData['companyName'])->toBe('Marché Boréal')
            ->and($mail->viewData['companyLogo'])->toBe('https://assets.example.test/marche-boreal.png')
            ->and($mail->viewData['companyPrimaryColor'])->toBe('#FACC15')
            ->and($mail->viewData['companyPrimaryForegroundColor'])->toBe('#111827')
            ->and($mail->replyTo)->toBe([[$owner->email, 'Marché Boréal']]);
    }
});

test('authentication emails use tenant branding for employees and platform branding for administrators', function () {
    $owner = User::factory()->create([
        'company_name' => 'Maison Boréale',
        'company_logo' => 'https://assets.example.test/maison-boreale.png',
        'company_branding_settings' => ['primary_color' => '#2563EB'],
    ]);
    $employeeRole = Role::query()->firstOrCreate(
        ['name' => 'employee'],
        ['description' => 'Employee role']
    );
    $employee = User::factory()->create([
        'role_id' => $employeeRole->id,
        'company_name' => null,
        'company_logo' => null,
    ]);
    TeamMember::query()->create([
        'account_id' => $owner->id,
        'user_id' => $employee->id,
        'role' => 'employee',
        'permissions' => [],
        'is_active' => true,
    ]);

    $tenantNotifications = [
        new ResetPasswordLinkNotification('tenant-token'),
        new TwoFactorCodeNotification('123456', now()->addMinutes(10)),
    ];

    foreach ($tenantNotifications as $notification) {
        $mail = $notification->toMail($employee);

        expect($mail->viewData['companyName'])->toBe('Maison Boréale')
            ->and($mail->viewData['companyLogo'])->toBe('https://assets.example.test/maison-boreale.png')
            ->and($mail->viewData['companyPrimaryColor'])->toBe('#2563EB')
            ->and($mail->viewData['companyPrimaryForegroundColor'])->toBe('#FFFFFF');
    }

    $adminRole = Role::query()->firstOrCreate(
        ['name' => 'admin'],
        ['description' => 'Platform admin role']
    );
    $platformAdmin = User::factory()->create([
        'role_id' => $adminRole->id,
        'company_name' => 'Internal Operations',
        'company_logo' => 'https://assets.example.test/internal-only.png',
    ]);

    foreach ($tenantNotifications as $notification) {
        $mail = $notification->toMail($platformAdmin);

        expect($mail->viewData['companyName'])->toBe(config('app.name'))
            ->and($mail->viewData['companyLogo'])->toBeNull()
            ->and($mail->viewData['companyPrimaryColor'])->toBeNull()
            ->and($mail->viewData['companyPrimaryForegroundColor'])->toBeNull();
    }
});

test('routed action emails and invitations keep explicit account owner branding', function () {
    $owner = User::factory()->create([
        'company_name' => 'Maison des Rendez-vous',
        'company_logo' => 'https://assets.example.test/maison-rendez-vous.png',
        'company_branding_settings' => ['primary_color' => '#7C3AED'],
    ]);
    $recipient = User::factory()->create();

    $actionMail = (new ActionEmailNotification(
        title: 'Reservation confirmed',
        accountOwnerId: $owner->id,
    ))->toMail(new AnonymousNotifiable);
    $inviteMail = (new InviteUserNotification(
        token: 'invite-token',
        companyName: 'Stale Company Name',
        companyLogo: 'customers/customer.png',
        context: 'team',
        accountOwnerId: $owner->id,
    ))->toMail($recipient);

    foreach ([$actionMail, $inviteMail] as $mail) {
        expect($mail->viewData['companyName'])->toBe('Maison des Rendez-vous')
            ->and($mail->viewData['companyLogo'])->toBe('https://assets.example.test/maison-rendez-vous.png')
            ->and($mail->viewData['companyPrimaryColor'])->toBe('#7C3AED')
            ->and($mail->viewData['companyPrimaryForegroundColor'])->toBe('#FFFFFF');
    }
});

test('platform emails ignore tenant branding snapshots after queue serialization', function () {
    app()->setLocale('en');
    config(['app.name' => 'Malikia Pro']);

    $recipient = User::factory()->create([
        'company_name' => 'Tenant That Must Stay Secondary',
        'company_logo' => 'https://assets.example.test/tenant-that-must-stay-secondary.png',
    ]);
    $staleTenantLogo = 'https://assets.example.test/stale-tenant.png';

    $billingReminder = new UpcomingBillingReminderNotification([
        'companyName' => 'Stale Tenant Snapshot',
        'companyLogo' => $staleTenantLogo,
        'companyPrimaryColor' => '#EF4444',
        'companyPrimaryForegroundColor' => '#FFFFFF',
        'recipientName' => $recipient->name,
        'billingDate' => '2026-09-01',
        'billingDateLabel' => 'Sep 1, 2026',
        'daysUntilBilling' => 14,
        'planName' => 'Team',
        'billingPeriod' => 'monthly',
        'seatQuantity' => 3,
        'currencyCode' => 'CAD',
        'formattedTotal' => '$100.00',
        'formattedSubtotal' => '$90.00',
        'formattedTax' => '$10.00',
        'lineItems' => [],
        'manageBillingUrl' => 'https://app.example.test/settings/billing',
        'supportEmail' => 'support@example.test',
    ]);

    $notifications = [
        new PlatformAdminDigestNotification('daily', []),
        $billingReminder,
        new ActionEmailNotification(
            title: 'Platform support update',
            platformBranding: true,
        ),
    ];

    foreach ($notifications as $notification) {
        $restored = unserialize(serialize($notification));
        $mail = $restored->toMail($recipient);
        $view = is_array($mail->view) ? $mail->view[0] : $mail->view;
        $html = view($view, $mail->viewData)->render();

        expect($mail->viewData['companyName'])->toBe('Malikia Pro')
            ->and($mail->viewData['companyLogo'])->toBeNull()
            ->and($mail->viewData['companyPrimaryColor'])->toBeNull()
            ->and($mail->viewData['companyPrimaryForegroundColor'])->toBeNull()
            ->and($mail->viewData['showPoweredBy'])->toBeFalse()
            ->and($html)->toContain('/brand/bimi-logo.svg')
            ->and($html)->not->toContain($staleTenantLogo)
            ->and($html)->not->toContain('#EF4444')
            ->and(substr_count($html, __('mail.layout.powered_by', ['platform' => 'Malikia Pro'])))->toBe(0);
    }

    $billingMail = unserialize(serialize($billingReminder))->toMail($recipient);
    expect($billingMail->viewData['billingCompanyName'])->toBe('Stale Tenant Snapshot');
});

test('demo workspace access preserves prospect branding after queue serialization', function () {
    app()->setLocale('en');
    config(['app.name' => 'Malikia Pro']);

    $demoMail = unserialize(serialize(new DemoWorkspaceAccessMail(
        companyName: 'Prospect Demo Brand',
        companyLogo: 'https://assets.example.test/prospect-demo-brand.png',
        recipientName: 'Demo Recipient',
        prospectCompany: 'Prospect Company',
        workspaceName: 'Demo Workspace',
        tagline: 'A guided demo',
        loginUrl: 'https://app.example.test/login',
        accessEmail: 'demo@example.test',
        accessPassword: 'demo-password',
        expiresAt: null,
        templateName: 'Service business',
        moduleLabels: [],
        scenarioLabels: [],
        extraCredentials: [],
        suggestedFlow: 'Review the workspace.',
    )));
    $demoMail->build();
    $demoHtml = view($demoMail->view, $demoMail->viewData)->render();

    expect($demoMail->viewData['companyName'])->toBe('Prospect Demo Brand')
        ->and($demoMail->viewData['companyLogo'])->toBe('https://assets.example.test/prospect-demo-brand.png')
        ->and($demoMail->viewData['showPoweredBy'])->toBeTrue()
        ->and($demoHtml)->toContain('https://assets.example.test/prospect-demo-brand.png')
        ->and(substr_count($demoHtml, __('mail.layout.powered_by', ['platform' => 'Malikia Pro'])))->toBe(1);
});

test('legacy queued action and invitation payloads keep safe branding fallbacks', function () {
    $owner = User::factory()->create([
        'company_name' => 'Legacy Workspace',
        'company_logo' => 'https://assets.example.test/legacy-workspace.png',
        'company_branding_settings' => ['primary_color' => '#0F766E'],
    ]);

    $legacyAction = new ActionEmailNotification(title: 'Legacy action');
    $stripActionProperties = Closure::bind(
        static function (ActionEmailNotification $notification): void {
            unset($notification->accountOwnerId, $notification->platformBranding);
        },
        null,
        ActionEmailNotification::class,
    );
    $stripActionProperties($legacyAction);

    $legacyActionMail = unserialize(serialize($legacyAction))->toMail($owner);

    $legacyInvite = new InviteUserNotification(
        token: 'legacy-token',
        companyName: 'Legacy Invite Workspace',
        companyLogo: 'customers/customer.png',
        context: 'team',
    );
    $stripInviteProperties = Closure::bind(
        static function (InviteUserNotification $notification): void {
            unset($notification->accountOwnerId);
        },
        null,
        InviteUserNotification::class,
    );
    $stripInviteProperties($legacyInvite);

    $legacyInviteMail = unserialize(serialize($legacyInvite))->toMail($owner);
    $inviteView = is_array($legacyInviteMail->view) ? $legacyInviteMail->view[0] : $legacyInviteMail->view;
    $inviteHtml = view($inviteView, $legacyInviteMail->viewData)->render();

    expect($legacyActionMail->viewData['companyName'])->toBe('Legacy Workspace')
        ->and($legacyActionMail->viewData['companyLogo'])->toBe('https://assets.example.test/legacy-workspace.png')
        ->and($legacyActionMail->viewData['companyPrimaryColor'])->toBe('#0F766E')
        ->and($legacyActionMail->viewData['companyPrimaryForegroundColor'])->toBe('#FFFFFF')
        ->and($legacyInviteMail->viewData['companyName'])->toBe('Legacy Invite Workspace')
        ->and($legacyInviteMail->viewData['companyPrimaryColor'])->toBeNull()
        ->and($legacyInviteMail->viewData['companyPrimaryForegroundColor'])->toBeNull()
        ->and($inviteHtml)->toContain('/brand/bimi-logo.svg')
        ->and(substr_count($inviteHtml, __('mail.layout.powered_by', ['platform' => 'Malikia Pro'])))->toBe(1);
});

test('crm and onboarding notifications retain workspace branding after serialization', function () {
    $owner = User::factory()->create([
        'company_name' => 'CRM Atelier',
        'company_logo' => 'https://assets.example.test/crm-atelier.png',
        'company_branding_settings' => ['primary_color' => '#0F766E'],
    ]);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $lead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'title' => 'CRM branding lead',
        'contact_name' => 'Lead Contact',
        'contact_email' => 'lead@example.test',
    ]);
    $quote = Quote::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'request_id' => $lead->id,
        'prospect_id' => $lead->id,
        'subtotal' => 125,
        'total' => 125,
        'initial_deposit' => 0,
        'is_fixed' => true,
    ]);
    $task = Task::query()->create([
        'account_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'request_id' => $lead->id,
        'title' => 'Follow up with lead',
        'status' => Task::STATUS_TODO,
        'priority' => Task::PRIORITY_NORMAL,
        'due_date' => now()->toDateString(),
    ]);

    $notifications = [
        new LeadCallRequestReceivedNotification($owner, $lead),
        new LeadQuoteRequestReceivedNotification($owner, $lead, $quote),
        new LeadFollowUpNotification($lead),
        new LeadFormOwnerNotification('lead_quote_created', $lead, $quote),
        new ProspectFollowUpReminderNotification($task),
        new WelcomeEmailNotification($owner),
    ];

    foreach ($notifications as $notification) {
        $mail = unserialize(serialize($notification))->toMail($owner);

        expect($mail->viewData['companyName'])->toBe('CRM Atelier')
            ->and($mail->viewData['companyLogo'])->toBe('https://assets.example.test/crm-atelier.png')
            ->and($mail->viewData['companyPrimaryColor'])->toBe('#0F766E')
            ->and($mail->viewData['companyPrimaryForegroundColor'])->toBe('#FFFFFF');
    }
});

test('campaign outreach preserves its configured marketing brand with one platform attribution', function () {
    app()->setLocale('en');

    $owner = User::factory()->create([
        'company_name' => 'Global Workspace Name',
        'company_logo' => 'https://assets.example.test/global-workspace.png',
    ]);
    $settings = MarketingSetting::defaults();
    $settings['templates']['brand_profile']['name'] = 'Campaign Brand';
    $settings['templates']['brand_profile']['logo_url'] = 'https://assets.example.test/campaign-brand.png';
    MarketingSetting::query()->create(array_merge(['user_id' => $owner->id], $settings));

    $tokens = app(BrandProfileService::class)->tokenMap($owner);
    $html = view('emails.customer-bulk-outreach-branded', [
        'companyName' => $tokens['brandName'],
        'companyLogo' => $tokens['brandLogoUrl'],
        'emailTitle' => 'Campaign message',
        'bodyHtml' => '<p>Hello from the campaign.</p>',
        'summaryRows' => [],
    ])->render();

    expect($tokens['brandName'])->toBe('Campaign Brand')
        ->and($tokens['brandLogoUrl'])->toBe('https://assets.example.test/campaign-brand.png')
        ->and($html)->toContain('Campaign Brand')
        ->and($html)->toContain('https://assets.example.test/campaign-brand.png')
        ->and($html)->not->toContain('https://assets.example.test/global-workspace.png')
        ->and($html)->not->toContain('/brand/bimi-logo.svg')
        ->and(substr_count($html, __('mail.layout.powered_by', ['platform' => 'Malikia Pro'])))->toBe(1);
});
