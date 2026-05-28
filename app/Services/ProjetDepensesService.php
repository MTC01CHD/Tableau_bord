<?php

namespace App\Services;

use App\Support\HfsqlDate;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Calcule les dépenses d'un projet sur une période, ventilées par famille.
 *
 * La liste des familles vient de S_Famille_Moyen (ActiveDefaut=true) — JAMAIS hard-codée.
 * Chaque famille porte deux flags qui déterminent sa source de données :
 *
 *   ParDefaut = true   → famille personnel (Main d'oeuvre)
 *                         → P_Planning × P_Planning_Pointage × P_Ressource_Prix(TypeRessource='Personnel')
 *   Materiel  = true   → famille engins (matériel + location agrégés)
 *                         → P_Pointage_Materiel + p_pointage_materiel_location × P_Ressource_Prix(TypeRessource='Materiel')
 *   sinon (achat)      → S_Com_Suivi_Element × S_Com_Suivi(Type='Achat')
 *                         filtré par ConstanteFamille (de l'élément, sinon du suivi parent)
 *                         total = S_Com_Suivi_Element.Somme_V (déjà pré-calculé)
 *
 * RÉALISÉ (calculerRealise / realiseParProjet) :
 *   Σ S_Com_Suivi_Element pour suivis Type='Vente'
 *   - Réalisé PV = Σ Somme_V (ce qui a été facturé)
 *   - Réalisé PR = Σ Somme_R (prix de revient des lignes facturées)
 *
 * Règles métier :
 * - Pointage personnel : Duree de la ligne non-original (modif) si elle existe, sinon original
 * - Pointage matériel : Valeur (Modif=0) ou ValeurModif (Modif=1)
 * - Tarif : P_Ressource_Prix.PrixImputation, dernier APartirDu <= date_pointage,
 *   filtré PrixImputation > 0 (Personnel : on garde quand même pour montrer les heures)
 */
class ProjetDepensesService
{
    public function __construct(private TenantContext $ctx) {}

    /**
     * Réalisé agrégé par projet (pour la liste du dashboard).
     * Une seule requête SQL pour tous les projets.
     * Type comparé en case-insensitive pour tolérer Vente/VENTE/vente.
     *
     * @return Collection<int, array{pv:float, pr:float}>
     */
    public function realiseParProjet(): Collection
    {
        if (!$this->tableExists('S_Com_Suivi') || !$this->tableExists('S_Com_Suivi_Element')) {
            return collect();
        }

        $tenantId = $this->ctx->requireId();
        // Cache 5 min : la sync HFSQL tourne toutes les ~15 min, donc fraîcheur OK.
        return Cache::remember("realise_par_projet:{$tenantId}", 300, function () use ($tenantId) {
            return $this->doRealiseParProjet($tenantId);
        });
    }

    private function doRealiseParProjet(int $tenantId): Collection
    {
        // NULLIF(..., '') sécurise les casts ::int et ::numeric contre les strings vides
        // qui plantent Postgres ("invalid input syntax for type integer/numeric").
        $rows = DB::select("
            SELECT
                NULLIF(sc.payload->>'IDProjet', '')::int AS pid,
                SUM(COALESCE(NULLIF(sce.payload->>'Somme_V', '')::numeric, 0)) AS pv,
                SUM(COALESCE(NULLIF(sce.payload->>'Somme_R', '')::numeric, 0)) AS pr
            FROM hfsql_raw_rows sc
            JOIN hfsql_raw_rows sce
              ON sce.table_name = 'S_Com_Suivi_Element'
             AND sce.tenant_id = sc.tenant_id
             AND NULLIF(sce.payload->>'IDS_Com_Suivi', '')::int = NULLIF(sc.payload->>'IDS_Com_Suivi', '')::int
            WHERE sc.table_name = 'S_Com_Suivi'
              AND sc.tenant_id = ?
              AND UPPER(COALESCE(sc.payload->>'TYPE', sc.payload->>'Type')) = 'VENTE'
            GROUP BY pid
        ", [$tenantId]);

        return collect($rows)->mapWithKeys(fn ($r) => [
            (int) $r->pid => ['pv' => (float) $r->pv, 'pr' => (float) $r->pr],
        ]);
    }

    /**
     * Dépensé agrégé par projet (pour la liste).
     *
     * IMPORTANT : version optimisée — on évite les LATERAL JOIN par ligne sur
     * P_Ressource_Prix (trop lents sur JSONB pour 100+ projets). À la place :
     * 1) Achats agrégés par projet (1 SQL léger)
     * 2) Personnel et Matériel calculés en PHP via dictionnaires en mémoire
     *
     * @return Collection<int, float> idProjet => total dépensé
     */
    public function depensesParProjet(): Collection
    {
        $tenantId = $this->ctx->requireId();
        // Cache 5 min : la sync HFSQL tourne toutes les ~15 min, donc fraîcheur OK.
        return Cache::remember("depenses_par_projet:{$tenantId}", 300, function () use ($tenantId) {
            return $this->doDepensesParProjet($tenantId);
        });
    }

    /**
     * Heures prévues + dépensées agrégées par projet (pour la liste).
     * - Prévues = Σ S_Tache.Heures (options inactives exclues)
     * - Dépensées = Σ Duree des pointages effectifs (modif > original)
     *   du personnel attaché aux plannings du projet
     *
     * @return Collection<int, array{prevues:float, depensees:float}>
     */
    public function heuresParProjet(): Collection
    {
        $tenantId = $this->ctx->requireId();
        return Cache::remember("heures_par_projet:{$tenantId}", 300, function () use ($tenantId) {
            return $this->doHeuresParProjet($tenantId);
        });
    }

    private function doHeuresParProjet(int $tenantId): Collection
    {
        $out = [];

        // Prévues : Σ S_Tache.Heures
        // Le filtre options inactives est fait côté PHP pour éviter les
        // problèmes de cast SQL sur OptionActive (peut contenir des valeurs
        // non numériques selon les configurations HFSQL).
        if ($this->tableExists('S_Tache')) {
            $rows = DB::select("
                SELECT
                    NULLIF(payload->>'IDProjet', '')::int AS pid,
                    payload->>'Heures' AS h_raw,
                    payload->>'TypeElement' AS type_elem,
                    payload->>'OptionActive' AS opt_active
                FROM hfsql_raw_rows
                WHERE table_name = 'S_Tache' AND tenant_id = ?
                  AND NULLIF(payload->>'IDProjet', '') IS NOT NULL
            ", [$tenantId]);
            foreach ($rows as $r) {
                $pid = (int) $r->pid;
                // Exclure option inactive (TypeElement=OPTION ET OptionActive=0)
                $isOption = strtoupper((string) $r->type_elem) === 'OPTION';
                $optActiveStr = (string) $r->opt_active;
                $optInactive = $isOption && ($optActiveStr === '0' || $optActiveStr === '' || strtolower($optActiveStr) === 'non' || strtolower($optActiveStr) === 'false');
                if ($optInactive) continue;
                $h = is_numeric($r->h_raw) ? (float) $r->h_raw : (float) str_replace(',', '.', (string) $r->h_raw);
                $out[$pid] = $out[$pid] ?? ['prevues' => 0.0, 'depensees' => 0.0];
                $out[$pid]['prevues'] += $h;
            }
        }

        // Dépensées : Σ Duree effective des pointages personnel par projet
        // Calcul intégral en PHP pour éviter tout cast SQL risqué sur 'original'.
        if ($this->tableExists('P_Planning') && $this->tableExists('P_Planning_Pointage')) {
            // 1. Map IDP_Planning -> ID_Origine (= IDProjet)
            $planningRows = DB::select("
                SELECT
                    NULLIF(payload->>'IDP_Planning','')::int AS idp,
                    NULLIF(payload->>'ID_Origine','')::int AS pid
                FROM hfsql_raw_rows
                WHERE table_name = 'P_Planning' AND tenant_id = ?
                  AND NULLIF(payload->>'IDP_Planning','') IS NOT NULL
            ", [$tenantId]);
            $pidByIdp = [];
            foreach ($planningRows as $r) {
                if ($r->idp) $pidByIdp[(int) $r->idp] = (int) $r->pid;
            }

            // 2. Pointages bruts : Duree par IDP_Planning, en distinguant modif/original
            $pointageRows = DB::select("
                SELECT
                    NULLIF(payload->>'IDP_Planning','')::int AS idp,
                    payload->>'Duree' AS duree_raw,
                    payload->>'original' AS orig_raw
                FROM hfsql_raw_rows
                WHERE table_name = 'P_Planning_Pointage' AND tenant_id = ?
                  AND NULLIF(payload->>'IDP_Planning','') IS NOT NULL
            ", [$tenantId]);

            // Pour chaque IDP_Planning : si modif (original=0) existe, prendre sa Duree, sinon original
            $modifParIdp = []; $originalParIdp = [];
            foreach ($pointageRows as $r) {
                $idp = (int) $r->idp;
                $duree = is_numeric($r->duree_raw) ? (float) $r->duree_raw : (float) str_replace(',', '.', (string) $r->duree_raw);
                $origStr = (string) $r->orig_raw;
                $isOriginal = $origStr === '1' || strtolower($origStr) === 'true' || strtolower($origStr) === 'vrai';
                if ($isOriginal) {
                    $originalParIdp[$idp] = $duree;
                } else {
                    $modifParIdp[$idp] = $duree;
                }
            }

            // 3. Agréger par projet
            foreach ($pidByIdp as $idp => $pid) {
                if (!$pid) continue;
                $duree = $modifParIdp[$idp] ?? $originalParIdp[$idp] ?? 0;
                if ($duree <= 0) continue;
                $out[$pid] = $out[$pid] ?? ['prevues' => 0.0, 'depensees' => 0.0];
                $out[$pid]['depensees'] += $duree;
            }
        }

        return collect($out);
    }

    private function doDepensesParProjet(int $tenantId): Collection
    {
        $totaux = [];

        // 1) Achats : 1 SQL agrégé léger (NULLIF pour sécuriser les casts)
        if ($this->tableExists('S_Com_Suivi') && $this->tableExists('S_Com_Suivi_Element')) {
            $rows = DB::select("
                SELECT
                    NULLIF(sc.payload->>'IDProjet', '')::int AS pid,
                    SUM(COALESCE(NULLIF(sce.payload->>'Somme_V', '')::numeric, 0)) AS s
                FROM hfsql_raw_rows sc
                JOIN hfsql_raw_rows sce
                  ON sce.table_name = 'S_Com_Suivi_Element'
                 AND sce.tenant_id = sc.tenant_id
                 AND NULLIF(sce.payload->>'IDS_Com_Suivi', '')::int = NULLIF(sc.payload->>'IDS_Com_Suivi', '')::int
                WHERE sc.table_name = 'S_Com_Suivi'
                  AND sc.tenant_id = ?
                  AND UPPER(COALESCE(sc.payload->>'TYPE', sc.payload->>'Type')) = 'ACHAT'
                GROUP BY pid
            ", [$tenantId]);
            foreach ($rows as $r) {
                $pid = (int) $r->pid;
                $totaux[$pid] = ($totaux[$pid] ?? 0) + (float) $r->s;
            }
        }

        // 2) Pré-charger les tarifs en mémoire pour éviter les LATERAL JOIN
        $tarifsPersonnel = $this->loadTarifs('Personnel');
        $tarifsMateriel  = $this->loadTarifs('Materiel');

        // 3) Personnel : map IDP_Planning -> Duree effective (modif prioritaire)
        if ($this->tableExists('P_Planning') && $this->tableExists('P_Planning_Pointage')) {
            // Charger tous les plannings : IDP_Planning -> [ID_Origine, ID_Personnel_Base, date]
            $plannings = DB::table('hfsql_raw_rows')
                ->where('tenant_id', $tenantId)->where('table_name', 'P_Planning')
                ->selectRaw("
                    NULLIF(payload->>'IDP_Planning', '')::int AS idp,
                    NULLIF(payload->>'ID_Origine', '')::int AS pid,
                    NULLIF(payload->>'ID_Personnel_Base', '')::int AS ipers,
                    payload->>'DateRDZDebut' AS date
                ")->get();

            // Charger tous les pointages bruts puis appliquer la règle modif > original en PHP
            // (évite le cast SQL sur 'original' qui peut planter selon le format HFSQL)
            $rows = DB::select("
                SELECT
                    NULLIF(payload->>'IDP_Planning', '')::int AS idp,
                    payload->>'Duree' AS duree_raw,
                    payload->>'original' AS orig_raw
                FROM hfsql_raw_rows
                WHERE table_name = 'P_Planning_Pointage' AND tenant_id = ?
                  AND NULLIF(payload->>'IDP_Planning', '') IS NOT NULL
            ", [$tenantId]);
            $modifParIdp = []; $origParIdp = [];
            foreach ($rows as $r) {
                $idp = (int) $r->idp;
                $duree = is_numeric($r->duree_raw) ? (float) $r->duree_raw : (float) str_replace(',', '.', (string) $r->duree_raw);
                $origStr = (string) $r->orig_raw;
                $isOriginal = $origStr === '1' || strtolower($origStr) === 'true' || strtolower($origStr) === 'vrai';
                if ($isOriginal) $origParIdp[$idp] = $duree;
                else $modifParIdp[$idp] = $duree;
            }
            $dureesParIdp = [];
            foreach (array_unique(array_merge(array_keys($modifParIdp), array_keys($origParIdp))) as $idp) {
                $dureesParIdp[$idp] = $modifParIdp[$idp] ?? $origParIdp[$idp] ?? 0;
            }

            foreach ($plannings as $pl) {
                $pid = (int) $pl->pid; $ipers = (int) $pl->ipers; $idp = (int) $pl->idp;
                $duree = $dureesParIdp[$idp] ?? 0;
                if (!$pid || !$ipers || $duree <= 0) continue;
                $date = HfsqlDate::parse($pl->date);
                if (!$date) continue;
                $prix = $this->tarifAt($tarifsPersonnel[$ipers] ?? [], $date);
                if ($prix <= 0) continue;
                $totaux[$pid] = ($totaux[$pid] ?? 0) + ($duree * $prix);
            }
        }

        // 4) Matériel sur planning
        if ($this->tableExists('P_Planning') && $this->tableExists('P_Pointage_Materiel') && isset($plannings)) {
            $planningsById = [];
            foreach ($plannings as $pl) $planningsById[(int) $pl->idp] = $pl;

            // Cast Modif/Valeur côté PHP pour tolérer valeurs non numériques
            $rows = DB::select("
                SELECT
                    NULLIF(payload->>'IDP_Planning', '')::int AS idp,
                    NULLIF(payload->>'ID_Materiel_Base', '')::int AS imat,
                    payload->>'Modif' AS modif_raw,
                    payload->>'Valeur' AS val_raw,
                    payload->>'ValeurModif' AS valmod_raw
                FROM hfsql_raw_rows
                WHERE table_name = 'P_Pointage_Materiel' AND tenant_id = ?
            ", [$tenantId]);

            foreach ($rows as $r) {
                $pl = $planningsById[(int) $r->idp] ?? null;
                if (!$pl) continue;
                $pid = (int) $pl->pid; $imat = (int) $r->imat;
                if (!$pid || !$imat) continue;
                $isModif = (string) $r->modif_raw === '1' || strtolower((string) $r->modif_raw) === 'true';
                $valRaw = $isModif ? (string) $r->valmod_raw : (string) $r->val_raw;
                $qte = is_numeric($valRaw) ? (float) $valRaw : (float) str_replace(',', '.', $valRaw);
                if ($qte <= 0) continue;
                $date = HfsqlDate::parse($pl->date);
                if (!$date) continue;
                $prix = $this->tarifAt($tarifsMateriel[$imat] ?? [], $date);
                if ($prix <= 0) continue;
                $totaux[$pid] = ($totaux[$pid] ?? 0) + ($qte * $prix);
            }
        }

        // 5) Location matériel
        if ($this->tableExists('p_pointage_materiel_location')) {
            $rows = DB::select("
                SELECT
                    NULLIF(payload->>'ID_Origine', '')::int AS pid,
                    NULLIF(payload->>'ID_Materiel', '')::int AS imat,
                    payload->>'Modif' AS modif_raw,
                    payload->>'Duree' AS dur_raw,
                    payload->>'DureeModif' AS durmod_raw,
                    payload->>'DatePointage' AS date
                FROM hfsql_raw_rows
                WHERE table_name = 'p_pointage_materiel_location' AND tenant_id = ?
            ", [$tenantId]);

            foreach ($rows as $r) {
                $pid = (int) $r->pid; $imat = (int) $r->imat;
                if (!$pid || !$imat) continue;
                $isModif = (string) $r->modif_raw === '1' || strtolower((string) $r->modif_raw) === 'true';
                $durRaw = $isModif ? (string) $r->durmod_raw : (string) $r->dur_raw;
                $qte = is_numeric($durRaw) ? (float) $durRaw : (float) str_replace(',', '.', $durRaw);
                if ($qte <= 0) continue;
                $date = HfsqlDate::parse($r->date);
                if (!$date) continue;
                $prix = $this->tarifAt($tarifsMateriel[$imat] ?? [], $date);
                if ($prix <= 0) continue;
                $totaux[$pid] = ($totaux[$pid] ?? 0) + ($qte * $prix);
            }
        }

        return collect($totaux)->map(fn ($v) => round((float) $v, 2));
    }

    /**
     * Réalisé = somme des éléments de S_Com_Suivi de Type='Vente' (ce qui a été facturé).
     *
     * @return array{pv:float, pr:float, lignes:Collection}
     */
    public function calculerRealise(int $idProjet, ?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        if (!$this->tableExists('S_Com_Suivi') || !$this->tableExists('S_Com_Suivi_Element')) {
            return ['pv' => 0.0, 'pr' => 0.0, 'lignes' => collect()];
        }

        // Charger les SUIVIS de vente du projet sur la période
        $suivis = $this->rows('S_Com_Suivi')
            ->whereRaw("UPPER(COALESCE(payload->>'TYPE', payload->>'Type')) = 'VENTE'")
            ->whereRaw("NULLIF(payload->>'IDProjet', '')::int = ?", [$idProjet])
            ->get()
            ->map(fn ($r) => $this->jsonRow($r->payload))
            ->filter(fn ($s) => $this->dateInRange($s['DateDeDebut'] ?? null, $from, $to))
            ->keyBy(fn ($s) => (int) ($s['IDS_Com_Suivi'] ?? 0));

        if ($suivis->isEmpty()) {
            return ['pv' => 0.0, 'pr' => 0.0, 'suivis' => collect()];
        }

        // Récupérer tous les éléments des suivis pour calculer le total par suivi
        $idsSuivi = $suivis->keys()->all();
        $elementsParSuivi = $this->rows('S_Com_Suivi_Element')
            ->whereRaw("NULLIF(payload->>'IDS_Com_Suivi', '')::int = ANY(?)", [$this->intArray($idsSuivi)])
            ->get()
            ->map(fn ($r) => $this->jsonRow($r->payload))
            ->groupBy(fn ($e) => (int) ($e['IDS_Com_Suivi'] ?? 0));

        // Une ligne par SUIVI (pas par élément), avec son total agrégé
        $pv = 0.0;
        $pr = 0.0;
        $listeSuivis = $suivis->map(function ($suivi, $idSuivi) use ($elementsParSuivi, &$pv, &$pr) {
            $elems  = $elementsParSuivi->get($idSuivi, collect());
            $totPV  = (float) $elems->sum(fn ($e) => (float) ($e['Somme_V'] ?? 0));
            $totPR  = (float) $elems->sum(fn ($e) => (float) ($e['Somme_R'] ?? 0));
            $pv += $totPV;
            $pr += $totPR;
            return [
                'id'                => $idSuivi,
                'numero'            => $suivi['numero'] ?? $suivi['Numero'] ?? '',
                'designation'       => (string) ($suivi['Designation'] ?? ''),
                'description'       => (string) ($suivi['Description'] ?? ''),
                'date_debut'        => HfsqlDate::parse($suivi['DateDeDebut'] ?? null),
                'date_fin'          => HfsqlDate::parse($suivi['DateDeFin'] ?? null),
                'constante_famille' => $suivi['ConstanteFamille'] ?? null,
                'date_document'     => HfsqlDate::parse($suivi['Date_Document'] ?? null),
                'nb_elements'       => $elems->count(),
                'total_pv'          => round($totPV, 2),
                'total_pr'          => round($totPR, 2),
            ];
        })->sortByDesc(fn ($s) => $s['date_debut']?->timestamp ?? 0)->values();

        return ['pv' => round($pv, 2), 'pr' => round($pr, 2), 'suivis' => $listeSuivis];
    }

    /**
     * @return array{
     *   familles: array<int,array{
     *     id:int, nom:string, constante:?string, par_defaut:bool, materiel:bool, couleur:?string,
     *     lignes:Collection, sous_total:float
     *   }>,
     *   total_general: float,
     *   periode: array{from:?CarbonImmutable,to:?CarbonImmutable}
     * }
     */
    public function calculer(int $idProjet, ?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        $familles = $this->loadFamillesActives();

        // Lookups partagés chargés une seule fois
        $tarifsPersonnel = $this->loadTarifs('Personnel');
        $tarifsMateriel  = $this->loadTarifs('Materiel');
        $engins          = $this->loadEngins();
        $personnel       = $this->loadPersonnel();

        // Calculs pré-faits une seule fois (réutilisés sur la bonne famille)
        $calcPersonnel  = $this->personnel($idProjet, $from, $to, $tarifsPersonnel, $personnel);
        $calcMateriel   = $this->materielEtLocation($idProjet, $from, $to, $tarifsMateriel, $engins);

        $resultats = [];
        $total = 0.0;
        foreach ($familles as $f) {
            if ($f['par_defaut']) {
                $famille = $calcPersonnel;
            } elseif ($f['materiel']) {
                $famille = $calcMateriel;
            } else {
                $famille = $this->achatsParFamille($idProjet, $from, $to, $f['constante']);
            }
            $resultats[] = $f + $famille;
            $total += $famille['sous_total'];
        }

        return [
            'familles' => $resultats,
            'total_general' => round($total, 2),
            'periode' => ['from' => $from, 'to' => $to],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Familles (S_Famille_Moyen)
    // ─────────────────────────────────────────────────────────────────────────

    /** @return array<int,array{id:int,nom:string,constante:?string,par_defaut:bool,materiel:bool,couleur:?string}> */
    private function loadFamillesActives(): array
    {
        if (!$this->tableExists('S_Famille_Moyen')) return [];

        return $this->rows('S_Famille_Moyen')
            ->get()
            ->map(fn ($r) => $this->jsonRow($r->payload))
            ->filter(fn ($p) => (int) ($p['ActiveDefaut'] ?? 0) === 1)
            ->map(fn ($p) => [
                'id'         => (int) ($p['IDFamille_Moyen'] ?? $p['ID_Famille_Moyen'] ?? $p['IDFamille'] ?? 0),
                'nom'        => (string) ($p['NomFamille'] ?? ''),
                'constante'  => $p['ConstanteFamille'] ?? null,
                'par_defaut' => (int) ($p['ParDefaut'] ?? 0) === 1,
                'materiel'   => (int) ($p['Materiel'] ?? 0) === 1,
                'couleur'    => $p['CouleurFamille'] ?? null,
            ])
            ->sortBy(fn ($f) => $f['nom'])
            ->values()
            ->all();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Famille personnel (Main d'oeuvre, ParDefaut=true)
    // ─────────────────────────────────────────────────────────────────────────
    private function personnel(
        int $idProjet,
        ?CarbonImmutable $from,
        ?CarbonImmutable $to,
        array $tarifsPersonnel,
        array $personnel,
    ): array {
        if (!$this->tableExists('P_Planning') || !$this->tableExists('P_Planning_Pointage')) {
            return $this->emptyFamille();
        }

        $plannings = $this->rows('P_Planning')
            ->whereRaw("(payload->>'ID_Origine')::int = ?", [$idProjet])
            ->get()
            ->map(fn ($r) => $this->jsonRow($r->payload))
            ->filter(fn ($pl) => $this->dateInRange($pl['DateRDZDebut'] ?? null, $from, $to))
            ->keyBy(fn ($pl) => (int) ($pl['IDP_Planning'] ?? 0));

        if ($plannings->isEmpty()) return $this->emptyFamille();

        $planningIds = $plannings->keys()->all();
        $pointages = $this->rows('P_Planning_Pointage')
            ->whereRaw("(payload->>'IDP_Planning')::int = ANY(?)", [$this->intArray($planningIds)])
            ->get()
            ->map(fn ($r) => $this->jsonRow($r->payload));

        // Pour chaque IDP_Planning : modif (original=0) prioritaire, sinon original
        $dureesParPlanning = $pointages
            ->groupBy(fn ($p) => (int) ($p['IDP_Planning'] ?? 0))
            ->map(function ($g) {
                $modif = $g->first(fn ($p) => (int) ($p['original'] ?? 1) === 0);
                $orig  = $g->first(fn ($p) => (int) ($p['original'] ?? 0) === 1);
                $chosen = $modif ?: $orig;
                return $chosen ? (float) ($chosen['Duree'] ?? 0) : 0;
            });

        $lignes = $plannings->map(function ($pl) use ($dureesParPlanning, $tarifsPersonnel, $personnel) {
            $idPlanning = (int) ($pl['IDP_Planning'] ?? 0);
            $idPers     = (int) ($pl['ID_Personnel_Base'] ?? 0);
            $date       = HfsqlDate::parse($pl['DateRDZDebut'] ?? null);
            if (!$idPers || !$date) return null;

            $qte = round($dureesParPlanning[$idPlanning] ?? 0, 2);
            if ($qte <= 0) return null;

            $prix = $this->tarifAt($tarifsPersonnel[$idPers] ?? [], $date);
            // Personnel : on garde même si prix=0 pour voir les heures
            $pers = $personnel[$idPers] ?? null;
            $desc = $pers
                ? trim(($pers['Nom'] ?? '') . ' ' . ($pers['Prenom'] ?? ''))
                : "Personnel #{$idPers}";

            return [
                'date_debut'  => $date,
                'date_fin'    => null,
                'description' => $desc,
                'qte'         => $qte,
                'prix'        => $prix,
                'total'       => round($qte * $prix, 2),
            ];
        })->filter()->values();

        return [
            'lignes'     => $lignes,
            'sous_total' => round($lignes->sum('total'), 2),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Famille matériel (Engins, Materiel=true) = pointage matériel + location
    // ─────────────────────────────────────────────────────────────────────────
    private function materielEtLocation(
        int $idProjet,
        ?CarbonImmutable $from,
        ?CarbonImmutable $to,
        array $tarifsMateriel,
        array $engins,
    ): array {
        $lignes = collect();
        $lignes = $lignes->merge($this->pointageMateriel($idProjet, $from, $to, $tarifsMateriel, $engins));
        $lignes = $lignes->merge($this->locationMateriel($idProjet, $from, $to, $tarifsMateriel, $engins));

        return [
            'lignes'     => $lignes->values(),
            'sous_total' => round($lignes->sum('total'), 2),
        ];
    }

    private function pointageMateriel(
        int $idProjet,
        ?CarbonImmutable $from,
        ?CarbonImmutable $to,
        array $tarifs,
        array $engins,
    ): Collection {
        if (!$this->tableExists('P_Planning') || !$this->tableExists('P_Pointage_Materiel')) {
            return collect();
        }

        $plannings = $this->rows('P_Planning')
            ->whereRaw("(payload->>'ID_Origine')::int = ?", [$idProjet])
            ->get()
            ->map(fn ($r) => $this->jsonRow($r->payload))
            ->filter(fn ($pl) => $this->dateInRange($pl['DateRDZDebut'] ?? null, $from, $to))
            ->keyBy(fn ($pl) => (int) ($pl['IDP_Planning'] ?? 0));

        if ($plannings->isEmpty()) return collect();

        $planningIds = $plannings->keys()->all();
        $pointages = $this->rows('P_Pointage_Materiel')
            ->whereRaw("(payload->>'IDP_Planning')::int = ANY(?)", [$this->intArray($planningIds)])
            ->whereRaw("((payload->>'Valeur')::numeric > 0 OR (payload->>'ValeurModif')::numeric > 0)")
            ->get()
            ->map(fn ($r) => $this->jsonRow($r->payload));

        return $pointages->map(function ($pm) use ($plannings, $tarifs, $engins) {
            $planning = $plannings[(int) ($pm['IDP_Planning'] ?? 0)] ?? null;
            if (!$planning) return null;

            $idMat = (int) ($pm['ID_Materiel_Base'] ?? 0);
            $date  = HfsqlDate::parse($planning['DateRDZDebut'] ?? null);
            if (!$idMat || !$date) return null;

            $val = ((int) ($pm['Modif'] ?? 0)) === 0
                ? (float) ($pm['Valeur'] ?? 0)
                : (float) ($pm['ValeurModif'] ?? 0);
            $qte = round($val, 2);

            $prix = $this->tarifAt($tarifs[$idMat] ?? [], $date);
            if ($prix <= 0) return null;

            $engin = $engins[$idMat] ?? null;
            $desc = $engin
                ? trim(($engin['Numero'] ?? '') . ' - ' . ($engin['Designation'] ?? ''))
                : "Matériel #{$idMat}";

            return [
                'date_debut'  => $date,
                'date_fin'    => null,
                'description' => $desc,
                'qte'         => $qte,
                'prix'        => $prix,
                'total'       => round($qte * $prix, 2),
            ];
        })->filter();
    }

    private function locationMateriel(
        int $idProjet,
        ?CarbonImmutable $from,
        ?CarbonImmutable $to,
        array $tarifs,
        array $engins,
    ): Collection {
        if (!$this->tableExists('p_pointage_materiel_location')) return collect();

        return $this->rows('p_pointage_materiel_location')
            ->whereRaw("(payload->>'ID_Origine')::int = ?", [$idProjet])
            ->get()
            ->map(fn ($r) => $this->jsonRow($r->payload))
            ->filter(fn ($p) => $this->dateInRange($p['DatePointage'] ?? null, $from, $to))
            ->map(function ($p) use ($tarifs, $engins) {
                $idMat = (int) ($p['ID_Materiel'] ?? 0);
                $date  = HfsqlDate::parse($p['DatePointage'] ?? null);
                if (!$idMat || !$date) return null;

                $duree = ((int) ($p['Modif'] ?? 0)) === 0
                    ? (float) ($p['Duree'] ?? 0)
                    : (float) ($p['DureeModif'] ?? 0);
                $qte = round($duree, 2);

                $prix = $this->tarifAt($tarifs[$idMat] ?? [], $date);
                if ($prix <= 0) return null;

                $engin = $engins[$idMat] ?? null;
                $desc = $engin
                    ? trim(($engin['Numero'] ?? '') . ' - ' . ($engin['Designation'] ?? '')) . ' (location)'
                    : "Matériel #{$idMat} (location)";

                return [
                    'date_debut'  => $date,
                    'date_fin'    => null,
                    'description' => $desc,
                    'qte'         => $qte,
                    'prix'        => $prix,
                    'total'       => round($qte * $prix, 2),
                ];
            })->filter();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Familles d'achat : S_Com_Suivi_Element filtré par ConstanteFamille
    // ─────────────────────────────────────────────────────────────────────────
    private function achatsParFamille(
        int $idProjet,
        ?CarbonImmutable $from,
        ?CarbonImmutable $to,
        ?string $constanteFamille,
    ): array {
        if (!$constanteFamille
            || !$this->tableExists('S_Com_Suivi')
            || !$this->tableExists('S_Com_Suivi_Element')
        ) {
            return $this->emptyFamille();
        }

        $suivis = $this->rows('S_Com_Suivi')
            ->whereRaw("UPPER(COALESCE(payload->>'TYPE', payload->>'Type')) = 'ACHAT'")
            ->whereRaw("(payload->>'IDProjet')::int = ?", [$idProjet])
            ->get()
            ->map(fn ($r) => $this->jsonRow($r->payload))
            ->filter(fn ($s) => $this->dateInRange($s['DateDeDebut'] ?? null, $from, $to))
            ->keyBy(fn ($s) => (int) ($s['IDS_Com_Suivi'] ?? 0));

        if ($suivis->isEmpty()) return $this->emptyFamille();

        $idsSuivi = $suivis->keys()->all();
        $elements = $this->rows('S_Com_Suivi_Element')
            ->whereRaw("(payload->>'IDS_Com_Suivi')::int = ANY(?)", [$this->intArray($idsSuivi)])
            ->get()
            ->map(fn ($r) => $this->jsonRow($r->payload));

        $lignes = $elements
            ->map(function ($e) use ($suivis, $constanteFamille) {
                $suivi = $suivis[(int) ($e['IDS_Com_Suivi'] ?? 0)] ?? null;
                if (!$suivi) return null;

                // Famille de l'élément, fallback sur celle du suivi parent
                $cstElem = $e['ConstanteFamille'] ?? null;
                $cstSuivi = $suivi['ConstanteFamille'] ?? null;
                $cstEffective = $cstElem !== null && $cstElem !== '' ? $cstElem : $cstSuivi;
                if ($cstEffective !== $constanteFamille) return null;

                $qte   = (float) ($e['Quantite'] ?? 0);
                $total = (float) ($e['Somme_V'] ?? 0);
                $prix  = $qte > 0 ? $total / $qte : 0.0;
                return [
                    'date_debut'  => HfsqlDate::parse($suivi['DateDeDebut'] ?? null),
                    'date_fin'    => HfsqlDate::parse($suivi['DateDeFin'] ?? null),
                    'description' => (string) ($e['Designation'] ?? ''),
                    'qte'         => $qte,
                    'prix'        => round($prix, 2),
                    'total'       => round($total, 2),
                ];
            })
            ->filter()
            ->values();

        return [
            'lignes'     => $lignes,
            'sous_total' => round($lignes->sum('total'), 2),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Lookups partagés
    // ─────────────────────────────────────────────────────────────────────────

    /** @return array<int, array<int, array{date:?CarbonImmutable, prix:float}>> */
    private function loadTarifs(string $typeRessource): array
    {
        if (!$this->tableExists('P_Ressource_Prix')) return [];

        $rows = $this->rows('P_Ressource_Prix')
            ->whereRaw("payload->>'TypeRessource' = ?", [$typeRessource])
            ->get()
            ->map(fn ($r) => $this->jsonRow($r->payload));

        $byId = [];
        foreach ($rows as $r) {
            $id = (int) ($r['IDRessource'] ?? 0);
            if (!$id) continue;
            $byId[$id][] = [
                'date' => HfsqlDate::parse($r['APartirDu'] ?? null),
                'prix' => (float) ($r['PrixImputation'] ?? 0),
            ];
        }
        foreach ($byId as $id => $list) {
            usort($byId[$id], function ($a, $b) {
                $ta = $a['date']?->timestamp ?? 0;
                $tb = $b['date']?->timestamp ?? 0;
                return $ta <=> $tb;
            });
        }
        return $byId;
    }

    private function tarifAt(array $historique, CarbonImmutable $date): float
    {
        $prix = 0.0;
        foreach ($historique as $entry) {
            if ($entry['date'] === null || $entry['date']->lte($date)) {
                $prix = $entry['prix'];
            } else {
                break;
            }
        }
        return $prix;
    }

    /** @return array<int, array{Numero:?string,Designation:?string}> */
    private function loadEngins(): array
    {
        if (!$this->tableExists('S_Engin')) return [];
        return $this->rows('S_Engin')
            ->get()
            ->mapWithKeys(function ($r) {
                $p = $this->jsonRow($r->payload);
                $id = (int) ($p['ID_Engin'] ?? 0);
                return $id ? [$id => ['Numero' => $p['Numero'] ?? null, 'Designation' => $p['Designation'] ?? null]] : [];
            })
            ->all();
    }

    /** @return array<int, array{Nom:?string,Prenom:?string}> */
    private function loadPersonnel(): array
    {
        if (!$this->tableExists('S_Personnel')) return [];
        return $this->rows('S_Personnel')
            ->get()
            ->mapWithKeys(function ($r) {
                $p = $this->jsonRow($r->payload);
                $id = (int) ($p['IDPersonnel'] ?? 0);
                return $id ? [$id => ['Nom' => $p['Nom'] ?? null, 'Prenom' => $p['Prenom'] ?? null]] : [];
            })
            ->all();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────
    private function rows(string $table)
    {
        return DB::table('hfsql_raw_rows')
            ->where('tenant_id', $this->ctx->requireId())
            ->where('table_name', $table);
    }

    private function tableExists(string $table): bool
    {
        return DB::table('hfsql_raw_rows')
            ->where('tenant_id', $this->ctx->requireId())
            ->where('table_name', $table)
            ->exists();
    }

    private function jsonRow($payload): array
    {
        return is_string($payload) ? (array) json_decode($payload, true) : (array) $payload;
    }

    private function dateInRange(?string $hfsqlDate, ?CarbonImmutable $from, ?CarbonImmutable $to): bool
    {
        if (!$from && !$to) return true;
        $d = HfsqlDate::parse($hfsqlDate);
        if (!$d) return false;
        if ($from && $d->lt($from)) return false;
        if ($to && $d->gt($to)) return false;
        return true;
    }

    private function intArray(array $ids): string
    {
        return '{' . implode(',', array_map('intval', $ids)) . '}';
    }

    private function emptyFamille(): array
    {
        return ['lignes' => collect(), 'sous_total' => 0.0];
    }
}
