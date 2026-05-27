@extends('layouts.super')
@section('title', 'Tenants — Super-admin')

@section('super')
    <div class="grid grid-2">
        <div class="card">
            <h2>Tenants</h2>
            @php $currentTenantId = session('current_tenant_id'); @endphp
            <table>
                <thead>
                    <tr><th></th><th>Nom</th><th>Slug</th><th>Users</th><th>Lignes HFSQL</th><th>Statut</th><th></th></tr>
                </thead>
                <tbody>
                    @forelse ($tenants as $t)
                        <tr>
                            <td>
                                @if ($t->id === $currentTenantId)
                                    <span class="badge ok" title="Tenant courant">✓ courant</span>
                                @elseif ($t->is_active)
                                    <form method="POST" action="{{ route('super.switch-tenant') }}" style="margin:0;">
                                        @csrf
                                        <input type="hidden" name="tenant_id" value="{{ $t->id }}">
                                        <input type="hidden" name="redirect_to" value="{{ route('dashboard') }}">
                                        <button type="submit" style="font-size:11px;padding:3px 10px;background:var(--accent);color:#0f172a;font-weight:600;">Utiliser → dashboard</button>
                                    </form>
                                @endif
                            </td>
                            <td><strong>{{ $t->name }}</strong></td>
                            <td><code>{{ $t->slug }}</code></td>
                            <td>{{ $t->users_count }}</td>
                            <td>{{ number_format($rowsByTenant[$t->id] ?? 0, 0, ',', ' ') }}</td>
                            <td>
                                @if ($t->is_active)
                                    <span class="badge ok">actif</span>
                                @else
                                    <span class="badge err">inactif</span>
                                @endif
                            </td>
                            <td style="display:flex;gap:6px;justify-content:flex-end;">
                                <form method="POST" action="{{ route('super.tenants.toggle', $t) }}" style="margin:0;">
                                    @csrf
                                    <button type="submit" style="font-size:11px;padding:3px 8px;background:var(--panel2);color:var(--text);">{{ $t->is_active ? 'Désactiver' : 'Activer' }}</button>
                                </form>
                                <form method="POST" action="{{ route('super.tenants.destroy', $t) }}" style="margin:0;" onsubmit="return confirm('Supprimer le tenant {{ $t->slug }} ?\n\nCela effacera en cascade toutes ses données HFSQL et ses users.\n\nTapez le slug pour confirmer dans le prompt suivant.') && (this.confirm_slug.value = prompt('Tapez « {{ $t->slug }} » pour confirmer :')) === '{{ $t->slug }}';">
                                    @csrf @method('DELETE')
                                    <input type="hidden" name="confirm_slug" value="">
                                    <button type="submit" style="font-size:11px;padding:3px 8px;background:rgba(239,68,68,.2);color:var(--err);">Supprimer</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="muted">Aucun tenant. Créez-en un à droite.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="card">
            <h2>Créer un tenant</h2>
            <form method="POST" action="{{ route('super.tenants.store') }}" style="display:flex;flex-direction:column;gap:10px;max-width:360px;">
                @csrf
                <label style="font-size:12px;color:var(--muted);">
                    Nom
                    <input type="text" name="name" required style="width:100%;padding:6px 10px;background:var(--panel2);color:var(--text);border:1px solid var(--border);border-radius:4px;">
                </label>
                <label style="font-size:12px;color:var(--muted);">
                    Slug <span class="muted">(a-z, 0-9, _, -)</span>
                    <input type="text" name="slug" required pattern="[A-Za-z0-9_\-]+" style="width:100%;padding:6px 10px;background:var(--panel2);color:var(--text);border:1px solid var(--border);border-radius:4px;">
                </label>
                <button type="submit" style="align-self:flex-start;">Créer</button>
            </form>
        </div>
    </div>
@endsection
