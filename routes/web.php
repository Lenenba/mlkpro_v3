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
use App\Http\Controllers\WelcomeController;
use App\Http\Controllers\Settings\CompanySettingsController;
use App\Http\Controllers\Settings\BillingSettingsController;
use App\Http\Controllers\CustomerPropertyController;

Route::get('/favicon.ico', function () {
    return response()->file(public_path('favicon.ico'));
})->name('favicon');

// Guest Routes
Route::get('/', [WelcomeController::class, 'index'])->name('welcome');

// Dashboard Route
Route::get('/dashboard', [DashboardController::class, 'index'])->middleware(['auth', 'verified'])->name('dashboard');

// Authenticated User Routes
Route::middleware('auth')->group(function () {

    // Onboarding (account setup)
    Route::get('/onboarding', [OnboardingController::class, 'index'])->name('onboarding.index');
    Route::post('/onboarding', [OnboardingController::class, 'store'])->name('onboarding.store');

    // Profile Management
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Settings (owner only)
    Route::get('/settings/company', [CompanySettingsController::class, 'edit'])->name('settings.company.edit');
    Route::put('/settings/company', [CompanySettingsController::class, 'update'])->name('settings.company.update');
    Route::get('/settings/billing', [BillingSettingsController::class, 'edit'])->name('settings.billing.edit');
    Route::put('/settings/billing', [BillingSettingsController::class, 'update'])->name('settings.billing.update');

    Route::get('/quotes', [QuoteController::class, 'index'])->name('quote.index');
    Route::get('/customer/{customer}/quote/create', [QuoteController::class, 'create'])->name('customer.quote.create');
    Route::post('/customer/quote/store', [QuoteController::class, 'store'])->name('customer.quote.store');
    Route::get('/customer/quote/{quote}/edit', [QuoteController::class, 'edit'])->name('customer.quote.edit');
    Route::get('/customer/quote/{quote}/show', [QuoteController::class, 'show'])->name('customer.quote.show');
    Route::put('/customer/quote/{quote}/update', [QuoteController::class, 'update'])->name('customer.quote.update');
    Route::delete('/customer/quote/{quote}/destroy', [QuoteController::class, 'destroy'])->name('customer.quote.destroy');



    Route::post('/quote/{quote}/send-email', QuoteEmaillingController::class)->name('quote.send.email');
    Route::post('/quote/{quote}/convert', [QuoteController::class, 'convertToWork'])->name('quote.convert');
    // Product custom search
    Route::get('/product/search', ProductsSearchController::class)->name('product.search');
    Route::get('/products/options', [ProductController::class, 'options'])->name('product.options');
    Route::post('/products/quick', [ProductController::class, 'storeQuick'])->name('product.quick.store');
    Route::get('/services/options', [ServiceController::class, 'options'])->name('service.options');
    Route::post('/services/quick', [ServiceController::class, 'storeQuick'])->name('service.quick.store');
    Route::get('/customers/options', [CustomerController::class, 'options'])->name('customer.options');
    Route::post('/customers/quick', [CustomerController::class, 'storeQuick'])->name('customer.quick.store');

    // Service Management
    Route::resource('service', ServiceController::class)
        ->only(['index', 'store', 'update', 'destroy']);

    // Product Management
    Route::post('/product/bulk', [ProductController::class, 'bulk'])->name('product.bulk');
    Route::post('/product/{product}/duplicate', [ProductController::class, 'duplicate'])->name('product.duplicate');
    Route::put('/product/{product}/quick-update', [ProductController::class, 'quickUpdate'])->name('product.quick-update');
    Route::post('/product/{product}/adjust-stock', [ProductController::class, 'adjustStock'])->name('product.adjust-stock');
    Route::get('/product/export/csv', [ProductController::class, 'export'])->name('product.export');
    Route::post('/product/import/csv', [ProductController::class, 'import'])->name('product.import');
    Route::resource('product', ProductController::class);

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
    Route::get('/jobs', [WorkController::class, 'index'])->name('jobs.index');
    Route::get('/work/create/{customer}', [WorkController::class, 'create'])
        ->name('work.create');

    Route::resource('work', WorkController::class)
        ->except(['create']);

    // Team Management
    Route::get('/team', [TeamMemberController::class, 'index'])->name('team.index');
    Route::post('/team', [TeamMemberController::class, 'store'])->name('team.store');
    Route::put('/team/{teamMember}', [TeamMemberController::class, 'update'])->name('team.update');
    Route::delete('/team/{teamMember}', [TeamMemberController::class, 'destroy'])->name('team.destroy');

    // Tasks
    Route::get('/tasks', [TaskController::class, 'index'])->name('task.index');
    Route::post('/tasks', [TaskController::class, 'store'])->name('task.store');
    Route::put('/tasks/{task}', [TaskController::class, 'update'])->name('task.update');
    Route::delete('/tasks/{task}', [TaskController::class, 'destroy'])->name('task.destroy');

    // Invoice Management
    Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoice.index');
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoice.show');
    Route::post('/work/{work}/invoice', [InvoiceController::class, 'storeFromWork'])->name('invoice.store-from-work');

    // Payment Management
    Route::post('/invoice/{invoice}/payments', [PaymentController::class, 'store'])->name('payment.store');
});

// Authentication Routes
require __DIR__ . '/auth.php';
