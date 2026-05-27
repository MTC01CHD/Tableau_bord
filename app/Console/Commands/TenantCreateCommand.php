<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;

class TenantCreateCommand extends Command
{
    protected $signature = 'tenant:create
        {slug : Identifiant court du tenant (a-z, 0-9, _, -)}
        {--name= : Nom affiché (par défaut: slug en majuscules)}';

    protected $description = 'Crée un nouveau tenant.';

    public function handle(): int
    {
        $slug = (string) $this->argument('slug');
        if (!preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $slug)) {
            $this->error("Slug invalide. Autorisé : a-z, 0-9, _, -, max 64 caractères.");
            return self::FAILURE;
        }
        if (Tenant::where('slug', $slug)->exists()) {
            $this->error("Tenant « {$slug} » existe déjà.");
            return self::FAILURE;
        }
        $name = (string) ($this->option('name') ?: strtoupper($slug));
        $tenant = Tenant::create(['slug' => $slug, 'name' => $name, 'is_active' => true]);
        $this->info("Tenant « {$tenant->slug} » créé (#{$tenant->id}, nom: {$tenant->name}).");
        return self::SUCCESS;
    }
}
