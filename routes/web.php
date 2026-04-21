<?php

use App\Http\Controllers\AccountingController;
use App\Http\Controllers\AiImageController;
use App\Http\Controllers\AssistantController;
use App\Http\Controllers\CampaignAutomationController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\CampaignProspectingController;
use App\Http\Controllers\CampaignRunController;
use App\Http\Controllers\CampaignTrackingController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerPropertyController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DemoController;
use App\Http\Controllers\DemoTourController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\FinanceApprovalInboxController;
use App\Http\Controllers\GlobalSearchController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\LoyaltyController;
use App\Http\Controllers\MarketingDashboardKpiController;
use App\Http\Controllers\MarketingMailingListController;
use App\Http\Controllers\MarketingMetaController;
use App\Http\Controllers\MarketingProspectProviderConnectionController;
use App\Http\Controllers\MarketingSegmentController;
use App\Http\Controllers\MarketingTemplateController;
use App\Http\Controllers\MarketingVipController;
use App\Http\Controllers\MyNextActionsController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OfferSearchController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PerformanceController;
use App\Http\Controllers\PipelineController;
use App\Http\Controllers\PlanningController;
use App\Http\Controllers\PlanScanController;
use App\Http\Controllers\PlaybookController;
use App\Http\Controllers\PlaybookRunController;
use App\Http\Controllers\Portal\PortalInvoiceController;
use App\Http\Controllers\Portal\PortalLoyaltyController;
use App\Http\Controllers\Portal\PortalProductOrderController;
use App\Http\Controllers\Portal\PortalQuoteController;
use App\Http\Controllers\Portal\PortalRatingController;
use App\Http\Controllers\Portal\PortalReviewController;
use App\Http\Controllers\Portal\PortalTaskMediaController;
use App\Http\Controllers\Portal\PortalWorkController;
use App\Http\Controllers\Portal\PortalWorkProofController;
use App\Http\Controllers\PresenceController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductPriceLookupController;
use App\Http\Controllers\ProductsSearchController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicInvoiceController;
use App\Http\Controllers\PublicPageController;
use App\Http\Controllers\PublicQuoteController;
use App\Http\Controllers\PublicRequestController;
use App\Http\Controllers\PublicShowcaseController;
use App\Http\Controllers\PublicStoreController;
use App\Http\Controllers\PublicTaskMediaController;
use App\Http\Controllers\PublicWorkController;
use App\Http\Controllers\PublicWorkProofController;
use App\Http\Controllers\QuoteController;
use App\Http\Controllers\QuoteEmaillingController;
use App\Http\Controllers\RequestController;
use App\Http\Controllers\RequestMediaController;
use App\Http\Controllers\RequestNoteController;
use App\Http\Controllers\Reservation\ClientReservationController;
use App\Http\Controllers\Reservation\PublicKioskReservationController;
use App\Http\Controllers\Reservation\ReservationSettingsController;
use App\Http\Controllers\Reservation\StaffReservationController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\SalePaymentController;
use App\Http\Controllers\SalesActivityController;
use App\Http\Controllers\SalesInboxController;
use App\Http\Controllers\SalesManagerDashboardController;
use App\Http\Controllers\SavedSegmentController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\Settings\ApiTokenController;
use App\Http\Controllers\Settings\BillingSettingsController;
use App\Http\Controllers\Settings\CompanySettingsController;
use App\Http\Controllers\Settings\HrSettingsController;
use App\Http\Controllers\Settings\LoyaltySettingsController;
use App\Http\Controllers\Settings\MarketingSettingsController;
use App\Http\Controllers\Settings\NotificationSettingsController;
use App\Http\Controllers\Settings\ProductCategoryController;
use App\Http\Controllers\Settings\SecuritySettingsController;
use App\Http\Controllers\Settings\SubscriptionController;
use App\Http\Controllers\SuperAdmin\AdminController as SuperAdminAdminController;
use App\Http\Controllers\SuperAdmin\AiImageController as SuperAdminAiImageController;
use App\Http\Controllers\SuperAdmin\AnnouncementController as SuperAdminAnnouncementController;
use App\Http\Controllers\SuperAdmin\DashboardController as SuperAdminDashboardController;
use App\Http\Controllers\SuperAdmin\DemoWorkspaceController as SuperAdminDemoWorkspaceController;
use App\Http\Controllers\SuperAdmin\MegaMenuController as SuperAdminMegaMenuController;
use App\Http\Controllers\SuperAdmin\NotificationController as SuperAdminNotificationController;
use App\Http\Controllers\SuperAdmin\PlatformAssetController as SuperAdminPlatformAssetController;
use App\Http\Controllers\SuperAdmin\PlatformPageController as SuperAdminPlatformPageController;
use App\Http\Controllers\SuperAdmin\PlatformSectionController as SuperAdminPlatformSectionController;
use App\Http\Controllers\SuperAdmin\PlatformSettingsController as SuperAdminPlatformSettingsController;
use App\Http\Controllers\SuperAdmin\SupportTicketController as SuperAdminSupportTicketController;
use App\Http\Controllers\SuperAdmin\SupportTicketMessageController as SuperAdminSupportTicketMessageController;
use App\Http\Controllers\SuperAdmin\TenantController as SuperAdminTenantController;
use App\Http\Controllers\SupportTicketController;
use App\Http\Controllers\SupportTicketMessageController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TaskMediaController;
use App\Http\Controllers\TeamMemberController;
use App\Http\Controllers\TipReportController;
use App\Http\Controllers\WarehouseController;
use App\Http\Controllers\WelcomeController;
use App\Http\Controllers\WorkChecklistController;
use App\Http\Controllers\WorkController;
use App\Http\Controllers\WorkMediaController;
use App\Http\Controllers\WorkProofController;
use App\Http\Controllers\WorkspaceCategoryController;
use App\Http\Middleware\EnsureClientUser;
use App\Http\Middleware\EnsureInternalUser;
use App\Http\Middleware\EnsurePlatformAdmin;
use Illuminate\Support\Facades\Route;

Route::get('/favicon.ico', function () {
    return response()->file(public_path('favicon.ico'));
})->name('favicon');

Route::get('/integrations/prospect-providers/{provider}/callback', [MarketingProspectProviderConnectionController::class, 'oauthCallback'])
    ->whereIn('provider', ['apollo'])
    ->name('marketing.prospect-providers.oauth.callback');

// Paddle webhook is registered by Cashier Paddle at `/{CASHIER_PATH}/webhook`.

// Guest Routes
Route::get('/', [WelcomeController::class, 'index'])->name('welcome');
Route::get('/terms', [LegalController::class, 'terms'])->name('terms');
Route::get('/privacy', [LegalController::class, 'privacy'])->name('privacy');
Route::get('/refund', [LegalController::class, 'refund'])->name('refund');
Route::get('/pricing', [LegalController::class, 'pricing'])->name('pricing');
Route::get('/t/{token}', [CampaignTrackingController::class, 'track'])->name('campaigns.track');
Route::get('/u/{token}', [CampaignTrackingController::class, 'unsubscribe'])->name('campaigns.unsubscribe');
Route::post('/webhooks/campaigns/sms', [CampaignTrackingController::class, 'smsWebhook'])
    ->name('campaigns.webhooks.sms');
Route::post('/webhooks/campaigns/email', [CampaignTrackingController::class, 'emailWebhook'])
    ->name('campaigns.webhooks.email');
Route::get('/pages/{slug}', [PublicPageController::class, 'show'])->name('public.pages.show');
Route::get('/store/{slug}', [PublicStoreController::class, 'show'])->name('public.store.show');
Route::get('/services/{slug}', [PublicShowcaseController::class, 'show'])
    ->where('slug', '^(?!categories$|options$|quick$).+')
    ->name('public.showcase.show');
Route::get('/showcase/{slug}', fn (string $slug) => redirect()->route('public.showcase.show', ['slug' => $slug], 301))
    ->name('public.showcase.legacy');
Route::prefix('/store/{slug}')->group(function () {
    Route::get('/products/{product}/reviews', [PublicStoreController::class, 'reviews'])
        ->name('public.store.product.reviews');
    Route::post('/cart', [PublicStoreController::class, 'addToCart'])->name('public.store.cart.add');
    Route::patch('/cart/{product}', [PublicStoreController::class, 'updateCartItem'])->name('public.store.cart.update');
    Route::delete('/cart/{product}', [PublicStoreController::class, 'removeCartItem'])->name('public.store.cart.remove');
    Route::delete('/cart', [PublicStoreController::class, 'clearCart'])->name('public.store.cart.clear');
    Route::post('/checkout', [PublicStoreController::class, 'checkout'])->name('public.store.checkout');
});

Route::middleware('guest')->group(function () {
    Route::get('/demo', [DemoController::class, 'index'])->name('demo.index');
    Route::post('/demo/login/{type}', [DemoController::class, 'login'])->name('demo.login');
});

