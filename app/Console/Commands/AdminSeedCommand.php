<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class AdminSeedCommand extends Command
{
    protected $signature = 'admin:seed {--force : Met à jour le mot de passe si l\'admin existe déjà}';
    protected $description = 'Bootstrap : crée le tenant mt-c (si absent) + un user super-admin à partir de ADMIN_EMAIL / ADMIN_PASSWORD.';

    public function handle(): int
    {
        $email    = env('ADMIN_EMAIL');
        $password = env('ADMIN_PASSWORD');
        $name     = env('ADMIN_NAME', 'Admin');

        if (!$email || !$password) {
            $this->error('ADMIN_EMAIL et ADMIN_PASSWORD doivent être définis dans .env');
            return self::FAILURE;
        }

        // Tenant par défaut (idempotent).
        $tenant = Tenant::firstOrCreate(
            ['slug' => 'mt-c'],
            ['name' => 'MT-C', 'is_active' => true]
        );
        $this->info("Tenant : {$tenant->slug} (#{$tenant->id})");

        $user = User::where('email', $email)->first();
        if ($user && !$this->option('force')) {
            $this->info("Admin {$email} déjà existant. Utilise --force pour mettre à jour le mot de passe / le marquer super-admin.");
            return self::SUCCESS;
        }

        if ($user) {
            $user->update([
                'name'           => $name,
                'password'       => Hash::make($password),
                'is_super_admin' => true,
                'tenant_id'      => null, // les super-admin n'ont pas de tenant fixe
            ]);
            $this->info("Admin {$email} : mis à jour, marqué super-admin.");
        } else {
            User::create([
                'name'              => $name,
                'email'             => $email,
                'password'          => Hash::make($password),
                'email_verified_at' => now(),
                'is_super_admin'    => true,
                'tenant_id'         => null,
            ]);
            $this->info("Admin {$email} créé (super-admin).");
        }

        return self::SUCCESS;
    }
}
