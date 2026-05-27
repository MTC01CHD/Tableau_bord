<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Middleware\EnsureAdmin;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('dashboard'));

// Public dashboard
Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
Route::get('/dashboard/projet/{id}', [DashboardController::class, 'projet'])->whereNumber('id')->name('dashboard.projet');

// Auth
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// Admin (protected)
Route::middleware(EnsureAdmin::class)->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', fn () => redirect()->route('admin.hfsql.edit'));

    Route::get('/hfsql', [AdminController::class, 'hfsqlEdit'])->name('hfsql.edit');
    Route::post('/hfsql', [AdminController::class, 'hfsqlSave'])->name('hfsql.save');
    Route::post('/hfsql/test', [AdminController::class, 'hfsqlTest'])->name('hfsql.test');

    Route::get('/hfsql/tables', [AdminController::class, 'tablesIndex'])->name('hfsql.tables');
    Route::post('/hfsql/tables', [AdminController::class, 'tablesSave'])->name('hfsql.tables.save');

    Route::get('/sync', [AdminController::class, 'syncIndex'])->name('sync');
    Route::post('/sync', [AdminController::class, 'syncTrigger'])->name('sync.trigger');
    Route::post('/sync/stop', [AdminController::class, 'syncStop'])->name('sync.stop');
    Route::post('/sync/date-range', [AdminController::class, 'syncDateRangeSave'])->name('sync.dates');
    Route::get('/sync/status.json', [AdminController::class, 'syncStatusJson'])->name('sync.status');
});
