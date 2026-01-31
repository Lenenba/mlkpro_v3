<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\WorkController;
use App\Http\Controllers\QuoteController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\SalePaymentController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductPriceLookupController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TaskMediaController;
use App\Http\Controllers\TeamMemberController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DemoController;
use App\Http\Controllers\DemoTourController;
use App\Http\Controllers\ProductsSearchController;
use App\Http\Controllers\QuoteEmaillingController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\PipelineController;
use App\Http\Controllers\RequestController;
use App\Http\Controllers\RequestMediaController;
use App\Http\Controllers\RequestNoteController;
use App\Http\Controllers\PlanScanController;
use App\Http\Controllers\WorkMediaController;
use App\Http\Controllers\WorkChecklistController;
use App\Http\Controllers\WelcomeController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\PublicInvoiceController;
use App\Http\Controllers\PublicPageController;
use App\Http\Controllers\PublicStoreController;
use App\Http\Controllers\PublicQuoteController;
use App\Http\Controllers\PublicRequestController;
use App\Http\Controllers\PublicWorkController;
use App\Http\Controllers\PublicWorkProofController;
use App\Http\Controllers\PublicTaskMediaController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\AssistantController;
use App\Http\Controllers\AiImageController;
use App\Http\Controllers\Settings\CompanySettingsController;
use App\Http\Controllers\Settings\BillingSettingsController;
use App\Http\Controllers\Settings\ProductCategoryController;
use App\Http\Controllers\Settings\SubscriptionController;
use App\Http\Controllers\Settings\NotificationSettingsController;
use App\Http\Controllers\Settings\ApiTokenController;
use App\Http\Controllers\WarehouseController;
use App\Http\Controllers\SupportTicketController;
use App\Http\Controllers\SupportTicketMessageController;
use App\Http\Controllers\SuperAdmin\DashboardController as SuperAdminDashboardController;
use App\Http\Controllers\SuperAdmin\TenantController as SuperAdminTenantController;
use App\Http\Controllers\SuperAdmin\AdminController as SuperAdminAdminController;
use App\Http\Controllers\SuperAdmin\NotificationController as SuperAdminNotificationController;
use App\Http\Controllers\SuperAdmin\PlatformSettingsController as SuperAdminPlatformSettingsController;
use App\Http\Controllers\SuperAdmin\AiImageController as SuperAdminAiImageController;
use App\Http\Controllers\SuperAdmin\WelcomeBuilderController as SuperAdminWelcomeBuilderController;
use App\Http\Controllers\SuperAdmin\PlatformPageController as SuperAdminPlatformPageController;
use App\Http\Controllers\SuperAdmin\PlatformSectionController as SuperAdminPlatformSectionController;
use App\Http\Controllers\SuperAdmin\PlatformAssetController as SuperAdminPlatformAssetController;
use App\Http\Controllers\SuperAdmin\SupportTicketController as SuperAdminSupportTicketController;
use App\Http\Controllers\SuperAdmin\SupportTicketMessageController as SuperAdminSupportTicketMessageController;
use App\Http\Controllers\SuperAdmin\AnnouncementController as SuperAdminAnnouncementController;
use App\Http\Controllers\CustomerPropertyController;
use App\Http\Controllers\Portal\PortalInvoiceController;
use App\Http\Controllers\Portal\PortalProductOrderController;
use App\Http\Controllers\Portal\PortalQuoteController;
use App\Http\Controllers\Portal\PortalRatingController;
use App\Http\Controllers\Portal\PortalReviewController;
use App\Http\Controllers\Portal\PortalTaskMediaController;
use App\Http\Controllers\Portal\PortalWorkProofController;
use App\Http\Controllers\Portal\PortalWorkController;
use App\Http\Controllers\WorkProofController;
use App\Http\Middleware\EnsureClientUser;
use App\Http\Middleware\EnsureInternalUser;
use App\Http\Middleware\EnsurePlatformAdmin;

Route::get('/favicon.ico', function () {
    return response()->file(public_path('favicon.ico'));
})->name('favicon');

// Paddle webhook is registered by Cashier Paddle at `/{CASHIER_PATH}/webhook`.

// Guest Routes
Route::get('/', [WelcomeController::class, 'index'])->name('welcome');
Route::get('/terms', [LegalController::class, 'terms'])->name('terms');
Route::get('/privacy', [LegalController::class, 'privacy'])->name('privacy');
Route::get('/refund', [LegalController::class, 'refund'])->name('refund');
Route::get('/pricing', [LegalController::class, 'pricing'])->name('pricing');
Route::get('/pages/{slug}', [PublicPageController::class, 'show'])->name('public.pages.show');
Route::get('/store/{slug}', [PublicStoreController::class, 'show'])->name('public.store.show');
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

