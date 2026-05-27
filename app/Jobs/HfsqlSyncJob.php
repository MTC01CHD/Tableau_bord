<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;

/**
 * Job dédié au sync HFSQL — permet d'imposer un timeout long indépendamment
 * de la commande `queue:work --timeout=...` (utile sur runtimes managés type
 * Laravel Cloud où on ne peut pas modifier la cmd du worker).
 *
 * Transporte le tenant_id (le worker tourne hors contexte HTTP : on doit lui
 * dire explicitement quel tenant synchroniser).
 */
class HfsqlSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** 1 heure max par sync — au-delà le worker estime le job mort. */
    public int $timeout = 3600;

    /** Pas de retry auto (on a notre propre logique de Reprise dans l'admin). */
    public int $tries = 1;

    public function __construct(public int $tenantId, public bool $resume = false) {}

    public function handle(): void
    {
        $options = ['--tenant' => (string) $this->tenantId];
        if ($this->resume) {
            $options['--resume'] = true;
        }
        Artisan::call('hfsql:sync', $options);
    }
}
