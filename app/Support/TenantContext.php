<?php

namespace App\Support;

use App\Models\Tenant;

/**
 * Conteneur du tenant courant pour la requête (HTTP) ou la commande (CLI).
 *
 * - En HTTP : peuplé par le middleware ResolveTenant à partir du user authentifié.
 * - En CLI : peuplé par HfsqlSyncCommand pour chaque tenant qu'il traite.
 *
 * Le scope global TenantScope lit cette valeur ; si elle est nulle et qu'on
 * essaie de lire un modèle scopé, on lève une exception (failsafe anti-fuite).
 */
class TenantContext
{
    private ?Tenant $current = null;

    public function set(?Tenant $tenant): void
    {
        $this->current = $tenant;
    }

    public function forget(): void
    {
        $this->current = null;
    }

    public function current(): ?Tenant
    {
        return $this->current;
    }

    public function id(): ?int
    {
        return $this->current?->id;
    }

    public function requireId(): int
    {
        if (!$this->current) {
            throw new \RuntimeException(
                'Aucun tenant courant. Le middleware ResolveTenant ou la commande '
                . 'CLI doit définir un tenant avant toute requête scopée.'
            );
        }
        return $this->current->id;
    }

    public function has(): bool
    {
        return $this->current !== null;
    }

    /** Exécute un callback avec un tenant donné comme contexte courant. */
    public function runAs(Tenant $tenant, callable $callback): mixed
    {
        $previous = $this->current;
        $this->current = $tenant;
        try {
            return $callback();
        } finally {
            $this->current = $previous;
        }
    }
}