Route::middleware('signed')->group(function () {
    Route::get('/pay/invoices/{invoice}', [PublicInvoiceController::class, 'show'])->name('public.invoices.show');
    Route::post('/pay/invoices/{invoice}', [PublicInvoiceController::class, 'storePayment'])->name('public.invoices.pay');
    Route::post('/pay/invoices/{invoice}/stripe', [PublicInvoiceController::class, 'createStripeCheckout'])
        ->name('public.invoices.stripe');
    Route::get('/public/quotes/{quote}', [PublicQuoteController::class, 'show'])->name('public.quotes.show');
    Route::post('/public/quotes/{quote}/accept', [PublicQuoteController::class, 'accept'])->name('public.quotes.accept');
    Route::post('/public/quotes/{quote}/decline', [PublicQuoteController::class, 'decline'])->name('public.quotes.decline');
    Route::get('/public/requests/{user}', [PublicRequestController::class, 'show'])->name('public.requests.form');
    Route::post('/public/requests/{user}', [PublicRequestController::class, 'store'])->name('public.requests.store');
    Route::get('/public/works/{work}', [PublicWorkController::class, 'show'])->name('public.works.show');
    Route::post('/public/works/{work}/validate', [PublicWorkController::class, 'validateWork'])->name('public.works.validate');
    Route::post('/public/works/{work}/dispute', [PublicWorkController::class, 'dispute'])->name('public.works.dispute');
    Route::post('/public/works/{work}/schedule/confirm', [PublicWorkController::class, 'confirmSchedule'])
        ->name('public.works.schedule.confirm');
    Route::post('/public/works/{work}/schedule/reject', [PublicWorkController::class, 'rejectSchedule'])
        ->name('public.works.schedule.reject');
    Route::get('/public/works/{work}/proofs', [PublicWorkProofController::class, 'show'])->name('public.works.proofs');
    Route::post('/public/tasks/{task}/media', [PublicTaskMediaController::class, 'store'])->name('public.tasks.media.store');
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
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])
        ->name('notifications.read-all');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead'])
        ->name('notifications.read');
});

