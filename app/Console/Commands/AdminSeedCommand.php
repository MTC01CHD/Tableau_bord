<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class AdminSeedCommand extends Command
{
    protected $signature = 'admin:seed {--force : Met à jour le mot de passe si l\'admin existe déjà}';
    protected $description = 'Crée un utilisateur admin à partir des variables ADMIN_EMAIL / ADMIN_PASSWORD du .env.';

    public function handle(): int
    {
        $email = env('ADMIN_EMAIL');
        $password = env('ADMIN_PASSWORD');
        $name = env('ADMIN_NAME', 'Admin');

        if (!$email || !$password) {
            $this->error('ADMIN_EMAIL et ADMIN_PASSWORD doivent être définis dans .env');
            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();
        if ($user && !$this->option('force')) {
            $this->info("Admin {$email} déjà existant. Utilise --force pour mettre à jour le mot de passe.");
            return self::SUCCESS;
        }

        if ($user) {
            $user->update(['password' => Hash::make($password), 'name' => $name]);
            $this->info("Admin {$email} : mot de passe mis à jour.");
        } else {
            User::create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
                'email_verified_at' => now(),
            ]);
            $this->info("Admin {$email} créé avec succès.");
        }

        return self::SUCCESS;
    }
}
