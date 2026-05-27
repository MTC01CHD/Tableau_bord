<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class UserCreateCommand extends Command
{
    protected $signature = 'user:create
        {email}
        {--name= : Nom affiché (par défaut: partie locale de l\'email)}
        {--password= : Mot de passe (sinon, lecture interactive)}
        {--tenant= : Slug ou ID du tenant (obligatoire sauf si --super)}
        {--super : Crée un super-admin (pas de tenant fixe, accès à tous)}';

    protected $description = 'Crée un utilisateur (normal ou super-admin).';

    public function handle(): int
    {
        $email    = (string) $this->argument('email');
        $name     = (string) ($this->option('name') ?: strstr($email, '@', true) ?: $email);
        $password = (string) ($this->option('password') ?: $this->secret('Mot de passe (min. 8 caractères)'));
        $isSuper  = (bool) $this->option('super');

        if (strlen($password) < 8) {
            $this->error('Mot de passe trop court (min. 8 caractères).');
            return self::FAILURE;
        }
        if (User::where('email', $email)->exists()) {
            $this->error("L'email {$email} est déjà pris.");
            return self::FAILURE;
        }

        $tenantId = null;
        if (!$isSuper) {
            $arg = (string) $this->option('tenant');
            if ($arg === '') {
                $this->error('--tenant est obligatoire pour un user non super-admin.');
                return self::FAILURE;
            }
            $tenant = is_numeric($arg) ? Tenant::find((int) $arg) : Tenant::where('slug', $arg)->first();
            if (!$tenant) {
                $this->error("Tenant introuvable : {$arg}");
                return self::FAILURE;
            }
            $tenantId = $tenant->id;
        }

        $user = User::create([
            'name'              => $name,
            'email'             => $email,
            'password'          => Hash::make($password),
            'email_verified_at' => now(),
            'is_super_admin'    => $isSuper,
            'tenant_id'         => $tenantId,
        ]);

        $this->info("Utilisateur #{$user->id} {$user->email} créé" . ($isSuper ? ' (super-admin)' : " (tenant_id={$tenantId})") . '.');
        return self::SUCCESS;
    }
}
