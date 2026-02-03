<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RequestController;
use App\Http\Controllers\RequestMediaController;
use App\Http\Controllers\RequestNoteController;
use App\Http\Controllers\QuoteController;
use App\Http\Controllers\QuoteEmaillingController;
use App\Http\Controllers\PlanScanController;
use App\Http\Controllers\ProductsSearchController;
use App\Http\Controllers\ProductPriceLookupController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerPropertyController;
use App\Http\Controllers\WorkController;
use App\Http\Controllers\WorkProofController;
use App\Http\Controllers\WorkMediaController;
use App\Http\Controllers\WorkChecklistController;
use App\Http\Controllers\TeamMemberController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TaskMediaController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\Portal\PortalInvoiceController;
use App\Http\Controllers\Portal\PortalQuoteController;
use App\Http\Controllers\Portal\PortalRatingController;
use App\Http\Controllers\Portal\PortalTaskMediaController;
use App\Http\Controllers\Portal\PortalWorkController;
use App\Http\Controllers\Portal\PortalWorkProofController;
use App\Http\Controllers\Portal\PortalProductOrderController;
use App\Http\Controllers\Portal\PortalNotificationController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\Billing\StripeWebhookController;
use App\Http\Controllers\Settings\CompanySettingsController;
use App\Http\Controllers\Settings\BillingSettingsController;
use App\Http\Controllers\Settings\ProductCategoryController;
use App\Http\Controllers\Settings\NotificationSettingsController;
use App\Http\Controllers\Settings\SubscriptionController;
use App\Http\Controllers\Api\NotificationController as ApiNotificationController;
use App\Http\Controllers\Api\PushTokenController;
use App\Http\Controllers\Api\SuperAdmin\AdminController as SuperAdminAdminController;
use App\Http\Controllers\Api\SuperAdmin\AnnouncementController as SuperAdminAnnouncementController;
use App\Http\Controllers\Api\SuperAdmin\DashboardController as SuperAdminDashboardController;
use App\Http\Controllers\Api\SuperAdmin\PlatformSettingsController as SuperAdminPlatformSettingsController;
use App\Http\Controllers\Api\SuperAdmin\SupportController as SuperAdminSupportController;
use App\Http\Controllers\Api\SuperAdmin\TenantController as SuperAdminTenantController;
use App\Http\Controllers\Api\Integration\InventoryController as IntegrationInventoryController;
use App\Http\Controllers\Api\Integration\RequestController as IntegrationRequestController;
use App\Http\Middleware\EnsureClientUser;
use App\Http\Middleware\EnsureInternalUser;
use App\Http\Middleware\EnsureNotSuspended;
use App\Http\Middleware\EnsureOnboardingIsComplete;
use App\Http\Middleware\EnsurePlatformAdmin;

Route::name('api.')->group(function () {
    Route::post('stripe/webhook', [StripeWebhookController::class, 'handle'])->name('stripe.webhook');

    Route::prefix('auth')->group(function () {
        Route::post('login', [AuthController::class, 'login']);
        Route::post('register', [AuthController::class, 'register']);
        Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
        Route::post('reset-password', [AuthController::class, 'resetPassword']);
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
        });

    Route::middleware(['auth:sanctum', EnsureInternalUser::class, EnsureNotSuspended::class])->group(function () {

        Route::get('onboarding', [OnboardingController::class, 'index']);
        Route::post('onboarding', [OnboardingController::class, 'store']);

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

            Route::prefix('settings')->group(function () {
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
                Route::post('billing/swap', [SubscriptionController::class, 'swap']);
                Route::post('billing/portal', [SubscriptionController::class, 'portal']);
                Route::post('billing/payment-method', [SubscriptionController::class, 'paymentMethodTransaction']);
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

                Route::middleware('company.feature:plan_scans')->group(function () {
                    Route::get('plan-scans', [PlanScanController::class, 'index']);
                    Route::get('plan-scans/create', [PlanScanController::class, 'create']);
                    Route::post('plan-scans', [PlanScanController::class, 'store']);
                    Route::get('plan-scans/{planScan}', [PlanScanController::class, 'show']);
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
                Route::delete('tasks/{task}', [TaskController::class, 'destroy']);
                Route::post('tasks/{task}/media', [TaskMediaController::class, 'store']);
            });

            Route::middleware('company.feature:invoices')->group(function () {
                Route::get('invoices', [InvoiceController::class, 'index']);
                Route::get('invoices/{invoice}', [InvoiceController::class, 'show']);
                Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'pdf']);
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
