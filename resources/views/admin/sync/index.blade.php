@extends('layouts.admin')

@section('title', 'Admin · Historique sync')

@section('admin')
    {{-- ── Bandeau live ───────────────────────────────────────────────── --}}
    <div class="card" style="margin-bottom:16px;">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
            <div>
                <h2 style="margin:0;display:flex;align-items:center;gap:10px;">
                    État sync
                    <span id="live-state" class="badge">…</span>
                </h2>
                <p class="muted" id="live-summary" style="font-size:13px;margin:6px 0 0;">Chargement…</p>
                <p class="muted" id="live-current" style="font-size:12px;margin:4px 0 0;font-family:monospace;"></p>
                <p class="muted" id="live-queue" style="font-size:11px;margin:4px 0 0;font-family:monospace;"></p>
            </div>

            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                {{-- Synchroniser (full) --}}
                <form method="POST" action="{{ route('admin.sync.trigger') }}" style="margin:0;" id="form-start">
                    @csrf
                    <button type="submit" id="btn-start" disabled style="opacity:.4;cursor:not-allowed;" title="Calcul de l'état en cours…">
                        Synchroniser
                    </button>
                </form>
                {{-- Reprendre (--resume) --}}
                <form method="POST" action="{{ route('admin.sync.trigger') }}" style="margin:0;" id="form-resume">
                    @csrf
                    <input type="hidden" name="resume" value="1">
                    <button type="submit" id="btn-resume" disabled style="opacity:.4;cursor:not-allowed;background:var(--panel2);color:var(--text);" title="Calcul de l'état en cours…">
                        Reprendre
                    </button>
                </form>
                {{-- Arrêter --}}
                <form method="POST" action="{{ route('admin.sync.stop') }}" style="margin:0;" id="form-stop"
                      onsubmit="return confirm('Arrêter le sync en cours ?');">
                    @csrf
                    <button type="submit" id="btn-stop" disabled style="opacity:.4;cursor:not-allowed;background:var(--err);color:#fff;" title="Calcul de l'état en cours…">
                        Arrêter
                    </button>
                </form>
            </div>
        </div>
    </div>

    {{-- ── Plage de dates ────────────────────────────────────────────── --}}
    <div class="card" style="margin-bottom:16px;">
        <h2>Plage de dates de synchronisation</h2>
        <p class="muted" style="font-size:13px;margin:0 0 12px;">
            S'applique aux tables qui ont une <strong>colonne date déclarée</strong> (cf
            <a href="{{ route('admin.hfsql.tables') }}">Tables à synchroniser</a>). Les autres tables sont
            toujours rapatriées en intégralité. Laissez les deux champs vides pour revenir au filtre par défaut
            (<code>HFSQL_SINCE_MONTHS={{ env('HFSQL_SINCE_MONTHS', 0) }}</code> derniers mois).
        </p>
        <form method="POST" action="{{ route('admin.sync.dates') }}" style="display:flex;gap:12px;align-items:end;flex-wrap:wrap;">
            @csrf
            <div>
                <label class="muted" style="font-size:11px;display:block;margin-bottom:4px;">Du</label>
                <input type="date" name="date_from" value="{{ $dateFrom }}"
                       style="padding:6px 10px;border-radius:6px;border:1px solid var(--border);background:var(--bg);color:var(--text);">
            </div>
            <div>
                <label class="muted" style="font-size:11px;display:block;margin-bottom:4px;">Au (vide = jusqu'à aujourd'hui)</label>
                <input type="date" name="date_to" value="{{ $dateTo }}"
                       style="padding:6px 10px;border-radius:6px;border:1px solid var(--border);background:var(--bg);color:var(--text);">
            </div>
            <button type="submit">Enregistrer la plage</button>
            <span class="muted" style="font-size:12px;">
                @if ($dateFrom)
                    Actuel : <strong style="color:var(--accent);">{{ $dateFrom }}</strong> → <strong style="color:var(--accent);">{{ $dateTo ?: 'aujourd\'hui' }}</strong>
                @else
                    Aucune plage définie (fallback {{ env('HFSQL_SINCE_MONTHS', 0) }} mois)
                @endif
            </span>
        </form>
    </div>

    {{-- ── État par table (configurées + synchronisées) ─────────────────── --}}
    <div class="card" style="margin-bottom:16px;">
        <h2>Tables (configurées + synchronisées)</h2>
        <p class="muted" style="font-size:12px;margin:0 0 10px;">
            Liste fusionnée des tables sélectionnées dans <a href="{{ route('admin.hfsql.tables') }}">l'admin</a>
            et de celles déjà présentes en base locale. Statut basé sur la dernière exécution de sync.
        </p>
        <table id="totals-table">
            <thead>
                <tr>
                    <th>Table</th>
                    <th>Sélectionnée</th>
                    <th>Col. date</th>
                    <th>Statut dernier sync</th>
                    <th style="text-align:right;">Lignes en base</th>
                    <th>Dernier sync</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($tables as $t)
                    <tr>
                        <td><code>{{ $t['name'] }}</code></td>
                        <td>
                            @if ($t['enabled'])
                                <span class="badge ok">oui</span>
                            @else
                                <span class="badge" style="background:rgba(148,163,184,.15);color:var(--muted);">non</span>
                            @endif
                        </td>
                        <td class="muted" style="font-family:monospace;font-size:11px;">{{ $t['date_column'] ?: '—' }}</td>
                        <td>
                            @if ($t['last_status'])
                                <span class="badge {{ $t['last_status'] === 'ok' ? 'ok' : ($t['last_status'] === 'error' ? 'err' : 'run') }}">{{ $t['last_status'] }}</span>
                                @if ($t['last_error'])
                                    <span class="muted" style="font-size:10px;font-family:monospace;display:block;">{{ \Illuminate\Support\Str::limit($t['last_error'], 80) }}</span>
                                @endif
                            @else
                                <span class="muted" style="font-size:11px;">jamais</span>
                            @endif
                        </td>
                        <td style="text-align:right;">{{ number_format($t['rows_in_db'], 0, ',', ' ') }}</td>
                        <td class="muted" style="font-size:12px;">
                            {{ $t['last_sync'] ? \Illuminate\Support\Carbon::parse($t['last_sync'])->diffForHumans() : '—' }}
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="muted">Aucune table configurée — allez dans <a href="{{ route('admin.hfsql.tables') }}">Tables à synchroniser</a>.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- ── Historique des exécutions ──────────────────────────────────── --}}
    <div class="card">
        <h2>Historique des exécutions</h2>
        @if ($runs->isEmpty())
            <p class="muted">Aucune exécution enregistrée.</p>
        @else
            <table>
                <thead>
                    <tr><th>Table</th><th>Démarrée</th><th>Durée</th><th>Status</th><th style="text-align:right;">Lignes</th><th>Erreur</th></tr>
                </thead>
                <tbody>
                    @foreach ($runs as $r)
                        <tr>
                            <td><code>{{ $r->table_name }}</code></td>
                            <td class="muted">{{ $r->started_at?->format('d/m H:i:s') ?? '—' }}</td>
                            <td class="muted">{{ $r->finished_at && $r->started_at ? $r->started_at->diffInSeconds($r->finished_at) . 's' : '—' }}</td>
                            <td><span class="badge {{ $r->status === 'ok' ? 'ok' : ($r->status === 'error' ? 'err' : 'run') }}">{{ $r->status }}</span></td>
                            <td style="text-align:right;">{{ $r->rows_upserted }} / {{ $r->rows_pulled }}</td>
                            <td class="muted" style="font-size:11px;font-family:monospace;">{{ \Illuminate\Support\Str::limit($r->error, 80) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div style="margin-top:12px;">{{ $runs->links() }}</div>
        @endif
    </div>

    <script>
        const STATUS_URL = '{{ route("admin.sync.status") }}';
        const stateEl = document.getElementById('live-state');
        const summaryEl = document.getElementById('live-summary');
        const currentEl = document.getElementById('live-current');
        const totalsTbody = document.querySelector('#totals-table tbody');

        let prevRunning = null;
        const btnStart  = document.getElementById('btn-start');
        const btnResume = document.getElementById('btn-resume');
        const btnStop   = document.getElementById('btn-stop');

        function setBtn(btn, enabled, title) {
            btn.disabled = !enabled;
            btn.style.opacity = enabled ? '1' : '.4';
            btn.style.cursor  = enabled ? 'pointer' : 'not-allowed';
            btn.title = title || '';
        }

        async function refresh() {
            try {
                const r = await fetch(STATUS_URL, { headers: { 'Accept': 'application/json' } });
                const d = await r.json();

                stateEl.className = 'badge ' + (d.is_running ? 'run' : 'ok');
                stateEl.textContent = d.is_running ? 'sync en cours' : 'inactif';

                summaryEl.textContent =
                    `${d.tables_count} table(s) en base · ${(d.rows_total).toLocaleString('fr-FR')} lignes au total`;

                if (d.is_running && d.current_table) {
                    const started = d.current_started_at ? new Date(d.current_started_at) : null;
                    const ago = started ? Math.round((Date.now() - started.getTime()) / 1000) + 's' : '';
                    currentEl.textContent = `→ en cours : ${d.current_table} (depuis ${ago})`;
                } else {
                    currentEl.textContent = '';
                }

                // Diag queue : permet de voir d'un coup d'oeil si le worker fait son taf
                const queueEl = document.getElementById('live-queue');
                if (queueEl && d.queue_diag) {
                    const q = d.queue_diag;
                    const pendingColor = q.jobs_pending > 0 ? 'var(--warn)' : 'var(--muted)';
                    const failedColor  = q.jobs_failed  > 0 ? 'var(--err)'  : 'var(--muted)';
                    queueEl.innerHTML =
                        `queue : <strong>${q.connection}</strong> · ` +
                        `<span style="color:${pendingColor}">${q.jobs_pending} job(s) en attente</span> · ` +
                        `<span style="color:${failedColor}">${q.jobs_failed} failed</span>` +
                        (q.connection === 'sync' ? ' · <span style="color:var(--err);">⚠ connection sync : les jobs bloquent la requête HTTP, le worker ne sert à rien</span>' : '');
                }

                // Boutons conditionnels
                setBtn(btnStart,  !d.is_running, d.is_running ? 'Sync déjà en cours — arrêtez d\'abord' : 'Synchroniser toutes les tables sélectionnées');
                setBtn(btnResume, !d.is_running && d.has_resumable,
                       d.is_running ? 'Sync en cours' : (d.has_resumable ? 'Reprendre : ne refait que les tables non OK' : 'Rien à reprendre — toutes les tables sont OK'));
                setBtn(btnStop,   d.is_running, d.is_running ? 'Stoppe le process artisan en cours' : 'Aucun sync à arrêter');

                // Si on était "running" et qu'on ne l'est plus → recharge la page
                if (prevRunning === true && d.is_running === false) {
                    setTimeout(() => location.reload(), 1500);
                }
                prevRunning = d.is_running;

                // Mise à jour live du tableau (configurées + synchronisées)
                if (d.tables) {
                    const STATUS_BADGE = {
                        'ok':      '<span class="badge ok">ok</span>',
                        'error':   '<span class="badge err">error</span>',
                        'running': '<span class="badge run">running</span>',
                    };
                    totalsTbody.innerHTML = d.tables.length === 0
                        ? '<tr><td colspan="6" class="muted">Aucune table configurée.</td></tr>'
                        : d.tables.map(t => {
                            const sel = t.enabled
                                ? '<span class="badge ok">oui</span>'
                                : '<span class="badge" style="background:rgba(148,163,184,.15);color:var(--muted);">non</span>';
                            const stat = t.status
                                ? (STATUS_BADGE[t.status] || t.status) +
                                  (t.error ? `<span class="muted" style="font-size:10px;font-family:monospace;display:block;">${t.error}</span>` : '')
                                : '<span class="muted" style="font-size:11px;">jamais</span>';
                            const lastSync = t.last_sync
                                ? new Date(t.last_sync).toLocaleString('fr-FR', { day:'2-digit', month:'2-digit', hour:'2-digit', minute:'2-digit' })
                                : '—';
                            return `<tr>
                                <td><code>${t.name}</code></td>
                                <td>${sel}</td>
                                <td class="muted" style="font-family:monospace;font-size:11px;">${t.date_column || '—'}</td>
                                <td>${stat}</td>
                                <td style="text-align:right;">${Number(t.rows).toLocaleString('fr-FR')}</td>
                                <td class="muted" style="font-size:12px;">${lastSync}</td>
                            </tr>`;
                        }).join('');
                }
            } catch (e) {
                stateEl.className = 'badge err';
                stateEl.textContent = 'erreur réseau';
            }
        }

        refresh();
        setInterval(refresh, 3000);
    </script>
@endsection
