@extends('layouts.app')

@section('title', 'Schema discovery')

@section('content')
    <p style="margin:0 0 12px;"><a href="{{ route('admin.hfsql.tables') }}" style="color:var(--muted);text-decoration:none;font-size:13px;">← retour admin</a></p>

    <div class="card" style="margin-bottom:16px;">
        <h2 style="margin:0 0 8px;">🔬 Schema discovery — payloads HFSQL</h2>
        <p class="muted" style="font-size:13px;margin:0 0 12px;">
            Pour chaque table synchronisée : noms exacts des colonnes (avec leur fréquence), 3 payloads échantillons,
            et pour les colonnes catégorielles (Type, Etat_Code, TypeRessource, ConstanteFamille, etc.) les valeurs
            distinctes avec leur fréquence. Permet d'identifier les vrais noms et valeurs avant d'écrire une requête.
        </p>

        <form method="GET" action="{{ route('admin.schema') }}" style="display:flex;gap:8px;align-items:center;">
            <label class="muted" style="font-size:12px;">Filtrer sur une table :</label>
            <select name="table" onchange="this.form.submit()" style="padding:4px 8px;border-radius:4px;border:1px solid var(--border);background:var(--bg);color:var(--text);">
                <option value="">— toutes —</option>
                @foreach ($tableNames as $t)
                    <option value="{{ $t->table_name }}" {{ $focus === $t->table_name ? 'selected' : '' }}>
                        {{ $t->table_name }} ({{ $t->n }} lignes)
                    </option>
                @endforeach
            </select>
            @if ($focus)
                <a href="{{ route('admin.schema') }}" style="color:var(--muted);font-size:12px;text-decoration:none;">tout afficher</a>
            @endif
        </form>
    </div>

    @foreach ($report as $r)
        <div class="card" style="margin-bottom:16px;">
            <h2 style="margin:0 0 4px;">
                <code style="font-size:18px;">{{ $r['table'] }}</code>
                <small class="muted" style="font-weight:400;font-size:13px;">— {{ number_format($r['nb_lignes'], 0, ',', ' ') }} ligne(s)</small>
            </h2>

            <h3 style="margin-top:14px;font-size:14px;">Colonnes (clés JSONB) présentes dans les 3 premiers payloads</h3>
            <table>
                <thead><tr><th>Colonne</th><th>Présente dans</th></tr></thead>
                <tbody>
                    @foreach ($r['keys_with_freq'] as $k => $n)
                        <tr>
                            <td><code>{{ $k }}</code></td>
                            <td style="font-size:11px;color:var(--muted);">{{ $n }}/3 échantillons</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            @if (!empty($r['distincts']))
                <h3 style="margin-top:14px;font-size:14px;">Valeurs distinctes des colonnes catégorielles</h3>
                @foreach ($r['distincts'] as $key => $values)
                    <h4 style="margin:10px 0 4px;font-size:13px;"><code>{{ $key }}</code></h4>
                    <table style="margin-bottom:8px;">
                        <thead><tr><th>Valeur</th><th style="text-align:right;">Occurrences</th></tr></thead>
                        <tbody>
                            @foreach ($values as $v)
                                <tr>
                                    <td><code>{{ $v['valeur'] !== null ? $v['valeur'] : 'NULL' }}</code></td>
                                    <td style="text-align:right;">{{ number_format($v['n'], 0, ',', ' ') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endforeach
            @endif

            <h3 style="margin-top:14px;font-size:14px;">3 payloads complets</h3>
            <pre style="background:var(--panel2);padding:10px;border-radius:6px;font-size:11px;overflow:auto;max-height:400px;">{{ json_encode($r['samples'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
        </div>
    @endforeach
@endsection
