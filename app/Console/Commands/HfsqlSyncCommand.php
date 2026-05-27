<?php

namespace App\Console\Commands;

use App\Models\HfsqlSyncRun;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Services\Hfsql\HfsqlService;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class HfsqlSyncCommand extends Command
{
    protected $signature = 'hfsql:sync
        {tables?* : Tables HFSQL à synchroniser (vide = toutes celles activées en admin)}
        {--tenant= : Slug ou ID du tenant à synchroniser (obligatoire si plus d\'un tenant actif)}
        {--all-tenants : Synchronise tous les tenants actifs en séquence}
        {--key= : Nom de la colonne servant de clé unique (par défaut: id, ID, ou première colonne)}
        {--max=0 : Limite de lignes par table (0 = illimité)}
        {--all : Ignore la liste DB et synchronise toutes les tables exposées par l\'agent}
        {--resume : Saute les tables qui ont un sync OK récent — ne refait que les manquantes/en erreur}
        {--dry : Ne rien écrire en DB, juste lister}';

    protected $description = 'Synchronise des tables HFSQL vers hfsql_raw_rows pour un ou plusieurs tenants.';

    public function handle(HfsqlService $hfsql, TenantContext $ctx): int
    {
        $tenants = $this->resolveTenants();
        if (empty($tenants)) {
            return self::FAILURE;
        }

        $globalOk = true;
        foreach ($tenants as $tenant) {
            $this->info("══ Tenant : {$tenant->slug} (#{$tenant->id})");
            $ok = $ctx->runAs($tenant, fn () => $this->syncTenant($hfsql));
            $globalOk = $ok && $globalOk;
        }
        return $globalOk ? self::SUCCESS : self::FAILURE;
    }

    /** @return list<Tenant> */
    private function resolveTenants(): array
    {
        if ($this->option('all-tenants')) {
            $list = Tenant::where('is_active', true)->orderBy('id')->get();
            if ($list->isEmpty()) {
                $this->error('Aucun tenant actif.');
                return [];
            }
            return $list->all();
        }

        $arg = (string) $this->option('tenant');
        if ($arg !== '') {
            $t = is_numeric($arg) ? Tenant::find((int) $arg) : Tenant::where('slug', $arg)->first();
            if (!$t) {
                $this->error("Tenant introuvable : {$arg}");
                return [];
            }
            return [$t];
        }

        // Pas d'option : si un seul tenant actif, on l'utilise. Sinon erreur.
        $list = Tenant::where('is_active', true)->get();
        if ($list->count() === 1) {
            return [$list->first()];
        }
        $this->error('Plus d\'un tenant actif : précisez --tenant=<slug> ou --all-tenants.');
        return [];
    }

    private function syncTenant(HfsqlService $hfsql): bool
    {
        $tables = $this->argument('tables');

        if (empty($tables) && !$this->option('all')) {
            $tables = \App\Models\HfsqlTable::query()
                ->where('enabled', true)
                ->orderBy('name')
                ->pluck('name')
                ->all();
            if (!empty($tables)) {
                $this->line('Tables configurées : ' . count($tables));
            }
        }
        if (empty($tables)) {
            $tables = $hfsql->getTables();
            if (empty($tables)) {
                $this->error('Aucune table renvoyée par l\'agent HFSQL pour ce tenant.');
                return false;
            }
            $this->line('Tables découvertes via l\'agent : ' . count($tables));
        }

        $forcedKey = $this->option('key');
        $max       = (int) $this->option('max');
        $dry       = (bool) $this->option('dry');
        $resume    = (bool) $this->option('resume');

        if ($resume) {
            $okSet = HfsqlSyncRun::query()
                ->selectRaw('DISTINCT ON (table_name) table_name, status')
                ->orderBy('table_name')
                ->orderByDesc('id')
                ->get()
                ->filter(fn ($r) => $r->status === 'ok')
                ->pluck('table_name')
                ->all();
            $before = count($tables);
            $tables = array_values(array_diff($tables, $okSet));
            $this->line("Mode --resume : " . count($tables) . "/{$before} tables à (re)faire.");
            if (empty($tables)) {
                $this->info('Rien à reprendre : toutes les tables ont un dernier sync OK.');
                return true;
            }
        }

        // Nettoie les "running" orphelins de ce tenant.
        HfsqlSyncRun::where('status', 'running')->update([
            'status'      => 'error',
            'finished_at' => now(),
            'error'       => 'sync interrompu — process tué avant la fin',
        ]);

        PlatformSetting::set('sync_cancel_requested', null);

        $globalOk = true;
        foreach ($tables as $table) {
            if ($this->isCancelRequested()) {
                $this->warn('⏹ Annulation demandée — arrêt entre 2 tables.');
                break;
            }
            $globalOk = $this->syncTable($hfsql, $table, $forcedKey, $max, $dry) && $globalOk;

            // Libération mémoire agressive entre 2 tables — évite l'OOM sur les
            // bases massives (les buffers JSONB peuvent grimper vite).
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
            $this->line('   mémoire: ' . round(memory_get_usage(true) / 1024 / 1024) . ' MB');
        }

        PlatformSetting::set('sync_cancel_requested', null);
        return $globalOk;
    }

    private function isCancelRequested(): bool
    {
        return (bool) PlatformSetting::get('sync_cancel_requested');
    }

    private function syncTable(HfsqlService $hfsql, string $table, ?string $forcedKey, int $max, bool $dry): bool
    {
        $since   = null;
        $until   = null;
        $dateCol = null;
        $cfg = \App\Models\HfsqlTable::where('name', $table)->first();
        if ($cfg && $cfg->date_column) {
            $dateCol = $cfg->date_column;
            $from = PlatformSetting::get('sync_date_from');
            $to   = PlatformSetting::get('sync_date_to');
            if ($from) {
                $since = $from;
                $until = $to ?: null;
            } else {
                $months = (int) env('HFSQL_SINCE_MONTHS', 0);
                if ($months > 0) $since = now()->subMonths($months)->format('Y-m-d');
            }
        }

        $label = $dateCol ? "{$table} ({$dateCol} >= " . ($since ?? '∅') . ($until ? " AND <= {$until}" : '') . ")" : $table;
        $this->info("→ {$label}");

        $run = HfsqlSyncRun::create([
            'table_name' => $table,
            'started_at' => now(),
            'status'     => 'running',
        ]);

        $tenantId = app(TenantContext::class)->requireId();

        try {
            $pulled = 0;
            $upserted = 0;
            $now = now();
            $buffer = [];
            $lastHeartbeat = microtime(true);

            $lastCancelCheck = microtime(true);
            foreach ($hfsql->streamRows($table, null, $max, $since, $dateCol, $until) as $row) {
                $pulled++;
                $key = $this->resolveKey($row, $table, $forcedKey, $pulled);

                // Heartbeat ET check annulation toutes les 5s pour réactivité.
                if (microtime(true) - $lastHeartbeat > 5) {
                    $run->update(['started_at' => now(), 'rows_pulled' => $pulled]);
                    $lastHeartbeat = microtime(true);
                }
                if (microtime(true) - $lastCancelCheck > 5) {
                    if ($this->isCancelRequested()) {
                        if (!empty($buffer) && !$dry) $upserted += $this->flush(array_values($buffer));
                        $run->update([
                            'finished_at'   => now(),
                            'rows_pulled'   => $pulled,
                            'rows_upserted' => $upserted,
                            'status'        => 'error',
                            'error'         => 'annulé depuis l\'admin (mid-stream)',
                        ]);
                        $this->warn("   ⏹ annulation reçue pendant {$table} — {$upserted} upserts effectués");
                        return false;
                    }
                    $lastCancelCheck = microtime(true);
                }

                $json = json_encode($row, JSON_UNESCAPED_UNICODE);
                if (str_contains($json, '\\u0000')) {
                    $json = str_replace('\\u0000', '', $json);
                }

                $buffer[$key] = [
                    'tenant_id'  => $tenantId,
                    'table_name' => $table,
                    'row_key'    => $key,
                    'payload'    => $json,
                    'synced_at'  => $now,
                ];

                if (count($buffer) >= 100) {
                    if (!$dry) $upserted += $this->flush(array_values($buffer));
                    $buffer = [];

                    $run->update(['started_at' => now(), 'rows_pulled' => $pulled, 'rows_upserted' => $upserted]);

                    if ($this->isCancelRequested()) {
                        $run->update([
                            'finished_at'   => now(),
                            'rows_pulled'   => $pulled,
                            'rows_upserted' => $upserted,
                            'status'        => 'error',
                            'error'         => 'annulé depuis l\'admin (entre 2 batches)',
                        ]);
                        $this->warn("   ⏹ annulation reçue pendant {$table} — {$upserted} upserts effectués");
                        return false;
                    }
                }
            }
            if (!empty($buffer) && !$dry) {
                $upserted += $this->flush(array_values($buffer));
            }

            $run->update([
                'finished_at'   => now(),
                'rows_pulled'   => $pulled,
                'rows_upserted' => $upserted,
                'status'        => 'ok',
            ]);
            $this->line("   {$pulled} lignes lues / {$upserted} upserts" . ($dry ? ' [dry-run]' : ''));
            return true;

        } catch (\Throwable $e) {
            $run->update([
                'finished_at' => now(),
                'status'      => 'error',
                'error'       => $e->getMessage(),
            ]);
            $this->error('   ' . $e->getMessage());
            Log::error('hfsql.sync', ['tenant_id' => $tenantId, 'table' => $table, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Détermine la clé unique d'une ligne HFSQL.
     *
     * Stratégies, par ordre de priorité :
     *  1. Clé forcée par l'utilisateur (option --key=)
     *  2. Convention HFSQL : "ID<NomTable>" (insensible à la casse)
     *  3. Colonne nommée "id" ou "pk" exactement
     *  4. Hash stable de la ligne + index d'itération (jamais en collision)
     */
    private function resolveKey(array $row, string $table, ?string $forcedKey, int $index): string
    {
        if ($forcedKey && array_key_exists($forcedKey, $row)) {
            return (string) $row[$forcedKey];
        }

        $lower = array_change_key_case($row, CASE_LOWER);

        $conventional = 'id' . strtolower($table);
        if (isset($lower[$conventional]) && $lower[$conventional] !== '' && $lower[$conventional] !== null) {
            return (string) $lower[$conventional];
        }

        foreach (['id', 'pk'] as $candidate) {
            if (isset($lower[$candidate]) && $lower[$candidate] !== '' && $lower[$candidate] !== null) {
                return (string) $lower[$candidate];
            }
        }

        return 'h:' . substr(sha1(json_encode($row, JSON_UNESCAPED_UNICODE)), 0, 24) . ':' . $index;
    }

    /**
     * Upsert PostgreSQL : ON CONFLICT (tenant_id,table_name,row_key) DO UPDATE.
     */
    private function flush(array $buffer): int
    {
        DB::table('hfsql_raw_rows')->upsert(
            $buffer,
            ['tenant_id', 'table_name', 'row_key'],
            ['payload', 'synced_at']
        );
        return count($buffer);
    }
}