Route::middleware(['signed', 'throttle:public-signed'])->group(function () {
    Route::get('/pay/invoices/{invoice}', [PublicInvoiceController::class, 'show'])->name('public.invoices.show');
    Route::post('/pay/invoices/{invoice}', [PublicInvoiceController::class, 'storePayment'])->name('public.invoices.pay');
    Route::post('/pay/invoices/{invoice}/stripe', [PublicInvoiceController::class, 'createStripeCheckout'])
        ->name('public.invoices.stripe');
    Route::get('/public/quotes/{quote}', [PublicQuoteController::class, 'show'])->name('public.quotes.show');
    Route::post('/public/quotes/{quote}/accept', [PublicQuoteController::class, 'accept'])->name('public.quotes.accept');
    Route::post('/public/quotes/{quote}/decline', [PublicQuoteController::class, 'decline'])->name('public.quotes.decline');
    Route::get('/public/requests/{user}', [PublicRequestController::class, 'show'])->name('public.requests.form');
    Route::post('/public/requests/{user}/address-search', [PublicRequestController::class, 'addressSearch'])
        ->middleware('throttle:public-leads-lookup')
        ->name('public.requests.address-search');
    Route::post('/public/requests/{user}/suggest-services', [PublicRequestController::class, 'suggest'])
        ->middleware('throttle:public-leads-lookup')
        ->name('public.requests.suggest');
    Route::post('/public/requests/{user}', [PublicRequestController::class, 'store'])
        ->middleware('throttle:public-leads-submit')
        ->name('public.requests.store');
    Route::get('/public/works/{work}', [PublicWorkController::class, 'show'])->name('public.works.show');
    Route::post('/public/works/{work}/validate', [PublicWorkController::class, 'validateWork'])->name('public.works.validate');
    Route::post('/public/works/{work}/dispute', [PublicWorkController::class, 'dispute'])->name('public.works.dispute');
    Route::post('/public/works/{work}/schedule/confirm', [PublicWorkController::class, 'confirmSchedule'])
        ->name('public.works.schedule.confirm');
    Route::post('/public/works/{work}/schedule/reject', [PublicWorkController::class, 'rejectSchedule'])
        ->name('public.works.schedule.reject');
    Route::get('/public/works/{work}/proofs', [PublicWorkProofController::class, 'show'])->name('public.works.proofs');
    Route::post('/public/tasks/{task}/media', [PublicTaskMediaController::class, 'store'])->name('public.tasks.media.store');

    Route::prefix('/kiosk/reservations')
        ->name('public.kiosk.reservations.')
        ->middleware('throttle:public-kiosk')
        ->group(function () {
            Route::get('/', [PublicKioskReservationController::class, 'show'])
                ->name('show');
            Route::post('/walk-in/tickets', [PublicKioskReservationController::class, 'walkInTicket'])
                ->name('walk-in.tickets.store');
            Route::post('/clients/lookup', [PublicKioskReservationController::class, 'lookupClient'])
                ->name('clients.lookup');
            Route::post('/clients/verify', [PublicKioskReservationController::class, 'verifyClient'])
                ->name('clients.verify');
            Route::post('/check-in', [PublicKioskReservationController::class, 'checkIn'])
                ->name('check-in');
            Route::post('/tickets/track', [PublicKioskReservationController::class, 'trackTicket'])
                ->name('tickets.track.submit');
            Route::get('/tickets/track', [PublicKioskReservationController::class, 'trackTicket'])
                ->name('tickets.track');
        });
});
// Onboarding (account setup)
Route::get('/onboarding', [OnboardingController::class, 'index'])
    ->middleware(EnsureInternalUser::class)
    ->name('onboarding.index');

// Dashboard Route
Route::get('/dashboard', [DashboardController::class, 'index'])->middleware(['auth', 'verified'])->name('dashboard');
Route::get('/dashboard/products/sellers-export', [DashboardController::class, 'exportProductSellers'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard.products.sellers-export');
Route::post('/locale', [LocaleController::class, 'update'])->name('locale.update');

// Authenticated User Routes
Route::middleware(['auth', 'demo.safe'])->group(function () {
    // Profile Management
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::get('/notifications', [NotificationController::class, 'index'])
        ->name('notifications.index');
    Route::get('/notifications/{notification}/open', [NotificationController::class, 'open'])
        ->name('notifications.open');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])
        ->name('notifications.read-all');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead'])
        ->name('notifications.read');
    Route::post('/notifications/{notification}/archive', [NotificationController::class, 'archive'])
        ->name('notifications.archive');
    Route::post('/notifications/{notification}/restore', [NotificationController::class, 'restore'])
        ->name('notifications.restore');
});

