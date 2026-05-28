<?php

namespace App\Services;

use App\Support\HfsqlDate;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
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
     *
     * @return Collection<int, array{pv:float, pr:float}>
     */
    public function realiseParProjet(): Collection
    {
        if (!$this->tableExists('S_Com_Suivi') || !$this->tableExists('S_Com_Suivi_Element')) {
            return collect();
        }

        $tenantId = $this->ctx->requireId();
        $rows = DB::select("
            SELECT
                (sc.payload->>'IDProjet')::int AS pid,
                SUM(COALESCE((sce.payload->>'Somme_V')::numeric, 0)) AS pv,
                SUM(COALESCE((sce.payload->>'Somme_R')::numeric, 0)) AS pr
            FROM hfsql_raw_rows sc
            JOIN hfsql_raw_rows sce
              ON sce.table_name = 'S_Com_Suivi_Element'
             AND sce.tenant_id = sc.tenant_id
             AND (sce.payload->>'IDS_Com_Suivi')::int = (sc.payload->>'IDS_Com_Suivi')::int
            WHERE sc.table_name = 'S_Com_Suivi'
              AND sc.tenant_id = ?
              AND sc.payload->>'Type' = 'Vente'
            GROUP BY pid
        ", [$tenantId]);

        return collect($rows)->mapWithKeys(fn ($r) => [
            (int) $r->pid => ['pv' => (float) $r->pv, 'pr' => (float) $r->pr],
        ]);
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

        $suivis = $this->rows('S_Com_Suivi')
            ->whereRaw("payload->>'Type' = 'Vente'")
            ->whereRaw("(payload->>'IDProjet')::int = ?", [$idProjet])
            ->get()
            ->map(fn ($r) => $this->jsonRow($r->payload))
            ->filter(fn ($s) => $this->dateInRange($s['DateDeDebut'] ?? null, $from, $to))
            ->keyBy(fn ($s) => (int) ($s['IDS_Com_Suivi'] ?? 0));

        if ($suivis->isEmpty()) {
            return ['pv' => 0.0, 'pr' => 0.0, 'lignes' => collect()];
        }

        $idsSuivi = $suivis->keys()->all();
        $elements = $this->rows('S_Com_Suivi_Element')
            ->whereRaw("(payload->>'IDS_Com_Suivi')::int = ANY(?)", [$this->intArray($idsSuivi)])
            ->get()
            ->map(fn ($r) => $this->jsonRow($r->payload));

        $pv = 0.0;
        $pr = 0.0;
        $lignes = $elements->map(function ($e) use ($suivis, &$pv, &$pr) {
            $suivi = $suivis[(int) ($e['IDS_Com_Suivi'] ?? 0)] ?? null;
            if (!$suivi) return null;
            $qte  = (float) ($e['Quantite'] ?? 0);
            $totV = (float) ($e['Somme_V'] ?? 0);
            $totR = (float) ($e['Somme_R'] ?? 0);
            $pv += $totV;
            $pr += $totR;
            return [
                'date_debut'  => HfsqlDate::parse($suivi['DateDeDebut'] ?? null),
                'description' => (string) ($e['Designation'] ?? ''),
                'constante'   => $e['ConstanteFamille'] ?? $suivi['ConstanteFamille'] ?? null,
                'qte'         => $qte,
                'total_pv'    => round($totV, 2),
                'total_pr'    => round($totR, 2),
            ];
        })->filter()->values();

        return ['pv' => round($pv, 2), 'pr' => round($pr, 2), 'lignes' => $lignes];
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
            ->whereRaw("payload->>'Type' = 'Achat'")
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
