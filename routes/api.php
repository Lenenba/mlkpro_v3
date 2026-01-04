<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RequestController;
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
use App\Http\Controllers\Settings\CompanySettingsController;
use App\Http\Controllers\Settings\BillingSettingsController;
use App\Http\Controllers\Settings\ProductCategoryController;
use App\Http\Controllers\Settings\SubscriptionController;
use App\Http\Middleware\EnsureInternalUser;
use App\Http\Middleware\EnsureNotSuspended;
use App\Http\Middleware\EnsureOnboardingIsComplete;

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
});

Route::middleware(['auth:sanctum', EnsureInternalUser::class, EnsureNotSuspended::class])->group(function () {
    Route::prefix('auth')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
    });

    Route::get('onboarding', [OnboardingController::class, 'index']);
    Route::post('onboarding', [OnboardingController::class, 'store']);

    Route::middleware(EnsureOnboardingIsComplete::class)->group(function () {
        Route::get('dashboard', [DashboardController::class, 'index']);
        Route::post('locale', [LocaleController::class, 'update']);
        Route::get('profile', [ProfileController::class, 'edit']);
        Route::patch('profile', [ProfileController::class, 'update']);
        Route::delete('profile', [ProfileController::class, 'destroy']);

        Route::prefix('settings')->group(function () {
            Route::get('company', [CompanySettingsController::class, 'edit']);
            Route::put('company', [CompanySettingsController::class, 'update']);

            Route::post('categories', [ProductCategoryController::class, 'store']);
            Route::patch('categories/{category}', [ProductCategoryController::class, 'update']);
            Route::patch('categories/{category}/archive', [ProductCategoryController::class, 'archive']);
            Route::patch('categories/{category}/restore', [ProductCategoryController::class, 'restore']);

            Route::get('billing', [BillingSettingsController::class, 'edit']);
            Route::put('billing', [BillingSettingsController::class, 'update']);
            Route::post('billing/swap', [SubscriptionController::class, 'swap']);
            Route::post('billing/portal', [SubscriptionController::class, 'portal']);
            Route::post('billing/payment-method', [SubscriptionController::class, 'paymentMethodTransaction']);
        });

        Route::middleware('company.feature:requests')->group(function () {
            Route::get('requests', [RequestController::class, 'index']);
            Route::post('requests', [RequestController::class, 'store']);
            Route::post('requests/{lead}/convert', [RequestController::class, 'convert'])
                ->middleware('company.feature:quotes');
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
            Route::get('product/export/csv', [ProductController::class, 'export']);
            Route::post('product/import/csv', [ProductController::class, 'import']);
            Route::apiResource('product', ProductController::class);
        });

        Route::middleware('company.feature:services')->group(function () {
            Route::get('services/options', [ServiceController::class, 'options']);
            Route::post('services/quick', [ServiceController::class, 'storeQuick']);
            Route::get('services/categories', [ServiceController::class, 'categories']);

            Route::apiResource('service', ServiceController::class)->only(['index', 'store', 'update', 'destroy']);
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
            Route::post('tasks', [TaskController::class, 'store']);
            Route::put('tasks/{task}', [TaskController::class, 'update']);
            Route::delete('tasks/{task}', [TaskController::class, 'destroy']);
            Route::post('tasks/{task}/media', [TaskMediaController::class, 'store']);
        });

        Route::middleware('company.feature:invoices')->group(function () {
            Route::get('invoices', [InvoiceController::class, 'index']);
            Route::get('invoices/{invoice}', [InvoiceController::class, 'show']);
            Route::post('work/{work}/invoice', [InvoiceController::class, 'storeFromWork']);
        });

        Route::post('invoice/{invoice}/payments', [PaymentController::class, 'store']);
    });
});
