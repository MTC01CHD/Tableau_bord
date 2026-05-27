<?php

namespace App\Providers;

use App\Support\TenantContext;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Tenant courant : un seul par requête HTTP, lu par les modèles scopés.
        $this->app->singleton(TenantContext::class);
    }

    public function boot(): void
    {
        //
    }
}
