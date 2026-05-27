@extends('layouts.admin')

@section('title', 'Admin · Connexion HFSQL')

@section('admin')
    <div class="card">
        <h2 style="margin:0 0 12px;">Connexion à la base HFSQL</h2>
        <p class="muted" style="font-size:13px;margin:0 0 16px;">
            La configuration est stockée en base. Elle prime sur les variables d'environnement (.env).
            Choisissez le mode <strong>Agent relai (REST)</strong> si la base HFSQL est sur un réseau privé
            distant (le seul cas réaliste pour un hébergement cloud), ou <strong>ODBC direct</strong> si
            cette application tourne sur la même machine que HFSQL.
        </p>

        <form method="POST" action="{{ route('admin.hfsql.save') }}" id="cfg-form">
            @csrf

            <div style="margin-bottom:14px;">
                <label class="muted" style="font-size:11px;display:block;margin-bottom:6px;">Mode</label>
                <select name="hfsql_mode" id="mode" style="padding:6px 10px;border-radius:6px;border:1px solid var(--border);background:var(--bg);color:var(--text);">
                    <option value="rest" {{ $cfg['hfsql_mode'] === 'rest' ? 'selected' : '' }}>REST (agent relai sur Windows)</option>
                    <option value="odbc" {{ $cfg['hfsql_mode'] === 'odbc' ? 'selected' : '' }}>ODBC direct (driver HFSQL local)</option>
                </select>
            </div>

            <div id="block-rest" class="grid grid-2" style="margin-bottom:14px;">
                <div>
                    <label class="muted" style="font-size:11px;display:block;margin-bottom:4px;">URL de l'agent <span style="color:var(--err);">*</span></label>
                    <input type="url" name="hfsql_api_url" value="{{ $cfg['hfsql_api_url'] }}" placeholder="https://xxx.ngrok-free.dev"
                           style="width:100%;padding:6px 10px;border-radius:6px;border:1px solid var(--border);background:var(--bg);color:var(--text);">
                </div>
                <div>
                    <label class="muted" style="font-size:11px;display:block;margin-bottom:4px;">Clé API (AGENT_KEY)</label>
                    <input type="text" name="hfsql_api_key" value="{{ $cfg['hfsql_api_key'] }}" placeholder="clé partagée avec l'agent"
                           style="width:100%;padding:6px 10px;border-radius:6px;border:1px solid var(--border);background:var(--bg);color:var(--text);font-family:monospace;">
                </div>
            </div>

            <div id="block-odbc" class="grid grid-2" style="margin-bottom:14px;display:none;">
                <div>
                    <label class="muted" style="font-size:11px;display:block;margin-bottom:4px;">Host HFSQL</label>
                    <input type="text" name="hfsql_host" value="{{ $cfg['hfsql_host'] }}" placeholder="192.168.x.x"
                           style="width:100%;padding:6px 10px;border-radius:6px;border:1px solid var(--border);background:var(--bg);color:var(--text);">
                </div>
                <div>
                    <label class="muted" style="font-size:11px;display:block;margin-bottom:4px;">Port</label>
                    <input type="text" name="hfsql_port" value="{{ $cfg['hfsql_port'] ?: '4900' }}"
                           style="width:100%;padding:6px 10px;border-radius:6px;border:1px solid var(--border);background:var(--bg);color:var(--text);">
                </div>
                <div>
                    <label class="muted" style="font-size:11px;display:block;margin-bottom:4px;">Database</label>
                    <input type="text" name="hfsql_database" value="{{ $cfg['hfsql_database'] }}"
                           style="width:100%;padding:6px 10px;border-radius:6px;border:1px solid var(--border);background:var(--bg);color:var(--text);">
                </div>
                <div>
                    <label class="muted" style="font-size:11px;display:block;margin-bottom:4px;">Driver ODBC</label>
                    <input type="text" name="hfsql_driver" value="{{ $cfg['hfsql_driver'] ?: 'HFSQL' }}"
                           style="width:100%;padding:6px 10px;border-radius:6px;border:1px solid var(--border);background:var(--bg);color:var(--text);">
                </div>
                <div>
                    <label class="muted" style="font-size:11px;display:block;margin-bottom:4px;">User</label>
                    <input type="text" name="hfsql_username" value="{{ $cfg['hfsql_username'] }}"
                           style="width:100%;padding:6px 10px;border-radius:6px;border:1px solid var(--border);background:var(--bg);color:var(--text);">
                </div>
                <div>
                    <label class="muted" style="font-size:11px;display:block;margin-bottom:4px;">Password</label>
                    <input type="password" name="hfsql_password" value="{{ $cfg['hfsql_password'] }}"
                           style="width:100%;padding:6px 10px;border-radius:6px;border:1px solid var(--border);background:var(--bg);color:var(--text);">
                </div>
                <div style="grid-column:1/-1;">
                    <label class="muted" style="font-size:11px;display:block;margin-bottom:4px;">DSN complet (optionnel, écrase les champs ci-dessus)</label>
                    <input type="text" name="hfsql_dsn" value="{{ $cfg['hfsql_dsn'] }}" placeholder="DSN=MonDSN; OU Driver={HFSQL};Server=…"
                           style="width:100%;padding:6px 10px;border-radius:6px;border:1px solid var(--border);background:var(--bg);color:var(--text);font-family:monospace;">
                </div>
            </div>

            <div style="display:flex;gap:10px;align-items:center;">
                <button type="submit">Enregistrer</button>
                <button type="button" id="test-btn" style="background:var(--panel2);color:var(--text);">Tester la connexion</button>
                <span id="test-result" style="font-size:13px;"></span>
            </div>
        </form>
    </div>

    <script>
        const modeSel = document.getElementById('mode');
        const blockRest = document.getElementById('block-rest');
        const blockOdbc = document.getElementById('block-odbc');
        function applyMode() {
            const m = modeSel.value;
            blockRest.style.display = m === 'rest' ? '' : 'none';
            blockOdbc.style.display = m === 'odbc' ? '' : 'none';
        }
        modeSel.addEventListener('change', applyMode);
        applyMode();

        document.getElementById('test-btn').addEventListener('click', async () => {
            const resultEl = document.getElementById('test-result');
            resultEl.textContent = '⏳ test en cours…';
            resultEl.style.color = 'var(--muted)';
            try {
                const r = await fetch('{{ route("admin.hfsql.test") }}', {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    },
                });
                const data = await r.json();
                resultEl.textContent = (data.ok ? '✅ ' : '❌ ') + data.message;
                resultEl.style.color = data.ok ? 'var(--ok)' : 'var(--err)';
            } catch (e) {
                resultEl.textContent = '❌ erreur réseau : ' + e.message;
                resultEl.style.color = 'var(--err)';
            }
        });
    </script>
@endsection
