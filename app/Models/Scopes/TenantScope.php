<?php

namespace App\Models\Scopes;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Scope global qui filtre toutes les requêtes par tenant_id du contexte courant.
 *
 * Pas de tenant en contexte → exception (failsafe anti-fuite cross-tenant).
 * Pour bypass volontairement (ex: super-admin, CRUD tenants) :
 *   Model::withoutGlobalScope(TenantScope::class)->...
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        /** @var TenantContext $ctx */
        $ctx = app(TenantContext::class);
        $builder->where($model->qualifyColumn('tenant_id'), $ctx->requireId());
    }
}