// Internal User Routes
Route::middleware(['auth', EnsureInternalUser::class, 'demo.safe'])->group(function () {

    // Onboarding (account setup)
    Route::post('/onboarding', [OnboardingController::class, 'store'])->name('onboarding.store');
    Route::get('/onboarding/billing', [OnboardingController::class, 'billing'])->name('onboarding.billing');
    Route::get('/global-search', GlobalSearchController::class)->name('global.search');
    Route::post('/assistant/message', [AssistantController::class, 'message'])
        ->middleware('company.feature:assistant')
        ->name('assistant.message');
    Route::post('/ai/images', [AiImageController::class, 'generate'])
        ->middleware('throttle:ai-images')
        ->name('ai.images.generate');
    Route::get('/pipeline/timeline/{entityType}/{entityId}', [PipelineController::class, 'timeline'])
        ->name('pipeline.timeline');
    Route::get('/pipeline', [PipelineController::class, 'data'])->name('pipeline.data');
    Route::get('/workspace-hubs/{category}', [WorkspaceCategoryController::class, 'show'])
        ->where('category', 'revenue|growth|operations|finance|catalog|workspace')
        ->name('workspace.hubs.show');

    Route::middleware('not.superadmin')->group(function () {
        Route::get('/settings/support', [SupportTicketController::class, 'index'])->name('settings.support.index');
        Route::get('/settings/support/{ticket}', [SupportTicketController::class, 'show'])->name('settings.support.show');
        Route::post('/settings/support', [SupportTicketController::class, 'store'])->name('settings.support.store');
        Route::put('/settings/support/{ticket}', [SupportTicketController::class, 'update'])->name('settings.support.update');
        Route::post('/settings/support/{ticket}/messages', [SupportTicketMessageController::class, 'store'])
            ->name('settings.support.messages.store');
        Route::get('/settings/security', [SecuritySettingsController::class, 'edit'])->name('settings.security.edit');
        Route::post('/settings/security/2fa/app/start', [SecuritySettingsController::class, 'startAppSetup'])
            ->name('settings.security.2fa.app.start');
        Route::post('/settings/security/2fa/app/confirm', [SecuritySettingsController::class, 'confirmAppSetup'])
            ->name('settings.security.2fa.app.confirm');
        Route::post('/settings/security/2fa/app/cancel', [SecuritySettingsController::class, 'cancelAppSetup'])
            ->name('settings.security.2fa.app.cancel');
        Route::post('/settings/security/2fa/email', [SecuritySettingsController::class, 'switchToEmail'])
            ->name('settings.security.2fa.email');
        Route::post('/settings/security/2fa/sms', [SecuritySettingsController::class, 'switchToSms'])
            ->name('settings.security.2fa.sms');

        // Settings (owner only)
        Route::get('/settings/company', [CompanySettingsController::class, 'edit'])->name('settings.company.edit');
        Route::put('/settings/company', [CompanySettingsController::class, 'update'])->name('settings.company.update');
        Route::get('/settings/hr', [HrSettingsController::class, 'edit'])->name('settings.hr.edit');
        Route::post('/settings/hr/shift-templates', [HrSettingsController::class, 'store'])
            ->name('settings.hr.shift-templates.store');
        Route::patch('/settings/hr/shift-templates/{template}', [HrSettingsController::class, 'update'])
            ->name('settings.hr.shift-templates.update');
        Route::delete('/settings/hr/shift-templates/{template}', [HrSettingsController::class, 'destroy'])
            ->name('settings.hr.shift-templates.destroy');
        Route::post('/settings/api-tokens', [ApiTokenController::class, 'store'])->name('settings.api-tokens.store');
        Route::delete('/settings/api-tokens/{token}', [ApiTokenController::class, 'destroy'])->name('settings.api-tokens.destroy');
        Route::post('/settings/warehouses', [WarehouseController::class, 'store'])->name('settings.warehouses.store');
        Route::put('/settings/warehouses/{warehouse}', [WarehouseController::class, 'update'])
            ->name('settings.warehouses.update');
        Route::patch('/settings/warehouses/{warehouse}/default', [WarehouseController::class, 'setDefault'])
            ->name('settings.warehouses.default');
        Route::delete('/settings/warehouses/{warehouse}', [WarehouseController::class, 'destroy'])
            ->name('settings.warehouses.destroy');
        Route::post('/settings/categories', [ProductCategoryController::class, 'store'])->name('settings.categories.store');
        Route::patch('/settings/categories/{category}', [ProductCategoryController::class, 'update'])
            ->name('settings.categories.update');
        Route::patch('/settings/categories/{category}/archive', [ProductCategoryController::class, 'archive'])
            ->name('settings.categories.archive');
        Route::patch('/settings/categories/{category}/restore', [ProductCategoryController::class, 'restore'])
            ->name('settings.categories.restore');
        Route::get('/settings/billing', [BillingSettingsController::class, 'edit'])->name('settings.billing.edit');
        Route::put('/settings/billing', [BillingSettingsController::class, 'update'])->name('settings.billing.update');
        Route::post('/settings/billing/checkout', [SubscriptionController::class, 'checkout'])->name('settings.billing.checkout');
        Route::post('/settings/billing/connect', [BillingSettingsController::class, 'connectStripe'])
            ->name('settings.billing.connect');
        Route::post('/settings/billing/assistant-addon', [BillingSettingsController::class, 'updateAssistantAddon'])
            ->name('settings.billing.assistant-addon');
        Route::post('/settings/billing/assistant-credits', [BillingSettingsController::class, 'createAssistantCreditCheckout'])
            ->name('settings.billing.assistant-credits');
        Route::get('/settings/loyalty', [LoyaltySettingsController::class, 'edit'])
            ->middleware('company.feature:loyalty')
            ->name('settings.loyalty.edit');
        Route::put('/settings/loyalty', [LoyaltySettingsController::class, 'update'])
            ->middleware('company.feature:loyalty')
            ->name('settings.loyalty.update');
        Route::get('/settings/marketing', [MarketingSettingsController::class, 'edit'])
            ->middleware('company.feature:campaigns')
            ->name('settings.marketing.edit');
        Route::put('/settings/marketing', [MarketingSettingsController::class, 'update'])
            ->middleware('company.feature:campaigns')
            ->name('settings.marketing.update');
        Route::get('/settings/notifications', [NotificationSettingsController::class, 'edit'])
            ->name('settings.notifications.edit');
        Route::put('/settings/notifications', [NotificationSettingsController::class, 'update'])
            ->name('settings.notifications.update');
        Route::post('/settings/billing/swap', [SubscriptionController::class, 'swap'])->name('settings.billing.swap');
        Route::post('/settings/billing/portal', [SubscriptionController::class, 'portal'])->name('settings.billing.portal');
        Route::post('/settings/billing/payment-method', [SubscriptionController::class, 'paymentMethodTransaction'])
            ->name('settings.billing.payment-method');
    });

    Route::get('/crm/saved-segments', [SavedSegmentController::class, 'index'])
        ->name('crm.saved-segments.index');
    Route::post('/crm/saved-segments', [SavedSegmentController::class, 'store'])
        ->name('crm.saved-segments.store');
    Route::put('/crm/saved-segments/{savedSegment}', [SavedSegmentController::class, 'update'])
        ->name('crm.saved-segments.update');
    Route::delete('/crm/saved-segments/{savedSegment}', [SavedSegmentController::class, 'destroy'])
        ->name('crm.saved-segments.destroy');
    Route::post('/crm/playbooks', [PlaybookController::class, 'store'])
        ->name('crm.playbooks.store');
    Route::post('/crm/playbooks/{playbook}/run', [PlaybookController::class, 'run'])
        ->name('crm.playbooks.run');
    Route::get('/crm/playbook-runs', [PlaybookRunController::class, 'index'])
        ->name('crm.playbook-runs.index');
    Route::middleware('company.feature:sales')->group(function () {
        Route::get('/crm/next-actions', [MyNextActionsController::class, 'index'])
            ->name('crm.next-actions.index');
        Route::get('/crm/sales-inbox', [SalesInboxController::class, 'index'])
            ->name('crm.sales-inbox.index');
        Route::get('/crm/manager-dashboard', [SalesManagerDashboardController::class, 'index'])
            ->name('crm.manager-dashboard.index');
    });

    // Lead Requests
    Route::middleware('company.feature:requests')->group(function () {
        Route::get('/requests', [RequestController::class, 'index'])->name('request.index');
        Route::patch('/requests/bulk', [RequestController::class, 'bulkUpdate'])->name('request.bulk');
        Route::post('/requests', [RequestController::class, 'store'])->name('request.store');
        Route::post('/requests/import', [RequestController::class, 'import'])->name('request.import');
        Route::get('/requests/{lead}', [RequestController::class, 'show'])->name('request.show');
        Route::post('/requests/{lead}/sales-activities', [SalesActivityController::class, 'storeForRequest'])
            ->name('crm.sales-activities.requests.store');
        Route::put('/requests/{lead}', [RequestController::class, 'update'])->name('request.update');
        Route::post('/requests/{lead}/merge', [RequestController::class, 'merge'])->name('request.merge');
        Route::post('/requests/{lead}/convert', [RequestController::class, 'convert'])
            ->middleware('company.feature:quotes')
            ->name('request.convert');
        Route::post('/requests/{lead}/notes', [RequestNoteController::class, 'store'])->name('request.notes.store');
        Route::delete('/requests/{lead}/notes/{note}', [RequestNoteController::class, 'destroy'])->name('request.notes.destroy');
        Route::post('/requests/{lead}/media', [RequestMediaController::class, 'store'])->name('request.media.store');
        Route::delete('/requests/{lead}/media/{media}', [RequestMediaController::class, 'destroy'])->name('request.media.destroy');
        Route::delete('/requests/{lead}', [RequestController::class, 'destroy'])->name('request.destroy');
    });

    // Reservations
    Route::middleware('company.feature:reservations')->group(function () {
        Route::get('/app/reservations', [StaffReservationController::class, 'index'])->name('reservation.index');
        Route::get('/app/reservations/screen', [StaffReservationController::class, 'screen'])->name('reservation.screen');
        Route::get('/app/reservations/screen/data', [StaffReservationController::class, 'screenData'])->name('reservation.screen.data');
        Route::get('/app/reservations/events', [StaffReservationController::class, 'events'])->name('reservation.events');
        Route::get('/app/reservations/slots', [StaffReservationController::class, 'slots'])->name('reservation.slots');
        Route::post('/app/reservations', [StaffReservationController::class, 'store'])->name('reservation.store');
        Route::put('/app/reservations/{reservation}', [StaffReservationController::class, 'update'])->name('reservation.update');
        Route::patch('/app/reservations/{reservation}/status', [StaffReservationController::class, 'updateStatus'])
            ->name('reservation.status');
        Route::patch('/app/reservations/waitlist/{waitlist}/status', [StaffReservationController::class, 'updateWaitlistStatus'])
            ->name('reservation.waitlist.status');
        Route::patch('/app/reservations/queue/{item}/check-in', [StaffReservationController::class, 'queueCheckIn'])
            ->name('reservation.queue.check-in');
        Route::patch('/app/reservations/queue/{item}/pre-call', [StaffReservationController::class, 'queuePreCall'])
            ->name('reservation.queue.pre-call');
        Route::patch('/app/reservations/queue/{item}/call', [StaffReservationController::class, 'queueCall'])
            ->name('reservation.queue.call');
        Route::post('/app/reservations/queue/call-next', [StaffReservationController::class, 'queueCallNext'])
            ->name('reservation.queue.call-next');
        Route::patch('/app/reservations/queue/{item}/start', [StaffReservationController::class, 'queueStart'])
            ->name('reservation.queue.start');
        Route::patch('/app/reservations/queue/{item}/done', [StaffReservationController::class, 'queueDone'])
            ->name('reservation.queue.done');
        Route::patch('/app/reservations/queue/{item}/skip', [StaffReservationController::class, 'queueSkip'])
            ->name('reservation.queue.skip');
        Route::delete('/app/reservations/{reservation}', [StaffReservationController::class, 'destroy'])->name('reservation.destroy');
        Route::get('/settings/reservations', [ReservationSettingsController::class, 'edit'])
            ->name('settings.reservations.edit');
        Route::put('/settings/reservations', [ReservationSettingsController::class, 'update'])
            ->name('settings.reservations.update');
        Route::redirect('/app/reservations/settings', '/settings/reservations')
            ->name('reservation.settings.legacy');
        Route::redirect('/reservations', '/app/reservations')->name('reservation.legacy');
    });

    Route::middleware('company.feature:quotes')->group(function () {
        Route::get('/quotes', [QuoteController::class, 'index'])->name('quote.index');
        Route::get('/customer/{customer}/quote/create', [QuoteController::class, 'create'])->name('customer.quote.create');
        Route::post('/customer/quote/store', [QuoteController::class, 'store'])->name('customer.quote.store');
        Route::get('/customer/quote/{quote}/edit', [QuoteController::class, 'edit'])->name('customer.quote.edit');
        Route::get('/customer/quote/{quote}/show', [QuoteController::class, 'show'])->name('customer.quote.show');
        Route::post('/quote/{quote}/sales-activities', [SalesActivityController::class, 'storeForQuote'])
            ->name('crm.sales-activities.quotes.store');
        Route::put('/customer/quote/{quote}/update', [QuoteController::class, 'update'])->name('customer.quote.update');
        Route::delete('/customer/quote/{quote}/destroy', [QuoteController::class, 'destroy'])->name('customer.quote.destroy');
        Route::post('/customer/quote/{quote}/restore', [QuoteController::class, 'restore'])->name('customer.quote.restore');
        Route::post('/quote/{quote}/accept', [QuoteController::class, 'accept'])->name('quote.accept');
        Route::post('/quote/{quote}/send-email', QuoteEmaillingController::class)->name('quote.send.email');
        Route::post('/quote/{quote}/convert', [QuoteController::class, 'convertToWork'])->name('quote.convert');
        Route::patch('/quote/{quote}/recovery', [QuoteController::class, 'updateRecovery'])->name('quote.recovery.update');

        Route::middleware('company.feature:tasks')->group(function () {
            Route::post('/quote/{quote}/recovery-task', [QuoteController::class, 'storeRecoveryTask'])->name('quote.recovery.task.store');
        });

        Route::middleware('company.feature:plan_scans')->group(function () {
            Route::get('/plan-scans', [PlanScanController::class, 'index'])->name('plan-scans.index');
            Route::get('/plan-scans/create', [PlanScanController::class, 'create'])->name('plan-scans.create');
            Route::post('/plan-scans', [PlanScanController::class, 'store'])->name('plan-scans.store');
            Route::get('/plan-scans/{planScan}', [PlanScanController::class, 'show'])->name('plan-scans.show');
            Route::patch('/plan-scans/{planScan}/review', [PlanScanController::class, 'review'])->name('plan-scans.review');
            Route::post('/plan-scans/{planScan}/reanalyze', [PlanScanController::class, 'reanalyze'])->name('plan-scans.reanalyze');
            Route::delete('/plan-scans/{planScan}', [PlanScanController::class, 'destroy'])->name('plan-scans.destroy');
            Route::post('/plan-scans/{planScan}/convert', [PlanScanController::class, 'convert'])->name('plan-scans.convert');
        });
    });

    // Product custom search
    Route::middleware('company.feature:products')->group(function () {
        Route::get('/product/search', ProductsSearchController::class)->name('product.search');
        Route::get('/product/price-lookup', ProductPriceLookupController::class)->name('product.price-lookup');
        Route::get('/products/options', [ProductController::class, 'options'])->name('product.options');
        Route::post('/products/quick', [ProductController::class, 'storeQuick'])->name('product.quick.store');
        Route::post('/products/draft', [ProductController::class, 'storeDraft'])->name('product.draft.store');
    });

    Route::middleware('company.feature:services')->group(function () {
        Route::get('/services/options', [ServiceController::class, 'options'])->name('service.options');
        Route::post('/services/quick', [ServiceController::class, 'storeQuick'])->name('service.quick.store');
        Route::get('/services/categories', [ServiceController::class, 'categories'])->name('service.categories');
    });
    Route::get('/catalog/search', ProductsSearchController::class)->name('catalog.search');
    Route::get('/customers/options', [CustomerController::class, 'options'])->name('customer.options');
    Route::post('/customers/quick', [CustomerController::class, 'storeQuick'])->name('customer.quick.store');

    // Service Management
    Route::middleware('company.feature:services')->group(function () {
        Route::resource('service', ServiceController::class)
            ->only(['index', 'store', 'update', 'destroy']);
    });

    // Product Management
    Route::middleware('company.feature:products')->group(function () {
        Route::post('/product/bulk', [ProductController::class, 'bulk'])->name('product.bulk');
        Route::post('/product/{product}/duplicate', [ProductController::class, 'duplicate'])->name('product.duplicate');
        Route::get('/product/ai-missing', [ProductController::class, 'missingAiImages'])->name('product.ai-missing');
        Route::post('/product/{product}/ai-image', [ProductController::class, 'generateAiImage'])->name('product.ai-image');
        Route::put('/product/{product}/quick-update', [ProductController::class, 'quickUpdate'])->name('product.quick-update');
        Route::post('/product/{product}/adjust-stock', [ProductController::class, 'adjustStock'])->name('product.adjust-stock');
        Route::get('/product/{product}/reserved-orders', [ProductController::class, 'reservedOrders'])
            ->name('product.reserved-orders');
        Route::post('/product/{product}/supplier-email', [ProductController::class, 'requestSupplierStock'])
            ->name('product.supplier-email');
        Route::get('/product/export/csv', [ProductController::class, 'export'])->name('product.export');
        Route::post('/product/import/csv', [ProductController::class, 'import'])->name('product.import');
        Route::resource('product', ProductController::class);
    });

    // Performance
    Route::middleware('company.feature:performance')->group(function () {
        Route::get('/performance', [PerformanceController::class, 'index'])->name('performance.index');
        Route::get('/performance/employees/{employee}', [PerformanceController::class, 'employee'])
            ->name('performance.employee.show');
    });

    // Presence
    Route::middleware('company.feature:presence')->group(function () {
        Route::get('/presence', [PresenceController::class, 'index'])->name('presence.index');
        Route::post('/presence/clock-in', [PresenceController::class, 'clockIn'])->name('presence.clock-in');
        Route::post('/presence/clock-out', [PresenceController::class, 'clockOut'])->name('presence.clock-out');
    });

    // Planning
    Route::middleware('company.feature:planning')->group(function () {
        Route::get('/planning', [PlanningController::class, 'index'])->name('planning.index');
        Route::get('/planning/calendar', [PlanningController::class, 'calendar'])->name('planning.calendar');
        Route::get('/planning/events', [PlanningController::class, 'events'])->name('planning.events');
        Route::post('/planning/shifts', [PlanningController::class, 'store'])->name('planning.shifts.store');
        Route::patch('/planning/shifts/{shift}', [PlanningController::class, 'update'])->name('planning.shifts.update');
        Route::delete('/planning/shifts/{shift}', [PlanningController::class, 'destroy'])->name('planning.shifts.destroy');
        Route::patch('/planning/shifts/{shift}/status', [PlanningController::class, 'updateStatus'])
            ->name('planning.shifts.status');
    });

    Route::middleware('company.feature:loyalty')->group(function () {
        Route::get('/loyalty', [LoyaltyController::class, 'index'])->name('loyalty.index');
    });

    Route::middleware('company.feature:campaigns')->group(function () {
        Route::get('/campaigns/templates', [MarketingTemplateController::class, 'manage'])
            ->name('campaigns.templates.manage');
        Route::get('/campaigns/prospect-providers', [MarketingProspectProviderConnectionController::class, 'manage'])
            ->name('campaigns.prospect-providers.manage');
        Route::get('/offers/search', [OfferSearchController::class, 'search'])->name('offers.search');
        Route::get('/marketing/meta', MarketingMetaController::class)->name('marketing.meta');
        Route::get('/marketing/dashboard/kpis', MarketingDashboardKpiController::class)->name('marketing.dashboard.kpis');
        Route::get('/marketing/templates', [MarketingTemplateController::class, 'index'])->name('marketing.templates.index');
        Route::post('/marketing/templates', [MarketingTemplateController::class, 'store'])->name('marketing.templates.store');
        Route::post('/marketing/templates/upload-image', [MarketingTemplateController::class, 'uploadImage'])
            ->name('marketing.templates.upload-image');
        Route::get('/marketing/templates/{template}', [MarketingTemplateController::class, 'show'])->name('marketing.templates.show');
        Route::post('/marketing/templates/{template}/duplicate', [MarketingTemplateController::class, 'duplicate'])->name('marketing.templates.duplicate');
        Route::put('/marketing/templates/{template}', [MarketingTemplateController::class, 'update'])->name('marketing.templates.update');
        Route::delete('/marketing/templates/{template}', [MarketingTemplateController::class, 'destroy'])->name('marketing.templates.destroy');
        Route::post('/marketing/templates/preview', [MarketingTemplateController::class, 'preview'])->name('marketing.templates.preview');
        Route::post('/marketing/templates/test-send', [MarketingTemplateController::class, 'testSend'])
            ->name('marketing.templates.test-send');
        Route::post('/marketing/templates/{template}/preview', [MarketingTemplateController::class, 'previewTemplate'])
            ->name('marketing.templates.preview-template');

        Route::get('/marketing/segments', [MarketingSegmentController::class, 'index'])->name('marketing.segments.index');
        Route::post('/marketing/segments', [MarketingSegmentController::class, 'store'])->name('marketing.segments.store');
        Route::get('/marketing/segments/{segment}', [MarketingSegmentController::class, 'show'])->name('marketing.segments.show');
        Route::put('/marketing/segments/{segment}', [MarketingSegmentController::class, 'update'])->name('marketing.segments.update');
        Route::delete('/marketing/segments/{segment}', [MarketingSegmentController::class, 'destroy'])->name('marketing.segments.destroy');
        Route::post('/marketing/segments/preview-count', [MarketingSegmentController::class, 'previewCount'])
            ->name('marketing.segments.preview-count');
        Route::get('/marketing/segments/{segment}/count', [MarketingSegmentController::class, 'count'])
            ->name('marketing.segments.count');

        Route::get('/marketing/mailing-lists', [MarketingMailingListController::class, 'index'])
            ->name('marketing.mailing-lists.index');
        Route::post('/marketing/mailing-lists', [MarketingMailingListController::class, 'store'])
            ->name('marketing.mailing-lists.store');
        Route::get('/marketing/mailing-lists/{mailingList}', [MarketingMailingListController::class, 'show'])
            ->name('marketing.mailing-lists.show');
        Route::get('/marketing/mailing-lists/{mailingList}/available-customers', [MarketingMailingListController::class, 'availableCustomers'])
            ->name('marketing.mailing-lists.available-customers');
        Route::put('/marketing/mailing-lists/{mailingList}', [MarketingMailingListController::class, 'update'])
            ->name('marketing.mailing-lists.update');
        Route::delete('/marketing/mailing-lists/{mailingList}', [MarketingMailingListController::class, 'destroy'])
            ->name('marketing.mailing-lists.destroy');
        Route::post('/marketing/mailing-lists/{mailingList}/import', [MarketingMailingListController::class, 'import'])
            ->name('marketing.mailing-lists.import');
        Route::post('/marketing/mailing-lists/{mailingList}/sync-customers', [MarketingMailingListController::class, 'syncCustomers'])
            ->name('marketing.mailing-lists.sync-customers');
        Route::post('/marketing/mailing-lists/{mailingList}/remove-customers', [MarketingMailingListController::class, 'removeCustomers'])
            ->name('marketing.mailing-lists.remove-customers');
        Route::get('/marketing/mailing-lists/{mailingList}/count', [MarketingMailingListController::class, 'count'])
            ->name('marketing.mailing-lists.count');

        Route::get('/marketing/vip-tiers', [MarketingVipController::class, 'index'])
            ->name('marketing.vip.index');
        Route::post('/marketing/vip-tiers', [MarketingVipController::class, 'store'])
            ->name('marketing.vip.store');
        Route::put('/marketing/vip-tiers/{vipTier}', [MarketingVipController::class, 'update'])
            ->name('marketing.vip.update');
        Route::delete('/marketing/vip-tiers/{vipTier}', [MarketingVipController::class, 'destroy'])
            ->name('marketing.vip.destroy');
        Route::patch('/marketing/customers/{customer}/vip', [MarketingVipController::class, 'updateCustomer'])
            ->name('marketing.vip.customer.update');
        Route::get('/marketing/prospect-providers', [MarketingProspectProviderConnectionController::class, 'index'])
            ->name('marketing.prospect-providers.index');
        Route::post('/marketing/prospect-providers/connect', [MarketingProspectProviderConnectionController::class, 'connect'])
            ->name('marketing.prospect-providers.connect');
        Route::post('/marketing/prospect-providers', [MarketingProspectProviderConnectionController::class, 'store'])
            ->name('marketing.prospect-providers.store');
        Route::put('/marketing/prospect-providers/{connection}', [MarketingProspectProviderConnectionController::class, 'update'])
            ->name('marketing.prospect-providers.update');
        Route::post('/marketing/prospect-providers/{connection}/reconnect', [MarketingProspectProviderConnectionController::class, 'reconnect'])
            ->name('marketing.prospect-providers.reconnect');
        Route::post('/marketing/prospect-providers/{connection}/refresh', [MarketingProspectProviderConnectionController::class, 'refresh'])
            ->name('marketing.prospect-providers.refresh');
        Route::post('/marketing/prospect-providers/{connection}/validate', [MarketingProspectProviderConnectionController::class, 'validateConnection'])
            ->name('marketing.prospect-providers.validate');
        Route::post('/marketing/prospect-providers/{connection}/disconnect', [MarketingProspectProviderConnectionController::class, 'disconnect'])
            ->name('marketing.prospect-providers.disconnect');

        Route::get('/campaigns', [CampaignController::class, 'index'])->name('campaigns.index');
        Route::get('/campaigns/create', [CampaignController::class, 'create'])->name('campaigns.create');
        Route::post('/campaigns', [CampaignController::class, 'store'])->name('campaigns.store');
        Route::get('/campaigns/{campaign}', [CampaignController::class, 'show'])->name('campaigns.show');
        Route::get('/campaigns/{campaign}/edit', [CampaignController::class, 'edit'])->name('campaigns.edit');
        Route::put('/campaigns/{campaign}', [CampaignController::class, 'update'])->name('campaigns.update');
        Route::delete('/campaigns/{campaign}', [CampaignController::class, 'destroy'])->name('campaigns.destroy');

        Route::post('/campaigns/{campaign}/estimate', [CampaignRunController::class, 'estimate'])->name('campaigns.estimate');
        Route::post('/campaigns/{campaign}/preview', [CampaignRunController::class, 'preview'])->name('campaigns.preview');
        Route::post('/campaigns/{campaign}/test-send', [CampaignRunController::class, 'testSend'])->name('campaigns.test-send');
        Route::get('/campaigns/{campaign}/prospect-batches', [CampaignProspectingController::class, 'batches'])
            ->name('campaigns.prospect-batches.index');
        Route::post('/campaigns/{campaign}/prospect-provider-preview', [CampaignProspectingController::class, 'providerPreview'])
            ->name('campaigns.prospect-provider-preview');
        Route::post('/campaigns/{campaign}/prospect-batches/import', [CampaignProspectingController::class, 'import'])
            ->name('campaigns.prospect-batches.import');
        Route::get('/campaigns/{campaign}/prospect-batches/{batch}/workspace', [CampaignProspectingController::class, 'workspace'])
            ->name('campaigns.prospect-batches.workspace');
        Route::get('/campaigns/{campaign}/prospect-batches/{batch}', [CampaignProspectingController::class, 'showBatch'])
            ->name('campaigns.prospect-batches.show');
        Route::post('/campaigns/{campaign}/prospect-batches/{batch}/approve', [CampaignProspectingController::class, 'approveBatch'])
            ->name('campaigns.prospect-batches.approve');
        Route::post('/campaigns/{campaign}/prospect-batches/{batch}/reject', [CampaignProspectingController::class, 'rejectBatch'])
            ->name('campaigns.prospect-batches.reject');
        Route::get('/campaigns/{campaign}/prospects', [CampaignProspectingController::class, 'prospects'])
            ->name('campaigns.prospects.index');
        Route::get('/campaigns/{campaign}/lead-options', [CampaignProspectingController::class, 'leadOptions'])
            ->name('campaigns.prospects.lead-options');
        Route::patch('/campaigns/{campaign}/prospects/bulk-status', [CampaignProspectingController::class, 'bulkUpdateProspects'])
            ->name('campaigns.prospects.bulk-status');
        Route::get('/campaigns/{campaign}/prospects/{prospect}', [CampaignProspectingController::class, 'showProspect'])
            ->name('campaigns.prospects.show');
        Route::patch('/campaigns/{campaign}/prospects/{prospect}/status', [CampaignProspectingController::class, 'updateProspectStatus'])
            ->name('campaigns.prospects.status');
        Route::post('/campaigns/{campaign}/prospects/{prospect}/convert-to-lead', [CampaignProspectingController::class, 'convertProspectToLead'])
            ->name('campaigns.prospects.convert');
        Route::post('/campaigns/{campaign}/prospects/{prospect}/link-to-lead', [CampaignProspectingController::class, 'linkProspectToLead'])
            ->name('campaigns.prospects.link');
        Route::post('/campaigns/{campaign}/send', [CampaignRunController::class, 'sendNow'])->name('campaigns.send');
        Route::post('/campaigns/{campaign}/schedule', [CampaignRunController::class, 'schedule'])->name('campaigns.schedule');
        Route::post('/campaigns/{campaign}/conversions', [CampaignRunController::class, 'recordConversion'])
            ->name('campaigns.conversions.store');
        Route::get('/campaign-runs/{run}/export', [CampaignRunController::class, 'exportRecipients'])->name('campaign-runs.export');
        Route::post('/campaigns/reconcile', [CampaignTrackingController::class, 'reconcile'])->name('campaigns.reconcile');

        Route::get('/campaign-automations', [CampaignAutomationController::class, 'index'])->name('campaign-automations.index');
        Route::post('/campaign-automations', [CampaignAutomationController::class, 'store'])->name('campaign-automations.store');
        Route::put('/campaign-automations/{rule}', [CampaignAutomationController::class, 'update'])->name('campaign-automations.update');
        Route::delete('/campaign-automations/{rule}', [CampaignAutomationController::class, 'destroy'])->name('campaign-automations.destroy');
    });

    // Sales Management (products)
    Route::middleware('company.feature:sales')->group(function () {
        Route::get('/orders', [SaleController::class, 'ordersIndex'])->name('orders.index');
        Route::get('/sales', [SaleController::class, 'index'])->name('sales.index');
        Route::get('/sales/create', [SaleController::class, 'create'])->name('sales.create');
        Route::get('/sales/{sale}/edit', [SaleController::class, 'edit'])->name('sales.edit');
        Route::post('/sales', [SaleController::class, 'store'])->name('sales.store');
        Route::put('/sales/{sale}', [SaleController::class, 'update'])->name('sales.update');
        Route::patch('/sales/{sale}/status', [SaleController::class, 'updateStatus'])->name('sales.status.update');
        Route::post('/sales/{sale}/stripe', [SaleController::class, 'createStripeCheckout'])->name('sales.stripe');
        Route::post('/sales/{sale}/pickup-confirm', [SaleController::class, 'confirmPickup'])
            ->name('sales.pickup.confirm');
        Route::get('/sales/{sale}/receipt', [SaleController::class, 'receipt'])->name('sales.receipt');
        Route::get('/sales/{sale}', [SaleController::class, 'show'])->name('sales.show');
    });

    Route::middleware('company.feature:expenses')->group(function () {
        Route::get('/expenses', [ExpenseController::class, 'index'])->name('expense.index');
        Route::get('/expenses/export', [ExpenseController::class, 'export'])->name('expense.export');
        Route::post('/expenses', [ExpenseController::class, 'store'])->name('expense.store');
        Route::post('/expenses/scan-ai', [ExpenseController::class, 'scanWithAi'])
            ->middleware('company.feature:assistant')
            ->name('expense.scan-ai');
        Route::patch('/expenses/{expense}/submit', [ExpenseController::class, 'submit'])->name('expense.submit');
        Route::patch('/expenses/{expense}/approve', [ExpenseController::class, 'approve'])->name('expense.approve');
        Route::patch('/expenses/{expense}/reject', [ExpenseController::class, 'reject'])->name('expense.reject');
        Route::patch('/expenses/{expense}/mark-due', [ExpenseController::class, 'markDue'])->name('expense.mark-due');
        Route::patch('/expenses/{expense}/mark-paid', [ExpenseController::class, 'markPaid'])->name('expense.mark-paid');
        Route::patch('/expenses/{expense}/mark-reimbursed', [ExpenseController::class, 'markReimbursed'])->name('expense.mark-reimbursed');
        Route::patch('/expenses/{expense}/cancel', [ExpenseController::class, 'cancel'])->name('expense.cancel');
        Route::get('/expenses/{expense}', [ExpenseController::class, 'show'])->name('expense.show');
        Route::put('/expenses/{expense}', [ExpenseController::class, 'update'])->name('expense.update');
        Route::delete('/expenses/{expense}', [ExpenseController::class, 'destroy'])->name('expense.destroy');
    });

    Route::middleware('company.feature:accounting')->group(function () {
        Route::get('/accounting', [AccountingController::class, 'index'])->name('accounting.index');
        Route::get('/accounting/export', [AccountingController::class, 'export'])->name('accounting.export');
        Route::get('/accounting/exports/{accountingExport}', [AccountingController::class, 'downloadExport'])->name('accounting.exports.download');
        Route::patch('/accounting/periods/{periodKey}/open', [AccountingController::class, 'openPeriod'])->where('periodKey', '\d{4}-\d{2}')->name('accounting.periods.open');
        Route::patch('/accounting/periods/{periodKey}/in-review', [AccountingController::class, 'markPeriodInReview'])->where('periodKey', '\d{4}-\d{2}')->name('accounting.periods.in-review');
        Route::patch('/accounting/periods/{periodKey}/close', [AccountingController::class, 'closePeriod'])->where('periodKey', '\d{4}-\d{2}')->name('accounting.periods.close');
        Route::patch('/accounting/periods/{periodKey}/reopen', [AccountingController::class, 'reopenPeriod'])->where('periodKey', '\d{4}-\d{2}')->name('accounting.periods.reopen');
        Route::patch('/accounting/entries/{accountingEntry}/unreview', [AccountingController::class, 'markEntryUnreviewed'])->name('accounting.entries.unreview');
        Route::patch('/accounting/entries/{accountingEntry}/review', [AccountingController::class, 'markEntryReviewed'])->name('accounting.entries.review');
        Route::patch('/accounting/entries/{accountingEntry}/reconcile', [AccountingController::class, 'markEntryReconciled'])->name('accounting.entries.reconcile');
        Route::patch('/accounting/batches/{accountingEntryBatch}/unreview', [AccountingController::class, 'markBatchUnreviewed'])->name('accounting.batches.unreview');
        Route::patch('/accounting/batches/{accountingEntryBatch}/review', [AccountingController::class, 'markBatchReviewed'])->name('accounting.batches.review');
        Route::patch('/accounting/batches/{accountingEntryBatch}/reconcile', [AccountingController::class, 'markBatchReconciled'])->name('accounting.batches.reconcile');
    });

    Route::get('/finance-approvals', [FinanceApprovalInboxController::class, 'index'])
        ->name('finance-approvals.index');

    // Customer Management
    Route::scopeBindings()->group(function () {
        Route::post('/customer/{customer}/properties', [CustomerPropertyController::class, 'store'])
            ->name('customer.properties.store');
        Route::put('/customer/{customer}/properties/{property}', [CustomerPropertyController::class, 'update'])
            ->name('customer.properties.update');
        Route::delete('/customer/{customer}/properties/{property}', [CustomerPropertyController::class, 'destroy'])
            ->name('customer.properties.destroy');
        Route::put('/customer/{customer}/properties/{property}/default', [CustomerPropertyController::class, 'setDefault'])
            ->name('customer.properties.default');
    });

    Route::patch('/customer/{customer}/notes', [CustomerController::class, 'updateNotes'])
        ->name('customer.notes.update');
    Route::post('/customer/{customer}/sales-activities', [SalesActivityController::class, 'storeForCustomer'])
        ->name('crm.sales-activities.customers.store');
    Route::patch('/customer/{customer}/tags', [CustomerController::class, 'updateTags'])
        ->name('customer.tags.update');
    Route::patch('/customer/{customer}/auto-validation', [CustomerController::class, 'updateAutoValidation'])
        ->name('customer.auto-validation.update');
    Route::post('/customer/bulk', [CustomerController::class, 'bulk'])
        ->name('customer.bulk');
    Route::post('/customer/bulk-contact/preview', [CustomerController::class, 'previewBulkContact'])
        ->middleware('company.feature:campaigns')
        ->name('customer.bulk-contact.preview');
    Route::post('/customer/bulk-contact/send', [CustomerController::class, 'sendBulkContact'])
        ->middleware('company.feature:campaigns')
        ->name('customer.bulk-contact.send');
    Route::post('/customer/bulk-contact/save-selection', [CustomerController::class, 'saveBulkContactSelection'])
        ->middleware('company.feature:campaigns')
        ->name('customer.bulk-contact.save-selection');
    Route::post('/customer/bulk-contact/open-campaign', [CustomerController::class, 'openBulkContactCampaign'])
        ->middleware('company.feature:campaigns')
        ->name('customer.bulk-contact.open-campaign');

    Route::resource('customer', CustomerController::class)
        ->only(['index', 'store', 'update', 'create', 'edit', 'show', 'destroy']);

    // Work Management
    Route::middleware('company.feature:jobs')->group(function () {
        Route::get('/jobs', [WorkController::class, 'index'])->name('jobs.index');
        Route::get('/work/create/{customer}', [WorkController::class, 'create'])
            ->name('work.create');

        Route::resource('work', WorkController::class)
            ->except(['create']);
        Route::get('/work/{work}/proofs', [WorkProofController::class, 'show'])->name('work.proofs');
        Route::post('/work/{work}/status', [WorkController::class, 'updateStatus'])->name('work.status');
        Route::post('/work/{work}/extras', [WorkController::class, 'addExtraQuote'])->name('work.extras');
        Route::post('/work/{work}/media', [WorkMediaController::class, 'store'])->name('work.media.store');
        Route::patch('/work/{work}/checklist/{item}', [WorkChecklistController::class, 'update'])->name('work.checklist.update');
    });

    // Team Management
    Route::middleware('company.feature:team_members')->group(function () {
        Route::get('/team', [TeamMemberController::class, 'index'])->name('team.index');
        Route::post('/team', [TeamMemberController::class, 'store'])->name('team.store');
        Route::put('/team/{teamMember}', [TeamMemberController::class, 'update'])->name('team.update');
        Route::delete('/team/{teamMember}', [TeamMemberController::class, 'destroy'])->name('team.destroy');
    });

    // Tasks
    Route::middleware('company.feature:tasks')->group(function () {
        Route::get('/tasks', [TaskController::class, 'index'])->name('task.index');
        Route::get('/tasks/calendar', [DashboardController::class, 'tasksCalendar'])->name('tasks.calendar');
        Route::get('/tasks/{task}', [TaskController::class, 'show'])->name('task.show');
        Route::post('/tasks', [TaskController::class, 'store'])->name('task.store');
        Route::put('/tasks/{task}', [TaskController::class, 'update'])->name('task.update');
        Route::patch('/tasks/{task}/assignee', [TaskController::class, 'assign'])->name('task.assign');
        Route::delete('/tasks/{task}', [TaskController::class, 'destroy'])->name('task.destroy');
        Route::post('/tasks/{task}/media', [TaskMediaController::class, 'store'])->name('task.media.store');
    });

    // Invoice Management
    Route::middleware('company.feature:invoices')->group(function () {
        Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoice.index');
        Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoice.show');
        Route::get('/invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->name('invoice.pdf');
        Route::post('/invoices/{invoice}/send-email', [InvoiceController::class, 'sendEmail'])->name('invoice.send.email');
        Route::patch('/invoices/{invoice}/approve', [InvoiceController::class, 'approve'])->name('invoice.approve');
        Route::patch('/invoices/{invoice}/reject', [InvoiceController::class, 'reject'])->name('invoice.reject');
        Route::patch('/invoices/{invoice}/process', [InvoiceController::class, 'markProcessed'])->name('invoice.process');
        Route::post('/work/{work}/invoice', [InvoiceController::class, 'storeFromWork'])->name('invoice.store-from-work');
        Route::get('/payments/tips', [TipReportController::class, 'ownerIndex'])->name('payments.tips.index');
        Route::get('/payments/tips/export', [TipReportController::class, 'ownerExport'])->name('payments.tips.export');
        Route::get('/my-earnings/tips', [TipReportController::class, 'memberIndex'])->name('my-earnings.tips.index');
        Route::post('/payments/{payment}/tip-reverse', [PaymentController::class, 'reverseTip'])->name('payment.tip-reverse');
    });

    // Payment Management
    Route::post('/invoice/{invoice}/payments', [PaymentController::class, 'store'])->name('payment.store');
    Route::patch('/payments/{payment}/mark-paid', [PaymentController::class, 'markAsPaid'])->name('payment.mark-paid');
    Route::post('/sales/{sale}/payments', [SalePaymentController::class, 'store'])->name('sales.payments.store');
});

Route::middleware(['auth', 'demo.safe'])->group(function () {
    Route::get('/demo/checklist', [DemoController::class, 'checklist'])->name('demo.checklist');
    Route::post('/demo/reset', [DemoController::class, 'reset'])->name('demo.reset');
    Route::get('/demo/tour/steps', [DemoTourController::class, 'steps'])->name('demo.tour.steps');
    Route::get('/demo/tour/progress', [DemoTourController::class, 'progress'])->name('demo.tour.progress');
    Route::post('/demo/tour/progress', [DemoTourController::class, 'updateProgress'])
        ->name('demo.tour.progress.update');
    Route::post('/demo/tour/reset', [DemoTourController::class, 'reset'])->name('demo.tour.reset');
});

// Client Portal Routes
Route::middleware(['auth', EnsureClientUser::class])
    ->prefix('portal')
    ->name('portal.')
    ->group(function () {
        Route::get('/orders', [PortalProductOrderController::class, 'index'])->name('orders.index');
        Route::post('/orders', [PortalProductOrderController::class, 'store'])->name('orders.store');
        Route::get('/orders/{sale}', [PortalProductOrderController::class, 'showPage'])->name('orders.show');
        Route::get('/orders/{sale}/edit', [PortalProductOrderController::class, 'edit'])->name('orders.edit');
        Route::get('/orders/{sale}/pdf', [PortalProductOrderController::class, 'pdf'])->name('orders.pdf');
        Route::put('/orders/{sale}', [PortalProductOrderController::class, 'update'])->name('orders.update');
        Route::post('/orders/{sale}/pay', [PortalProductOrderController::class, 'pay'])->name('orders.pay');
        Route::post('/orders/{sale}/confirm', [PortalProductOrderController::class, 'confirmReceipt'])->name('orders.confirm');
        Route::delete('/orders/{sale}', [PortalProductOrderController::class, 'destroy'])->name('orders.destroy');
        Route::post('/orders/{sale}/reorder', [PortalProductOrderController::class, 'reorder'])->name('orders.reorder');
        Route::post('/orders/{sale}/reviews', [PortalReviewController::class, 'storeOrder'])->name('orders.reviews.store');
        Route::post('/orders/{sale}/products/{product}/reviews', [PortalReviewController::class, 'storeProduct'])
            ->name('orders.products.reviews.store');
        Route::get('/loyalty', [PortalLoyaltyController::class, 'index'])
            ->middleware('company.feature:loyalty')
            ->name('loyalty.index');
        Route::post('/quotes/{quote}/accept', [PortalQuoteController::class, 'accept'])->name('quotes.accept');
        Route::post('/quotes/{quote}/decline', [PortalQuoteController::class, 'decline'])->name('quotes.decline');
        Route::post('/works/{work}/validate', [PortalWorkController::class, 'validateWork'])->name('works.validate');
        Route::get('/works/{work}/proofs', [PortalWorkProofController::class, 'show'])->name('works.proofs');
        Route::post('/works/{work}/schedule/confirm', [PortalWorkController::class, 'confirmSchedule'])->name('works.schedule.confirm');
        Route::post('/works/{work}/schedule/reject', [PortalWorkController::class, 'rejectSchedule'])->name('works.schedule.reject');
        Route::post('/works/{work}/dispute', [PortalWorkController::class, 'dispute'])->name('works.dispute');
        Route::post('/tasks/{task}/media', [PortalTaskMediaController::class, 'store'])->name('tasks.media.store');
        Route::get('/invoices/{invoice}', [PortalInvoiceController::class, 'show'])->name('invoices.show');
        Route::post('/invoices/{invoice}/payments', [PortalInvoiceController::class, 'storePayment'])->name('invoices.payments.store');
        Route::post('/invoices/{invoice}/stripe', [PortalInvoiceController::class, 'createStripeCheckout'])
            ->name('invoices.stripe');
        Route::post('/quotes/{quote}/ratings', [PortalRatingController::class, 'storeQuote'])->name('quotes.ratings.store');
        Route::post('/works/{work}/ratings', [PortalRatingController::class, 'storeWork'])->name('works.ratings.store');
    });

Route::middleware(['auth', EnsureClientUser::class, 'company.feature:reservations'])
    ->prefix('client/reservations')
    ->name('client.reservations.')
    ->group(function () {
        Route::get('/kiosk', [ClientReservationController::class, 'kiosk'])->name('kiosk');
        Route::get('/book', [ClientReservationController::class, 'book'])->name('book');
        Route::get('/slots', [ClientReservationController::class, 'slots'])->name('slots');
        Route::post('/book', [ClientReservationController::class, 'store'])->name('store');
        Route::post('/tickets', [ClientReservationController::class, 'ticketStore'])->name('tickets.store');
        Route::patch('/tickets/{ticket}/cancel', [ClientReservationController::class, 'ticketCancel'])->name('tickets.cancel');
        Route::patch('/tickets/{ticket}/still-here', [ClientReservationController::class, 'ticketStillHere'])->name('tickets.still-here');
        Route::post('/waitlist', [ClientReservationController::class, 'waitlistStore'])->name('waitlist.store');
        Route::patch('/waitlist/{waitlist}/cancel', [ClientReservationController::class, 'waitlistCancel'])
            ->name('waitlist.cancel');
        Route::get('/', [ClientReservationController::class, 'index'])->name('index');
        Route::get('/events', [ClientReservationController::class, 'events'])->name('events');
        Route::patch('/{reservation}/cancel', [ClientReservationController::class, 'cancel'])->name('cancel');
        Route::post('/{reservation}/review', [ClientReservationController::class, 'review'])->name('review');
        Route::patch('/{reservation}/reschedule', [ClientReservationController::class, 'reschedule'])
            ->name('reschedule');
    });

// Authentication Routes
require __DIR__.'/auth.php';
// Super Admin
Route::prefix('super-admin')
    ->name('superadmin.')
    ->middleware([EnsurePlatformAdmin::class])
    ->group(function () {
        Route::get('/', [SuperAdminDashboardController::class, 'index'])->name('dashboard');

        Route::get('/tenants', [SuperAdminTenantController::class, 'index'])->name('tenants.index');
        Route::get('/tenants/{tenant}', [SuperAdminTenantController::class, 'show'])->name('tenants.show');
        Route::post('/tenants/{tenant}/suspend', [SuperAdminTenantController::class, 'suspend'])->name('tenants.suspend');
        Route::post('/tenants/{tenant}/restore', [SuperAdminTenantController::class, 'restore'])->name('tenants.restore');
        Route::post('/tenants/{tenant}/reset-onboarding', [SuperAdminTenantController::class, 'resetOnboarding'])->name('tenants.reset-onboarding');
        Route::put('/tenants/{tenant}/features', [SuperAdminTenantController::class, 'updateFeatures'])->name('tenants.features.update');
        Route::put('/tenants/{tenant}/limits', [SuperAdminTenantController::class, 'updateLimits'])->name('tenants.limits.update');
        Route::put('/tenants/{tenant}/security', [SuperAdminTenantController::class, 'updateSecurity'])->name('tenants.security.update');
        Route::put('/tenants/{tenant}/plan', [SuperAdminTenantController::class, 'updatePlan'])->name('tenants.plan.update');
        Route::post('/tenants/{tenant}/impersonate', [SuperAdminTenantController::class, 'impersonate'])->name('tenants.impersonate');
        Route::get('/tenants/{tenant}/export', [SuperAdminTenantController::class, 'export'])->name('tenants.export');

        Route::get('/demo-workspaces', [SuperAdminDemoWorkspaceController::class, 'index'])->name('demo-workspaces.index');
        Route::get('/demo-workspaces/create', [SuperAdminDemoWorkspaceController::class, 'create'])->name('demo-workspaces.create');
        Route::get('/demo-workspaces/{demoWorkspace}', [SuperAdminDemoWorkspaceController::class, 'show'])->name('demo-workspaces.show');
        Route::post('/demo-workspaces', [SuperAdminDemoWorkspaceController::class, 'store'])->name('demo-workspaces.store');
        Route::post('/demo-workspaces/{demoWorkspace}/queue', [SuperAdminDemoWorkspaceController::class, 'queueProvisioning'])
            ->name('demo-workspaces.queue');
        Route::patch('/demo-workspaces/{demoWorkspace}/extend', [SuperAdminDemoWorkspaceController::class, 'extendExpiration'])
            ->name('demo-workspaces.expiration.extend');
        Route::patch('/demo-workspaces/{demoWorkspace}/expiration', [SuperAdminDemoWorkspaceController::class, 'updateExpiration'])
            ->name('demo-workspaces.expiration.update');
        Route::patch('/demo-workspaces/{demoWorkspace}/delivery', [SuperAdminDemoWorkspaceController::class, 'updateDeliveryStatus'])
            ->name('demo-workspaces.delivery.update');
        Route::post('/demo-workspaces/{demoWorkspace}/send-access-email', [SuperAdminDemoWorkspaceController::class, 'sendAccessEmail'])
            ->name('demo-workspaces.access-email.send');
        Route::patch('/demo-workspaces/{demoWorkspace}/sales-status', [SuperAdminDemoWorkspaceController::class, 'updateSalesStatus'])
            ->name('demo-workspaces.sales-status.update');
        Route::post('/demo-workspaces/{demoWorkspace}/extra-access/{roleKey}/revoke', [SuperAdminDemoWorkspaceController::class, 'revokeExtraAccess'])
            ->name('demo-workspaces.extra-access.revoke');
        Route::post('/demo-workspaces/{demoWorkspace}/extra-access/{roleKey}/regenerate', [SuperAdminDemoWorkspaceController::class, 'regenerateExtraAccess'])
            ->name('demo-workspaces.extra-access.regenerate');
        Route::post('/demo-workspaces/{demoWorkspace}/clone', [SuperAdminDemoWorkspaceController::class, 'cloneWorkspace'])
            ->name('demo-workspaces.clone');
        Route::put('/demo-workspaces/{demoWorkspace}/baseline', [SuperAdminDemoWorkspaceController::class, 'snapshotBaseline'])
            ->name('demo-workspaces.baseline.snapshot');
        Route::post('/demo-workspaces/{demoWorkspace}/reset', [SuperAdminDemoWorkspaceController::class, 'resetToBaseline'])
            ->name('demo-workspaces.baseline.reset');
        Route::delete('/demo-workspaces/{demoWorkspace}', [SuperAdminDemoWorkspaceController::class, 'destroy'])
            ->name('demo-workspaces.destroy');
        Route::post('/demo-workspaces/templates', [SuperAdminDemoWorkspaceController::class, 'storeTemplate'])
            ->name('demo-workspaces.templates.store');
        Route::put('/demo-workspaces/templates/{demoWorkspaceTemplate}', [SuperAdminDemoWorkspaceController::class, 'updateTemplate'])
            ->name('demo-workspaces.templates.update');
        Route::post('/demo-workspaces/templates/{demoWorkspaceTemplate}/duplicate', [SuperAdminDemoWorkspaceController::class, 'duplicateTemplate'])
            ->name('demo-workspaces.templates.duplicate');
        Route::patch('/demo-workspaces/templates/{demoWorkspaceTemplate}/archive', [SuperAdminDemoWorkspaceController::class, 'toggleTemplateArchive'])
            ->name('demo-workspaces.templates.archive');

        Route::get('/admins', [SuperAdminAdminController::class, 'index'])->name('admins.index');
        Route::post('/admins', [SuperAdminAdminController::class, 'store'])->name('admins.store');
        Route::put('/admins/{admin}', [SuperAdminAdminController::class, 'update'])->name('admins.update');

        Route::get('/notifications', [SuperAdminNotificationController::class, 'edit'])->name('notifications.edit');
        Route::put('/notifications', [SuperAdminNotificationController::class, 'update'])->name('notifications.update');

        Route::get('/announcements', [SuperAdminAnnouncementController::class, 'index'])->name('announcements.index');
        Route::get('/announcements/preview', [SuperAdminAnnouncementController::class, 'preview'])->name('announcements.preview');
        Route::post('/announcements', [SuperAdminAnnouncementController::class, 'store'])->name('announcements.store');
        Route::put('/announcements/{announcement}', [SuperAdminAnnouncementController::class, 'update'])->name('announcements.update');
        Route::delete('/announcements/{announcement}', [SuperAdminAnnouncementController::class, 'destroy'])->name('announcements.destroy');

        Route::get('/support', [SuperAdminSupportTicketController::class, 'index'])->name('support.index');
        Route::get('/support/{ticket}', [SuperAdminSupportTicketController::class, 'show'])->name('support.show');
        Route::post('/support', [SuperAdminSupportTicketController::class, 'store'])->name('support.store');
        Route::put('/support/{ticket}', [SuperAdminSupportTicketController::class, 'update'])->name('support.update');
        Route::post('/support/{ticket}/messages', [SuperAdminSupportTicketMessageController::class, 'store'])
            ->name('support.messages.store');

        Route::get('/pages', [SuperAdminPlatformPageController::class, 'index'])->name('pages.index');
        Route::get('/pages/create', [SuperAdminPlatformPageController::class, 'create'])->name('pages.create');
        Route::post('/pages', [SuperAdminPlatformPageController::class, 'store'])->name('pages.store');
        Route::get('/pages/{page}/edit', [SuperAdminPlatformPageController::class, 'edit'])->name('pages.edit');
        Route::put('/pages/{page}', [SuperAdminPlatformPageController::class, 'update'])->name('pages.update');
        Route::delete('/pages/{page}', [SuperAdminPlatformPageController::class, 'destroy'])->name('pages.destroy');

        Route::get('/mega-menus', [SuperAdminMegaMenuController::class, 'index'])->name('mega-menus.index');
        Route::get('/mega-menus/create', [SuperAdminMegaMenuController::class, 'create'])->name('mega-menus.create');
        Route::post('/mega-menus', [SuperAdminMegaMenuController::class, 'store'])->name('mega-menus.store');
        Route::post('/mega-menus/reorder', [SuperAdminMegaMenuController::class, 'reorder'])->name('mega-menus.reorder');
        Route::get('/mega-menus/{megaMenu}/edit', [SuperAdminMegaMenuController::class, 'edit'])->name('mega-menus.edit');
        Route::put('/mega-menus/{megaMenu}', [SuperAdminMegaMenuController::class, 'update'])->name('mega-menus.update');
        Route::delete('/mega-menus/{megaMenu}', [SuperAdminMegaMenuController::class, 'destroy'])->name('mega-menus.destroy');
        Route::post('/mega-menus/{megaMenu}/duplicate', [SuperAdminMegaMenuController::class, 'duplicate'])->name('mega-menus.duplicate');
        Route::post('/mega-menus/{megaMenu}/activate', [SuperAdminMegaMenuController::class, 'activate'])->name('mega-menus.activate');
        Route::post('/mega-menus/{megaMenu}/deactivate', [SuperAdminMegaMenuController::class, 'deactivate'])->name('mega-menus.deactivate');
        Route::get('/mega-menus/{megaMenu}/preview', [SuperAdminMegaMenuController::class, 'preview'])->name('mega-menus.preview');

        Route::get('/sections', [SuperAdminPlatformSectionController::class, 'index'])->name('sections.index');
        Route::get('/sections/create', [SuperAdminPlatformSectionController::class, 'create'])->name('sections.create');
        Route::post('/sections', [SuperAdminPlatformSectionController::class, 'store'])->name('sections.store');
        Route::post('/sections/{section}/duplicate', [SuperAdminPlatformSectionController::class, 'duplicate'])->name('sections.duplicate');
        Route::get('/sections/{section}/edit', [SuperAdminPlatformSectionController::class, 'edit'])->name('sections.edit');
        Route::put('/sections/{section}', [SuperAdminPlatformSectionController::class, 'update'])->name('sections.update');
        Route::delete('/sections/{section}', [SuperAdminPlatformSectionController::class, 'destroy'])->name('sections.destroy');

        Route::get('/assets', [SuperAdminPlatformAssetController::class, 'index'])->name('assets.index');
        Route::post('/assets', [SuperAdminPlatformAssetController::class, 'store'])->name('assets.store');
        Route::get('/assets/list', [SuperAdminPlatformAssetController::class, 'list'])->name('assets.list');
        Route::delete('/assets/{asset}', [SuperAdminPlatformAssetController::class, 'destroy'])->name('assets.destroy');

        Route::post('/ai/images', [SuperAdminAiImageController::class, 'generate'])->name('ai.images.generate');

        Route::get('/settings', [SuperAdminPlatformSettingsController::class, 'edit'])->name('settings.edit');
        Route::put('/settings', [SuperAdminPlatformSettingsController::class, 'update'])->name('settings.update');
    });

Route::post('/impersonate/stop', [SuperAdminTenantController::class, 'stopImpersonate'])
    ->middleware('impersonating')
    ->name('superadmin.impersonate.stop');
