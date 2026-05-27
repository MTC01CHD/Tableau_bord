<?php

namespace App\Http\Controllers;

use App\Models\HfsqlSyncRun;
use App\Models\HfsqlTable;
use App\Models\PlatformSetting;
use App\Services\Hfsql\HfsqlService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    private array $hfsqlKeys = [
        'hfsql_mode', 'hfsql_api_url', 'hfsql_api_key',
        'hfsql_host', 'hfsql_port', 'hfsql_database',
        'hfsql_username', 'hfsql_password', 'hfsql_driver', 'hfsql_dsn',
    ];

    // ── HFSQL connexion ───────────────────────────────────────────────────

    public function hfsqlEdit()
    {
        $cfg = collect($this->hfsqlKeys)->mapWithKeys(function ($k) {
            $v = PlatformSetting::get($k);
            // fallback config si rien en DB
            if ($v === null || $v === '') {
                $configKey = str_replace('hfsql_', 'hfsql.', $k);
                $map = [
                    'hfsql_mode'     => 'hfsql.mode',
                    'hfsql_api_url'  => 'hfsql.rest.url',
                    'hfsql_api_key'  => 'hfsql.rest.api_key',
                    'hfsql_host'     => 'hfsql.odbc.host',
                    'hfsql_port'     => 'hfsql.odbc.port',
                    'hfsql_database' => 'hfsql.odbc.database',
                    'hfsql_username' => 'hfsql.odbc.username',
                    'hfsql_password' => 'hfsql.odbc.password',
                    'hfsql_driver'   => 'hfsql.odbc.driver',
                    'hfsql_dsn'      => 'hfsql.odbc.dsn',
                ];
                $v = config($map[$k] ?? '');
            }
            return [$k => $v];
        });

        return view('admin.hfsql.edit', ['cfg' => $cfg]);
    }

    public function hfsqlSave(Request $request)
    {
        $data = $request->validate([
            'hfsql_mode'     => 'required|in:rest,odbc',
            'hfsql_api_url'  => 'nullable|url',
            'hfsql_api_key'  => 'nullable|string',
            'hfsql_host'     => 'nullable|string',
            'hfsql_port'     => 'nullable|string',
            'hfsql_database' => 'nullable|string',
            'hfsql_username' => 'nullable|string',
            'hfsql_password' => 'nullable|string',
            'hfsql_driver'   => 'nullable|string',
            'hfsql_dsn'      => 'nullable|string',
        ]);
        foreach ($this->hfsqlKeys as $k) {
            PlatformSetting::set($k, $data[$k] ?? null);
        }
        return back()->with('status', 'Configuration HFSQL enregistrée.');
    }

    public function hfsqlTest(HfsqlService $hfsql)
    {
        return response()->json($hfsql->testConnection());
    }

    // ── HFSQL tables (sélection à synchroniser) ──────────────────────────

    public function tablesIndex(HfsqlService $hfsql)
    {
        $remoteError = null;
        try {
            $remote = collect($hfsql->getTables())->sort()->values();
        } catch (\Throwable $e) {
            $remote = collect();
            $remoteError = $e->getMessage();
        }

        $local  = HfsqlTable::orderBy('name')->get()->keyBy('name');

        $rows = DB::table('hfsql_raw_rows')
            ->select('table_name', DB::raw('COUNT(*) AS n'), DB::raw('MAX(synced_at) AS last_sync'))
            ->groupBy('table_name')
            ->get()
            ->keyBy('table_name');

        // Suggestion auto de colonne date pour chaque table
        $suggestions = [];
        foreach ($remote as $name) {
            $existing = $local->get($name)?->date_column;
            $suggestions[$name] = $existing ?: $this->suggestDateColumn($name);
        }

        return view('admin.hfsql.tables', compact('remote', 'local', 'rows', 'remoteError', 'suggestions'));
    }

    /**
     * Propose une colonne date plausible pour une table HFSQL.
     *  - Si on a déjà des données en base, on inspecte le payload JSONB et on prend
     *    la première colonne dont le nom ressemble à une date de modification.
     *  - Sinon on tombe sur DateHeureModification (convention HFSQL/WinDev standard).
     */
    private function suggestDateColumn(string $table): string
    {
        $defaults = ['DateHeureModification', 'Modification_date', 'DateModif', 'DateModification'];

        // Inspection des données réelles
        $sample = DB::table('hfsql_raw_rows')
            ->where('table_name', $table)
            ->limit(1)
            ->value('payload');

        if ($sample) {
            $payload = is_string($sample) ? json_decode($sample, true) : (array) $sample;
            if (is_array($payload)) {
                $keys = array_keys($payload);
                // Priorité 1 : noms attendus (insensible casse)
                foreach ($defaults as $d) {
                    foreach ($keys as $k) {
                        if (strcasecmp($k, $d) === 0) return $k;
                    }
                }
                // Priorité 2 : toute colonne contenant "modif" + "date" / "heure"
                foreach ($keys as $k) {
                    if (preg_match('/modif/i', $k) && preg_match('/date|heure/i', $k)) {
                        return $k;
                    }
                }
                // Priorité 3 : toute colonne avec "date" dans le nom
                foreach ($keys as $k) {
                    if (preg_match('/date/i', $k)) return $k;
                }
            }
        }

        // Fallback convention HFSQL standard
        return $defaults[0];
    }

    public function tablesSave(Request $request)
    {
        $selected = (array) $request->input('tables', []);
        $dateCols = (array) $request->input('date_columns', []);
        $selectedSet = array_flip($selected);

        DB::transaction(function () use ($selectedSet, $dateCols) {
            // On désactive toutes les tables actuelles
            HfsqlTable::query()->update(['enabled' => false]);

            // On upsert chaque ligne qui a une colonne date OU qui est cochée
            // (la date column est conservée même si l'utilisateur décoche temporairement)
            foreach ($dateCols as $name => $col) {
                $name = (string) $name;
                $col = trim((string) $col) ?: null;
                $enabled = isset($selectedSet[$name]);
                if (!$enabled && !$col) continue; // rien à garder pour cette ligne
                HfsqlTable::updateOrCreate(
                    ['name' => $name],
                    ['enabled' => $enabled, 'date_column' => $col]
                );
            }
        });
        return back()->with('status', count($selectedSet) . ' tables enregistrées pour sync.');
    }

    // ── Sync history + manual trigger ────────────────────────────────────

    public function syncIndex()
    {
        $runs = HfsqlSyncRun::orderByDesc('id')->paginate(50);

        // Plage de dates persistée (s'applique aux tables avec une colonne date déclarée)
        $dateFrom = PlatformSetting::get('sync_date_from');
        $dateTo   = PlatformSetting::get('sync_date_to');

        // Tableau enrichi : toutes les tables configurées (admin) + tables avec données.
        $configured = HfsqlTable::orderBy('name')->get()->keyBy('name');
        $rowsAgg = DB::table('hfsql_raw_rows')
            ->select('table_name', DB::raw('COUNT(*) AS n'), DB::raw('MAX(synced_at) AS last_sync'))
            ->groupBy('table_name')->get()->keyBy('table_name');
        $lastRunPerTable = HfsqlSyncRun::query()
            ->selectRaw('DISTINCT ON (table_name) table_name, status, started_at, finished_at, rows_pulled, error')
            ->orderBy('table_name')
            ->orderByDesc('id')
            ->get()
            ->keyBy('table_name');

        $allNames = $configured->keys()->merge($rowsAgg->keys())->unique()->sort()->values();
        $tables = $allNames->map(function ($name) use ($configured, $rowsAgg, $lastRunPerTable) {
            $cfg = $configured->get($name);
            $rows = $rowsAgg->get($name);
            $run = $lastRunPerTable->get($name);
            return [
                'name'        => $name,
                'enabled'     => $cfg?->enabled ?? false,
                'date_column' => $cfg?->date_column,
                'rows_in_db'  => $rows?->n ?? 0,
                'last_sync'   => $rows?->last_sync,
                'last_status' => $run?->status,
                'last_error'  => $run?->error,
                'last_run_at' => $run?->started_at,
            ];
        });

        return view('admin.sync.index', compact('runs', 'tables', 'dateFrom', 'dateTo'));
    }

    public function syncDateRangeSave(Request $request)
    {
        $data = $request->validate([
            'date_from' => 'nullable|date',
            'date_to'   => 'nullable|date|after_or_equal:date_from',
        ]);
        PlatformSetting::set('sync_date_from', $data['date_from'] ?? null);
        PlatformSetting::set('sync_date_to',   $data['date_to']   ?? null);
        $msg = ($data['date_from'] ?? null)
            ? 'Plage de dates enregistrée : du ' . $data['date_from'] . ' au ' . ($data['date_to'] ?? 'aujourd\'hui')
            : 'Plage de dates réinitialisée (retour au filtre par défaut HFSQL_SINCE_MONTHS).';
        return back()->with('status', $msg);
    }

    public function syncTrigger(Request $request)
    {
        if ($this->syncJobAlive()) {
            return back()->with('status', '⚠️ Un sync est déjà en cours. Utilisez « Arrêter » d\'abord.');
        }

        // Reset éventuelle demande d'annulation précédente
        PlatformSetting::set('sync_cancel_requested', null);

        $resume = $request->boolean('resume');
        // Dispatch via queue — un worker (Laravel Cloud "Worker" ou
        // `php artisan queue:work` en local) le traitera.
        Artisan::queue('hfsql:sync', $resume ? ['--resume' => true] : []);

        return back()->with('status', $resume
            ? 'Reprise du sync mise en file. Un worker va l\'exécuter dans quelques secondes.'
            : 'Synchronisation mise en file. Un worker va l\'exécuter dans quelques secondes.');
    }

    public function syncStop()
    {
        if (!$this->syncJobAlive()) {
            return back()->with('status', 'Aucun sync en cours.');
        }
        // Drapeau lu par la commande entre chaque batch — arrêt propre.
        PlatformSetting::set('sync_cancel_requested', '1');
        return back()->with('status', '⏹ Demande d\'arrêt enregistrée. La sync s\'interrompra entre 2 batches (quelques secondes). Vous pourrez ensuite « Reprendre ».');
    }

    /**
     * True si une sync est considérée comme active.
     * Compte un "running" en DB mis à jour il y a moins de 5 min (heartbeat).
     */
    private function syncJobAlive(): bool
    {
        $threshold = now()->subSeconds(300);
        return HfsqlSyncRun::where('status', 'running')
            ->where('started_at', '>=', $threshold)
            ->exists();
    }

    public function syncStatusJson()
    {
        // Détection "sync vivante" par heartbeat DB : la commande met à jour
        // hfsql_sync_runs.started_at toutes les 30s. Si pas de mise à jour
        // depuis 5 min, on considère le job mort.
        $threshold = now()->subSeconds(300);
        HfsqlSyncRun::where('status', 'running')
            ->where('started_at', '<', $threshold)
            ->update([
                'status'      => 'error',
                'finished_at' => now(),
                'error'       => 'job interrompu — heartbeat absent depuis 5 min (worker tué ? timeout queue ?)',
            ]);
        $running = HfsqlSyncRun::where('status', 'running')->orderByDesc('id')->first();
        $isProcessLive = $running !== null;

        // Liste complète : tables configurées (admin) ∪ tables présentes en base.
        $configured = HfsqlTable::orderBy('name')->get()->keyBy('name');
        $rowsAgg = DB::table('hfsql_raw_rows')
            ->select('table_name', DB::raw('COUNT(*) AS n'), DB::raw('MAX(synced_at) AS last_sync'))
            ->groupBy('table_name')->get()->keyBy('table_name');
        $lastRuns = HfsqlSyncRun::query()
            ->selectRaw('DISTINCT ON (table_name) table_name, status, started_at, error')
            ->orderBy('table_name')->orderByDesc('id')->get()->keyBy('table_name');

        $names = $configured->keys()->merge($rowsAgg->keys())->unique()->sort()->values();
        $tables = $names->map(fn ($name) => [
            'name'        => $name,
            'enabled'     => (bool) ($configured->get($name)?->enabled ?? false),
            'date_column' => $configured->get($name)?->date_column,
            'rows'        => (int) ($rowsAgg->get($name)?->n ?? 0),
            'last_sync'   => $rowsAgg->get($name)?->last_sync,
            'status'      => $lastRuns->get($name)?->status,
            'error'       => $lastRuns->get($name)?->error ? substr($lastRuns->get($name)->error, 0, 100) : null,
        ])->values();

        // Reprise possible si au moins une table activée n'a pas un dernier sync OK
        $hasResumable = $configured->filter(function ($c) use ($lastRuns) {
            if (!$c->enabled) return false;
            $r = $lastRuns->get($c->name);
            return !$r || $r->status !== 'ok';
        })->isNotEmpty();

        return response()->json([
            'is_running'         => $isProcessLive,
            'current_table'      => $running?->table_name,
            'current_started_at' => $running?->started_at?->toIso8601String(),
            'rows_total'         => (int) DB::table('hfsql_raw_rows')->count(),
            'tables_count'       => $rowsAgg->count(),
            'has_resumable'      => $hasResumable,
            'tables'             => $tables,
        ]);
    }
}
