<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;

/**
 * Réserve l'accès aux super-admins. Doit s'exécuter après AuthUser.
 */
class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next)
    {
        /** @var User|null $user */
        $user = $request->attributes->get('user');
        if (!$user || !$user->isSuperAdmin()) {
            abort(403, 'Accès réservé aux super-administrateurs.');
        }
        return $next($request);
    }
}
