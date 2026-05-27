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
                @if ($syncStatus['errors']->isNotEmpty())
                    <details style="margin-top:6px;">
                        <summary style="cursor:pointer;color:var(--err);font-size:12px;">{{ $syncStatus['errors']->count() }} erreur(s) récente(s)</summary>
                        <ul style="font-size:11px;color:var(--err);padding-left:20px;">
                            @foreach ($syncStatus['errors'] as $e)
                                <li><code>{{ $e->table_name }}</code> : {{ \Illuminate\Support\Str::limit($e->error, 160) }}</li>
                            @endforeach
                        </ul>
                    </details>
                @endif
            </div>
            <a href="{{ route('admin.sync') }}" class="btn" style="background:var(--accent);color:#0f172a;padding:6px 14px;border-radius:6px;font-weight:600;font-size:13px;text-decoration:none;">Gérer / Synchroniser</a>
        </div>
    </div>

    {{-- ── KPI ──────────────────────────────────────────────────────────── --}}
    <div class="grid grid-2" style="margin-bottom:16px;">
        <div class="card kpi"><span class="kpi-label">Projets total</span><span class="kpi-value">{{ number_format($stats['total_projets'],0,',',' ') }}</span></div>
        <div class="card kpi"><span class="kpi-label">Projets actifs</span><span class="kpi-value">{{ number_format($stats['projets_actifs'],0,',',' ') }}</span></div>
        <div class="card kpi"><span class="kpi-label">Heures prévues (actifs)</span><span class="kpi-value">{{ number_format($stats['heures_prevues'],0,',',' ') }}h</span></div>
        <div class="card">
            <h2>Répartition par état</h2>
            <canvas id="chart-etats" style="max-height:180px;"></canvas>
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
                <select name="etat" style="padding:6px 10px;border-radius:6px;border:1px solid var(--border);background:var(--bg);color:var(--text);">
                    <option value="">(tous)</option>
                    @foreach ($etats as $e)
                        @if ($e->etat)
                            <option value="{{ $e->etat }}" {{ $etat === $e->etat ? 'selected' : '' }}>{{ $e->etat }} ({{ $e->n }})</option>
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

    {{-- ── Liste projets ───────────────────────────────────────────────── --}}
    <div class="card">
        <h2>Projets ({{ number_format($projets->total(),0,',',' ') }})</h2>
        @if ($projets->isEmpty())
            <p class="muted">Aucun projet ne correspond aux filtres.</p>
        @else
            <table>
                <thead>
                    <tr>
                        <th>N°</th><th>Nom</th><th>Description</th><th>État</th>
                        <th style="text-align:right;">H. prévues</th><th>Début</th><th>Fin</th><th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($projets as $p)
                        <tr>
                            <td><code>{{ $p->numero }}</code></td>
                            <td><strong>{{ \Illuminate\Support\Str::limit($p->nom, 50) }}</strong></td>
                            <td class="muted">{{ \Illuminate\Support\Str::limit($p->description, 60) }}</td>
                            <td><span class="badge {{ $p->etat === 'EN_COURS' ? 'ok' : ($p->etat === 'A_PLANIFIER' ? 'run' : '') }}">{{ $p->etat ?: '—' }}</span></td>
                            <td style="text-align:right;">{{ number_format($p->heures_prevues, 0, ',', ' ') }}</td>
                            <td class="muted">{{ $p->date_debut?->format('d/m/Y') ?? '—' }}</td>
                            <td class="muted">{{ $p->date_fin?->format('d/m/Y') ?? '—' }}</td>
                            <td><a href="{{ route('dashboard.projet', $p->id_projet) }}" style="color:var(--accent);text-decoration:none;font-size:13px;">détail →</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div style="margin-top:12px;">{{ $projets->links() }}</div>
        @endif
    </div>

    <script>
        const labels = @json($etats->pluck('etat'));
        const data   = @json($etats->pluck('n'));
        if (labels.length > 0) {
            new Chart(document.getElementById('chart-etats'), {
                type: 'doughnut',
                data: { labels, datasets: [{ data, backgroundColor: ['#38bdf8','#22c55e','#f59e0b','#a855f7','#ef4444','#94a3b8','#fb7185','#14b8a6'] }] },
                options: { plugins: { legend: { position: 'right', labels: { color: '#e2e8f0', font: { size: 11 } } } } },
            });
        }
    </script>
@endsection
