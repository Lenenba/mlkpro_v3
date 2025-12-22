<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\WorkController;
use App\Http\Controllers\QuoteController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TeamMemberController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProductsSearchController;
use App\Http\Controllers\QuoteEmaillingController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\RequestController;
use App\Http\Controllers\WorkMediaController;
use App\Http\Controllers\WorkChecklistController;
use App\Http\Controllers\WelcomeController;
use App\Http\Controllers\Settings\CompanySettingsController;
use App\Http\Controllers\Settings\BillingSettingsController;
use App\Http\Controllers\Settings\ProductCategoryController;
use App\Http\Controllers\Settings\SubscriptionController;
use App\Http\Controllers\SuperAdmin\DashboardController as SuperAdminDashboardController;
use App\Http\Controllers\SuperAdmin\TenantController as SuperAdminTenantController;
use App\Http\Controllers\SuperAdmin\AdminController as SuperAdminAdminController;
use App\Http\Controllers\SuperAdmin\NotificationController as SuperAdminNotificationController;
use App\Http\Controllers\SuperAdmin\PlatformSettingsController as SuperAdminPlatformSettingsController;
use App\Http\Controllers\SuperAdmin\SupportTicketController as SuperAdminSupportTicketController;
use App\Http\Controllers\CustomerPropertyController;
use App\Http\Controllers\Portal\PortalInvoiceController;
use App\Http\Controllers\Portal\PortalQuoteController;
use App\Http\Controllers\Portal\PortalRatingController;
use App\Http\Controllers\Portal\PortalWorkController;
use App\Http\Middleware\EnsureClientUser;
use App\Http\Middleware\EnsureInternalUser;
use App\Http\Middleware\EnsurePlatformAdmin;
use Laravel\Cashier\Http\Controllers\WebhookController as CashierWebhookController;

Route::get('/favicon.ico', function () {
    return response()->file(public_path('favicon.ico'));
})->name('favicon');

Route::post('/stripe/webhook', [CashierWebhookController::class, 'handleWebhook'])
    ->name('cashier.webhook');

// Guest Routes
Route::get('/', [WelcomeController::class, 'index'])->name('welcome');

// Dashboard Route
Route::get('/dashboard', [DashboardController::class, 'index'])->middleware(['auth', 'verified'])->name('dashboard');

