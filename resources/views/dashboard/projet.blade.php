@extends('layouts.app')

@section('title', 'Projet ' . $projet->numero)

@section('content')
    <p style="margin:0 0 12px;"><a href="{{ route('dashboard') }}" style="color:var(--muted);text-decoration:none;font-size:13px;">← retour aux projets</a></p>

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

    {{-- ── Filtre période pour les dépenses ────────────────────────────── --}}
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

    {{-- ── KPI : Prévu (S_Tache) / Réalisé (suivis Type=Vente) / Dépensé (familles) ── --}}
    @php
        $prevuPV = (float) $taches->sum('Somme_V');  // prévu en prix de vente
        $prevuPR = (float) $taches->sum('Somme_R');  // prévu en prix de revient
        $heuresPrevues = (float) $taches->sum('Heures');
        $margePrevue = $prevuPV - $prevuPR;
        $margePrevuePct = $prevuPV > 0 ? round(($margePrevue / $prevuPV) * 100, 1) : null;

        $realisePV = $realise['pv'];
        $realisePR = $realise['pr'];
        $margeRealise = $realisePV - $realisePR;
        $margeRealisePct = $realisePV > 0 ? round(($margeRealise / $realisePV) * 100, 1) : null;

        $totalDepenses = $depenses['total_general'];
        $margeReelle = $realisePV - $totalDepenses;
        $margeReellePct = $realisePV > 0 ? round(($margeReelle / $realisePV) * 100, 1) : null;
        $avancement = $prevuPV > 0 ? round(($realisePV / $prevuPV) * 100, 1) : null;
    @endphp

    {{-- Ligne 1 : Prévu --}}
    <div class="grid grid-2" style="margin-bottom:8px;">
        <div class="card kpi">
            <span class="kpi-label">Prévu — prix de vente</span>
            <span class="kpi-value">{{ number_format($prevuPV, 2, ',', ' ') }} €</span>
            <span class="muted">Σ S_Tache.Somme_V · options inactives exclues</span>
        </div>
        <div class="card kpi">
            <span class="kpi-label">Prévu — prix de revient</span>
            <span class="kpi-value">{{ number_format($prevuPR, 2, ',', ' ') }} €</span>
            <span class="muted">Σ S_Tache.Somme_R · {{ number_format($heuresPrevues, 1, ',', ' ') }} h prévues</span>
        </div>
        <div class="card kpi">
            <span class="kpi-label">Marge prévue</span>
            <span class="kpi-value" style="color: {{ $margePrevue >= 0 ? 'var(--ok)' : 'var(--err)' }};">
                {{ number_format($margePrevue, 2, ',', ' ') }} €
                @if ($margePrevuePct !== null)
                    <small style="font-size:13px;font-weight:400;opacity:.7;">({{ $margePrevuePct }}%)</small>
                @endif
            </span>
        </div>
        <div class="card kpi">
            <span class="kpi-label">Heures pointées (sur période)</span>
            <span class="kpi-value">
                {{ number_format($heuresPersonnel, 1, ',', ' ') }} h
                <small style="font-size:13px;font-weight:400;opacity:.7;">personnel</small>
            </span>
            <span class="muted">
                + {{ number_format($heuresMateriel, 1, ',', ' ') }} h matériel
                · {{ $planningCount }} planning(s)
            </span>
        </div>
    </div>

    {{-- Ligne 2 : Réalisé (suivis Type=Vente) --}}
    <div class="grid grid-2" style="margin-bottom:8px;">
        <div class="card kpi">
            <span class="kpi-label">Réalisé — prix de vente (facturé)</span>
            <span class="kpi-value" style="color:var(--ok);">{{ number_format($realisePV, 2, ',', ' ') }} €</span>
            <span class="muted">
                Σ S_Com_Suivi_Element (Type=Vente)
                @if ($avancement !== null) · avancement {{ $avancement }}% du prévu @endif
            </span>
        </div>
        <div class="card kpi">
            <span class="kpi-label">Réalisé — prix de revient</span>
            <span class="kpi-value">{{ number_format($realisePR, 2, ',', ' ') }} €</span>
            <span class="muted">Σ S_Com_Suivi_Element (Type=Vente) · {{ $realise['lignes']->count() }} ligne(s)</span>
        </div>
        <div class="card kpi">
            <span class="kpi-label">Marge réalisée (théorique)</span>
            <span class="kpi-value" style="color: {{ $margeRealise >= 0 ? 'var(--ok)' : 'var(--err)' }};">
                {{ number_format($margeRealise, 2, ',', ' ') }} €
                @if ($margeRealisePct !== null)
                    <small style="font-size:13px;font-weight:400;opacity:.7;">({{ $margeRealisePct }}%)</small>
                @endif
            </span>
            <span class="muted">vente − revient des suivis</span>
        </div>
        <div class="card kpi" style="background:linear-gradient(135deg,var(--bg),var(--bg-card));">
            <span class="kpi-label">Marge réelle (réalisé PV − dépensé)</span>
            <span class="kpi-value" style="color: {{ $margeReelle >= 0 ? 'var(--ok)' : 'var(--err)' }};">
                {{ number_format($margeReelle, 2, ',', ' ') }} €
                @if ($margeReellePct !== null)
                    <small style="font-size:13px;font-weight:400;opacity:.7;">({{ $margeReellePct }}%)</small>
                @endif
            </span>
            <span class="muted">vrai écart entre facturé et consommé</span>
        </div>
    </div>

    {{-- Ligne 3 : Dépensé total (mis en évidence) --}}
    <div class="card kpi" style="margin-bottom:16px;">
        <span class="kpi-label">Dépensé réel sur la période (Σ familles)</span>
        <span class="kpi-value" style="color:var(--err);font-size:32px;">{{ number_format($totalDepenses, 2, ',', ' ') }} €</span>
        <span class="muted">détail par famille ci-dessous</span>
    </div>

    {{-- ── Récap par famille (toutes les familles actives) ─────────────── --}}
    <div class="card" style="margin-bottom:16px;">
        <h2>Dépenses par famille</h2>
        @if (empty($depenses['familles']))
            <p class="muted">
                Aucune famille active trouvée dans <code>S_Famille_Moyen</code> —
                vérifie que la table est synchronisée et que <code>ActiveDefaut=1</code> pour au moins une ligne.
            </p>
        @else
            <table>
                <thead>
                    <tr>
                        <th>Famille</th>
                        <th>Source</th>
                        <th style="text-align:right;">Nombre de lignes</th>
                        <th style="text-align:right;">Sous-total</th>
                        <th style="text-align:right;">Part</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($depenses['familles'] as $f)
                        @php
                            $part = $totalDepenses > 0 ? round(($f['sous_total'] / $totalDepenses) * 100, 1) : 0;
                            $sourceLib = $f['par_defaut'] ? 'Pointages personnel'
                                       : ($f['materiel']  ? 'Pointages matériel + location'
                                                          : 'Achats (S_Com_Suivi)');
                        @endphp
                        <tr>
                            <td>
                                <strong>{{ $f['nom'] ?: 'Famille #' . $f['id'] }}</strong>
                                @if ($f['constante'])
                                    <code style="font-size:11px;opacity:.7;">{{ $f['constante'] }}</code>
                                @endif
                            </td>
                            <td class="muted" style="font-size:12px;">{{ $sourceLib }}</td>
                            <td style="text-align:right;" class="muted">{{ $f['lignes']->count() }}</td>
                            <td style="text-align:right;font-weight:600;">{{ number_format($f['sous_total'], 2, ',', ' ') }} €</td>
                            <td style="text-align:right;" class="muted">{{ $part }} %</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr style="border-top:2px solid var(--border);">
                        <th colspan="3">TOTAL</th>
                        <th style="text-align:right;color:var(--err);">{{ number_format($totalDepenses, 2, ',', ' ') }} €</th>
                        <th></th>
                    </tr>
                </tfoot>
            </table>
        @endif
    </div>

    {{-- ── Détail par famille (lignes) ─────────────────────────────────── --}}
    @foreach ($depenses['familles'] as $f)
        <div class="card" style="margin-bottom:16px;">
            <h2>
                {{ $f['nom'] ?: 'Famille #' . $f['id'] }}
                <small style="font-weight:400;opacity:.7;font-size:14px;">
                    — {{ $f['lignes']->count() }} ligne{{ $f['lignes']->count() > 1 ? 's' : '' }}
                </small>
            </h2>
            @if ($f['lignes']->isEmpty())
                <p class="muted">
                    Aucune ligne sur cette période.
                    @if ($f['par_defaut'])
                        <br><span style="font-size:12px;">Tables requises : <code>P_Planning</code> · <code>P_Planning_Pointage</code> · <code>P_Ressource_Prix</code> · <code>S_Personnel</code></span>
                    @elseif ($f['materiel'])
                        <br><span style="font-size:12px;">Tables requises : <code>P_Pointage_Materiel</code> · <code>p_pointage_materiel_location</code> · <code>P_Ressource_Prix</code> · <code>S_Engin</code></span>
                    @else
                        <br><span style="font-size:12px;">Tables requises : <code>S_Com_Suivi</code> (Type=Achat, ConstanteFamille={{ $f['constante'] }}) · <code>S_Com_Suivi_Element</code></span>
                    @endif
                </p>
            @else
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Description</th>
                            <th style="text-align:right;">Qté</th>
                            <th style="text-align:right;">Prix unitaire</th>
                            <th style="text-align:right;">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($f['lignes']->sortBy(fn ($l) => $l['date_debut']?->timestamp ?? 0) as $ligne)
                            <tr>
                                <td class="muted">{{ $ligne['date_debut']?->format('d/m/Y') ?? '—' }}</td>
                                <td>{{ \Illuminate\Support\Str::limit($ligne['description'], 80) }}</td>
                                <td style="text-align:right;">{{ number_format($ligne['qte'], 2, ',', ' ') }}</td>
                                <td style="text-align:right;{{ $ligne['prix'] == 0 ? 'color:var(--err);' : '' }}">
                                    {{ $ligne['prix'] > 0 ? number_format($ligne['prix'], 2, ',', ' ') . ' €' : '— tarif manquant' }}
                                </td>
                                <td style="text-align:right;font-weight:600;">{{ number_format($ligne['total'], 2, ',', ' ') }} €</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr style="border-top:2px solid var(--border);">
                            <th colspan="4" style="text-align:right;">Sous-total {{ $f['nom'] }}</th>
                            <th style="text-align:right;">{{ number_format($f['sous_total'], 2, ',', ' ') }} €</th>
                        </tr>
                    </tfoot>
                </table>
            @endif
        </div>
    @endforeach

    {{-- ── Tâches prévues (options inactives exclues) ──────────────────── --}}
    <div class="card" style="margin-bottom:16px;">
        <h2>Tâches prévues ({{ $taches->count() }})</h2>
        <p class="muted" style="font-size:12px;margin-top:0;">
            Σ depuis S_Tache, options inactives (<code>TypeElement='OPTION'</code> AND <code>OptionActive=0</code>) exclues.
            Colonnes Somme_V/Somme_R/Heures = <strong>prévu</strong>, pas réalisé.
        </p>
        @if ($taches->isEmpty())
            <p class="muted">Aucune tâche pour ce projet (ou toutes en option inactive).</p>
        @else
            <table>
                <thead>
                    <tr>
                        <th>N°</th><th>Désignation</th><th>Type</th><th>Unité</th>
                        <th style="text-align:right;">Qté prévue</th>
                        <th style="text-align:right;">PU vente</th>
                        <th style="text-align:right;">Prévu PV</th>
                        <th style="text-align:right;">Prévu PR</th>
                        <th style="text-align:right;">Marge prévue</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($taches as $t)
                        @php
                            $sv = (float) ($t['Somme_V'] ?? 0);
                            $sr = (float) ($t['Somme_R'] ?? 0);
                            $m = $sv - $sr;
                            $type = (string) ($t['TypeElement'] ?? '');
                            $isOption = strtoupper($type) === 'OPTION';
                        @endphp
                        <tr>
                            <td><code>{{ $t['Numero'] ?? '' }}</code></td>
                            <td>{{ \Illuminate\Support\Str::limit($t['Designation'] ?? '', 60) }}</td>
                            <td class="muted">
                                {{ $type ?: '—' }}
                                @if ($isOption)
                                    <span class="badge ok" style="font-size:10px;">option active</span>
                                @endif
                            </td>
                            <td class="muted">{{ $t['Unite'] ?? '' }}</td>
                            <td style="text-align:right;">{{ number_format((float)($t['Quantite'] ?? 0), 2, ',', ' ') }}</td>
                            <td style="text-align:right;">{{ number_format((float)($t['PU_V'] ?? 0), 2, ',', ' ') }}</td>
                            <td style="text-align:right;">{{ number_format($sv, 2, ',', ' ') }}</td>
                            <td style="text-align:right;">{{ number_format($sr, 2, ',', ' ') }}</td>
                            <td style="text-align:right;color:{{ $m >= 0 ? 'var(--ok)' : 'var(--err)' }};">{{ number_format($m, 2, ',', ' ') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection
