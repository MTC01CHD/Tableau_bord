@extends('layouts.admin')

@section('title', 'Admin · Tables à synchroniser')

@section('admin')
    <div class="card">
        <h2 style="margin:0 0 8px;">Tables HFSQL à synchroniser</h2>
        <p class="muted" style="font-size:13px;margin:0 0 14px;">
            Cochez les tables que vous voulez rapatrier dans la base locale. Vous pouvez aussi déclarer une
            <em>colonne date</em> par table (ex: <code>Modification_date</code>) — l'agent ne renverra alors
            que les lignes modifiées dans les N derniers mois (cf .env <code>HFSQL_SINCE_MONTHS</code>).
        </p>

        @if (!empty($remoteError))
            <div class="alert err" style="margin-bottom:14px;">
                <strong>Erreur en interrogeant l'agent HFSQL :</strong><br>
                <code style="font-size:11px;">{{ \Illuminate\Support\Str::limit($remoteError, 250) }}</code><br>
                <span style="font-size:12px;">Vérifiez que l'agent Python tourne et que ngrok est en ligne, puis
                    <a href="{{ url()->current() }}">rafraîchissez</a>. Si la liste ci-dessous est vide, vous pouvez
                    quand même éditer vos sélections (les tables déjà connues localement restent).</span>
            </div>
        @endif
        @if (!$remote || $remote->isEmpty())
            <div class="alert err">L'agent HFSQL ne renvoie aucune table en ce moment. Vérifiez la configuration dans
                <a href="{{ route('admin.hfsql.edit') }}">Connexion HFSQL</a>, puis revenez ici.</div>
        @else
            <form method="POST" action="{{ route('admin.hfsql.tables.save') }}">
                @csrf

                <div style="margin-bottom:10px;display:flex;gap:10px;align-items:center;">
                    <input type="text" id="filter" placeholder="filtrer par nom…"
                           style="padding:6px 10px;border-radius:6px;border:1px solid var(--border);background:var(--bg);color:var(--text);flex:1;max-width:300px;">
                    <span class="muted" style="font-size:12px;">
                        {{ $remote->count() }} tables disponibles · {{ $local->where('enabled', true)->count() }} sélectionnées
                    </span>
                    <span style="flex:1;"></span>
                    <button type="button" id="apply-suggestions" style="background:var(--accent);color:#0f172a;font-size:12px;">⚡ Appliquer les suggestions vides</button>
                    <button type="button" id="select-current" style="background:var(--panel2);color:var(--text);font-size:12px;">tout cocher (visible)</button>
                    <button type="button" id="unselect-all" style="background:var(--panel2);color:var(--text);font-size:12px;">tout décocher</button>
                </div>

                <p class="muted" style="font-size:12px;margin:0 0 10px;">
                    💡 La colonne « Date suggérée » est devinée à partir des données déjà synchronisées (ou par défaut <code>DateHeureModification</code>).
                    Vous pouvez l'éditer table par table, ou cliquer <strong>« Appliquer les suggestions vides »</strong> pour remplir tout ce qui est vide d'un coup.
                </p>

                <table>
                    <thead>
                        <tr>
                            <th style="width:30px;"></th>
                            <th>Table HFSQL</th>
                            <th>Colonne date (modifiable)</th>
                            <th>Suggérée</th>
                            <th style="text-align:right;">Lignes locales</th>
                            <th>Dernier sync</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($remote as $name)
                            @php
                                $l = $local[$name] ?? null;
                                $row = $rows[$name] ?? null;
                                $sug = $suggestions[$name] ?? 'DateHeureModification';
                                $current = $l->date_column ?? '';
                            @endphp
                            <tr class="row" data-name="{{ strtolower($name) }}" data-suggested="{{ $sug }}">
                                <td><input type="checkbox" name="tables[]" value="{{ $name }}" {{ $l && $l->enabled ? 'checked' : '' }}></td>
                                <td><code>{{ $name }}</code></td>
                                <td>
                                    <input type="text" name="date_columns[{{ $name }}]" value="{{ $current }}" placeholder="{{ $sug }}"
                                           class="date-col-input"
                                           style="width:200px;padding:3px 6px;border-radius:4px;border:1px solid var(--border);background:var(--bg);color:var(--text);font-size:12px;font-family:monospace;">
                                </td>
                                <td>
                                    <button type="button" class="apply-one" data-target="date_columns[{{ $name }}]"
                                            style="background:transparent;border:1px dashed var(--border);color:var(--muted);font-family:monospace;font-size:11px;padding:2px 6px;">
                                        ↩ {{ $sug }}
                                    </button>
                                </td>
                                <td style="text-align:right;">{{ $row ? number_format($row->n, 0, ',', ' ') : '—' }}</td>
                                <td class="muted">{{ $row ? \Illuminate\Support\Carbon::parse($row->last_sync)->diffForHumans() : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <div style="margin-top:14px;">
                    <button type="submit">Enregistrer la sélection</button>
                </div>
            </form>
        @endif
    </div>

    <script>
        const filter = document.getElementById('filter');
        const rows = document.querySelectorAll('tr.row');
        filter?.addEventListener('input', (e) => {
            const q = e.target.value.toLowerCase();
            rows.forEach(r => r.style.display = r.dataset.name.includes(q) ? '' : 'none');
        });
        document.getElementById('select-current')?.addEventListener('click', () => {
            rows.forEach(r => { if (r.style.display !== 'none') r.querySelector('input[type=checkbox]').checked = true; });
        });
        document.getElementById('unselect-all')?.addEventListener('click', () => {
            rows.forEach(r => r.querySelector('input[type=checkbox]').checked = false);
        });

        // Appliquer la suggestion d'une ligne (bouton ↩ par table)
        document.querySelectorAll('.apply-one').forEach(btn => {
            btn.addEventListener('click', () => {
                const target = btn.dataset.target;
                const tr = btn.closest('tr');
                const input = tr.querySelector('.date-col-input');
                input.value = tr.dataset.suggested;
            });
        });

        // Remplir d'un coup toutes les suggestions sur les champs vides
        document.getElementById('apply-suggestions')?.addEventListener('click', () => {
            let n = 0;
            rows.forEach(r => {
                if (r.style.display === 'none') return;
                const input = r.querySelector('.date-col-input');
                if (!input.value.trim()) {
                    input.value = r.dataset.suggested;
                    n++;
                }
            });
            alert(`${n} champ(s) rempli(s) avec la suggestion. N'oubliez pas d'« Enregistrer la sélection » en bas.`);
        });
    </script>
@endsection