// Authenticated User Routes
Route::middleware('auth')->group(function () {
    // Profile Management
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// Internal User Routes
Route::middleware(['auth', EnsureInternalUser::class])->group(function () {

    // Onboarding (account setup)
    Route::get('/onboarding', [OnboardingController::class, 'index'])->name('onboarding.index');
    Route::post('/onboarding', [OnboardingController::class, 'store'])->name('onboarding.store');

    // Settings (owner only)
    Route::get('/settings/company', [CompanySettingsController::class, 'edit'])->name('settings.company.edit');
    Route::put('/settings/company', [CompanySettingsController::class, 'update'])->name('settings.company.update');
    Route::post('/settings/categories', [ProductCategoryController::class, 'store'])->name('settings.categories.store');
    Route::get('/settings/billing', [BillingSettingsController::class, 'edit'])->name('settings.billing.edit');
    Route::put('/settings/billing', [BillingSettingsController::class, 'update'])->name('settings.billing.update');
    Route::post('/settings/billing/subscribe', [SubscriptionController::class, 'checkout'])->name('settings.billing.subscribe');
    Route::post('/settings/billing/portal', [SubscriptionController::class, 'portal'])->name('settings.billing.portal');

    // Lead Requests
    Route::post('/requests', [RequestController::class, 'store'])->name('request.store');
    Route::post('/requests/{lead}/convert', [RequestController::class, 'convert'])->name('request.convert');

    Route::middleware('company.feature:quotes')->group(function () {
        Route::get('/quotes', [QuoteController::class, 'index'])->name('quote.index');
        Route::get('/customer/{customer}/quote/create', [QuoteController::class, 'create'])->name('customer.quote.create');
        Route::post('/customer/quote/store', [QuoteController::class, 'store'])->name('customer.quote.store');
        Route::get('/customer/quote/{quote}/edit', [QuoteController::class, 'edit'])->name('customer.quote.edit');
        Route::get('/customer/quote/{quote}/show', [QuoteController::class, 'show'])->name('customer.quote.show');
        Route::put('/customer/quote/{quote}/update', [QuoteController::class, 'update'])->name('customer.quote.update');
        Route::delete('/customer/quote/{quote}/destroy', [QuoteController::class, 'destroy'])->name('customer.quote.destroy');
        Route::post('/quote/{quote}/accept', [QuoteController::class, 'accept'])->name('quote.accept');
        Route::post('/quote/{quote}/send-email', QuoteEmaillingController::class)->name('quote.send.email');
        Route::post('/quote/{quote}/convert', [QuoteController::class, 'convertToWork'])->name('quote.convert');
    });

    // Product custom search
    Route::middleware('company.feature:products')->group(function () {
        Route::get('/product/search', ProductsSearchController::class)->name('product.search');
        Route::get('/products/options', [ProductController::class, 'options'])->name('product.options');
        Route::post('/products/quick', [ProductController::class, 'storeQuick'])->name('product.quick.store');
    });

    Route::middleware('company.feature:services')->group(function () {
        Route::get('/services/options', [ServiceController::class, 'options'])->name('service.options');
        Route::post('/services/quick', [ServiceController::class, 'storeQuick'])->name('service.quick.store');
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
        Route::put('/product/{product}/quick-update', [ProductController::class, 'quickUpdate'])->name('product.quick-update');
        Route::post('/product/{product}/adjust-stock', [ProductController::class, 'adjustStock'])->name('product.adjust-stock');
        Route::get('/product/export/csv', [ProductController::class, 'export'])->name('product.export');
        Route::post('/product/import/csv', [ProductController::class, 'import'])->name('product.import');
        Route::resource('product', ProductController::class);
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

    Route::resource('customer', CustomerController::class)
        ->only(['index', 'store', 'update', 'create', 'show', 'destroy']);

    // Work Management
    Route::middleware('company.feature:jobs')->group(function () {
        Route::get('/jobs', [WorkController::class, 'index'])->name('jobs.index');
        Route::get('/work/create/{customer}', [WorkController::class, 'create'])
            ->name('work.create');

        Route::resource('work', WorkController::class)
            ->except(['create']);
        Route::post('/work/{work}/status', [WorkController::class, 'updateStatus'])->name('work.status');
        Route::post('/work/{work}/extras', [WorkController::class, 'addExtraQuote'])->name('work.extras');
        Route::post('/work/{work}/media', [WorkMediaController::class, 'store'])->name('work.media.store');
        Route::patch('/work/{work}/checklist/{item}', [WorkChecklistController::class, 'update'])->name('work.checklist.update');
    });

    // Team Management
    Route::get('/team', [TeamMemberController::class, 'index'])->name('team.index');
    Route::post('/team', [TeamMemberController::class, 'store'])->name('team.store');
    Route::put('/team/{teamMember}', [TeamMemberController::class, 'update'])->name('team.update');
    Route::delete('/team/{teamMember}', [TeamMemberController::class, 'destroy'])->name('team.destroy');

    // Tasks
    Route::middleware('company.feature:tasks')->group(function () {
        Route::get('/tasks', [TaskController::class, 'index'])->name('task.index');
        Route::post('/tasks', [TaskController::class, 'store'])->name('task.store');
        Route::put('/tasks/{task}', [TaskController::class, 'update'])->name('task.update');
        Route::delete('/tasks/{task}', [TaskController::class, 'destroy'])->name('task.destroy');
    });

    // Invoice Management
    Route::middleware('company.feature:invoices')->group(function () {
        Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoice.index');
        Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoice.show');
        Route::post('/work/{work}/invoice', [InvoiceController::class, 'storeFromWork'])->name('invoice.store-from-work');
    });

    // Payment Management
    Route::post('/invoice/{invoice}/payments', [PaymentController::class, 'store'])->name('payment.store');
});

// Client Portal Routes
Route::middleware(['auth', EnsureClientUser::class])
    ->prefix('portal')
    ->name('portal.')
    ->group(function () {
        Route::post('/quotes/{quote}/accept', [PortalQuoteController::class, 'accept'])->name('quotes.accept');
        Route::post('/quotes/{quote}/decline', [PortalQuoteController::class, 'decline'])->name('quotes.decline');
        Route::post('/works/{work}/validate', [PortalWorkController::class, 'validateWork'])->name('works.validate');
        Route::post('/works/{work}/dispute', [PortalWorkController::class, 'dispute'])->name('works.dispute');
        Route::post('/invoices/{invoice}/payments', [PortalInvoiceController::class, 'storePayment'])->name('invoices.payments.store');
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
            Route::post('/tenants/{tenant}/impersonate', [SuperAdminTenantController::class, 'impersonate'])->name('tenants.impersonate');
            Route::get('/tenants/{tenant}/export', [SuperAdminTenantController::class, 'export'])->name('tenants.export');

            Route::post('/impersonate/stop', [SuperAdminTenantController::class, 'stopImpersonate'])->name('impersonate.stop');

            Route::get('/admins', [SuperAdminAdminController::class, 'index'])->name('admins.index');
            Route::post('/admins', [SuperAdminAdminController::class, 'store'])->name('admins.store');
            Route::put('/admins/{admin}', [SuperAdminAdminController::class, 'update'])->name('admins.update');

            Route::get('/notifications', [SuperAdminNotificationController::class, 'edit'])->name('notifications.edit');
            Route::put('/notifications', [SuperAdminNotificationController::class, 'update'])->name('notifications.update');

            Route::get('/support', [SuperAdminSupportTicketController::class, 'index'])->name('support.index');
            Route::post('/support', [SuperAdminSupportTicketController::class, 'store'])->name('support.store');
            Route::put('/support/{ticket}', [SuperAdminSupportTicketController::class, 'update'])->name('support.update');

            Route::get('/settings', [SuperAdminPlatformSettingsController::class, 'edit'])->name('settings.edit');
            Route::put('/settings', [SuperAdminPlatformSettingsController::class, 'update'])->name('settings.update');
        });
