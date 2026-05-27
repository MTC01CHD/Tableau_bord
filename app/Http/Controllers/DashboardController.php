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

        // ── Agrégats par projet (un seul SQL chacun, lookup par IDProjet) ──
        // Σ vendu par projet (depuis S_Tache.Somme_V)
        $ventesParProjet = $this->rawRows()
            ->where('table_name', 'S_Tache')
            ->selectRaw("(payload->>'IDProjet')::int AS pid, SUM(COALESCE((payload->>'Somme_V')::numeric, 0)) AS s")
            ->groupBy('pid')
            ->pluck('s', 'pid');
        // Σ réalisé par projet (depuis S_Tache.Somme_R)
        $realisesParProjet = $this->rawRows()
            ->where('table_name', 'S_Tache')
            ->selectRaw("(payload->>'IDProjet')::int AS pid, SUM(COALESCE((payload->>'Somme_R')::numeric, 0)) AS s")
            ->groupBy('pid')
            ->pluck('s', 'pid');
        // Nombre de tâches par projet (indicateur "y a-t-il quelque chose à voir ?")
        $nbTachesParProjet = $this->rawRows()
            ->where('table_name', 'S_Tache')
            ->selectRaw("(payload->>'IDProjet')::int AS pid, COUNT(*) AS n")
            ->groupBy('pid')
            ->pluck('n', 'pid');
        // Nombre de plannings par projet
        $nbPlanningsParProjet = $this->rawRows()
            ->where('table_name', 'P_Planning')
            ->selectRaw("(payload->>'IDProjet')::int AS pid, COUNT(*) AS n")
            ->groupBy('pid')
            ->pluck('n', 'pid');

        // Lookup libellés d'état
        $libellesEtats = $this->rawRows()
            ->where('table_name', 'S_Projet_etat')
            ->get()
            ->mapWithKeys(function ($r) {
                $p = $this->jsonRow($r->payload);
                return [$p['Etat_Code'] ?? '' => $p['Descriptif'] ?? ''];
            })
            ->filter(fn ($v, $k) => $k !== '');

        // ── KPI portfolio ──────────────────────────────────────────────────
        $totalVendu   = (float) $ventesParProjet->sum();
        $totalRealise = (float) $realisesParProjet->sum();
        $totalMarge   = $totalVendu - $totalRealise;

        // Top 5 dérapages (réalisé > vendu) parmi les projets actifs
        $projetsActifsIds = Projet::actifs()
            ->selectRaw("(payload->>'IDProjet')::int AS pid")
            ->pluck('pid');

        $derapages = $projetsActifsIds
            ->mapWithKeys(fn ($pid) => [$pid => (float) ($realisesParProjet[$pid] ?? 0) - (float) ($ventesParProjet[$pid] ?? 0)])
            ->filter(fn ($delta) => $delta > 0)
            ->sortDesc()
            ->take(5);
        // Top 5 mieux maîtrisés (plus grosse marge en €)
        $topMarge = $projetsActifsIds
            ->mapWithKeys(fn ($pid) => [$pid => (float) ($ventesParProjet[$pid] ?? 0) - (float) ($realisesParProjet[$pid] ?? 0)])
            ->filter(fn ($m) => $m > 0)
            ->sortDesc()
            ->take(5);

        $derapagesEnriched = $this->enrichTopProjets($derapages, 'depassement', $ventesParProjet, $realisesParProjet);
        $topMargeEnriched  = $this->enrichTopProjets($topMarge,  'marge',         $ventesParProjet, $realisesParProjet);

        $stats = [
            'total_projets'    => Projet::count(),
            'projets_actifs'   => Projet::actifs()->count(),
            'total_vendu'      => $totalVendu,
            'total_realise'    => $totalRealise,
            'total_marge'      => $totalMarge,
            'nb_derapages'     => $derapages->count(),
            'heures_prevues'   => (float) Projet::actifs()
                ->sum(DB::raw("(payload->>'HeuresPrevues')::numeric")),
        ];

        // Données pré-formatées pour le bar chart Vendu vs Réalisé (top 10)
        $chartVenduRealise = $ventesParProjet
            ->map(fn ($v, $pid) => [
                'pid'     => (int) $pid,
                'vendu'   => (float) $v,
                'realise' => (float) ($realisesParProjet[$pid] ?? 0),
            ])
            ->sortByDesc('vendu')
            ->take(10)
            ->values()
            ->all();

        // Liste des états avec libellé pour le filtre
        $etats = Projet::query()
            ->selectRaw("payload->>'Etat_Code' as etat, COUNT(*) as n")
            ->groupBy('etat')
            ->orderByDesc('n')
            ->get()
            ->map(function ($e) use ($libellesEtats) {
                $e->libelle = $libellesEtats[$e->etat] ?? $e->etat;
                return $e;
            });

        $syncStatus = $this->syncStatus();

        return view('dashboard.index', compact(
            'projets', 'stats', 'etats', 'syncStatus', 'term', 'etat', 'only',
            'ventesParProjet', 'realisesParProjet',
            'nbTachesParProjet', 'nbPlanningsParProjet', 'libellesEtats',
            'derapagesEnriched', 'topMargeEnriched', 'chartVenduRealise'
        ));
    }

    /** Enrichit un top (id => valeur) avec nom + numéro du projet. */
    private function enrichTopProjets($collection, string $valueKey, $ventes, $realises): \Illuminate\Support\Collection
    {
        return collect($collection)->map(function ($val, $pid) use ($valueKey, $ventes, $realises) {
            $p = Projet::query()->whereRaw("(payload->>'IDProjet')::int = ?", [$pid])->first();
            return [
                'id'        => $pid,
                'nom'       => $p?->nom ?? "Projet #{$pid}",
                'numero'    => $p?->numero ?? '',
                'vendu'     => (float) ($ventes[$pid] ?? 0),
                'realise'   => (float) ($realises[$pid] ?? 0),
                $valueKey   => round($val, 2),
            ];
        })->values();
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

        // ── Tarifs : P_Ressource_Prix (index par IDPersonnel et IDMateriel) ──
        $tarifsPersonnel = $this->loadTarifs('IDPersonnel');
        $tarifsMateriel  = $this->loadTarifs('IDMateriel');

        // ── Coût MO estimé = Σ (heures pointées × tarif personnel) ──────────
        $coutMO = 0.0;
        $topContributeurs = $heuresParPersonne
            ->sortDesc()
            ->take(10)
            ->map(function ($h, $idPers) use ($tarifsPersonnel, &$coutMO) {
                $row = $this->lookupRow('S_Personnel', 'IDPersonnel', $idPers);
                $nom = $row
                    ? trim(($row['Prenom'] ?? '') . ' ' . ($row['Nom'] ?? '')) ?: ($row['Login'] ?? "Personnel #{$idPers}")
                    : "Personnel #{$idPers}";
                $tarif = (float) ($tarifsPersonnel[$idPers] ?? 0);
                $cout  = round($h * $tarif, 2);
                return ['nom' => $nom, 'heures' => round($h, 2), 'tarif' => $tarif, 'cout' => $cout];
            })
            ->values();
        // Coût total MO (toutes les personnes, pas seulement le top 10)
        foreach ($heuresParPersonne as $idPers => $h) {
            $coutMO += $h * (float) ($tarifsPersonnel[$idPers] ?? 0);
        }
        $coutMO = round($coutMO, 2);

        // ── Heures matériel + coût matériel ────────────────────────────────
        $matTable = $this->detectMaterielTable();
        $heuresParMateriel = collect();
        $coutMateriel = 0.0;
        if ($matTable) {
            // Plusieurs schémas possibles : la table peut avoir IDProjet direct,
            // ou être liée par IDP_Planning à un planning du projet.
            $rowsMat = $this->rawRows()
                ->where('table_name', $matTable)
                ->where(function ($q) use ($id, $planningIds) {
                    $q->whereRaw("(payload->>'IDProjet')::int = ?", [$id]);
                    if (!empty($planningIds)) {
                        $q->orWhereRaw(
                            "(payload->>'IDP_Planning')::int = ANY(?)",
                            ['{' . implode(',', $planningIds) . '}']
                        );
                    }
                })
                ->get()
                ->map(fn ($r) => $this->jsonRow($r->payload));

            foreach ($rowsMat as $pm) {
                $idMat  = $pm['IDMateriel'] ?? $pm['IDP_Materiel'] ?? null;
                $heures = (float) ($pm['Heures'] ?? $pm['NbHeures'] ?? $pm['Quantite'] ?? $pm['Duree'] ?? 0);
                if (!$idMat || $heures <= 0) continue;
                $heuresParMateriel[$idMat] = ($heuresParMateriel[$idMat] ?? 0) + $heures;
                $coutMateriel += $heures * (float) ($tarifsMateriel[$idMat] ?? 0);
            }
        }
        $coutMateriel = round($coutMateriel, 2);

        $topMateriel = $heuresParMateriel
            ->sortDesc()
            ->take(10)
            ->map(function ($h, $idMat) use ($tarifsMateriel) {
                $row = $this->lookupRow('P_Materiel', 'IDMateriel', $idMat)
                    ?? $this->lookupRow('S_Moyen', 'IDMoyen', $idMat);
                $nom = $row['Designation'] ?? $row['Nom'] ?? $row['Libelle'] ?? "Matériel #{$idMat}";
                $tarif = (float) ($tarifsMateriel[$idMat] ?? 0);
                $cout  = round($h * $tarif, 2);
                return ['nom' => $nom, 'heures' => round($h, 2), 'tarif' => $tarif, 'cout' => $cout];
            })
            ->values();

        $coutTotal = round($coutMO + $coutMateriel, 2);

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
            'coutMO', 'coutMateriel', 'coutTotal', 'topMateriel', 'matTable',
            'depenseParFamille'
        ));
    }

    /**
     * Construit un index id → tarif horaire depuis P_Ressource_Prix.
     * `$idCol` = IDPersonnel ou IDMateriel.
     * Si plusieurs lignes existent (historique de tarifs), on garde la dernière non-vide.
     */
    private function loadTarifs(string $idCol): array
    {
        if (!$this->rawRows()->where('table_name', 'P_Ressource_Prix')->exists()) {
            return [];
        }
        $rows = $this->rawRows()
            ->where('table_name', 'P_Ressource_Prix')
            ->get()
            ->map(fn ($r) => $this->jsonRow($r->payload));

        $out = [];
        foreach ($rows as $r) {
            $id = $r[$idCol] ?? null;
            if (!$id) continue;
            // Colonnes prix possibles, par ordre de priorité
            $tarif = (float) ($r['PrixHoraire'] ?? $r['Tarif'] ?? $r['Prix']
                ?? $r['PrixUnitaire'] ?? $r['Montant'] ?? 0);
            if ($tarif > 0) $out[$id] = $tarif;
        }
        return $out;
    }

    /** Détecte la 1ère table de pointage matériel synchronisée. */
    private function detectMaterielTable(): ?string
    {
        foreach (['p_pointage_materiel_location', 'P_Pointage_Materiel_Affectation', 'P_Pointage_Materiel'] as $name) {
            if ($this->rawRows()->where('table_name', $name)->exists()) {
                return $name;
            }
        }
        return null;
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
