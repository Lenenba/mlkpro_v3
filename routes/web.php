<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\WorkController;
use App\Http\Controllers\QuoteController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProductsSearchController;
use App\Http\Controllers\QuoteEmaillingController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;

// Guest Routes
Route::get('/', [AuthenticatedSessionController::class, 'create'])
    ->middleware('guest')
    ->name('login');

// Dashboard Route
Route::get('/dashboard', [DashboardController::class, 'index'])->middleware(['auth', 'verified'])->name('dashboard');

// Authenticated User Routes
Route::middleware('auth')->group(function () {

    // Profile Management
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('/customer/{customer}/quote/create', [QuoteController::class, 'create'])->name('customer.quote.create');
    Route::post('/customer/quote/store', [QuoteController::class, 'store'])->name('customer.quote.store');
    Route::get('/customer/quote/{quote}/edit', [QuoteController::class, 'edit'])->name('customer.quote.edit');
    Route::get('/customer/quote/{quote}/show', [QuoteController::class, 'show'])->name('customer.quote.show');
    Route::put('/customer/quote/{quote}/update', [QuoteController::class, 'update'])->name('customer.quote.update');
    Route::delete('/customer/quote/{quote}/destroy', [QuoteController::class, 'destroy'])->name('customer.quote.destroy');



    Route::post('/quote/{quote}/send-email', QuoteEmaillingController::class)->name('quote.send.email');
    // Product custom search
    Route::get('/product/search', ProductsSearchController::class)->name('product.search');

    // Product Management
    Route::post('/product/bulk', [ProductController::class, 'bulk'])->name('product.bulk');
    Route::post('/product/{product}/duplicate', [ProductController::class, 'duplicate'])->name('product.duplicate');
    Route::put('/product/{product}/quick-update', [ProductController::class, 'quickUpdate'])->name('product.quick-update');
    Route::post('/product/{product}/adjust-stock', [ProductController::class, 'adjustStock'])->name('product.adjust-stock');
    Route::get('/product/export/csv', [ProductController::class, 'export'])->name('product.export');
    Route::post('/product/import/csv', [ProductController::class, 'import'])->name('product.import');
    Route::resource('product', ProductController::class);

    // Customer Management
    Route::resource('customer', CustomerController::class)
        ->only(['index', 'store', 'update', 'create', 'show']);

    // Work Management
    Route::get('/work/create/{customer}', [WorkController::class, 'create'])
        ->name('work.create');

    Route::resource('work', WorkController::class)
        ->except(['create']);
});

// Authentication Routes
require __DIR__ . '/auth.php';
