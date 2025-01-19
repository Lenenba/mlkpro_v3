<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\QuoteController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\ProductsSearchController;

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

    // Product custom search
    Route::get('/product/search', ProductsSearchController::class)->name('product.search');

    // Product Management
    Route::resource('product', ProductController::class);

    // Customer Management
    Route::resource('customer', CustomerController::class)
        ->only(['index', 'store', 'update', 'create', 'show']);
});

// Authentication Routes
require __DIR__ . '/auth.php';
