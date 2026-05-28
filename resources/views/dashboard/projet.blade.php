@extends('layouts.app')

@section('title', 'Projet ' . $projet->numero)

@section('content')
    <p style="margin:0 0 12px;"><a href="{{ route('dashboard') }}" style="color:var(--muted);text-decoration:none;font-size:13px;">← retour aux projets</a></p>

    {{-- ── Diagnostic dépenses (visible avec ?debug=depenses) ─────────── --}}
    @if (!empty($debugDepenses))
        <div class="card" style="margin-bottom:16px;border:2px dashed var(--warn);">
            <h2 style="color:var(--warn);">🛠 Diagnostic dépenses</h2>
            <p class="muted" style="font-size:12px;">Visible parce que <code>?debug=depenses</code>. Colle-moi ce qui apparaît ici.</p>
            <h3 style="margin-top:12px;font-size:14px;">Tables présentes en BD</h3>
            <pre style="background:var(--panel2);padding:10px;border-radius:6px;font-size:11px;overflow:auto;">{{ json_encode($debugDepenses['tables_presentes'], JSON_PRETTY_PRINT) }}</pre>
            <h3 style="margin-top:12px;font-size:14px;">Familles chargées (S_Famille_Moyen)</h3>
            <pre style="background:var(--panel2);padding:10px;border-radius:6px;font-size:11px;overflow:auto;max-height:300px;">{{ json_encode($debugDepenses['familles_chargees'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
            <h3 style="margin-top:12px;font-size:14px;">Plannings & pointages</h3>
            <p style="font-size:12px;margin:4px 0;">
                Plannings (P_Planning où ID_Origine={{ $projet->id_projet }}) : <strong>{{ $debugDepenses['planning_count_total'] }}</strong><br>
                Pointages liés aux 3 premiers : <strong>{{ $debugDepenses['pointages_count_pour_ces_plannings'] }}</strong>
            </p>
            <h3 style="margin-top:12px;font-size:14px;">Clés payload P_Planning</h3>
            <pre style="background:var(--panel2);padding:10px;border-radius:6px;font-size:11px;overflow:auto;max-height:200px;">{{ json_encode($debugDepenses['sample_planning_keys'], JSON_PRETTY_PRINT) }}</pre>
            <h3 style="margin-top:12px;font-size:14px;">Payload P_Planning (échantillon)</h3>
            <pre style="background:var(--panel2);padding:10px;border-radius:6px;font-size:11px;overflow:auto;max-height:300px;">{{ json_encode($debugDepenses['sample_planning_payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
            <h3 style="margin-top:12px;font-size:14px;">Clés payload P_Planning_Pointage</h3>
            <pre style="background:var(--panel2);padding:10px;border-radius:6px;font-size:11px;overflow:auto;max-height:200px;">{{ json_encode($debugDepenses['sample_pointage_keys'], JSON_PRETTY_PRINT) }}</pre>
            <h3 style="margin-top:12px;font-size:14px;">Payload P_Planning_Pointage (échantillon)</h3>
            <pre style="background:var(--panel2);padding:10px;border-radius:6px;font-size:11px;overflow:auto;max-height:300px;">{{ json_encode($debugDepenses['sample_pointage_payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
        </div>
    @endif

    {{-- ── Entête projet ────────────────────────────────────────────────── --}}
    <div class="card" style="margin-bottom:16px;">
        <div style="display:flex;justify-content:space-between;align-items:start;gap:24px;flex-wrap:wrap;">
            <div style="flex:1;min-width:280px;">
                <h2 style="margin:0 0 6px;">{{ $projet->nom }}</h2>
                <p class="muted" style="margin:0 0 8px;font-size:13px;">
                    N° <code>{{ $projet->numero }}</code>
                    · État :
                    <span class="badge {{ $projet->etat === 'CHANTIER_EN_COURS' || $projet->etat === 'EN_COURS' ? 'ok' : ($projet->etat === 'A_PLANIFIER' ? 'run' : '') }}">
                        {{ $etatLibelle ?: ($projet->etat ?: '—') }}
                    </span>
                </p>
                @if ($projet->description)
                    <p style="margin:8px 0 0;color:var(--text);">{{ $projet->description }}</p>
                @endif
            </div>
            <div style="font-size:13px;min-width:240px;">
                <div class="muted">Début : <strong style="color:var(--text);">{{ $projet->date_debut?->format('d/m/Y') ?? '—' }}</strong></div>
                <div class="muted">Fin : <strong style="color:var(--text);">{{ $projet->date_fin?->format('d/m/Y') ?? '—' }}</strong></div>
                <div class="muted" style="margin-top:6px;">
                    Gestionnaire : <strong style="color:var(--text);">{{ $gestionnaireNom ?: '—' }}</strong>
                </div>
                <div class="muted">
                    Département : <strong style="color:var(--text);">{{ $departementNom ?: '—' }}</strong>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Filtre période ─────────────────────────────────────────────── --}}
    <div class="card" style="margin-bottom:16px;">
        <form method="GET" action="{{ route('dashboard.projet', $projet->id_projet) }}"
              style="display:flex;gap:12px;align-items:end;flex-wrap:wrap;">
            <div>
                <label class="muted" style="font-size:11px;display:block;margin-bottom:4px;">Période — du</label>
                <input type="date" name="from" value="{{ $from?->format('Y-m-d') }}"
                       style="padding:6px 10px;border-radius:6px;border:1px solid var(--border);background:var(--bg);color:var(--text);">
            </div>
            <div>
                <label class="muted" style="font-size:11px;display:block;margin-bottom:4px;">au</label>
                <input type="date" name="to" value="{{ $to?->format('Y-m-d') }}"
                       style="padding:6px 10px;border-radius:6px;border:1px solid var(--border);background:var(--bg);color:var(--text);">
            </div>
            <button type="submit">Filtrer</button>
            <a href="{{ route('dashboard.projet', $projet->id_projet) }}" class="muted" style="text-decoration:none;font-size:12px;">réinitialiser</a>
            <span class="muted" style="margin-left:auto;font-size:12px;">
                @if (!$from && !$to)
                    Période : <strong style="color:var(--text);">depuis le début du projet</strong>
                @else
                    Période : <strong style="color:var(--text);">{{ $from?->format('d/m/Y') ?? '…' }} → {{ $to?->format('d/m/Y') ?? '…' }}</strong>
                @endif
            </span>
        </form>
    </div>

    {{-- ── Synthèse : 3 axes côte à côte (PRÉVU · RÉALISÉ · DÉPENSÉ) ─── --}}
    @php
        // Prévu (depuis S_Tache, options inactives exclues)
        $prevuPV = (float) $taches->sum('Somme_V');
        $prevuPR = (float) $taches->sum('Somme_R');
        $heuresPrevues = (float) $taches->sum('Heures');
        $margePrevue = $prevuPV - $prevuPR;

        // Réalisé (suivis de Type=Vente)
        $realisePV = $realise['pv'];
        $realisePR = $realise['pr'];
        $margeRealisee = $realisePV - $realisePR;
        $avancement = $prevuPV > 0 ? round(($realisePV / $prevuPV) * 100, 1) : null;

        // Dépensé par grande catégorie
        $totalDepense = $depenses['total_general'];
        $depensePersonnel = 0.0; $depenseMateriel = 0.0; $depenseAchats = 0.0;
        foreach ($depenses['familles'] as $f) {
            if ($f['par_defaut']) $depensePersonnel += $f['sous_total'];
            elseif ($f['materiel']) $depenseMateriel += $f['sous_total'];
            else $depenseAchats += $f['sous_total'];
        }

        // Marge réelle (la vraie : ce qu'on a facturé moins ce qui nous a coûté)
        $margeReelle = $realisePV - $totalDepense;
        $margeReellePct = $realisePV > 0 ? round(($margeReelle / $realisePV) * 100, 1) : null;

        $colorize = fn ($v) => $v >= 0 ? 'var(--ok)' : 'var(--err)';
    @endphp

    <div class="grid grid-3" style="margin-bottom:16px;display:grid;grid-template-columns:repeat(3,1fr);gap:12px;">

        {{-- AXE 1 : PRÉVU --}}
        <div class="card" style="border-top:3px solid var(--accent);">
            <h2 style="margin:0 0 4px;font-size:14px;color:var(--accent);">📋 PRÉVU</h2>
            <p class="muted" style="font-size:11px;margin:0 0 12px;">depuis S_Tache (options inactives exclues)</p>
            <table style="width:100%;font-size:13px;">
                <tr><td>Prix de vente</td><td style="text-align:right;font-weight:600;">{{ number_format($prevuPV, 0, ',', ' ') }} €</td></tr>
                <tr><td>Prix de revient</td><td style="text-align:right;font-weight:600;">{{ number_format($prevuPR, 0, ',', ' ') }} €</td></tr>
                <tr><td class="muted">Marge prévue</td><td style="text-align:right;font-weight:600;color:{{ $colorize($margePrevue) }};">{{ number_format($margePrevue, 0, ',', ' ') }} €</td></tr>
                <tr><td class="muted">Heures prévues</td><td style="text-align:right;">{{ number_format($heuresPrevues, 1, ',', ' ') }} h</td></tr>
            </table>
        </div>

        {{-- AXE 2 : RÉALISÉ (suivi vente) --}}
        <div class="card" style="border-top:3px solid var(--ok);">
            <h2 style="margin:0 0 4px;font-size:14px;color:var(--ok);">🧾 RÉALISÉ</h2>
            <p class="muted" style="font-size:11px;margin:0 0 12px;">facturé — S_Com_Suivi (Type=Vente)</p>
            <table style="width:100%;font-size:13px;">
                <tr><td>Prix de vente</td><td style="text-align:right;font-weight:600;">{{ number_format($realisePV, 0, ',', ' ') }} €</td></tr>
                <tr><td>Prix de revient</td><td style="text-align:right;font-weight:600;">{{ number_format($realisePR, 0, ',', ' ') }} €</td></tr>
                <tr><td class="muted">Marge sur facture</td><td style="text-align:right;font-weight:600;color:{{ $colorize($margeRealisee) }};">{{ number_format($margeRealisee, 0, ',', ' ') }} €</td></tr>
                <tr><td class="muted">Avancement vs prévu</td><td style="text-align:right;">{{ $avancement !== null ? $avancement . ' %' : '—' }}</td></tr>
            </table>
        </div>

        {{-- AXE 3 : DÉPENSÉ (4 familles) --}}
        <div class="card" style="border-top:3px solid var(--err);">
            <h2 style="margin:0 0 4px;font-size:14px;color:var(--err);">💸 DÉPENSÉ</h2>
            <p class="muted" style="font-size:11px;margin:0 0 12px;">consommé — pointages + achats par famille</p>
            <table style="width:100%;font-size:13px;">
                <tr><td>Personnel</td><td style="text-align:right;font-weight:600;">{{ number_format($depensePersonnel, 0, ',', ' ') }} €</td></tr>
                <tr><td>Matériel + Location</td><td style="text-align:right;font-weight:600;">{{ number_format($depenseMateriel, 0, ',', ' ') }} €</td></tr>
                <tr><td>Autres familles (achats)</td><td style="text-align:right;font-weight:600;">{{ number_format($depenseAchats, 0, ',', ' ') }} €</td></tr>
                <tr style="border-top:1px solid var(--border);"><td><strong>Total dépensé</strong></td><td style="text-align:right;font-weight:700;color:var(--err);">{{ number_format($totalDepense, 0, ',', ' ') }} €</td></tr>
            </table>
        </div>
    </div>

    {{-- ── Marge réelle (la vraie) ────────────────────────────────────── --}}
    <div class="card" style="margin-bottom:16px;background:linear-gradient(135deg,var(--bg-card),var(--panel2));text-align:center;padding:20px;">
        <div class="muted" style="font-size:13px;">Marge réelle = Réalisé PV − Dépensé total</div>
        <div style="font-size:36px;font-weight:700;color:{{ $colorize($margeReelle) }};margin-top:4px;">
            {{ number_format($margeReelle, 0, ',', ' ') }} €
            @if ($margeReellePct !== null)
                <small style="font-size:18px;font-weight:400;opacity:.8;">({{ $margeReellePct }} %)</small>
            @endif
        </div>
        <div class="muted" style="font-size:12px;margin-top:6px;">
            Heures pointées sur la période : <strong style="color:var(--text);">{{ number_format($heuresPersonnel, 1, ',', ' ') }} h personnel</strong>
            · <strong style="color:var(--text);">{{ number_format($heuresMateriel, 1, ',', ' ') }} h matériel</strong>
            · {{ $planningCount }} planning(s) total au projet
        </div>
    </div>

    {{-- ── Détail 1 : Suivis de vente (= lignes Réalisé) ─────────────── --}}
    <div class="card" style="margin-bottom:16px;">
        <h2 style="border-bottom:2px solid var(--ok);padding-bottom:6px;">🧾 Détail Réalisé — lignes des suivis de vente ({{ $realise['lignes']->count() }})</h2>
        @if ($realise['lignes']->isEmpty())
            <p class="muted">Aucun élément de suivi de vente sur cette période.</p>
        @else
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Famille</th>
                        <th>Description</th>
                        <th style="text-align:right;">Qté</th>
                        <th style="text-align:right;">Somme PV</th>
                        <th style="text-align:right;">Somme PR</th>
                        <th style="text-align:right;">Marge</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($realise['lignes']->sortBy(fn ($l) => $l['date_debut']?->timestamp ?? 0) as $l)
                        @php $mLigne = $l['total_pv'] - $l['total_pr']; @endphp
                        <tr>
                            <td class="muted">{{ $l['date_debut']?->format('d/m/Y') ?? '—' }}</td>
                            <td class="muted" style="font-size:11px;">{{ $l['constante'] ?? '—' }}</td>
                            <td>{{ \Illuminate\Support\Str::limit($l['description'], 70) }}</td>
                            <td style="text-align:right;">{{ number_format($l['qte'], 2, ',', ' ') }}</td>
                            <td style="text-align:right;">{{ number_format($l['total_pv'], 2, ',', ' ') }} €</td>
                            <td style="text-align:right;">{{ number_format($l['total_pr'], 2, ',', ' ') }} €</td>
                            <td style="text-align:right;color:{{ $colorize($mLigne) }};">{{ number_format($mLigne, 2, ',', ' ') }} €</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr style="border-top:2px solid var(--border);">
                        <th colspan="4" style="text-align:right;">TOTAL</th>
                        <th style="text-align:right;">{{ number_format($realisePV, 2, ',', ' ') }} €</th>
                        <th style="text-align:right;">{{ number_format($realisePR, 2, ',', ' ') }} €</th>
                        <th style="text-align:right;color:{{ $colorize($margeRealisee) }};">{{ number_format($margeRealisee, 2, ',', ' ') }} €</th>
                    </tr>
                </tfoot>
            </table>
        @endif
    </div>

    {{-- ── Détail 2 : Pointages personnel (famille ParDefaut) ────────── --}}
    @php $famPersonnel = collect($depenses['familles'])->firstWhere('par_defaut', true); @endphp
    <div class="card" style="margin-bottom:16px;">
        <h2 style="border-bottom:2px solid var(--err);padding-bottom:6px;">
            👷 Détail Personnel — {{ $famPersonnel['nom'] ?? 'Main d\'oeuvre' }}
            ({{ $famPersonnel ? $famPersonnel['lignes']->count() : 0 }} ligne(s))
        </h2>
        @if (!$famPersonnel)
            <p class="muted">Aucune famille avec ParDefaut=true dans S_Famille_Moyen.</p>
        @elseif ($famPersonnel['lignes']->isEmpty())
            <p class="muted">
                Aucun pointage personnel sur cette période.
                <br><span style="font-size:12px;">Tables requises : <code>P_Planning</code> · <code>P_Planning_Pointage</code> · <code>P_Ressource_Prix</code> (TypeRessource='Personnel') · <code>S_Personnel</code></span>
            </p>
        @else
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Personne</th>
                        <th style="text-align:right;">Heures</th>
                        <th style="text-align:right;">Tarif horaire</th>
                        <th style="text-align:right;">Coût</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($famPersonnel['lignes']->sortBy(fn ($l) => $l['date_debut']?->timestamp ?? 0) as $l)
                        <tr>
                            <td class="muted">{{ $l['date_debut']?->format('d/m/Y') ?? '—' }}</td>
                            <td>{{ $l['description'] }}</td>
                            <td style="text-align:right;">{{ number_format($l['qte'], 2, ',', ' ') }} h</td>
                            <td style="text-align:right;{{ $l['prix'] == 0 ? 'color:var(--err);' : '' }}">
                                {{ $l['prix'] > 0 ? number_format($l['prix'], 2, ',', ' ') . ' €' : '— tarif manquant' }}
                            </td>
                            <td style="text-align:right;font-weight:600;">{{ number_format($l['total'], 2, ',', ' ') }} €</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr style="border-top:2px solid var(--border);">
                        <th colspan="2" style="text-align:right;">TOTAL personnel</th>
                        <th style="text-align:right;">{{ number_format($famPersonnel['lignes']->sum('qte'), 2, ',', ' ') }} h</th>
                        <th></th>
                        <th style="text-align:right;">{{ number_format($famPersonnel['sous_total'], 2, ',', ' ') }} €</th>
                    </tr>
                </tfoot>
            </table>
        @endif
    </div>

    {{-- ── Détail 3 : Pointages matériel + location (famille Materiel) ── --}}
    @php $famMateriel = collect($depenses['familles'])->firstWhere('materiel', true); @endphp
    <div class="card" style="margin-bottom:16px;">
        <h2 style="border-bottom:2px solid var(--err);padding-bottom:6px;">
            🚜 Détail Matériel — {{ $famMateriel['nom'] ?? 'Engins' }}
            ({{ $famMateriel ? $famMateriel['lignes']->count() : 0 }} ligne(s))
        </h2>
        @if (!$famMateriel)
            <p class="muted">Aucune famille avec Materiel=true dans S_Famille_Moyen.</p>
        @elseif ($famMateriel['lignes']->isEmpty())
            <p class="muted">
                Aucun pointage matériel ni location sur cette période.
                <br><span style="font-size:12px;">Tables requises : <code>P_Pointage_Materiel</code> · <code>p_pointage_materiel_location</code> · <code>P_Ressource_Prix</code> (TypeRessource='Materiel') · <code>S_Engin</code></span>
            </p>
        @else
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Engin</th>
                        <th style="text-align:right;">Quantité</th>
                        <th style="text-align:right;">Tarif</th>
                        <th style="text-align:right;">Coût</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($famMateriel['lignes']->sortBy(fn ($l) => $l['date_debut']?->timestamp ?? 0) as $l)
                        <tr>
                            <td class="muted">{{ $l['date_debut']?->format('d/m/Y') ?? '—' }}</td>
                            <td>{{ \Illuminate\Support\Str::limit($l['description'], 60) }}</td>
                            <td style="text-align:right;">{{ number_format($l['qte'], 2, ',', ' ') }}</td>
                            <td style="text-align:right;{{ $l['prix'] == 0 ? 'color:var(--err);' : '' }}">
                                {{ $l['prix'] > 0 ? number_format($l['prix'], 2, ',', ' ') . ' €' : '— tarif manquant' }}
                            </td>
                            <td style="text-align:right;font-weight:600;">{{ number_format($l['total'], 2, ',', ' ') }} €</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr style="border-top:2px solid var(--border);">
                        <th colspan="2" style="text-align:right;">TOTAL matériel</th>
                        <th style="text-align:right;">{{ number_format($famMateriel['lignes']->sum('qte'), 2, ',', ' ') }}</th>
                        <th></th>
                        <th style="text-align:right;">{{ number_format($famMateriel['sous_total'], 2, ',', ' ') }} €</th>
                    </tr>
                </tfoot>
            </table>
        @endif
    </div>

    {{-- ── Détail 4 : Imputations par famille d'achat ─────────────────── --}}
    @php $famAchats = collect($depenses['familles'])->filter(fn ($f) => !$f['par_defaut'] && !$f['materiel']); @endphp
    <div class="card" style="margin-bottom:16px;">
        <h2 style="border-bottom:2px solid var(--warn);padding-bottom:6px;">
            🛒 Détail Achats par famille ({{ $famAchats->sum(fn ($f) => $f['lignes']->count()) }} ligne(s) au total)
        </h2>
        @if ($famAchats->isEmpty())
            <p class="muted">Aucune famille d'achat active dans S_Famille_Moyen (hors Personnel/Matériel).</p>
        @else
            @foreach ($famAchats as $f)
                <h3 style="margin-top:18px;font-size:14px;">
                    {{ $f['nom'] ?: 'Famille #' . $f['id'] }}
                    @if ($f['constante'])
                        <code style="font-size:11px;opacity:.7;">{{ $f['constante'] }}</code>
                    @endif
                    <small style="font-weight:400;opacity:.7;">— {{ $f['lignes']->count() }} ligne(s) · sous-total <strong>{{ number_format($f['sous_total'], 2, ',', ' ') }} €</strong></small>
                </h3>
                @if ($f['lignes']->isEmpty())
                    <p class="muted" style="font-size:12px;margin:4px 0 12px;">
                        Aucune ligne — vérifie qu'il existe des S_Com_Suivi (Type='Achat') avec ConstanteFamille=<code>{{ $f['constante'] }}</code> sur ce projet.
                    </p>
                @else
                    <table style="margin-bottom:12px;">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Description</th>
                                <th style="text-align:right;">Qté</th>
                                <th style="text-align:right;">PU</th>
                                <th style="text-align:right;">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($f['lignes']->sortBy(fn ($l) => $l['date_debut']?->timestamp ?? 0) as $l)
                                <tr>
                                    <td class="muted">{{ $l['date_debut']?->format('d/m/Y') ?? '—' }}</td>
                                    <td>{{ \Illuminate\Support\Str::limit($l['description'], 70) }}</td>
                                    <td style="text-align:right;">{{ number_format($l['qte'], 2, ',', ' ') }}</td>
                                    <td style="text-align:right;">{{ number_format($l['prix'], 2, ',', ' ') }} €</td>
                                    <td style="text-align:right;font-weight:600;">{{ number_format($l['total'], 2, ',', ' ') }} €</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            @endforeach
        @endif
    </div>

    {{-- ── Tâches prévues (référence) ─────────────────────────────────── --}}
    <div class="card" style="margin-bottom:16px;">
        <h2 style="border-bottom:2px solid var(--accent);padding-bottom:6px;">
            📋 Tâches prévues ({{ $taches->count() }})
        </h2>
        <p class="muted" style="font-size:12px;margin:6px 0;">
            Σ depuis S_Tache (options inactives <code>TypeElement='OPTION' AND OptionActive=0</code> exclues).
            Les colonnes Somme_V/Somme_R/Heures sont du <strong>prévu</strong>.
        </p>
        @if ($taches->isEmpty())
            <p class="muted">Aucune tâche pour ce projet (ou toutes en option inactive).</p>
        @else
            <table>
                <thead>
                    <tr>
                        <th>N°</th><th>Désignation</th><th>Type</th><th>Unité</th>
                        <th style="text-align:right;">Qté</th>
                        <th style="text-align:right;">PU vente</th>
                        <th style="text-align:right;">Prévu PV</th>
                        <th style="text-align:right;">Prévu PR</th>
                        <th style="text-align:right;">Marge</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($taches as $t)
                        @php
                            $sv = (float) ($t['Somme_V'] ?? 0);
                            $sr = (float) ($t['Somme_R'] ?? 0);
                            $m = $sv - $sr;
                            $type = (string) ($t['TypeElement'] ?? '');
                        @endphp
                        <tr>
                            <td><code>{{ $t['Numero'] ?? '' }}</code></td>
                            <td>{{ \Illuminate\Support\Str::limit($t['Designation'] ?? '', 60) }}</td>
                            <td class="muted">{{ $type ?: '—' }}</td>
                            <td class="muted">{{ $t['Unite'] ?? '' }}</td>
                            <td style="text-align:right;">{{ number_format((float)($t['Quantite'] ?? 0), 2, ',', ' ') }}</td>
                            <td style="text-align:right;">{{ number_format((float)($t['PU_V'] ?? 0), 2, ',', ' ') }}</td>
                            <td style="text-align:right;">{{ number_format($sv, 2, ',', ' ') }}</td>
                            <td style="text-align:right;">{{ number_format($sr, 2, ',', ' ') }}</td>
                            <td style="text-align:right;color:{{ $colorize($m) }};">{{ number_format($m, 2, ',', ' ') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection
