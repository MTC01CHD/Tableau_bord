<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\SuperAdminController;
use App\Http\Middleware\AuthUser;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('dashboard'));

// Auth (public)
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// Super-admin uniquement : gestion des tenants/users (PAS de ResolveTenant — pas
// de tenant courant requis pour cette section). Le switch de tenant courant
// vit ici aussi.
Route::middleware([AuthUser::class, EnsureSuperAdmin::class])
    ->prefix('super')->name('super.')->group(function () {
        Route::get('/', fn () => redirect()->route('super.tenants.index'));
        Route::get('/tenants',  [SuperAdminController::class, 'tenantsIndex'])->name('tenants.index');
        Route::post('/tenants', [SuperAdminController::class, 'tenantsStore'])->name('tenants.store');
        Route::post('/tenants/{tenant}/toggle', [SuperAdminController::class, 'tenantsToggle'])->name('tenants.toggle');
        Route::delete('/tenants/{tenant}',      [SuperAdminController::class, 'tenantsDestroy'])->name('tenants.destroy');

        Route::get('/users',  [SuperAdminController::class, 'usersIndex'])->name('users.index');
        Route::post('/users', [SuperAdminController::class, 'usersStore'])->name('users.store');
        Route::delete('/users/{user}', [SuperAdminController::class, 'usersDestroy'])->name('users.destroy');

        Route::post('/switch-tenant', [AuthController::class, 'switchTenant'])->name('switch-tenant');
    });

// Dashboard + admin : auth + tenant requis (le user normal a un tenant fixe,
// le super-admin doit avoir sélectionné un tenant courant).
Route::middleware([AuthUser::class, ResolveTenant::class])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard/projet/{id}', [DashboardController::class, 'projet'])->whereNumber('id')->name('dashboard.projet');

    Route::prefix('admin')->name('admin.')->group(function () {
        Route::get('/', fn () => redirect()->route('admin.hfsql.edit'));

        Route::get('/hfsql', [AdminController::class, 'hfsqlEdit'])->name('hfsql.edit');
        Route::post('/hfsql', [AdminController::class, 'hfsqlSave'])->name('hfsql.save');
        Route::post('/hfsql/test', [AdminController::class, 'hfsqlTest'])->name('hfsql.test');

        Route::get('/hfsql/tables', [AdminController::class, 'tablesIndex'])->name('hfsql.tables');
        Route::post('/hfsql/tables', [AdminController::class, 'tablesSave'])->name('hfsql.tables.save');
        Route::get('/hfsql/tables/{table}/columns', [AdminController::class, 'tableColumns'])
            ->where('table', '[A-Za-z0-9_]+')->name('hfsql.tables.columns');

        Route::get('/sync', [AdminController::class, 'syncIndex'])->name('sync');
        Route::post('/sync', [AdminController::class, 'syncTrigger'])->name('sync.trigger');
        Route::post('/sync/stop', [AdminController::class, 'syncStop'])->name('sync.stop');
        Route::post('/sync/date-range', [AdminController::class, 'syncDateRangeSave'])->name('sync.dates');
        Route::get('/sync/status.json', [AdminController::class, 'syncStatusJson'])->name('sync.status');
    });
});