// Internal User Routes
Route::middleware(['auth', EnsureInternalUser::class, 'demo.safe'])->group(function () {

    // Onboarding (account setup)
    Route::post('/onboarding', [OnboardingController::class, 'store'])->name('onboarding.store');
    Route::post('/assistant/message', [AssistantController::class, 'message'])
        ->middleware('company.feature:assistant')
        ->name('assistant.message');
    Route::post('/ai/images', [AiImageController::class, 'generate'])->name('ai.images.generate');
    Route::get('/pipeline/timeline/{entityType}/{entityId}', [PipelineController::class, 'timeline'])
        ->name('pipeline.timeline');
    Route::get('/pipeline', [PipelineController::class, 'data'])->name('pipeline.data');

    Route::get('/settings/support', [SupportTicketController::class, 'index'])->name('settings.support.index');
    Route::get('/settings/support/{ticket}', [SupportTicketController::class, 'show'])->name('settings.support.show');
    Route::post('/settings/support', [SupportTicketController::class, 'store'])->name('settings.support.store');
    Route::put('/settings/support/{ticket}', [SupportTicketController::class, 'update'])->name('settings.support.update');
    Route::post('/settings/support/{ticket}/messages', [SupportTicketMessageController::class, 'store'])
        ->name('settings.support.messages.store');

    // Settings (owner only)
    Route::get('/settings/company', [CompanySettingsController::class, 'edit'])->name('settings.company.edit');
    Route::put('/settings/company', [CompanySettingsController::class, 'update'])->name('settings.company.update');
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
    Route::get('/settings/notifications', [NotificationSettingsController::class, 'edit'])
        ->name('settings.notifications.edit');
    Route::put('/settings/notifications', [NotificationSettingsController::class, 'update'])
        ->name('settings.notifications.update');
    Route::post('/settings/billing/swap', [SubscriptionController::class, 'swap'])->name('settings.billing.swap');
    Route::post('/settings/billing/portal', [SubscriptionController::class, 'portal'])->name('settings.billing.portal');
    Route::post('/settings/billing/payment-method', [SubscriptionController::class, 'paymentMethodTransaction'])
        ->name('settings.billing.payment-method');

    // Lead Requests
    Route::middleware('company.feature:requests')->group(function () {
        Route::get('/requests', [RequestController::class, 'index'])->name('request.index');
        Route::patch('/requests/bulk', [RequestController::class, 'bulkUpdate'])->name('request.bulk');
        Route::post('/requests', [RequestController::class, 'store'])->name('request.store');
        Route::post('/requests/import', [RequestController::class, 'import'])->name('request.import');
        Route::get('/requests/{lead}', [RequestController::class, 'show'])->name('request.show');
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

    Route::middleware('company.feature:quotes')->group(function () {
        Route::get('/quotes', [QuoteController::class, 'index'])->name('quote.index');
        Route::get('/customer/{customer}/quote/create', [QuoteController::class, 'create'])->name('customer.quote.create');
        Route::post('/customer/quote/store', [QuoteController::class, 'store'])->name('customer.quote.store');
        Route::get('/customer/quote/{quote}/edit', [QuoteController::class, 'edit'])->name('customer.quote.edit');
        Route::get('/customer/quote/{quote}/show', [QuoteController::class, 'show'])->name('customer.quote.show');
        Route::put('/customer/quote/{quote}/update', [QuoteController::class, 'update'])->name('customer.quote.update');
        Route::delete('/customer/quote/{quote}/destroy', [QuoteController::class, 'destroy'])->name('customer.quote.destroy');
        Route::post('/customer/quote/{quote}/restore', [QuoteController::class, 'restore'])->name('customer.quote.restore');
        Route::post('/quote/{quote}/accept', [QuoteController::class, 'accept'])->name('quote.accept');
        Route::post('/quote/{quote}/send-email', QuoteEmaillingController::class)->name('quote.send.email');
        Route::post('/quote/{quote}/convert', [QuoteController::class, 'convertToWork'])->name('quote.convert');

        Route::middleware('company.feature:plan_scans')->group(function () {
            Route::get('/plan-scans', [PlanScanController::class, 'index'])->name('plan-scans.index');
            Route::get('/plan-scans/create', [PlanScanController::class, 'create'])->name('plan-scans.create');
            Route::post('/plan-scans', [PlanScanController::class, 'store'])->name('plan-scans.store');
            Route::get('/plan-scans/{planScan}', [PlanScanController::class, 'show'])->name('plan-scans.show');
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
    Route::patch('/customer/{customer}/tags', [CustomerController::class, 'updateTags'])
        ->name('customer.tags.update');
    Route::patch('/customer/{customer}/auto-validation', [CustomerController::class, 'updateAutoValidation'])
        ->name('customer.auto-validation.update');
    Route::post('/customer/bulk', [CustomerController::class, 'bulk'])
        ->name('customer.bulk');

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
        Route::delete('/tasks/{task}', [TaskController::class, 'destroy'])->name('task.destroy');
        Route::post('/tasks/{task}/media', [TaskMediaController::class, 'store'])->name('task.media.store');
    });

    // Invoice Management
    Route::middleware('company.feature:invoices')->group(function () {
        Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoice.index');
        Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoice.show');
        Route::get('/invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->name('invoice.pdf');
        Route::post('/work/{work}/invoice', [InvoiceController::class, 'storeFromWork'])->name('invoice.store-from-work');
    });

// Payment Management
Route::post('/invoice/{invoice}/payments', [PaymentController::class, 'store'])->name('payment.store');
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

// Authentication Routes
require __DIR__ . '/auth.php';
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
            Route::put('/tenants/{tenant}/plan', [SuperAdminTenantController::class, 'updatePlan'])->name('tenants.plan.update');
            Route::post('/tenants/{tenant}/impersonate', [SuperAdminTenantController::class, 'impersonate'])->name('tenants.impersonate');
            Route::get('/tenants/{tenant}/export', [SuperAdminTenantController::class, 'export'])->name('tenants.export');

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

            Route::get('/welcome-builder', [SuperAdminWelcomeBuilderController::class, 'edit'])->name('welcome.edit');
            Route::put('/welcome-builder', [SuperAdminWelcomeBuilderController::class, 'update'])->name('welcome.update');

            Route::get('/pages', [SuperAdminPlatformPageController::class, 'index'])->name('pages.index');
            Route::get('/pages/create', [SuperAdminPlatformPageController::class, 'create'])->name('pages.create');
            Route::post('/pages', [SuperAdminPlatformPageController::class, 'store'])->name('pages.store');
            Route::get('/pages/{page}/edit', [SuperAdminPlatformPageController::class, 'edit'])->name('pages.edit');
            Route::put('/pages/{page}', [SuperAdminPlatformPageController::class, 'update'])->name('pages.update');
            Route::delete('/pages/{page}', [SuperAdminPlatformPageController::class, 'destroy'])->name('pages.destroy');

            Route::get('/sections', [SuperAdminPlatformSectionController::class, 'index'])->name('sections.index');
            Route::get('/sections/create', [SuperAdminPlatformSectionController::class, 'create'])->name('sections.create');
            Route::post('/sections', [SuperAdminPlatformSectionController::class, 'store'])->name('sections.store');
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
