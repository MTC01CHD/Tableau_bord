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

    /** Pas de timeout applicatif — base de données massive peut prendre des heures.
     *  Le worker reste sous sa propre limite (cf cmd queue:work). */
    public int $timeout = 0;

    /** Si le worker meurt (OOM, restart container, deploy), Laravel re-queue le job
     *  jusqu'à 3 fois — chaque retry repart en mode --resume pour ne pas refaire
     *  les tables déjà OK. */
    public int $tries = 3;

    /** Délai entre 2 tentatives (laissez le worker se rétablir). */
    public int $backoff = 30;

    public function __construct(public int $tenantId, public bool $resume = false)
    {
        // Force connection 'database' et queue 'default' via l'API du trait Queueable
        // (override property direct conflit avec ?string du trait → FatalError).
        // Override de QUEUE_CONNECTION : sinon Laravel tombe sur 'sync' par défaut
        // → le job s'exécute dans la requête HTTP au lieu d'être mis en file.
        $this->onConnection('database')->onQueue('default');
    }

    public function handle(): void
    {
        // Mémoire généreuse — la consommation peut grimper sur grosses tables JSONB.
        @ini_set('memory_limit', '512M');

        // Si on est dans une tentative >1 (retry après crash), on bascule auto en
        // mode --resume pour ne pas refaire les tables déjà OK.
        $resume = $this->resume || $this->attempts() > 1;

        $options = ['--tenant' => (string) $this->tenantId];
        if ($resume) {
            $options['--resume'] = true;
        }
        Artisan::call('hfsql:sync', $options);
    }

    /**
     * Quand toutes les tentatives ont échoué : on log pour qu'on puisse diagnostiquer.
     */
    public function failed(\Throwable $e): void
    {
        \Log::error('HfsqlSyncJob échec définitif après ' . $this->attempts() . ' tentatives', [
            'tenant_id' => $this->tenantId,
            'error'     => $e->getMessage(),
        ]);
    }
}
