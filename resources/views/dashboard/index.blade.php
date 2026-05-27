@extends('layouts.app')

@section('title', 'Projets')

@section('content')

    {{-- ── Bandeau sync (état de fraîcheur des données) ──────────────────── --}}
    <div class="card" style="margin-bottom:16px;">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;">
            <div>
                <h2 style="margin:0 0 4px;">Sources HFSQL</h2>
                <div class="muted" style="font-size:12px;">
                    @foreach ($syncStatus['tables_locales'] as $name => $t)
                        <span style="display:inline-block;margin-right:14px;">
                            <code>{{ $name }}</code> · {{ number_format($t->rows,0,',',' ') }} lignes
                            <span style="opacity:.6">({{ \Illuminate\Support\Carbon::parse($t->last_sync)->diffForHumans() }})</span>
                        </span>
                    @endforeach
                </div>
                @if ($syncStatus['is_running'])
                    <p style="margin:6px 0 0;"><span class="badge run">sync en cours</span></p>
                @endif
            </div>
            <a href="{{ route('admin.sync') }}" class="btn" style="background:var(--accent);color:#0f172a;padding:6px 14px;border-radius:6px;font-weight:600;font-size:13px;text-decoration:none;">Gérer / Synchroniser</a>
        </div>
    </div>

    {{-- ── KPI portfolio ────────────────────────────────────────────────── --}}
    <div class="grid grid-2" style="margin-bottom:16px;">
        <div class="card kpi">
            <span class="kpi-label">Projets total / actifs</span>
            <span class="kpi-value">{{ number_format($stats['projets_actifs'],0,',',' ') }}<small style="font-size:14px;color:var(--muted);"> / {{ number_format($stats['total_projets'],0,',',' ') }}</small></span>
        </div>
        <div class="card kpi">
            <span class="kpi-label">Total vendu (Σ Somme_V)</span>
            <span class="kpi-value">{{ number_format($stats['total_vendu'],0,',',' ') }} €</span>
        </div>
        <div class="card kpi">
            <span class="kpi-label">Total réalisé (Σ Somme_R)</span>
            <span class="kpi-value">{{ number_format($stats['total_realise'],0,',',' ') }} €</span>
        </div>
        <div class="card kpi">
            <span class="kpi-label">Marge globale</span>
            <span class="kpi-value" style="color:{{ $stats['total_marge'] >= 0 ? 'var(--ok)' : 'var(--err)' }};">
                {{ number_format($stats['total_marge'],0,',',' ') }} €
            </span>
            @if ($stats['nb_derapages'] > 0)
                <span class="muted" style="font-size:12px;">{{ $stats['nb_derapages'] }} projet(s) en dépassement</span>
            @endif
        </div>
    </div>

    {{-- ── Tops portfolio (dérapages + marges) ──────────────────────────── --}}
    <div class="grid grid-2" style="margin-bottom:16px;">
        <div class="card">
            <h2 style="color:var(--err);">🔴 Top 5 dérapages (réalisé &gt; vendu)</h2>
            @if ($derapagesEnriched->isEmpty())
                <p class="muted">Aucun projet en dépassement parmi les projets actifs.</p>
            @else
                <table>
                    <thead><tr><th>N°</th><th>Projet</th><th style="text-align:right;">Dépass.</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($derapagesEnriched as $d)
                            <tr>
                                <td><code>{{ $d['numero'] }}</code></td>
                                <td>{{ \Illuminate\Support\Str::limit($d['nom'], 35) }}</td>
                                <td style="text-align:right;color:var(--err);font-weight:600;">+{{ number_format($d['depassement'],0,',',' ') }} €</td>
                                <td><a href="{{ route('dashboard.projet', $d['id']) }}" style="color:var(--accent);font-size:12px;text-decoration:none;">détail →</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        <div class="card">
            <h2 style="color:var(--ok);">🟢 Top 5 marges</h2>
            @if ($topMargeEnriched->isEmpty())
                <p class="muted">Aucune marge calculable (tâches non synchronisées ou marges nulles).</p>
            @else
                <table>
                    <thead><tr><th>N°</th><th>Projet</th><th style="text-align:right;">Marge</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($topMargeEnriched as $m)
                            <tr>
                                <td><code>{{ $m['numero'] }}</code></td>
                                <td>{{ \Illuminate\Support\Str::limit($m['nom'], 35) }}</td>
                                <td style="text-align:right;color:var(--ok);font-weight:600;">+{{ number_format($m['marge'],0,',',' ') }} €</td>
                                <td><a href="{{ route('dashboard.projet', $m['id']) }}" style="color:var(--accent);font-size:12px;text-decoration:none;">détail →</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>

    {{-- ── Camembert états + histogramme global ─────────────────────────── --}}
    <div class="grid grid-2" style="margin-bottom:16px;">
        <div class="card">
            <h2>Répartition par état</h2>
            <canvas id="chart-etats" style="max-height:240px;"></canvas>
        </div>
        <div class="card">
            <h2>Vendu vs réalisé (top 10 projets actifs par vendu)</h2>
            <canvas id="chart-vendu-realise" style="max-height:240px;"></canvas>
        </div>
    </div>

    {{-- ── Filtres ──────────────────────────────────────────────────────── --}}
    <div class="card" style="margin-bottom:16px;">
        <form method="GET" action="{{ route('dashboard') }}" style="display:flex;gap:12px;align-items:end;flex-wrap:wrap;">
            <div style="flex:1;min-width:200px;">
                <label class="muted" style="font-size:11px;display:block;margin-bottom:4px;">Recherche (nom / n° / description)</label>
                <input type="text" name="q" value="{{ $term }}" placeholder="ex: REGIE, 2024…" style="width:100%;padding:6px 10px;border-radius:6px;border:1px solid var(--border);background:var(--bg);color:var(--text);">
            </div>
            <div>
                <label class="muted" style="font-size:11px;display:block;margin-bottom:4px;">État</label>
                <select name="etat" style="padding:6px 10px;border-radius:6px;border:1px solid var(--border);background:var(--bg);color:var(--text);min-width:220px;">
                    <option value="">— tous les états —</option>
                    @foreach ($etats as $e)
                        @if ($e->etat)
                            <option value="{{ $e->etat }}" {{ $etat === $e->etat ? 'selected' : '' }}>
                                {{ $e->libelle }} ({{ $e->n }})
                            </option>
                        @endif
                    @endforeach
                </select>
            </div>
            <div>
                <label style="font-size:12px;">
                    <input type="checkbox" name="actifs" value="1" {{ $only ? 'checked' : '' }}> Seulement actifs
                </label>
            </div>
            <button type="submit">Filtrer</button>
            <a href="{{ route('dashboard') }}" class="muted" style="text-decoration:none;font-size:12px;">réinitialiser</a>
        </form>
    </div>

    {{-- ── Liste projets enrichie ──────────────────────────────────────── --}}
    <div class="card">
        <h2>Projets ({{ number_format($projets->total(),0,',',' ') }})</h2>
        @if ($projets->isEmpty())
            <p class="muted">Aucun projet ne correspond aux filtres.
                @if ($etat)<br>État sélectionné : <code>{{ $etat }}</code> — essayez de retirer le filtre « Seulement actifs » ou de changer d'état.@endif
            </p>
        @else
            <table>
                <thead>
                    <tr>
                        <th>N°</th><th>Nom</th><th>État</th>
                        <th style="text-align:right;" title="Σ Somme_V des tâches">Vendu</th>
                        <th style="text-align:right;" title="Σ Somme_R des tâches">Réalisé</th>
                        <th style="text-align:right;">Marge</th>
                        <th style="text-align:center;" title="Tâches · Plannings disponibles">Données</th>
                        <th>Début</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($projets as $p)
                        @php
                            $pid = $p->id_projet;
                            $v = (float) ($ventesParProjet[$pid]   ?? 0);
                            $r = (float) ($realisesParProjet[$pid] ?? 0);
                            $marge = $v - $r;
                            $nbT = (int) ($nbTachesParProjet[$pid]    ?? 0);
                            $nbP = (int) ($nbPlanningsParProjet[$pid] ?? 0);
                            $etatLib = $libellesEtats[$p->etat] ?? $p->etat;
                        @endphp
                        <tr>
                            <td><code>{{ $p->numero }}</code></td>
                            <td><strong>{{ \Illuminate\Support\Str::limit($p->nom, 40) }}</strong></td>
                            <td>
                                <span class="badge {{ str_contains($p->etat, 'CHANTIER') ? 'ok' : (str_contains($p->etat, 'PLANIFI') ? 'run' : '') }}">{{ $etatLib ?: '—' }}</span>
                            </td>
                            <td style="text-align:right;">{{ $v > 0 ? number_format($v, 0, ',', ' ') . ' €' : '—' }}</td>
                            <td style="text-align:right;">{{ $r > 0 ? number_format($r, 0, ',', ' ') . ' €' : '—' }}</td>
                            <td style="text-align:right;{{ $marge != 0 ? 'color:' . ($marge >= 0 ? 'var(--ok)' : 'var(--err)') . ';font-weight:600;' : '' }}">
                                {{ ($v > 0 || $r > 0) ? number_format($marge, 0, ',', ' ') . ' €' : '—' }}
                            </td>
                            <td style="text-align:center;font-size:11px;color:var(--muted);">
                                <span title="{{ $nbT }} tâche(s)" style="color:{{ $nbT > 0 ? 'var(--ok)' : 'var(--muted)' }};">📋 {{ $nbT }}</span>
                                <span title="{{ $nbP }} planning(s)" style="color:{{ $nbP > 0 ? 'var(--ok)' : 'var(--muted)' }};margin-left:6px;">🗓 {{ $nbP }}</span>
                            </td>
                            <td class="muted">{{ $p->date_debut?->format('d/m/Y') ?? '—' }}</td>
                            <td><a href="{{ route('dashboard.projet', $pid) }}" style="color:var(--accent);text-decoration:none;font-size:13px;">détail →</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div style="margin-top:12px;">{{ $projets->links() }}</div>
        @endif
    </div>

    <script>
        // ── Camembert répartition par état ──
        const etatLabels = @json($etats->pluck('libelle'));
        const etatData   = @json($etats->pluck('n'));
        if (etatLabels.length > 0) {
            new Chart(document.getElementById('chart-etats'), {
                type: 'doughnut',
                data: { labels: etatLabels, datasets: [{ data: etatData, backgroundColor: ['#38bdf8','#22c55e','#f59e0b','#a855f7','#ef4444','#94a3b8','#fb7185','#14b8a6'] }] },
                options: { plugins: { legend: { position: 'right', labels: { color: '#e2e8f0', font: { size: 11 } } } } },
            });
        }

        // ── Bar chart Vendu vs Réalisé (top 10 par vendu) ──
        const topProjects = @json(
            $ventesParProjet
                ->map(fn ($v, $pid) => [
                    'pid' => $pid,
                    'vendu' => (float) $v,
                    'realise' => (float) ($realisesParProjet[$pid] ?? 0)
                ])
                ->sortByDesc('vendu')
                ->take(10)
                ->values()
        );
        if (topProjects.length > 0) {
            const labels = topProjects.map(p => '#' + p.pid);
            new Chart(document.getElementById('chart-vendu-realise'), {
                type: 'bar',
                data: {
                    labels,
                    datasets: [
                        { label: 'Vendu',   data: topProjects.map(p => p.vendu),   backgroundColor: 'rgba(56,189,248,.6)' },
                        { label: 'Réalisé', data: topProjects.map(p => p.realise), backgroundColor: 'rgba(245,158,11,.6)' },
                    ],
                },
                options: {
                    responsive: true,
                    plugins: { legend: { labels: { color: '#e2e8f0' } } },
                    scales: {
                        x: { ticks: { color: '#94a3b8' }, grid: { color: '#334155' } },
                        y: { ticks: { color: '#94a3b8' }, grid: { color: '#334155' }, beginAtZero: true },
                    },
                },
            });
        }
    </script>
@endsection
