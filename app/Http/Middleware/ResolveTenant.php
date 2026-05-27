<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;

/**
 * Détermine le tenant courant à partir du user authentifié :
 *  - User normal     → son tenant_id (fixe).
 *  - Super-admin     → tenant_id stocké en session ('current_tenant_id'),
 *                      sélectionné via /super/switch-tenant.
 *
 * Si aucun tenant n'est résolu, redirige vers le sélecteur (super-admin)
 * ou renvoie une erreur (user normal mal configuré).
 *
 * Doit s'exécuter APRÈS AuthUser.
 */
class ResolveTenant
{
    public function __construct(private TenantContext $ctx) {}

    public function handle(Request $request, Closure $next)
    {
        /** @var User|null $user */
        $user = $request->attributes->get('user');
        if (!$user) {
            return redirect()->route('login');
        }

        if ($user->isSuperAdmin()) {
            $tenantId = $request->session()->get('current_tenant_id');
            if (!$tenantId) {
                return redirect()->route('super.tenants.index')
                    ->with('status', 'Sélectionnez un tenant pour continuer.');
            }
            $tenant = Tenant::find($tenantId);
        } else {
            if (!$user->tenant_id) {
                abort(403, 'Compte non rattaché à un tenant. Contactez l\'administrateur.');
            }
            $tenant = $user->tenant;
        }

        if (!$tenant || !$tenant->is_active) {
            abort(403, 'Tenant inactif ou introuvable.');
        }

        $this->ctx->set($tenant);
        $request->attributes->set('tenant', $tenant);
        return $next($request);
    }
}
