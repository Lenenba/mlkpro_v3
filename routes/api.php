<?php

use App\Http\Controllers\AccountingController;
use App\Http\Controllers\AiImageController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Integration\CrmConnectorEventController as IntegrationCrmConnectorEventController;
use App\Http\Controllers\Api\Integration\InventoryController as IntegrationInventoryController;
use App\Http\Controllers\Api\Integration\RequestController as IntegrationRequestController;
use App\Http\Controllers\Api\NotificationController as ApiNotificationController;
use App\Http\Controllers\Api\PublicPricingController;
use App\Http\Controllers\Api\PushTokenController;
use App\Http\Controllers\Api\SuperAdmin\AdminController as SuperAdminAdminController;
use App\Http\Controllers\Api\SuperAdmin\AnnouncementController as SuperAdminAnnouncementController;
use App\Http\Controllers\Api\SuperAdmin\DashboardController as SuperAdminDashboardController;
use App\Http\Controllers\Api\SuperAdmin\PlatformSettingsController as SuperAdminPlatformSettingsController;
use App\Http\Controllers\Api\SuperAdmin\SupportController as SuperAdminSupportController;
use App\Http\Controllers\Api\SuperAdmin\TenantController as SuperAdminTenantController;
use App\Http\Controllers\Api\TwoFactorController as ApiTwoFactorController;
use App\Http\Controllers\AssistantController;
use App\Http\Controllers\Billing\StripeWebhookController;
use App\Http\Controllers\CampaignAutomationController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\CampaignProspectingController;
use App\Http\Controllers\CampaignRunController;
use App\Http\Controllers\CampaignTrackingController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerPropertyController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\FinanceApprovalInboxController;
use App\Http\Controllers\GlobalSearchController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\MarketingDashboardKpiController;
use App\Http\Controllers\MarketingMailingListController;
use App\Http\Controllers\MarketingMetaController;
use App\Http\Controllers\MarketingProspectProviderConnectionController;
use App\Http\Controllers\MarketingSegmentController;
use App\Http\Controllers\MarketingTemplateController;
use App\Http\Controllers\MarketingVipController;
use App\Http\Controllers\OfferSearchController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PerformanceController;
use App\Http\Controllers\PipelineController;
use App\Http\Controllers\PlanningController;
use App\Http\Controllers\PlanScanController;
use App\Http\Controllers\Portal\PortalInvoiceController;
use App\Http\Controllers\Portal\PortalNotificationController;
use App\Http\Controllers\Portal\PortalProductOrderController;
use App\Http\Controllers\Portal\PortalQuoteController;
use App\Http\Controllers\Portal\PortalRatingController;
use App\Http\Controllers\Portal\PortalTaskMediaController;
use App\Http\Controllers\Portal\PortalWorkController;
use App\Http\Controllers\Portal\PortalWorkProofController;
use App\Http\Controllers\PresenceController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductPriceLookupController;
use App\Http\Controllers\ProductsSearchController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\QuoteController;
use App\Http\Controllers\QuoteEmaillingController;
use App\Http\Controllers\RequestController;
use App\Http\Controllers\RequestMediaController;
use App\Http\Controllers\RequestNoteController;
use App\Http\Controllers\Reservation\ClientReservationController;
use App\Http\Controllers\Reservation\ReservationSettingsController;
use App\Http\Controllers\Reservation\StaffReservationController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\Settings\BillingSettingsController;
use App\Http\Controllers\Settings\CompanySettingsController;
use App\Http\Controllers\Settings\MarketingSettingsController;
use App\Http\Controllers\Settings\NotificationSettingsController;
use App\Http\Controllers\Settings\ProductCategoryController;
use App\Http\Controllers\Settings\SecuritySettingsController;
use App\Http\Controllers\Settings\SubscriptionController;
use App\Http\Controllers\SupportTicketController;
use App\Http\Controllers\SupportTicketMessageController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TaskMediaController;
use App\Http\Controllers\TeamMemberController;
use App\Http\Controllers\WorkChecklistController;
use App\Http\Controllers\WorkController;
use App\Http\Controllers\WorkMediaController;
use App\Http\Controllers\WorkProofController;
use App\Http\Middleware\EnsureClientUser;
use App\Http\Middleware\EnsureInternalUser;
use App\Http\Middleware\EnsureNotSuspended;
use App\Http\Middleware\EnsureOnboardingIsComplete;
use App\Http\Middleware\EnsurePlatformAdmin;
use Illuminate\Support\Facades\Route;

