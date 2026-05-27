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

        return view('admin.hfsql.tables', compact('remote', 'local', 'rows', 'remoteError'));
    }

    public function tablesSave(Request $request)
    {
        $selected = (array) $request->input('tables', []);
        $dateCols = (array) $request->input('date_columns', []);

        DB::transaction(function () use ($selected, $dateCols) {
            HfsqlTable::query()->update(['enabled' => false]);
            foreach ($selected as $name) {
                HfsqlTable::updateOrCreate(
                    ['name' => $name],
                    ['enabled' => true, 'date_column' => $dateCols[$name] ?? null]
                );
            }
        });
        return back()->with('status', count($selected) . ' tables enregistrées pour sync.');
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
        if ($this->syncProcessAlive()) {
            return back()->with('status', '⚠️ Un sync est déjà en cours. Utilisez « Arrêter » d\'abord.');
        }

        $resume = $request->boolean('resume');
        $php = PHP_BINARY;
        $artisan = base_path('artisan');
        $log = storage_path('logs/hfsql-sync-manual.log');
        $flag = $resume ? ' --resume' : '';
        $cmd = sprintf('%s %s hfsql:sync%s > %s 2>&1 &',
            escapeshellarg($php), escapeshellarg($artisan), $flag, escapeshellarg($log));
        exec($cmd);
        return back()->with('status', $resume
            ? 'Reprise du sync lancée — ne refait que les tables non OK.'
            : 'Synchronisation lancée. La page se rafraîchit en live.');
    }

    public function syncStop()
    {
        $pids = $this->syncPids();
        if (empty($pids)) {
            return back()->with('status', 'Aucun sync en cours.');
        }
        foreach ($pids as $pid) {
            // SIGTERM d'abord (propre), si toujours là après 2s on SIGKILL
            @posix_kill((int) $pid, 15);
        }
        sleep(2);
        foreach ($this->syncPids() as $pid) {
            @posix_kill((int) $pid, 9);
        }
        // Marque les "running" orphelins en error
        HfsqlSyncRun::where('status', 'running')->update([
            'status'      => 'error',
            'finished_at' => now(),
            'error'       => 'arrêté manuellement depuis l\'admin',
        ]);
        return back()->with('status', '⏹ Sync arrêté (' . count($pids) . ' process tué·s). Vous pouvez « Reprendre » pour ne refaire que les manquantes.');
    }

    /** True si au moins un process `php artisan hfsql:sync` tourne actuellement. */
    private function syncProcessAlive(): bool
    {
        return !empty($this->syncPids());
    }

    /** Retourne les PIDs des process artisan hfsql:sync (hors shell wrappers). */
    private function syncPids(): array
    {
        $out = [];
        // -f matche la ligne de commande, -x pour matcher exactement /usr/bin/phpX.Y artisan hfsql:sync
        @exec("pgrep -af 'artisan hfsql:sync' 2>/dev/null", $lines);
        foreach ($lines as $line) {
            // On filtre les bash/grep/pgrep eux-mêmes : on garde les lignes contenant "php" en début
            if (preg_match('/^(\d+)\s+\S*php\S*\s+\S*artisan\s+hfsql:sync/', $line, $m)) {
                $out[] = $m[1];
            }
        }
        return $out;
    }

    public function syncStatusJson()
    {
        $isProcessLive = $this->syncProcessAlive();

        // Si pas de process vivant mais un "running" orphelin en DB → on le nettoie
        if (!$isProcessLive) {
            HfsqlSyncRun::where('status', 'running')->update([
                'status' => 'error',
                'finished_at' => now(),
                'error' => 'process interrompu — orphelin détecté au refresh',
            ]);
        }
        $running = $isProcessLive
            ? HfsqlSyncRun::where('status', 'running')->orderByDesc('id')->first()
            : null;

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
