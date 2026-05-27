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

    {{-- ── KPI principaux ──────────────────────────────────────────────── --}}
    @php
        $sommeV = (float) $taches->sum('Somme_V');
        $sommeR = (float) $taches->sum('Somme_R');
        $marge  = $sommeV - $sommeR;
        $margePct = $sommeV > 0 ? round(($marge / $sommeV) * 100, 1) : null;
    @endphp
    <div class="grid grid-2" style="margin-bottom:16px;">
        <div class="card kpi">
            <span class="kpi-label">Vendu (Σ tâches)</span>
            <span class="kpi-value">{{ number_format($sommeV, 2, ',', ' ') }} €</span>
        </div>
        <div class="card kpi">
            <span class="kpi-label">Réalisé (Σ tâches)</span>
            <span class="kpi-value">{{ number_format($sommeR, 2, ',', ' ') }} €</span>
        </div>
        <div class="card kpi">
            <span class="kpi-label">Marge</span>
            <span class="kpi-value" style="color: {{ $marge >= 0 ? 'var(--ok)' : 'var(--err)' }};">
                {{ number_format($marge, 2, ',', ' ') }} €
                @if ($margePct !== null)
                    <small style="font-size:13px;font-weight:400;opacity:.7;">({{ $margePct }}%)</small>
                @endif
            </span>
        </div>
        <div class="card kpi">
            <span class="kpi-label">Heures pointées effectives</span>
            <span class="kpi-value">{{ number_format($heuresEffectives, 1, ',', ' ') }} h</span>
            <span class="muted">{{ $planningCount }} planning(s) · règle original/modifié appliquée</span>
        </div>
    </div>

    {{-- ── KPI dépenses estimées (heures × tarif P_Ressource_Prix) ────── --}}
    <div class="grid grid-2" style="margin-bottom:16px;">
        <div class="card kpi">
            <span class="kpi-label">Coût MO estimé</span>
            <span class="kpi-value" style="color:var(--warn);">{{ number_format($coutMO, 2, ',', ' ') }} €</span>
            <span class="muted">Σ heures personnel × tarif (P_Ressource_Prix)</span>
        </div>
        <div class="card kpi">
            <span class="kpi-label">Coût matériel estimé</span>
            <span class="kpi-value" style="color:var(--warn);">{{ number_format($coutMateriel, 2, ',', ' ') }} €</span>
            <span class="muted">
                Σ heures matériel × tarif
                @if ($matTable)
                    · source : <code>{{ $matTable }}</code>
                @else
                    · <span style="color:var(--err);">aucune table de pointage matériel sync</span>
                @endif
            </span>
        </div>
        <div class="card kpi" style="grid-column:1/-1;">
            <span class="kpi-label">Coût total dépensé estimé (MO + matériel)</span>
            <span class="kpi-value" style="color:var(--err);font-size:32px;">{{ number_format($coutTotal, 2, ',', ' ') }} €</span>
            @php
                $resteAFaire = $sommeV - $coutTotal;
            @endphp
            <span class="muted">
                Marge théorique vs vendu :
                <strong style="color:{{ $resteAFaire >= 0 ? 'var(--ok)' : 'var(--err)' }};">
                    {{ number_format($resteAFaire, 2, ',', ' ') }} €
                </strong>
            </span>
        </div>
    </div>

    {{-- ── Tâches ──────────────────────────────────────────────────────── --}}
    <div class="card" style="margin-bottom:16px;">
        <h2>Tâches du projet ({{ $taches->count() }})</h2>
        @if ($taches->isEmpty())
            <p class="muted">Aucune tâche pour ce projet, ou la table <code>S_Tache</code> n'est pas encore synchronisée.</p>
        @else
            <table>
                <thead>
                    <tr>
                        <th>N°</th><th>Désignation</th><th>Référence</th><th>Unité</th>
                        <th style="text-align:right;">Qté</th>
                        <th style="text-align:right;">PU vente</th>
                        <th style="text-align:right;">Σ vendu</th>
                        <th style="text-align:right;">Σ réalisé</th>
                        <th style="text-align:right;">Marge</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($taches as $t)
                        @php
                            $sv = (float) ($t['Somme_V'] ?? 0);
                            $sr = (float) ($t['Somme_R'] ?? 0);
                            $m = $sv - $sr;
                        @endphp
                        <tr>
                            <td><code>{{ $t['Numero'] ?? '' }}</code></td>
                            <td>{{ \Illuminate\Support\Str::limit($t['Designation'] ?? '', 60) }}</td>
                            <td class="muted">{{ \Illuminate\Support\Str::limit($t['Reference1'] ?? '', 30) }}</td>
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

    {{-- ── Top contributeurs MO (personnel) ────────────────────────────── --}}
    <div class="card" style="margin-bottom:16px;">
        <h2>Personnel — heures × tarif (Top 10)</h2>
        @if ($topContributeurs->isEmpty())
            <p class="muted">
                Aucun pointage trouvé pour ce projet, ou les tables <code>P_Planning_Pointage</code> / <code>S_Personnel</code>
                ne sont pas encore synchronisées.
            </p>
        @else
            <table>
                <thead>
                    <tr>
                        <th>Personne</th>
                        <th style="text-align:right;">Heures</th>
                        <th style="text-align:right;">Tarif horaire</th>
                        <th style="text-align:right;">Coût MO</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($topContributeurs as $c)
                        <tr>
                            <td>{{ $c['nom'] }}</td>
                            <td style="text-align:right;">{{ number_format($c['heures'], 2, ',', ' ') }} h</td>
                            <td style="text-align:right;{{ $c['tarif'] == 0 ? 'color:var(--err);' : '' }}">
                                {{ $c['tarif'] > 0 ? number_format($c['tarif'], 2, ',', ' ') . ' €' : '— tarif manquant' }}
                            </td>
                            <td style="text-align:right;font-weight:600;">{{ number_format($c['cout'], 2, ',', ' ') }} €</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    {{-- ── Top matériel (location/utilisation) ─────────────────────────── --}}
    <div class="card" style="margin-bottom:16px;">
        <h2>Matériel — heures × tarif (Top 10)</h2>
        @if ($topMateriel->isEmpty())
            <p class="muted">
                @if (!$matTable)
                    Aucune table de pointage matériel synchronisée. Ajoutez
                    <code>p_pointage_materiel_location</code> ou <code>P_Pointage_Materiel</code>
                    dans <a href="{{ route('admin.hfsql.tables') }}">Admin → Tables à synchroniser</a>.
                @else
                    Aucun pointage matériel trouvé pour ce projet dans <code>{{ $matTable }}</code>.
                @endif
            </p>
        @else
            <table>
                <thead>
                    <tr>
                        <th>Matériel</th>
                        <th style="text-align:right;">Heures</th>
                        <th style="text-align:right;">Tarif horaire</th>
                        <th style="text-align:right;">Coût matériel</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($topMateriel as $m)
                        <tr>
                            <td>{{ $m['nom'] }}</td>
                            <td style="text-align:right;">{{ number_format($m['heures'], 2, ',', ' ') }} h</td>
                            <td style="text-align:right;{{ $m['tarif'] == 0 ? 'color:var(--err);' : '' }}">
                                {{ $m['tarif'] > 0 ? number_format($m['tarif'], 2, ',', ' ') . ' €' : '— tarif manquant' }}
                            </td>
                            <td style="text-align:right;font-weight:600;">{{ number_format($m['cout'], 2, ',', ' ') }} €</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    {{-- ── Dépensé par famille (S_Com_Suivi_Element) — placeholder ────── --}}
    <div class="card" style="margin-bottom:16px;">
        <h2>Dépensé par famille (S_Com_Suivi_Element)</h2>
        @if ($depenseParFamille->isEmpty())
            <p class="muted">
                @if (\Illuminate\Support\Facades\DB::table('hfsql_raw_rows')->where('table_name','S_Com_Suivi_Element')->exists())
                    Aucune dépense enregistrée pour ce projet dans <code>S_Com_Suivi_Element</code>.
                @else
                    Table <code>S_Com_Suivi_Element</code> non encore synchronisée — à activer dans <a href="{{ route('admin.hfsql.tables') }}">Admin → Tables à synchroniser</a>.
                @endif
            </p>
        @else
            <p class="muted" style="font-size:12px;">{{ $depenseParFamille->count() }} ligne(s) brute(s) — mapping vente/achat × famille à finaliser dès que la structure réelle est identifiée.</p>
        @endif
    </div>
@endsection
