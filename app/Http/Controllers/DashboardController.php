<?php

namespace App\Http\Controllers;

use App\Models\HfsqlSyncRun;
use App\Models\Projet;
use App\Services\Hfsql\HfsqlService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $term  = $request->string('q')->toString() ?: null;
        $etat  = $request->string('etat')->toString() ?: null;
        $only  = $request->boolean('actifs', true);

        $base = Projet::query();
        if ($only) $base->actifs();
        $base->search($term)->etat($etat);

        $projets = $base->orderByRaw("payload->>'Nom'")->paginate(25)->withQueryString();

        $stats = [
            'total_projets'    => Projet::count(),
            'projets_actifs'   => Projet::actifs()->count(),
            'heures_prevues'   => (float) Projet::actifs()
                ->sum(DB::raw("(payload->>'HeuresPrevues')::numeric")),
        ];

        $etats = Projet::query()
            ->selectRaw("payload->>'Etat_Code' as etat, COUNT(*) as n")
            ->groupBy('etat')
            ->orderByDesc('n')
            ->get();

        $syncStatus = $this->syncStatus();

        return view('dashboard.index', compact('projets', 'stats', 'etats', 'syncStatus', 'term', 'etat', 'only'));
    }

    public function projet(int $id)
    {
        $projet = Projet::query()
            ->whereRaw("(payload->>'IDProjet')::int = ?", [$id])
            ->firstOrFail();

        // Les tâches du projet (si S_Tache est synchronisée)
        $taches = DB::table('hfsql_raw_rows')
            ->where('table_name', 'S_Tache')
            ->whereRaw("(payload->>'IDProjet')::int = ?", [$id])
            ->get()
            ->map(fn ($r) => is_string($r->payload) ? json_decode($r->payload, true) : (array) $r->payload);

        // Les plannings du projet (si P_Planning est synchronisée)
        $planningCount = DB::table('hfsql_raw_rows')
            ->where('table_name', 'P_Planning')
            ->whereRaw("(payload->>'IDProjet')::int = ?", [$id])
            ->count();

        // Dépensé par famille via S_Com_Suivi_Element (si dispo)
        $depenseParFamille = collect();
        if (DB::table('hfsql_raw_rows')->where('table_name', 'S_Com_Suivi_Element')->exists()) {
            $depenseParFamille = DB::table('hfsql_raw_rows')
                ->where('table_name', 'S_Com_Suivi_Element')
                ->whereRaw("(payload->>'IDProjet')::int = ?", [$id])
                ->get();
        }

        return view('dashboard.projet', compact('projet', 'taches', 'planningCount', 'depenseParFamille'));
    }

    public function syncNow(Request $request)
    {
        $tables = (array) $request->input('tables', []);
        Artisan::queue('hfsql:sync', ['tables' => $tables]);
        return back()->with('status', 'Synchronisation lancée en arrière-plan.');
    }

    /** État résumé de la dernière synchro pour le bandeau du dashboard. */
    private function syncStatus(): array
    {
        $tables = DB::table('hfsql_raw_rows')
            ->select('table_name', DB::raw('COUNT(*) AS rows'), DB::raw('MAX(synced_at) AS last_sync'))
            ->groupBy('table_name')
            ->get()
            ->keyBy('table_name');

        $errors = HfsqlSyncRun::where('status', 'error')->latest('id')->limit(5)->get();
        $running = HfsqlSyncRun::where('status', 'running')->exists();

        return [
            'tables_locales' => $tables,
            'errors'         => $errors,
            'is_running'     => $running,
        ];
    }
}
