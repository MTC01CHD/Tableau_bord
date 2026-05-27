@extends('layouts.app')

@section('title', 'Projet ' . $projet->numero)

@section('content')
    <p style="margin:0 0 12px;"><a href="{{ route('dashboard') }}" style="color:var(--muted);text-decoration:none;font-size:13px;">← retour aux projets</a></p>

    <div class="card" style="margin-bottom:16px;">
        <div style="display:flex;justify-content:space-between;align-items:start;gap:16px;flex-wrap:wrap;">
            <div style="flex:1;min-width:280px;">
                <h2 style="margin:0 0 4px;">{{ $projet->nom }}</h2>
                <p class="muted" style="margin:0 0 8px;">N° <code>{{ $projet->numero }}</code> · <span class="badge {{ $projet->etat === 'EN_COURS' ? 'ok' : ($projet->etat === 'A_PLANIFIER' ? 'run' : '') }}">{{ $projet->etat ?: '—' }}</span></p>
                @if ($projet->description)
                    <p style="margin:0;">{{ $projet->description }}</p>
                @endif
            </div>
            <div style="text-align:right;font-size:13px;">
                <div class="muted">début : <strong style="color:var(--text);">{{ $projet->date_debut?->format('d/m/Y') ?? '—' }}</strong></div>
                <div class="muted">fin : <strong style="color:var(--text);">{{ $projet->date_fin?->format('d/m/Y') ?? '—' }}</strong></div>
                <div class="muted" style="margin-top:6px;">heures prévues : <strong style="color:var(--accent);">{{ number_format($projet->heures_prevues, 0, ',', ' ') }} h</strong></div>
            </div>
        </div>
    </div>

    {{-- ── KPI prévu/dépensé/planning ────────────────────────────────────── --}}
    <div class="grid grid-2" style="margin-bottom:16px;">
        <div class="card kpi">
            <span class="kpi-label">Tâches du projet</span>
            <span class="kpi-value">{{ number_format($taches->count(),0,',',' ') }}</span>
            <span class="muted">depuis S_Tache</span>
        </div>
        <div class="card kpi">
            <span class="kpi-label">Lignes de planning</span>
            <span class="kpi-value">{{ number_format($planningCount,0,',',' ') }}</span>
            <span class="muted">depuis P_Planning</span>
        </div>
        <div class="card kpi">
            <span class="kpi-label">Prévu (Σ Somme_V)</span>
            <span class="kpi-value">{{ number_format($taches->sum('Somme_V'),2,',',' ') }}</span>
            <span class="muted">€ HT (tâches)</span>
        </div>
        <div class="card kpi">
            <span class="kpi-label">Réalisé (Σ Somme_R)</span>
            <span class="kpi-value">{{ number_format($taches->sum('Somme_R'),2,',',' ') }}</span>
            <span class="muted">€ HT (tâches)</span>
        </div>
    </div>

    {{-- ── Tâches ────────────────────────────────────────────────────────── --}}
    <div class="card" style="margin-bottom:16px;">
        <h2>Tâches du projet</h2>
        @if ($taches->isEmpty())
            <p class="muted">Aucune tâche pour ce projet — ou la table <code>S_Tache</code> n'est pas encore synchronisée.</p>
        @else
            <table>
                <thead>
                    <tr>
                        <th>N°</th><th>Désignation</th><th>Référence</th><th>Unité</th>
                        <th style="text-align:right;">Qté</th><th style="text-align:right;">PU vente</th>
                        <th style="text-align:right;">Σ vendu</th><th style="text-align:right;">Σ réalisé</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($taches as $t)
                        <tr>
                            <td><code>{{ $t['Numero'] ?? '' }}</code></td>
                            <td>{{ \Illuminate\Support\Str::limit($t['Designation'] ?? '', 50) }}</td>
                            <td class="muted">{{ \Illuminate\Support\Str::limit($t['Reference1'] ?? '', 30) }}</td>
                            <td class="muted">{{ $t['Unite'] ?? '' }}</td>
                            <td style="text-align:right;">{{ number_format((float)($t['Quantite'] ?? 0), 2, ',', ' ') }}</td>
                            <td style="text-align:right;">{{ number_format((float)($t['PU_V'] ?? 0), 2, ',', ' ') }}</td>
                            <td style="text-align:right;">{{ number_format((float)($t['Somme_V'] ?? 0), 2, ',', ' ') }}</td>
                            <td style="text-align:right;">{{ number_format((float)($t['Somme_R'] ?? 0), 2, ',', ' ') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    {{-- ── Dépensé par famille (S_Com_Suivi_Element) ─────────────────────── --}}
    <div class="card">
        <h2>Dépensé par famille (S_Com_Suivi_Element)</h2>
        @if ($depenseParFamille->isEmpty())
            <p class="muted">
                @if (\Illuminate\Support\Facades\DB::table('hfsql_raw_rows')->where('table_name','S_Com_Suivi_Element')->exists())
                    Aucune dépense enregistrée pour ce projet dans <code>S_Com_Suivi_Element</code>.
                @else
                    La table <code>S_Com_Suivi_Element</code> n'est pas encore synchronisée — la sync est en cours en arrière-plan.
                @endif
            </p>
        @else
            <p class="muted">Affichage brut en attendant le mapping famille (S_Famille_Moyen) :</p>
            <table>
                <thead><tr><th>row_key</th><th>extrait payload</th></tr></thead>
                <tbody>
                    @foreach ($depenseParFamille->take(20) as $r)
                        <tr>
                            <td><code>{{ $r->row_key }}</code></td>
                            <td class="muted" style="font-family:monospace;font-size:11px;">{{ \Illuminate\Support\Str::limit($r->payload, 200) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection
