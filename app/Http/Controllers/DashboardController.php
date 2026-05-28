<?php

namespace App\Http\Controllers;

use App\Models\HfsqlSyncRun;
use App\Models\Projet;
use App\Services\ProjetDepensesService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __construct(
        private TenantContext $ctx,
        private ProjetDepensesService $depenses,
    ) {}

    /** Query builder sur hfsql_raw_rows pré-scopé au tenant courant. */
    private function rawRows(): Builder
    {
        return DB::table('hfsql_raw_rows')->where('tenant_id', $this->ctx->requireId());
    }

    public function index(Request $request)
    {
        // Export CSV : on intercepte avant le rendu normal
        if ($request->boolean('export') === true || $request->query('export') === 'csv') {
            return $this->exportCsv($request);
        }

        $term  = $request->string('q')->toString() ?: null;
        $etat  = $request->string('etat')->toString() ?: null;
        $only  = $request->boolean('actifs', true);
        $derapagesOnly = $request->boolean('derapages');
        $sort  = $request->string('sort')->toString() ?: 'nom';

        $base = Projet::query();
        if ($only) $base->actifs();
        $base->search($term)->etat($etat);

        // ── Agrégats par projet (un seul SQL chacun, lookup par IDProjet) ──
        // Prévu en prix de vente par projet (Σ S_Tache.Somme_V)
        $prevuPVParProjet = $this->rawRows()
            ->where('table_name', 'S_Tache')
            ->selectRaw("(payload->>'IDProjet')::int AS pid, SUM(COALESCE((payload->>'Somme_V')::numeric, 0)) AS s")
            ->groupBy('pid')
            ->pluck('s', 'pid');
        // Prévu en prix de revient par projet (Σ S_Tache.Somme_R) — c'est du PRÉVU, pas du réalisé.
        $prevuPRParProjet = $this->rawRows()
            ->where('table_name', 'S_Tache')
            ->selectRaw("(payload->>'IDProjet')::int AS pid, SUM(COALESCE((payload->>'Somme_R')::numeric, 0)) AS s")
            ->groupBy('pid')
            ->pluck('s', 'pid');
        // RÉALISÉ par projet = Σ éléments de S_Com_Suivi(Type='Vente') — PV et PR
        // (une seule requête SQL agrégée pour tous les projets).
        $realiseAggr = $this->depenses->realiseParProjet();
        $realisePVParProjet = $realiseAggr->map(fn ($r) => $r['pv']);
        $realisePRParProjet = $realiseAggr->map(fn ($r) => $r['pr']);
        // DÉPENSÉ par projet = Σ achats + personnel + matériel + location
        $depensesParProjet = $this->depenses->depensesParProjet();
        // HEURES par projet = prévues (S_Tache.Heures) + dépensées (Σ Duree pointages)
        $heuresParProjet = $this->depenses->heuresParProjet();

        // ── Diagnostic visible : si Réalisé ou Dépensé sont tous à 0, on
        // remonte d'où ça vient (compte les types, les clés, etc.).
        $diagnostic = $this->buildDiagnostic($realisePVParProjet, $depensesParProjet);
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
        $totalPrevuPV   = (float) $prevuPVParProjet->sum();
        $totalPrevuPR   = (float) $prevuPRParProjet->sum();
        $totalRealisePV = (float) $realisePVParProjet->sum();
        $totalRealisePR = (float) $realisePRParProjet->sum();
        $totalMargeRealise = $totalRealisePV - $totalRealisePR;

        // Top 5 dérapages : marge réalisée négative (Réalisé PR > Réalisé PV)
        $projetsActifsIds = Projet::actifs()
            ->selectRaw("(payload->>'IDProjet')::int AS pid")
            ->pluck('pid');

        $derapages = $projetsActifsIds
            ->mapWithKeys(fn ($pid) => [$pid => (float) ($realisePRParProjet[$pid] ?? 0) - (float) ($realisePVParProjet[$pid] ?? 0)])
            ->filter(fn ($delta) => $delta > 0)
            ->sortDesc()
            ->take(5);
        // Top 5 mieux maîtrisés : plus grosse marge réalisée en €
        $topMarge = $projetsActifsIds
            ->mapWithKeys(fn ($pid) => [$pid => (float) ($realisePVParProjet[$pid] ?? 0) - (float) ($realisePRParProjet[$pid] ?? 0)])
            ->filter(fn ($m) => $m > 0)
            ->sortDesc()
            ->take(5);

        $derapagesEnriched = $this->enrichTopProjets($derapages, 'depassement', $realisePVParProjet, $realisePRParProjet);
        $topMargeEnriched  = $this->enrichTopProjets($topMarge,  'marge',         $realisePVParProjet, $realisePRParProjet);

        $stats = [
            'total_projets'      => Projet::count(),
            'projets_actifs'     => Projet::actifs()->count(),
            'total_prevu_pv'     => $totalPrevuPV,
            'total_prevu_pr'     => $totalPrevuPR,
            'total_realise_pv'   => $totalRealisePV,
            'total_realise_pr'   => $totalRealisePR,
            'total_marge_realise'=> $totalMargeRealise,
            'nb_derapages'       => $derapages->count(),
            'heures_prevues'     => (float) Projet::actifs()
                ->sum(DB::raw("(payload->>'HeuresPrevues')::numeric")),
        ];

        // Données pré-formatées pour le bar chart Prévu PV vs Réalisé PV (top 10)
        $chartVenduRealise = $prevuPVParProjet
            ->map(fn ($v, $pid) => [
                'pid'     => (int) $pid,
                'vendu'   => (float) $v,                                  // = Prévu PV
                'realise' => (float) ($realisePVParProjet[$pid] ?? 0),    // = Réalisé PV (facturé)
            ])
            ->sortByDesc('vendu')
            ->take(10)
            ->values()
            ->all();

        // Filtre "dérapages seulement" : marge réalisée négative
        if ($derapagesOnly) {
            $derapageIds = $realisePRParProjet->filter(function ($pr, $pid) use ($realisePVParProjet) {
                return (float) $pr > (float) ($realisePVParProjet[$pid] ?? 0);
            })->keys()->all();
            if (!empty($derapageIds)) {
                $base->whereRaw("(payload->>'IDProjet')::int = ANY(?)", ['{' . implode(',', $derapageIds) . '}']);
            } else {
                $base->whereRaw('1=0'); // aucun dérapage → résultat vide
            }
        }

        // Tri (les tris par valeurs agrégées se font côté view sur la page courante)
        $sortMap = [
            'nom'        => "payload->>'Nom' ASC",
            'numero'     => "payload->>'numero' ASC",
            'date_debut' => "payload->>'DateDeDebut' DESC NULLS LAST",
            'date_fin'   => "payload->>'DateDeFin' DESC NULLS LAST",
        ];
        $orderClause = $sortMap[$sort] ?? $sortMap['nom'];
        $projets = $base->orderByRaw($orderClause)->paginate(25)->withQueryString();

        // Liste des états avec libellé pour le filtre.
        // On regroupe tolérant à la casse/aux variantes de nom de la clé d'état :
        // le payload peut être 'Etat_Code', 'EtatCode', 'etat_code', 'IDEtat' selon
        // comment HFSQL/T-REPORT a sérialisé la colonne. On les essaie en cascade.
        $etats = $this->rawRows()
            ->where('table_name', 'S_Projet')
            ->selectRaw("
                COALESCE(
                    NULLIF(payload->>'Etat_Code', ''),
                    NULLIF(payload->>'EtatCode', ''),
                    NULLIF(payload->>'etat_code', ''),
                    NULLIF(payload->>'IDEtat', ''),
                    NULLIF(payload->>'Etat', '')
                ) as etat,
                COUNT(*) as n
            ")
            ->groupBy('etat')
            ->orderByDesc('n')
            ->get()
            ->map(function ($e) use ($libellesEtats) {
                $e->libelle = $libellesEtats[$e->etat] ?? $e->etat;
                return $e;
            });

        // Diagnostic état : si demandé via ?debug=etat, on retourne les clés réelles
        // d'un payload S_Projet pour voir lesquelles existent vraiment.
        $debugEtat = null;
        if ($request->query('debug') === 'etat') {
            $sample = $this->rawRows()
                ->where('table_name', 'S_Projet')
                ->limit(3)
                ->get();
            $debugEtat = [
                'etats_collection' => $etats->toArray(),
                'sample_payloads_keys' => $sample->map(fn ($r) => array_keys($this->jsonRow($r->payload)))->all(),
                'sample_payloads' => $sample->map(fn ($r) => $this->jsonRow($r->payload))->all(),
            ];
        }

        $syncStatus = $this->syncStatus();

        return view('dashboard.index', compact(
            'projets', 'stats', 'etats', 'syncStatus', 'term', 'etat', 'only',
            'derapagesOnly', 'sort',
            'prevuPVParProjet', 'prevuPRParProjet',
            'realisePVParProjet', 'realisePRParProjet',
            'depensesParProjet', 'heuresParProjet',
            'nbTachesParProjet', 'nbPlanningsParProjet', 'libellesEtats',
            'derapagesEnriched', 'topMargeEnriched', 'chartVenduRealise',
            'debugEtat', 'diagnostic'
        ));
    }

    /**
     * Construit un mini-diagnostic affiché en haut de la liste UNIQUEMENT
     * si Réalisé ou Dépensé sont entièrement vides — sinon on cache.
     * But : voir IMMÉDIATEMENT pourquoi les colonnes restent vides
     * (table manquante, valeurs Type différentes de 'Vente'/'Achat', etc.).
     */
    private function buildDiagnostic($realisePVParProjet, $depensesParProjet): ?array
    {
        $sumRealise = (float) $realisePVParProjet->sum();
        $sumDepense = (float) $depensesParProjet->sum();
        if ($sumRealise > 0 && $sumDepense > 0) return null; // tout OK, pas de diag

        $tenantId = $this->ctx->requireId();
        $diag = ['realise_total' => $sumRealise, 'depense_total' => $sumDepense, 'pistes' => []];

        // 1) S_Com_Suivi : combien total, et quelles valeurs distinctes pour Type ?
        $nbSuivis = (int) DB::table('hfsql_raw_rows')
            ->where('tenant_id', $tenantId)->where('table_name', 'S_Com_Suivi')->count();
        $typesDistincts = $nbSuivis > 0 ? DB::select("
            SELECT COALESCE(payload->>'TYPE', payload->>'Type') AS t, COUNT(*) AS n
            FROM hfsql_raw_rows
            WHERE tenant_id = ? AND table_name = 'S_Com_Suivi'
            GROUP BY t ORDER BY n DESC LIMIT 20
        ", [$tenantId]) : [];
        $diag['s_com_suivi_count'] = $nbSuivis;
        $diag['s_com_suivi_types'] = array_map(fn ($r) => ['valeur' => $r->t, 'n' => (int) $r->n], $typesDistincts);

        // 2) S_Com_Suivi : combien ont IDProjet renseigné ?
        if ($nbSuivis > 0) {
            $nbAvecIDProjet = (int) DB::table('hfsql_raw_rows')
                ->where('tenant_id', $tenantId)->where('table_name', 'S_Com_Suivi')
                ->whereRaw("payload->>'IDProjet' IS NOT NULL AND payload->>'IDProjet' != ''")
                ->count();
            $diag['s_com_suivi_avec_idprojet'] = $nbAvecIDProjet;

            // Clés présentes dans le premier payload
            $firstPayload = DB::table('hfsql_raw_rows')
                ->where('tenant_id', $tenantId)->where('table_name', 'S_Com_Suivi')
                ->value('payload');
            if ($firstPayload) {
                $arr = is_string($firstPayload) ? json_decode($firstPayload, true) : (array) $firstPayload;
                $diag['s_com_suivi_keys'] = array_keys((array) $arr);
            }
        }

        // 3) S_Com_Suivi_Element : count + clés
        $nbElems = (int) DB::table('hfsql_raw_rows')
            ->where('tenant_id', $tenantId)->where('table_name', 'S_Com_Suivi_Element')->count();
        $diag['s_com_suivi_element_count'] = $nbElems;
        if ($nbElems > 0) {
            $firstElem = DB::table('hfsql_raw_rows')
                ->where('tenant_id', $tenantId)->where('table_name', 'S_Com_Suivi_Element')
                ->value('payload');
            if ($firstElem) {
                $arr = is_string($firstElem) ? json_decode($firstElem, true) : (array) $firstElem;
                $diag['s_com_suivi_element_keys'] = array_keys((array) $arr);
            }
        }

        // 4) Tables pointages : count + clés du 1er payload pour chaque
        $pointageTables = ['P_Planning', 'P_Planning_Pointage', 'P_Pointage_Materiel', 'p_pointage_materiel_location', 'P_Ressource_Prix', 'S_Famille_Moyen'];
        $diag['tables_pointages'] = [];
        foreach ($pointageTables as $tbl) {
            $count = (int) DB::table('hfsql_raw_rows')->where('tenant_id', $tenantId)->where('table_name', $tbl)->count();
            $keys = [];
            if ($count > 0) {
                $first = DB::table('hfsql_raw_rows')->where('tenant_id', $tenantId)->where('table_name', $tbl)->value('payload');
                if ($first) {
                    $arr = is_string($first) ? json_decode($first, true) : (array) $first;
                    $keys = array_keys((array) $arr);
                }
            }
            $diag['tables_pointages'][$tbl] = ['count' => $count, 'keys' => $keys];
        }

        // 5) Conclusions automatiques
        if ($nbSuivis === 0) {
            $diag['pistes'][] = "⚠️ Table S_Com_Suivi VIDE → relance un sync.";
        }
        if ($nbSuivis > 0 && $sumRealise == 0) {
            $valeurs = array_column($diag['s_com_suivi_types'], 'valeur');
            $hasVente = false;
            foreach ($valeurs as $v) if (strcasecmp((string) $v, 'Vente') === 0) $hasVente = true;
            if (!$hasVente) {
                $diag['pistes'][] = "⚠️ S_Com_Suivi.Type ne contient PAS la valeur 'Vente'. Valeurs trouvées : " . implode(', ', array_map(fn ($v) => "'$v'", $valeurs)) . ". Mon code cherche 'VENTE' (insensible casse) — adapter si besoin.";
            }
        }
        if ($nbSuivis > 0 && ($diag['s_com_suivi_avec_idprojet'] ?? 0) === 0) {
            $diag['pistes'][] = "⚠️ Aucun S_Com_Suivi n'a IDProjet renseigné. Clés trouvées dans payload : " . implode(', ', $diag['s_com_suivi_keys'] ?? []) . ".";
        }

        return $diag;
    }

    /** Export CSV de la liste des projets (avec filtres courants appliqués). */
    private function exportCsv(Request $request)
    {
        $term  = $request->string('q')->toString() ?: null;
        $etat  = $request->string('etat')->toString() ?: null;
        $only  = $request->boolean('actifs', true);
        $derapagesOnly = $request->boolean('derapages');

        $base = Projet::query();
        if ($only) $base->actifs();
        $base->search($term)->etat($etat);

        // Prévu (S_Tache) et Réalisé (S_Com_Suivi Vente) agrégés par projet
        $prevuPV = $this->rawRows()->where('table_name', 'S_Tache')
            ->selectRaw("(payload->>'IDProjet')::int AS pid, SUM(COALESCE((payload->>'Somme_V')::numeric, 0)) AS s")
            ->groupBy('pid')->pluck('s', 'pid');
        $prevuPR = $this->rawRows()->where('table_name', 'S_Tache')
            ->selectRaw("(payload->>'IDProjet')::int AS pid, SUM(COALESCE((payload->>'Somme_R')::numeric, 0)) AS s")
            ->groupBy('pid')->pluck('s', 'pid');
        $realiseAggr = $this->depenses->realiseParProjet();
        $realisePV = $realiseAggr->map(fn ($r) => $r['pv']);
        $realisePR = $realiseAggr->map(fn ($r) => $r['pr']);

        if ($derapagesOnly) {
            $ids = $realisePR->filter(fn ($pr, $pid) => (float) $pr > (float) ($realisePV[$pid] ?? 0))->keys()->all();
            if (!empty($ids)) $base->whereRaw("(payload->>'IDProjet')::int = ANY(?)", ['{' . implode(',', $ids) . '}']);
            else $base->whereRaw('1=0');
        }

        $libelles = $this->rawRows()->where('table_name', 'S_Projet_etat')->get()
            ->mapWithKeys(function ($r) {
                $p = $this->jsonRow($r->payload);
                return [$p['Etat_Code'] ?? '' => $p['Descriptif'] ?? ''];
            });

        $filename = 'projets-' . now()->format('Ymd-His') . '.csv';
        return response()->streamDownload(function () use ($base, $prevuPV, $prevuPR, $realisePV, $realisePR, $libelles) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8 pour Excel
            fputcsv($out, [
                'Numero', 'Nom', 'Etat', 'Description', 'DateDebut', 'DateFin', 'HeuresPrevues',
                'Prevu_PV', 'Prevu_PR', 'Realise_PV', 'Realise_PR',
                'Marge_Prevue', 'Marge_Realisee', 'Avancement%',
            ], ';');

            $base->orderByRaw("payload->>'Nom'")->chunk(200, function ($chunk) use ($out, $prevuPV, $prevuPR, $realisePV, $realisePR, $libelles) {
                foreach ($chunk as $p) {
                    $pid = $p->id_projet;
                    $pPV = (float) ($prevuPV[$pid]   ?? 0);
                    $pPR = (float) ($prevuPR[$pid]   ?? 0);
                    $rPV = (float) ($realisePV[$pid] ?? 0);
                    $rPR = (float) ($realisePR[$pid] ?? 0);
                    $margePrevue = $pPV - $pPR;
                    $margeRealisee = $rPV - $rPR;
                    $avancement = $pPV > 0 ? round(($rPV / $pPV) * 100, 1) : 0;
                    fputcsv($out, [
                        $p->numero,
                        $p->nom,
                        $libelles[$p->etat] ?? $p->etat,
                        \Illuminate\Support\Str::limit($p->description, 200, ''),
                        $p->date_debut?->format('Y-m-d') ?? '',
                        $p->date_fin?->format('Y-m-d') ?? '',
                        $p->heures_prevues,
                        $pPV, $pPR, $rPV, $rPR,
                        $margePrevue, $margeRealisee, $avancement,
                    ], ';');
                }
            });
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
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

    public function projet(int $id, Request $request)
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

        // ── Tâches du projet (prévu : Somme_V/Somme_R/Heures) ───────────────
        // Règle d'exclusion : les options inactives ne comptent pas.
        // → on garde tout sauf (TypeElement = 'OPTION' AND OptionActive = 0)
        $taches = $this->rawRows()
            ->where('table_name', 'S_Tache')
            ->whereRaw("(payload->>'IDProjet')::int = ?", [$id])
            ->get()
            ->map(fn ($r) => $this->jsonRow($r->payload))
            ->reject(fn ($t) => strtoupper((string) ($t['TypeElement'] ?? '')) === 'OPTION'
                && (int) ($t['OptionActive'] ?? 0) === 0)
            ->sortBy(fn ($t) => (int) ($t['Ordre'] ?? 0))
            ->values();

        $planningCount = $this->rawRows()
            ->where('table_name', 'P_Planning')
            ->whereRaw("(payload->>'ID_Origine')::int = ?", [$id])
            ->count();

        // ── Filtre période (DateDeDebut HFSQL) ───────────────────────────────
        $from = $this->parsePeriodeDate($request->query('from'));
        $to   = $this->parsePeriodeDate($request->query('to'), endOfDay: true);

        // ── Dépenses par famille (familles dynamiques de S_Famille_Moyen) ──
        $depenses = $this->depenses->calculer($id, $from, $to);

        // ── Réalisé (suivis de Type='Vente' : ce qui a été facturé) ─────────
        $realise = $this->depenses->calculerRealise($id, $from, $to);

        // Heures personnel et matériel à partir des familles correspondantes
        $heuresPersonnel = 0.0;
        $heuresMateriel  = 0.0;
        foreach ($depenses['familles'] as $f) {
            if ($f['par_defaut']) {
                $heuresPersonnel += (float) $f['lignes']->sum('qte');
            } elseif ($f['materiel']) {
                $heuresMateriel += (float) $f['lignes']->sum('qte');
            }
        }

        // Diagnostic dépenses : accessible via ?debug=depenses
        $debugDepenses = null;
        if ($request->query('debug') === 'depenses') {
            $samplePlannings = $this->rawRows()
                ->where('table_name', 'P_Planning')
                ->whereRaw("(payload->>'ID_Origine')::int = ?", [$id])
                ->limit(3)
                ->get()
                ->map(fn ($r) => $this->jsonRow($r->payload));

            $planningIdsSample = $samplePlannings->pluck('IDP_Planning')->filter()->map(fn ($v) => (int) $v)->all();
            $samplePointages = empty($planningIdsSample) ? collect() : $this->rawRows()
                ->where('table_name', 'P_Planning_Pointage')
                ->whereRaw("(payload->>'IDP_Planning')::int = ANY(?)", ['{' . implode(',', $planningIdsSample) . '}'])
                ->limit(3)
                ->get()
                ->map(fn ($r) => $this->jsonRow($r->payload));

            $debugDepenses = [
                'familles_chargees' => array_map(fn ($f) => [
                    'id' => $f['id'], 'nom' => $f['nom'], 'constante' => $f['constante'],
                    'par_defaut' => $f['par_defaut'], 'materiel' => $f['materiel'],
                    'nb_lignes' => $f['lignes']->count(), 'sous_total' => $f['sous_total'],
                ], $depenses['familles']),
                'tables_presentes' => [
                    'P_Planning'           => $this->rawRows()->where('table_name', 'P_Planning')->exists(),
                    'P_Planning_Pointage'  => $this->rawRows()->where('table_name', 'P_Planning_Pointage')->exists(),
                    'P_Pointage_Materiel'  => $this->rawRows()->where('table_name', 'P_Pointage_Materiel')->exists(),
                    'p_pointage_materiel_location' => $this->rawRows()->where('table_name', 'p_pointage_materiel_location')->exists(),
                    'P_Ressource_Prix'     => $this->rawRows()->where('table_name', 'P_Ressource_Prix')->exists(),
                    'S_Engin'              => $this->rawRows()->where('table_name', 'S_Engin')->exists(),
                    'S_Personnel'          => $this->rawRows()->where('table_name', 'S_Personnel')->exists(),
                    'S_Famille_Moyen'      => $this->rawRows()->where('table_name', 'S_Famille_Moyen')->exists(),
                    'S_Com_Suivi'          => $this->rawRows()->where('table_name', 'S_Com_Suivi')->exists(),
                    'S_Com_Suivi_Element'  => $this->rawRows()->where('table_name', 'S_Com_Suivi_Element')->exists(),
                ],
                'planning_count_total'      => $planningCount,
                'pointages_count_pour_ces_plannings' => $samplePointages->count(),
                'sample_planning_payload'   => $samplePlannings->first(),
                'sample_planning_keys'      => $samplePlannings->first() ? array_keys($samplePlannings->first()) : [],
                'sample_pointage_payload'   => $samplePointages->first(),
                'sample_pointage_keys'      => $samplePointages->first() ? array_keys($samplePointages->first()) : [],
            ];
        }

        return view('dashboard.projet', compact(
            'projet', 'etatLibelle', 'gestionnaireNom', 'departementNom',
            'taches', 'planningCount',
            'depenses', 'realise', 'heuresPersonnel', 'heuresMateriel',
            'from', 'to', 'debugDepenses'
        ));
    }

    /** Parse une date YYYY-MM-DD venant d'un input type=date, optionnellement fin de journée. */
    private function parsePeriodeDate(?string $val, bool $endOfDay = false): ?CarbonImmutable
    {
        if (!$val) return null;
        try {
            $d = CarbonImmutable::parse($val);
            return $endOfDay ? $d->endOfDay() : $d->startOfDay();
        } catch (\Throwable) {
            return null;
        }
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