Route::name('api.')->group(function () {
    Route::post('stripe/webhook', [StripeWebhookController::class, 'handle'])->name('stripe.webhook');
    Route::post('webhooks/campaigns/sms', [CampaignTrackingController::class, 'smsWebhook']);
    Route::post('webhooks/campaigns/email', [CampaignTrackingController::class, 'emailWebhook']);
    Route::get('public/pricing', [PublicPricingController::class, 'index'])->name('public.pricing');

    Route::prefix('auth')->group(function () {
        Route::post('login', [AuthController::class, 'login']);
        Route::post('register', [AuthController::class, 'register']);
        Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
        Route::post('reset-password', [AuthController::class, 'resetPassword']);
        Route::post('two-factor/verify', [ApiTwoFactorController::class, 'verify']);
        Route::post('two-factor/resend', [ApiTwoFactorController::class, 'resend']);
    });

    Route::middleware(['auth:sanctum', EnsureNotSuspended::class])->group(function () {
        Route::prefix('auth')->group(function () {
            Route::get('me', [AuthController::class, 'me']);
            Route::post('logout', [AuthController::class, 'logout']);
            Route::post('verify/resend', [AuthController::class, 'resendVerification']);
        });

        Route::post('push-tokens', [PushTokenController::class, 'store']);
    });

    Route::middleware(['auth:sanctum', EnsureClientUser::class, EnsureNotSuspended::class])
        ->prefix('portal')
        ->group(function () {
            Route::get('dashboard', [DashboardController::class, 'index']);
            Route::get('orders', [PortalProductOrderController::class, 'index']);
            Route::get('orders/history', [PortalProductOrderController::class, 'history']);
            Route::get('orders/{sale}', [PortalProductOrderController::class, 'show']);
            Route::get('orders/{sale}/edit', [PortalProductOrderController::class, 'edit']);
            Route::post('orders', [PortalProductOrderController::class, 'store']);
            Route::put('orders/{sale}', [PortalProductOrderController::class, 'update']);
            Route::post('orders/{sale}/pay', [PortalProductOrderController::class, 'pay']);
            Route::post('orders/{sale}/confirm', [PortalProductOrderController::class, 'confirmReceipt']);
            Route::delete('orders/{sale}', [PortalProductOrderController::class, 'destroy']);
            Route::post('orders/{sale}/reorder', [PortalProductOrderController::class, 'reorder']);
            Route::get('notifications', [PortalNotificationController::class, 'index']);
            Route::post('notifications/read-all', [PortalNotificationController::class, 'markAllRead']);
            Route::post('notifications/{notification}/read', [PortalNotificationController::class, 'markRead']);
            Route::post('quotes/{quote}/accept', [PortalQuoteController::class, 'accept']);
            Route::post('quotes/{quote}/decline', [PortalQuoteController::class, 'decline']);
            Route::post('works/{work}/validate', [PortalWorkController::class, 'validateWork']);
            Route::post('works/{work}/schedule/confirm', [PortalWorkController::class, 'confirmSchedule']);
            Route::post('works/{work}/schedule/reject', [PortalWorkController::class, 'rejectSchedule']);
            Route::post('works/{work}/dispute', [PortalWorkController::class, 'dispute']);
            Route::get('works/{work}/proofs', [PortalWorkProofController::class, 'show']);
            Route::post('tasks/{task}/media', [PortalTaskMediaController::class, 'store']);
            Route::post('invoices/{invoice}/payments', [PortalInvoiceController::class, 'storePayment']);
            Route::post('quotes/{quote}/ratings', [PortalRatingController::class, 'storeQuote']);
            Route::post('works/{work}/ratings', [PortalRatingController::class, 'storeWork']);

            Route::middleware('company.feature:reservations')->group(function () {
                Route::get('reservations/book', [ClientReservationController::class, 'book']);
                Route::get('reservations/slots', [ClientReservationController::class, 'slots']);
                Route::post('reservations/book', [ClientReservationController::class, 'store']);
                Route::get('reservations', [ClientReservationController::class, 'index']);
                Route::get('reservations/events', [ClientReservationController::class, 'events']);
                Route::patch('reservations/{reservation}/cancel', [ClientReservationController::class, 'cancel']);
                Route::post('reservations/{reservation}/review', [ClientReservationController::class, 'review']);
                Route::patch('reservations/{reservation}/reschedule', [ClientReservationController::class, 'reschedule']);
            });
        });

    Route::middleware(['auth:sanctum', EnsureInternalUser::class, EnsureNotSuspended::class])->group(function () {

        Route::get('onboarding', [OnboardingController::class, 'index']);
        Route::post('onboarding', [OnboardingController::class, 'store']);
        Route::get('onboarding/billing', [OnboardingController::class, 'billing']);
        Route::get('global-search', GlobalSearchController::class);

        Route::middleware(EnsureOnboardingIsComplete::class)->group(function () {
            Route::get('dashboard', [DashboardController::class, 'index']);
            Route::post('locale', [LocaleController::class, 'update']);
            Route::get('profile', [ProfileController::class, 'edit']);
            Route::patch('profile', [ProfileController::class, 'update']);
            Route::delete('profile', [ProfileController::class, 'destroy']);
            Route::get('notifications', [ApiNotificationController::class, 'index']);
            Route::post('notifications/read-all', [ApiNotificationController::class, 'markAllRead']);
            Route::post('notifications/{notification}/read', [ApiNotificationController::class, 'markRead']);
            Route::get('notifications/settings', [NotificationSettingsController::class, 'edit']);
            Route::put('notifications/settings', [NotificationSettingsController::class, 'update']);
            Route::get('pipeline', [PipelineController::class, 'data']);

            Route::prefix('settings')->group(function () {
                Route::middleware('not.superadmin')->group(function () {
                    Route::get('security', [SecuritySettingsController::class, 'edit']);
                    Route::post('security/2fa/app/start', [SecuritySettingsController::class, 'startAppSetup']);
                    Route::post('security/2fa/app/confirm', [SecuritySettingsController::class, 'confirmAppSetup']);
                    Route::post('security/2fa/app/cancel', [SecuritySettingsController::class, 'cancelAppSetup']);
                    Route::post('security/2fa/email', [SecuritySettingsController::class, 'switchToEmail']);
                    Route::post('security/2fa/sms', [SecuritySettingsController::class, 'switchToSms']);
                });

                Route::get('company', [CompanySettingsController::class, 'edit']);
                Route::put('company', [CompanySettingsController::class, 'update']);

                Route::post('categories', [ProductCategoryController::class, 'store']);
                Route::patch('categories/{category}', [ProductCategoryController::class, 'update']);
                Route::patch('categories/{category}/archive', [ProductCategoryController::class, 'archive']);
                Route::patch('categories/{category}/restore', [ProductCategoryController::class, 'restore']);

                Route::get('billing', [BillingSettingsController::class, 'edit']);
                Route::put('billing', [BillingSettingsController::class, 'update']);
                Route::post('billing/checkout', [SubscriptionController::class, 'checkout']);
                Route::post('billing/connect', [BillingSettingsController::class, 'connectStripe']);
                Route::post('billing/assistant-addon', [BillingSettingsController::class, 'updateAssistantAddon']);
                Route::post('billing/assistant-credits', [BillingSettingsController::class, 'createAssistantCreditCheckout']);
                Route::post('billing/swap', [SubscriptionController::class, 'swap']);
                Route::post('billing/portal', [SubscriptionController::class, 'portal']);
                Route::post('billing/payment-method', [SubscriptionController::class, 'paymentMethodTransaction']);

                Route::middleware('company.feature:campaigns')->group(function () {
                    Route::get('marketing', [MarketingSettingsController::class, 'edit']);
                    Route::put('marketing', [MarketingSettingsController::class, 'update']);
                });
            });

            Route::middleware('company.feature:planning')->group(function () {
                Route::get('planning', [PlanningController::class, 'index']);
                Route::get('planning/events', [PlanningController::class, 'events']);
                Route::post('planning/shifts', [PlanningController::class, 'store']);
                Route::patch('planning/shifts/{shift}', [PlanningController::class, 'update']);
                Route::delete('planning/shifts/{shift}', [PlanningController::class, 'destroy']);
                Route::patch('planning/shifts/{shift}/status', [PlanningController::class, 'updateStatus']);
            });

            Route::middleware('company.feature:presence')->group(function () {
                Route::get('presence', [PresenceController::class, 'index']);
                Route::post('presence/clock-in', [PresenceController::class, 'clockIn']);
                Route::post('presence/clock-out', [PresenceController::class, 'clockOut']);
            });

            Route::middleware('company.feature:performance')->group(function () {
                Route::get('performance', [PerformanceController::class, 'index']);
                Route::get('performance/employees/{employee}', [PerformanceController::class, 'employee']);
            });

            Route::prefix('support')->group(function () {
                Route::get('/', [SupportTicketController::class, 'index']);
                Route::get('{ticket}', [SupportTicketController::class, 'show']);
                Route::post('/', [SupportTicketController::class, 'store']);
                Route::put('{ticket}', [SupportTicketController::class, 'update']);
                Route::post('{ticket}/messages', [SupportTicketMessageController::class, 'store']);
            });

            Route::post('ai/images', [AiImageController::class, 'generate'])
                ->middleware('throttle:ai-images');

            Route::middleware('company.feature:assistant')->group(function () {
                Route::post('assistant/message', [AssistantController::class, 'message']);
            });

            Route::middleware('company.feature:requests')->group(function () {
                Route::get('requests', [RequestController::class, 'index']);
                Route::patch('requests/bulk', [RequestController::class, 'bulkUpdate']);
                Route::post('requests', [RequestController::class, 'store']);
                Route::get('requests/{lead}', [RequestController::class, 'show']);
                Route::put('requests/{lead}', [RequestController::class, 'update']);
                Route::post('requests/{lead}/merge', [RequestController::class, 'merge']);
                Route::post('requests/{lead}/convert', [RequestController::class, 'convert'])
                    ->middleware('company.feature:quotes');
                Route::post('requests/{lead}/notes', [RequestNoteController::class, 'store']);
                Route::delete('requests/{lead}/notes/{note}', [RequestNoteController::class, 'destroy']);
                Route::post('requests/{lead}/media', [RequestMediaController::class, 'store']);
                Route::delete('requests/{lead}/media/{media}', [RequestMediaController::class, 'destroy']);
                Route::delete('requests/{lead}', [RequestController::class, 'destroy']);
            });

            Route::middleware('company.feature:reservations')->group(function () {
                Route::get('reservations', [StaffReservationController::class, 'index']);
                Route::get('reservations/events', [StaffReservationController::class, 'events']);
                Route::get('reservations/slots', [StaffReservationController::class, 'slots']);
                Route::post('reservations', [StaffReservationController::class, 'store']);
                Route::put('reservations/{reservation}', [StaffReservationController::class, 'update']);
                Route::patch('reservations/{reservation}/status', [StaffReservationController::class, 'updateStatus']);
                Route::delete('reservations/{reservation}', [StaffReservationController::class, 'destroy']);
                Route::get('settings/reservations', [ReservationSettingsController::class, 'edit']);
                Route::put('settings/reservations', [ReservationSettingsController::class, 'update']);
            });

            Route::middleware('company.feature:quotes')->group(function () {
                Route::get('quotes', [QuoteController::class, 'index']);
                Route::get('customer/{customer}/quote/create', [QuoteController::class, 'create']);
                Route::post('customer/quote/store', [QuoteController::class, 'store']);
                Route::get('customer/quote/{quote}/edit', [QuoteController::class, 'edit']);
                Route::get('customer/quote/{quote}/show', [QuoteController::class, 'show']);
                Route::put('customer/quote/{quote}/update', [QuoteController::class, 'update']);
                Route::delete('customer/quote/{quote}/destroy', [QuoteController::class, 'destroy']);
                Route::post('customer/quote/{quote}/restore', [QuoteController::class, 'restore']);
                Route::post('quote/{quote}/accept', [QuoteController::class, 'accept']);
                Route::post('quote/{quote}/send-email', QuoteEmaillingController::class);
                Route::post('quote/{quote}/convert', [QuoteController::class, 'convertToWork']);
                Route::patch('quote/{quote}/recovery', [QuoteController::class, 'updateRecovery']);

                Route::middleware('company.feature:tasks')->group(function () {
                    Route::post('quote/{quote}/recovery-task', [QuoteController::class, 'storeRecoveryTask']);
                });

                Route::middleware('company.feature:plan_scans')->group(function () {
                    Route::get('plan-scans', [PlanScanController::class, 'index']);
                    Route::get('plan-scans/create', [PlanScanController::class, 'create']);
                    Route::post('plan-scans', [PlanScanController::class, 'store']);
                    Route::get('plan-scans/{planScan}', [PlanScanController::class, 'show']);
                    Route::patch('plan-scans/{planScan}/review', [PlanScanController::class, 'review']);
                    Route::post('plan-scans/{planScan}/reanalyze', [PlanScanController::class, 'reanalyze']);
                    Route::delete('plan-scans/{planScan}', [PlanScanController::class, 'destroy']);
                    Route::post('plan-scans/{planScan}/convert', [PlanScanController::class, 'convert']);
                });
            });

            Route::middleware('company.feature:products')->group(function () {
                Route::get('product/search', ProductsSearchController::class);
                Route::get('product/price-lookup', ProductPriceLookupController::class);
                Route::get('products/options', [ProductController::class, 'options']);
                Route::post('products/quick', [ProductController::class, 'storeQuick']);
                Route::post('products/draft', [ProductController::class, 'storeDraft']);

                Route::post('product/bulk', [ProductController::class, 'bulk']);
                Route::post('product/{product}/duplicate', [ProductController::class, 'duplicate']);
                Route::put('product/{product}/quick-update', [ProductController::class, 'quickUpdate']);
                Route::post('product/{product}/adjust-stock', [ProductController::class, 'adjustStock']);
                Route::post('product/{product}/supplier-email', [ProductController::class, 'requestSupplierStock']);
                Route::get('product/export/csv', [ProductController::class, 'export']);
                Route::post('product/import/csv', [ProductController::class, 'import']);
                Route::apiResource('product', ProductController::class);
            });

            Route::prefix('integrations')->group(function () {
                Route::get('products', [IntegrationInventoryController::class, 'products']);
                Route::get('products/{product}', [IntegrationInventoryController::class, 'product']);
                Route::get('warehouses', [IntegrationInventoryController::class, 'warehouses']);
                Route::get('movements', [IntegrationInventoryController::class, 'movements']);
                Route::get('alerts', [IntegrationInventoryController::class, 'alerts']);
                Route::post('products/{product}/adjust', [IntegrationInventoryController::class, 'adjust']);
                Route::post('requests', [IntegrationRequestController::class, 'store'])
                    ->name('integrations.requests.store');
                Route::post('crm/connector-events', [IntegrationCrmConnectorEventController::class, 'store'])
                    ->name('integrations.crm.connector_events.store');
            });

            Route::middleware('company.feature:services')->group(function () {
                Route::get('services/options', [ServiceController::class, 'options']);
                Route::post('services/quick', [ServiceController::class, 'storeQuick']);
                Route::get('services/categories', [ServiceController::class, 'categories']);

                Route::apiResource('service', ServiceController::class)->only(['index', 'store', 'update', 'destroy']);
            });

            Route::middleware('company.feature:sales')->group(function () {
                Route::get('orders', [SaleController::class, 'ordersIndex']);
                Route::get('sales', [SaleController::class, 'index']);
                Route::get('sales/create', [SaleController::class, 'create']);
                Route::post('sales', [SaleController::class, 'store']);
                Route::get('sales/{sale}', [SaleController::class, 'show']);
                Route::put('sales/{sale}', [SaleController::class, 'update']);
                Route::patch('sales/{sale}/status', [SaleController::class, 'updateStatus']);
                Route::post('sales/{sale}/pickup-confirm', [SaleController::class, 'confirmPickup']);
            });

            Route::get('finance-approvals', [FinanceApprovalInboxController::class, 'index'])
                ->name('finance-approvals.index');

            Route::middleware('company.feature:accounting')->group(function () {
                Route::get('accounting', [AccountingController::class, 'index']);
                Route::get('accounting/export', [AccountingController::class, 'export']);
                Route::get('accounting/exports/{accountingExport}', [AccountingController::class, 'downloadExport']);
                Route::patch('accounting/periods/{periodKey}/open', [AccountingController::class, 'openPeriod'])->where('periodKey', '\d{4}-\d{2}');
                Route::patch('accounting/periods/{periodKey}/in-review', [AccountingController::class, 'markPeriodInReview'])->where('periodKey', '\d{4}-\d{2}');
                Route::patch('accounting/periods/{periodKey}/close', [AccountingController::class, 'closePeriod'])->where('periodKey', '\d{4}-\d{2}');
                Route::patch('accounting/periods/{periodKey}/reopen', [AccountingController::class, 'reopenPeriod'])->where('periodKey', '\d{4}-\d{2}');
                Route::patch('accounting/entries/{accountingEntry}/unreview', [AccountingController::class, 'markEntryUnreviewed']);
                Route::patch('accounting/entries/{accountingEntry}/review', [AccountingController::class, 'markEntryReviewed']);
                Route::patch('accounting/entries/{accountingEntry}/reconcile', [AccountingController::class, 'markEntryReconciled']);
                Route::patch('accounting/batches/{accountingEntryBatch}/unreview', [AccountingController::class, 'markBatchUnreviewed']);
                Route::patch('accounting/batches/{accountingEntryBatch}/review', [AccountingController::class, 'markBatchReviewed']);
                Route::patch('accounting/batches/{accountingEntryBatch}/reconcile', [AccountingController::class, 'markBatchReconciled']);
            });

            Route::middleware('company.feature:expenses')->group(function () {
                Route::get('expenses', [ExpenseController::class, 'index']);
                Route::get('expenses/export', [ExpenseController::class, 'export']);
                Route::post('expenses', [ExpenseController::class, 'store']);
                Route::post('expenses/scan-ai', [ExpenseController::class, 'scanWithAi'])
                    ->middleware('company.feature:assistant');
                Route::patch('expenses/{expense}/submit', [ExpenseController::class, 'submit']);
                Route::patch('expenses/{expense}/approve', [ExpenseController::class, 'approve']);
                Route::patch('expenses/{expense}/reject', [ExpenseController::class, 'reject']);
                Route::patch('expenses/{expense}/mark-due', [ExpenseController::class, 'markDue']);
                Route::patch('expenses/{expense}/mark-paid', [ExpenseController::class, 'markPaid']);
                Route::patch('expenses/{expense}/mark-reimbursed', [ExpenseController::class, 'markReimbursed']);
                Route::patch('expenses/{expense}/cancel', [ExpenseController::class, 'cancel']);
                Route::get('expenses/{expense}', [ExpenseController::class, 'show']);
                Route::put('expenses/{expense}', [ExpenseController::class, 'update']);
                Route::delete('expenses/{expense}', [ExpenseController::class, 'destroy']);
            });

            Route::middleware('company.feature:campaigns')->group(function () {
                Route::get('offers/search', [OfferSearchController::class, 'search']);
                Route::get('marketing/meta', MarketingMetaController::class);
                Route::get('marketing/dashboard/kpis', MarketingDashboardKpiController::class);
                Route::get('marketing/templates', [MarketingTemplateController::class, 'index']);
                Route::post('marketing/templates', [MarketingTemplateController::class, 'store']);
                Route::get('marketing/templates/{template}', [MarketingTemplateController::class, 'show']);
                Route::post('marketing/templates/{template}/duplicate', [MarketingTemplateController::class, 'duplicate']);
                Route::put('marketing/templates/{template}', [MarketingTemplateController::class, 'update']);
                Route::delete('marketing/templates/{template}', [MarketingTemplateController::class, 'destroy']);
                Route::post('marketing/templates/preview', [MarketingTemplateController::class, 'preview']);
                Route::post('marketing/templates/{template}/preview', [MarketingTemplateController::class, 'previewTemplate']);

                Route::get('marketing/segments', [MarketingSegmentController::class, 'index']);
                Route::post('marketing/segments', [MarketingSegmentController::class, 'store']);
                Route::get('marketing/segments/{segment}', [MarketingSegmentController::class, 'show']);
                Route::put('marketing/segments/{segment}', [MarketingSegmentController::class, 'update']);
                Route::delete('marketing/segments/{segment}', [MarketingSegmentController::class, 'destroy']);
                Route::post('marketing/segments/preview-count', [MarketingSegmentController::class, 'previewCount']);
                Route::get('marketing/segments/{segment}/count', [MarketingSegmentController::class, 'count']);

                Route::get('marketing/mailing-lists', [MarketingMailingListController::class, 'index']);
                Route::post('marketing/mailing-lists', [MarketingMailingListController::class, 'store']);
                Route::get('marketing/mailing-lists/{mailingList}', [MarketingMailingListController::class, 'show']);
                Route::put('marketing/mailing-lists/{mailingList}', [MarketingMailingListController::class, 'update']);
                Route::delete('marketing/mailing-lists/{mailingList}', [MarketingMailingListController::class, 'destroy']);
                Route::post('marketing/mailing-lists/{mailingList}/import', [MarketingMailingListController::class, 'import']);
                Route::post('marketing/mailing-lists/{mailingList}/sync-customers', [MarketingMailingListController::class, 'syncCustomers']);
                Route::post('marketing/mailing-lists/{mailingList}/remove-customers', [MarketingMailingListController::class, 'removeCustomers']);
                Route::get('marketing/mailing-lists/{mailingList}/count', [MarketingMailingListController::class, 'count']);

                Route::get('marketing/vip-tiers', [MarketingVipController::class, 'index']);
                Route::post('marketing/vip-tiers', [MarketingVipController::class, 'store']);
                Route::put('marketing/vip-tiers/{vipTier}', [MarketingVipController::class, 'update']);
                Route::delete('marketing/vip-tiers/{vipTier}', [MarketingVipController::class, 'destroy']);
                Route::patch('marketing/customers/{customer}/vip', [MarketingVipController::class, 'updateCustomer']);
                Route::get('marketing/prospect-providers', [MarketingProspectProviderConnectionController::class, 'index']);
                Route::post('marketing/prospect-providers', [MarketingProspectProviderConnectionController::class, 'store']);
                Route::put('marketing/prospect-providers/{connection}', [MarketingProspectProviderConnectionController::class, 'update']);
                Route::post('marketing/prospect-providers/{connection}/validate', [MarketingProspectProviderConnectionController::class, 'validateConnection']);
                Route::post('marketing/prospect-providers/{connection}/disconnect', [MarketingProspectProviderConnectionController::class, 'disconnect']);

                Route::get('campaigns', [CampaignController::class, 'index']);
                Route::get('campaigns/create', [CampaignController::class, 'create']);
                Route::post('campaigns', [CampaignController::class, 'store']);
                Route::get('campaigns/{campaign}', [CampaignController::class, 'show']);
                Route::get('campaigns/{campaign}/edit', [CampaignController::class, 'edit']);
                Route::put('campaigns/{campaign}', [CampaignController::class, 'update']);
                Route::delete('campaigns/{campaign}', [CampaignController::class, 'destroy']);
                Route::post('campaigns/{campaign}/estimate', [CampaignRunController::class, 'estimate']);
                Route::post('campaigns/{campaign}/preview', [CampaignRunController::class, 'preview']);
                Route::post('campaigns/{campaign}/test-send', [CampaignRunController::class, 'testSend']);
                Route::get('campaigns/{campaign}/prospect-batches', [CampaignProspectingController::class, 'batches']);
                Route::post('campaigns/{campaign}/prospect-provider-preview', [CampaignProspectingController::class, 'providerPreview']);
                Route::post('campaigns/{campaign}/prospect-batches/import', [CampaignProspectingController::class, 'import']);
                Route::get('campaigns/{campaign}/prospect-batches/{batch}', [CampaignProspectingController::class, 'showBatch']);
                Route::post('campaigns/{campaign}/prospect-batches/{batch}/approve', [CampaignProspectingController::class, 'approveBatch']);
                Route::post('campaigns/{campaign}/prospect-batches/{batch}/reject', [CampaignProspectingController::class, 'rejectBatch']);
                Route::get('campaigns/{campaign}/prospects', [CampaignProspectingController::class, 'prospects']);
                Route::get('campaigns/{campaign}/lead-options', [CampaignProspectingController::class, 'leadOptions']);
                Route::patch('campaigns/{campaign}/prospects/bulk-status', [CampaignProspectingController::class, 'bulkUpdateProspects']);
                Route::get('campaigns/{campaign}/prospects/{prospect}', [CampaignProspectingController::class, 'showProspect']);
                Route::patch('campaigns/{campaign}/prospects/{prospect}/status', [CampaignProspectingController::class, 'updateProspectStatus']);
                Route::post('campaigns/{campaign}/prospects/{prospect}/convert-to-lead', [CampaignProspectingController::class, 'convertProspectToLead']);
                Route::post('campaigns/{campaign}/prospects/{prospect}/link-to-lead', [CampaignProspectingController::class, 'linkProspectToLead']);
                Route::post('campaigns/{campaign}/send', [CampaignRunController::class, 'sendNow']);
                Route::post('campaigns/{campaign}/schedule', [CampaignRunController::class, 'schedule']);
                Route::post('campaigns/{campaign}/conversions', [CampaignRunController::class, 'recordConversion']);
                Route::get('campaign-runs/{run}/export', [CampaignRunController::class, 'exportRecipients']);
                Route::post('campaigns/reconcile', [CampaignTrackingController::class, 'reconcile']);
                Route::get('campaign-automations', [CampaignAutomationController::class, 'index']);
                Route::post('campaign-automations', [CampaignAutomationController::class, 'store']);
                Route::put('campaign-automations/{rule}', [CampaignAutomationController::class, 'update']);
                Route::delete('campaign-automations/{rule}', [CampaignAutomationController::class, 'destroy']);
            });

            Route::get('customers/options', [CustomerController::class, 'options']);
            Route::post('customers/quick', [CustomerController::class, 'storeQuick']);

            Route::scopeBindings()->group(function () {
                Route::post('customer/{customer}/properties', [CustomerPropertyController::class, 'store']);
                Route::put('customer/{customer}/properties/{property}', [CustomerPropertyController::class, 'update']);
                Route::delete('customer/{customer}/properties/{property}', [CustomerPropertyController::class, 'destroy']);
                Route::put('customer/{customer}/properties/{property}/default', [CustomerPropertyController::class, 'setDefault']);
            });

            Route::patch('customer/{customer}/notes', [CustomerController::class, 'updateNotes']);
            Route::patch('customer/{customer}/tags', [CustomerController::class, 'updateTags']);
            Route::patch('customer/{customer}/auto-validation', [CustomerController::class, 'updateAutoValidation']);
            Route::apiResource('customer', CustomerController::class)->only(['index', 'store', 'update', 'show', 'destroy']);

            Route::middleware('company.feature:jobs')->group(function () {
                Route::get('jobs', [WorkController::class, 'index']);
                Route::get('work/create/{customer}', [WorkController::class, 'create']);
                Route::get('work/{work}/edit', [WorkController::class, 'edit']);
                Route::apiResource('work', WorkController::class)->except(['create', 'edit']);
                Route::get('work/{work}/proofs', [WorkProofController::class, 'show']);
                Route::post('work/{work}/status', [WorkController::class, 'updateStatus']);
                Route::post('work/{work}/extras', [WorkController::class, 'addExtraQuote']);
                Route::post('work/{work}/media', [WorkMediaController::class, 'store']);
                Route::patch('work/{work}/checklist/{item}', [WorkChecklistController::class, 'update']);
            });

            Route::middleware('company.feature:team_members')->group(function () {
                Route::get('team', [TeamMemberController::class, 'index']);
                Route::post('team', [TeamMemberController::class, 'store']);
                Route::put('team/{teamMember}', [TeamMemberController::class, 'update']);
                Route::delete('team/{teamMember}', [TeamMemberController::class, 'destroy']);
            });

            Route::middleware('company.feature:tasks')->group(function () {
                Route::get('tasks', [TaskController::class, 'index']);
                Route::get('tasks/{task}', [TaskController::class, 'show']);
                Route::post('tasks', [TaskController::class, 'store']);
                Route::put('tasks/{task}', [TaskController::class, 'update']);
                Route::patch('tasks/{task}/assignee', [TaskController::class, 'assign']);
                Route::delete('tasks/{task}', [TaskController::class, 'destroy']);
                Route::post('tasks/{task}/media', [TaskMediaController::class, 'store']);
            });

            Route::middleware('company.feature:invoices')->group(function () {
                Route::get('invoices', [InvoiceController::class, 'index']);
                Route::get('invoices/{invoice}', [InvoiceController::class, 'show']);
                Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'pdf']);
                Route::post('invoices/{invoice}/send-email', [InvoiceController::class, 'sendEmail']);
                Route::post('work/{work}/invoice', [InvoiceController::class, 'storeFromWork']);
            });

            Route::post('invoice/{invoice}/payments', [PaymentController::class, 'store']);
        });
    });

    Route::middleware(['auth:sanctum', EnsurePlatformAdmin::class, EnsureNotSuspended::class])
        ->prefix('super-admin')
        ->name('super-admin.')
        ->group(function () {
            Route::get('dashboard', [SuperAdminDashboardController::class, 'index']);
            Route::get('admins', [SuperAdminAdminController::class, 'index']);
            Route::post('admins', [SuperAdminAdminController::class, 'store']);
            Route::put('admins/{admin}', [SuperAdminAdminController::class, 'update']);
            Route::get('announcements', [SuperAdminAnnouncementController::class, 'index']);
            Route::post('announcements', [SuperAdminAnnouncementController::class, 'store']);
            Route::put('announcements/{announcement}', [SuperAdminAnnouncementController::class, 'update']);
            Route::delete('announcements/{announcement}', [SuperAdminAnnouncementController::class, 'destroy']);
            Route::get('support', [SuperAdminSupportController::class, 'index']);
            Route::post('support', [SuperAdminSupportController::class, 'store']);
            Route::put('support/{ticket}', [SuperAdminSupportController::class, 'update']);
            Route::get('settings', [SuperAdminPlatformSettingsController::class, 'show']);
            Route::put('settings', [SuperAdminPlatformSettingsController::class, 'update']);
            Route::get('tenants', [SuperAdminTenantController::class, 'index']);
            Route::get('tenants/{tenant}', [SuperAdminTenantController::class, 'show']);
            Route::post('tenants/{tenant}/suspend', [SuperAdminTenantController::class, 'suspend']);
            Route::post('tenants/{tenant}/restore', [SuperAdminTenantController::class, 'restore']);
            Route::put('tenants/{tenant}/security', [SuperAdminTenantController::class, 'updateSecurity']);
            Route::put('tenants/{tenant}/features', [SuperAdminTenantController::class, 'updateFeatures']);
            Route::put('tenants/{tenant}/limits', [SuperAdminTenantController::class, 'updateLimits']);
            Route::put('tenants/{tenant}/plan', [SuperAdminTenantController::class, 'updatePlan']);
        });
});
