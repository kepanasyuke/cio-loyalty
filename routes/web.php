<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\RewardController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome');

Route::middleware('guest')->group(function (): void {
    Route::get('admin/login', [AdminAuthController::class, 'create'])->name('admin.login');
    Route::post('admin/login', [AdminAuthController::class, 'store'])->name('admin.login.store');
});

Route::post('admin/logout', [AdminAuthController::class, 'destroy'])
    ->middleware('auth')
    ->name('admin.logout');

Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth', 'role:admin,manager'])
    ->group(function (): void {
        Route::get('/', DashboardController::class)->name('dashboard');

        Route::resource('rewards', RewardController::class)->except(['show']);

        Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
        Route::get('customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
        Route::post('customers/{customer}/points', [CustomerController::class, 'adjustPoints'])
            ->name('customers.points');
    });
