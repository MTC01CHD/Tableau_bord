<?php

namespace App\Http\Controllers;

use App\Models\HfsqlSyncRun;
use App\Models\Projet;
use App\Support\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __construct(private TenantContext $ctx) {}

    /** Query builder sur hfsql_raw_rows pré-scopé au tenant courant. */
    private function rawRows(): Builder
    {
        return DB::table('hfsql_raw_rows')->where('tenant_id', $this->ctx->requireId());
    }

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

        // ── Lookups référentiels (libellés humains à partir des FK) ──────────
        $etatLibelle = $this->lookupValue(
            'S_Projet_etat', 'Etat_Code', $projet->etat, 'Descriptif'
        );

        $gestionnaire = $this->lookupRow('S_Personnel', 'IDPersonnel', $projet->id_gestionnaire);
        $gestionnaireNom = $gestionnaire
            ? trim(($gestionnaire['Prenom'] ?? '') . ' ' . ($gestionnaire['Nom'] ?? '')) ?: ($gestionnaire['Login'] ?? null)
            : null;

        $departementNom = $this->lookupValue(
            'S_Departement', 'IDDepartement', $projet->id_departement, 'Nom'
        ) ?? $this->lookupValue('S_Departement', 'IDDepartement', $projet->id_departement, 'Designation');

        // ── Tâches du projet ─────────────────────────────────────────────────
        $taches = $this->rawRows()
            ->where('table_name', 'S_Tache')
            ->whereRaw("(payload->>'IDProjet')::int = ?", [$id])
            ->get()
            ->map(fn ($r) => $this->jsonRow($r->payload))
            ->sortBy(fn ($t) => (int) ($t['Ordre'] ?? 0))
            ->values();

        // ── Plannings du projet (depuis P_Planning) ─────────────────────────
        $plannings = $this->rawRows()
            ->where('table_name', 'P_Planning')
            ->whereRaw("(payload->>'IDProjet')::int = ?", [$id])
            ->get()
            ->map(fn ($r) => $this->jsonRow($r->payload));

        $planningCount = $plannings->count();

        // ── Heures pointées effectives (règle original/modifié) ──────────────
        // Pour chaque IDP_Planning de ce projet, prendre la ligne P_Planning_Pointage
        // non-original si elle existe (correction), sinon l'original.
        $heuresEffectives = 0.0;
        $heuresParPersonne = collect();

        $planningIds = $plannings->pluck('IDP_Planning')->filter()->map(fn ($v) => (int) $v)->all();
        if (!empty($planningIds) && $this->rawRows()->where('table_name', 'P_Planning_Pointage')->exists()) {
            $pointages = $this->rawRows()
                ->where('table_name', 'P_Planning_Pointage')
                ->whereRaw("(payload->>'IDP_Planning')::int = ANY(?)", ['{' . implode(',', $planningIds) . '}'])
                ->get()
                ->map(fn ($r) => $this->jsonRow($r->payload));

            // Garder UN pointage par IDP_Planning : non-original prioritaire
            $effectifs = $pointages
                ->groupBy(fn ($p) => $p['IDP_Planning'] ?? null)
                ->map(function ($group) {
                    $modif = $group->first(fn ($p) => !((int) ($p['original'] ?? 1)));
                    return $modif ?: $group->first();
                })
                ->values();

            // Mapper plannings par ID pour récupérer la personne
            $planningById = $plannings->keyBy(fn ($p) => (int) ($p['IDP_Planning'] ?? 0));

            foreach ($effectifs as $p) {
                $heures = (float) ($p['Heures'] ?? $p['NbHeures'] ?? 0);
                $heuresEffectives += $heures;

                $idPlanning = (int) ($p['IDP_Planning'] ?? 0);
                $idPers     = $planningById[$idPlanning]['IDPersonnel'] ?? null;
                if ($idPers) {
                    $heuresParPersonne[$idPers] = ($heuresParPersonne[$idPers] ?? 0) + $heures;
                }
            }
        }

        // Enrichir le top contributeurs avec les noms
        $topContributeurs = $heuresParPersonne
            ->sortDesc()
            ->take(10)
            ->map(function ($h, $idPers) {
                $row = $this->lookupRow('S_Personnel', 'IDPersonnel', $idPers);
                $nom = $row
                    ? trim(($row['Prenom'] ?? '') . ' ' . ($row['Nom'] ?? '')) ?: ($row['Login'] ?? "Personnel #{$idPers}")
                    : "Personnel #{$idPers}";
                return ['nom' => $nom, 'heures' => round($h, 2)];
            })
            ->values();

        // ── Dépensé par famille via S_Com_Suivi_Element (si dispo) ──────────
        $depenseParFamille = collect();
        if ($this->rawRows()->where('table_name', 'S_Com_Suivi_Element')->exists()) {
            $depenseParFamille = $this->rawRows()
                ->where('table_name', 'S_Com_Suivi_Element')
                ->whereRaw("(payload->>'IDProjet')::int = ?", [$id])
                ->get();
        }

        return view('dashboard.projet', compact(
            'projet', 'etatLibelle', 'gestionnaireNom', 'departementNom',
            'taches', 'planningCount', 'heuresEffectives', 'topContributeurs',
            'depenseParFamille'
        ));
    }

    /** Décode un payload JSONB (string ou objet). */
    private function jsonRow($payload): array
    {
        return is_string($payload) ? (array) json_decode($payload, true) : (array) $payload;
    }

    /** Lookup d'une seule colonne dans une table hfsql_raw_rows par FK. */
    private function lookupValue(string $table, string $fkCol, $fkVal, string $returnCol): ?string
    {
        $row = $this->lookupRow($table, $fkCol, $fkVal);
        return $row[$returnCol] ?? null;
    }

    /** Récupère le 1er payload d'une table où FK == valeur. */
    private function lookupRow(string $table, string $fkCol, $fkVal): ?array
    {
        if ($fkVal === null || $fkVal === '') return null;
        $payload = $this->rawRows()
            ->where('table_name', $table)
            ->whereRaw("payload->>? = ?", [$fkCol, (string) $fkVal])
            ->limit(1)
            ->value('payload');
        return $payload ? $this->jsonRow($payload) : null;
    }

    /** État résumé de la dernière synchro pour le bandeau du dashboard. */
    private function syncStatus(): array
    {
        $tables = $this->rawRows()
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
