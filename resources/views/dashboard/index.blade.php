@extends('layouts.app')

@section('title', 'Projets')

@section('content')

    {{-- ── Diagnostic Réalisé/Dépensé : VISIBLE si l'un des deux est entièrement vide ── --}}
    @if (!empty($diagnostic))
        <div class="card" style="margin-bottom:16px;border:2px solid var(--err);">
            <h2 style="color:var(--err);margin:0 0 8px;">🚨 Diagnostic : Réalisé et/ou Dépensé sont à 0 partout</h2>

            <p style="font-size:13px;margin:6px 0;">
                <strong>Σ Réalisé PV portfolio :</strong> {{ number_format($diagnostic['realise_total'], 2, ',', ' ') }} €
                · <strong>Σ Dépensé portfolio :</strong> {{ number_format($diagnostic['depense_total'], 2, ',', ' ') }} €
            </p>

            @if (!empty($diagnostic['pistes']))
                <div style="background:rgba(239,68,68,0.08);padding:10px;border-radius:6px;margin:8px 0;">
                    <strong style="font-size:13px;">Pistes détectées :</strong>
                    <ul style="margin:6px 0 0 18px;font-size:13px;">
                        @foreach ($diagnostic['pistes'] as $p)
                            <li>{{ $p }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <details style="margin-top:10px;">
                <summary style="cursor:pointer;font-size:13px;color:var(--accent);">▶ Détails techniques</summary>

                <h3 style="margin-top:10px;font-size:13px;">S_Com_Suivi</h3>
                <p style="font-size:12px;margin:4px 0;">
                    Lignes totales : <strong>{{ number_format($diagnostic['s_com_suivi_count'], 0, ',', ' ') }}</strong>
                    · Avec IDProjet : <strong>{{ number_format($diagnostic['s_com_suivi_avec_idprojet'] ?? 0, 0, ',', ' ') }}</strong>
                </p>
                @if (!empty($diagnostic['s_com_suivi_types']))
                    <p style="font-size:12px;margin:4px 0;"><strong>Valeurs distinctes de Type :</strong></p>
                    <table style="font-size:11px;margin:4px 0;">
                        <thead><tr><th>Valeur</th><th style="text-align:right;">Occurrences</th></tr></thead>
                        <tbody>
                            @foreach ($diagnostic['s_com_suivi_types'] as $t)
                                <tr><td><code>{{ $t['valeur'] !== null ? $t['valeur'] : 'NULL' }}</code></td><td style="text-align:right;">{{ $t['n'] }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
                @if (!empty($diagnostic['s_com_suivi_keys']))
                    <p style="font-size:11px;margin:4px 0;">Clés payload : <code>{{ implode(', ', $diagnostic['s_com_suivi_keys']) }}</code></p>
                @endif

                <h3 style="margin-top:10px;font-size:13px;">S_Com_Suivi_Element</h3>
                <p style="font-size:12px;margin:4px 0;">Lignes totales : <strong>{{ number_format($diagnostic['s_com_suivi_element_count'], 0, ',', ' ') }}</strong></p>
                @if (!empty($diagnostic['s_com_suivi_element_keys']))
                    <p style="font-size:11px;margin:4px 0;">Clés payload : <code>{{ implode(', ', $diagnostic['s_com_suivi_element_keys']) }}</code></p>
                @endif

                <h3 style="margin-top:10px;font-size:13px;">Tables pointages</h3>
                <table style="font-size:11px;">
                    <thead><tr><th>Table</th><th style="text-align:right;">Lignes</th></tr></thead>
                    <tbody>
                        @foreach ($diagnostic['tables_pointages'] as $name => $n)
                            <tr><td><code>{{ $name }}</code></td><td style="text-align:right;color:{{ $n > 0 ? 'var(--ok)' : 'var(--err)' }};">{{ number_format($n, 0, ',', ' ') }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </details>

            <p style="margin-top:10px;font-size:12px;">
                <a href="{{ route('admin.schema') }}" style="color:var(--accent);">→ Page complète Schema Discovery</a>
            </p>
        </div>
    @endif

    {{-- ── Diagnostic état (visible avec ?debug=etat) ──────────────────── --}}
    @if (!empty($debugEtat))
        <div class="card" style="margin-bottom:16px;border:2px dashed var(--warn);">
            <h2 style="color:var(--warn);">🛠 Diagnostic filtre état</h2>
            <p class="muted" style="font-size:12px;">
                Visible parce que <code>?debug=etat</code>. Copie-moi ce qui apparaît ici
                pour qu'on identifie le vrai nom de la colonne d'état dans ton payload S_Projet.
            </p>

            <h3 style="margin-top:12px;font-size:14px;">Collection <code>$etats</code> (ce qui alimente le dropdown)</h3>
            <pre style="background:var(--panel2);padding:10px;border-radius:6px;font-size:11px;overflow:auto;max-height:200px;">{{ json_encode($debugEtat['etats_collection'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>

            <h3 style="margin-top:12px;font-size:14px;">Clés présentes dans les 3 premiers payloads S_Projet</h3>
            <pre style="background:var(--panel2);padding:10px;border-radius:6px;font-size:11px;overflow:auto;max-height:300px;">{{ json_encode($debugEtat['sample_payloads_keys'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>

            <h3 style="margin-top:12px;font-size:14px;">Échantillon complet (3 projets)</h3>
            <pre style="background:var(--panel2);padding:10px;border-radius:6px;font-size:11px;overflow:auto;max-height:400px;">{{ json_encode($debugEtat['sample_payloads'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
        </div>
    @endif

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
            <span class="kpi-label">Prévu PV (Σ Somme_V)</span>
            <span class="kpi-value">{{ number_format($stats['total_prevu_pv'],0,',',' ') }} €</span>
            <span class="muted">prix de vente prévu — autorisé</span>
        </div>
        <div class="card kpi">
            <span class="kpi-label">Réalisé PV (S_Com_Suivi Vente)</span>
            <span class="kpi-value" style="color:var(--ok);">{{ number_format($stats['total_realise_pv'],0,',',' ') }} €</span>
            <span class="muted">Σ Quantité × PU_V facturés</span>
        </div>
        <div class="card kpi">
            <span class="kpi-label">Marge réalisée (PV − PR)</span>
            <span class="kpi-value" style="color:{{ $stats['total_marge_realise'] >= 0 ? 'var(--ok)' : 'var(--err)' }};">
                {{ number_format($stats['total_marge_realise'],0,',',' ') }} €
            </span>
            @if ($stats['nb_derapages'] > 0)
                <span class="muted" style="font-size:12px;color:var(--err);">{{ $stats['nb_derapages'] }} projet(s) en marge négative</span>
            @endif
        </div>
    </div>

    {{-- ── Tops portfolio (dérapages + marges) ──────────────────────────── --}}
    <div class="grid grid-2" style="margin-bottom:16px;">
        <div class="card">
            <h2 style="color:var(--err);">🔴 Top 5 marges négatives (Réalisé PR &gt; Réalisé PV)</h2>
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
            <h2>Prévu PV vs Réalisé PV (top 10 par prévu)</h2>
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
                <label class="muted" style="font-size:11px;display:block;margin-bottom:4px;">Tri</label>
                <select name="sort" style="padding:6px 10px;border-radius:6px;border:1px solid var(--border);background:var(--bg);color:var(--text);">
                    <option value="nom"        {{ $sort === 'nom'        ? 'selected' : '' }}>Nom (A-Z)</option>
                    <option value="numero"     {{ $sort === 'numero'     ? 'selected' : '' }}>N° projet</option>
                    <option value="date_debut" {{ $sort === 'date_debut' ? 'selected' : '' }}>Date début (récent)</option>
                    <option value="date_fin"   {{ $sort === 'date_fin'   ? 'selected' : '' }}>Date fin (récent)</option>
                </select>
            </div>
            <div style="display:flex;flex-direction:column;gap:4px;">
                <label style="font-size:12px;">
                    <input type="checkbox" name="actifs" value="1" {{ $only ? 'checked' : '' }}> Seulement actifs
                </label>
                <label style="font-size:12px;color:{{ $derapagesOnly ? 'var(--err)' : 'inherit' }};">
                    <input type="checkbox" name="derapages" value="1" {{ $derapagesOnly ? 'checked' : '' }}> 🔴 Seulement dérapages
                </label>
            </div>
            <button type="submit">Filtrer</button>
            <a href="{{ route('dashboard') }}" class="muted" style="text-decoration:none;font-size:12px;">réinitialiser</a>
            <a href="{{ route('dashboard', array_merge(request()->query(), ['export' => 'csv'])) }}"
               style="margin-left:auto;font-size:12px;color:var(--ok);text-decoration:none;border:1px solid var(--ok);padding:5px 10px;border-radius:4px;">
                📥 Export CSV
            </a>
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
                        <th style="text-align:right;" title="Prévu en prix de vente — Σ S_Tache.Somme_V">Prévu PV</th>
                        <th style="text-align:right;" title="Réalisé en prix de vente — Σ S_Com_Suivi_Element (Type=Vente)">Réalisé PV</th>
                        <th style="text-align:right;" title="Dépensé total — Achats + Personnel + Matériel + Location">Dépensé</th>
                        <th style="text-align:right;" title="Marge réelle = Réalisé PV − Dépensé">Marge réelle</th>
                        <th title="% avancement = Réalisé PV / Prévu PV">Avanc.</th>
                        <th style="text-align:center;" title="Tâches · Plannings disponibles">Données</th>
                        <th>Début</th>
                        <th></th>
                    </tr>
                </thead>
                @php
                    $pagePrevuPV = 0; $pageRealisePV = 0; $pageDepense = 0;
                @endphp
                <tbody>
                    @foreach ($projets as $p)
                        @php
                            $pid = $p->id_projet;
                            $pPV  = (float) ($prevuPVParProjet[$pid]    ?? 0);
                            $rPV  = (float) ($realisePVParProjet[$pid]  ?? 0);
                            $dep  = (float) ($depensesParProjet[$pid]   ?? 0);
                            $margeReelle = $rPV - $dep;
                            $pagePrevuPV += $pPV; $pageRealisePV += $rPV; $pageDepense += $dep;
                            $pctAvanc = $pPV > 0 ? min(round(($rPV / $pPV) * 100, 1), 200) : 0;
                            $isMargeNeg = ($rPV > 0 || $dep > 0) && $margeReelle < 0;
                            $nbT = (int) ($nbTachesParProjet[$pid]    ?? 0);
                            $nbP = (int) ($nbPlanningsParProjet[$pid] ?? 0);
                            $etatLib = $libellesEtats[$p->etat] ?? $p->etat;
                        @endphp
                        <tr style="{{ $isMargeNeg ? 'background: rgba(239,68,68,0.06);' : '' }}">
                            <td><code>{{ $p->numero }}</code></td>
                            <td><strong>{{ \Illuminate\Support\Str::limit($p->nom, 40) }}</strong></td>
                            <td>
                                <span class="badge {{ str_contains($p->etat, 'CHANTIER') ? 'ok' : (str_contains($p->etat, 'PLANIFI') ? 'run' : '') }}">{{ $etatLib ?: '—' }}</span>
                            </td>
                            <td style="text-align:right;">{{ $pPV > 0 ? number_format($pPV, 0, ',', ' ') . ' €' : '—' }}</td>
                            <td style="text-align:right;">{{ $rPV > 0 ? number_format($rPV, 0, ',', ' ') . ' €' : '—' }}</td>
                            <td style="text-align:right;color:{{ $dep > 0 ? 'var(--err)' : 'var(--muted)' }};">{{ $dep > 0 ? number_format($dep, 0, ',', ' ') . ' €' : '—' }}</td>
                            <td style="text-align:right;{{ ($rPV > 0 || $dep > 0) ? 'color:' . ($margeReelle >= 0 ? 'var(--ok)' : 'var(--err)') . ';font-weight:600;' : '' }}">
                                {{ ($rPV > 0 || $dep > 0) ? number_format($margeReelle, 0, ',', ' ') . ' €' : '—' }}
                            </td>
                            <td style="min-width:120px;">
                                @if ($pPV > 0)
                                    <div style="display:flex;align-items:center;gap:6px;font-size:11px;">
                                        <div style="flex:1;height:6px;background:var(--panel2);border-radius:3px;overflow:hidden;position:relative;">
                                            <div style="height:100%;width:{{ min($pctAvanc, 100) }}%;background:{{ $pctAvanc > 100 ? 'var(--warn)' : 'var(--accent)' }};"></div>
                                        </div>
                                        <span style="font-family:monospace;{{ $pctAvanc > 100 ? 'color:var(--warn);font-weight:600;' : '' }}">{{ $pctAvanc }}%</span>
                                    </div>
                                @else
                                    <span class="muted" style="font-size:11px;">—</span>
                                @endif
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
                @php $pageMargeReelle = $pageRealisePV - $pageDepense; @endphp
                <tfoot>
                    <tr style="border-top:2px solid var(--accent);background:var(--panel2);">
                        <td colspan="3" style="font-weight:600;">TOTAL page ({{ $projets->count() }} projets)</td>
                        <td style="text-align:right;font-weight:600;">{{ number_format($pagePrevuPV, 0, ',', ' ') }} €</td>
                        <td style="text-align:right;font-weight:600;">{{ number_format($pageRealisePV, 0, ',', ' ') }} €</td>
                        <td style="text-align:right;font-weight:600;color:var(--err);">{{ number_format($pageDepense, 0, ',', ' ') }} €</td>
                        <td style="text-align:right;font-weight:600;color:{{ $pageMargeReelle >= 0 ? 'var(--ok)' : 'var(--err)' }};">
                            {{ number_format($pageMargeReelle, 0, ',', ' ') }} €
                        </td>
                        <td colspan="4" class="muted" style="font-size:11px;">
                            ({{ $projets->total() }} au total)
                        </td>
                    </tr>
                </tfoot>
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
        const topProjects = @json($chartVenduRealise);
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
