@extends('layouts.super')
@section('title', 'Utilisateurs — Super-admin')

@section('super')
    <div class="grid grid-2">
        <div class="card">
            <h2>Utilisateurs</h2>
            <table>
                <thead>
                    <tr><th>Email</th><th>Nom</th><th>Tenant</th><th>Rôle</th><th></th></tr>
                </thead>
                <tbody>
                    @forelse ($users as $u)
                        <tr>
                            <td><strong>{{ $u->email }}</strong></td>
                            <td>{{ $u->name }}</td>
                            <td>{{ $u->tenant?->name ?? '—' }}</td>
                            <td>
                                @if ($u->isSuperAdmin())
                                    <span class="badge" style="background:rgba(56,189,248,.15);color:var(--accent);">super-admin</span>
                                @else
                                    <span class="muted">user</span>
                                @endif
                            </td>
                            <td style="text-align:right;">
                                <form method="POST" action="{{ route('super.users.destroy', $u) }}" style="margin:0;" onsubmit="return confirm('Supprimer le user {{ $u->email }} ?');">
                                    @csrf @method('DELETE')
                                    <button type="submit" style="font-size:11px;padding:3px 8px;background:rgba(239,68,68,.2);color:var(--err);">Supprimer</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="muted">Aucun utilisateur.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="card">
            <h2>Créer un utilisateur</h2>
            <form method="POST" action="{{ route('super.users.store') }}" style="display:flex;flex-direction:column;gap:10px;max-width:360px;">
                @csrf
                <label style="font-size:12px;color:var(--muted);">
                    Nom
                    <input type="text" name="name" required style="width:100%;padding:6px 10px;background:var(--panel2);color:var(--text);border:1px solid var(--border);border-radius:4px;">
                </label>
                <label style="font-size:12px;color:var(--muted);">
                    Email
                    <input type="email" name="email" required style="width:100%;padding:6px 10px;background:var(--panel2);color:var(--text);border:1px solid var(--border);border-radius:4px;">
                </label>
                <label style="font-size:12px;color:var(--muted);">
                    Mot de passe (min. 8 caractères)
                    <input type="password" name="password" required minlength="8" style="width:100%;padding:6px 10px;background:var(--panel2);color:var(--text);border:1px solid var(--border);border-radius:4px;">
                </label>
                <label style="font-size:12px;color:var(--muted);">
                    Tenant
                    <select name="tenant_id" style="width:100%;padding:6px 10px;background:var(--panel2);color:var(--text);border:1px solid var(--border);border-radius:4px;">
                        <option value="">— aucun (super-admin uniquement) —</option>
                        @foreach ($tenants as $t)
                            <option value="{{ $t->id }}">{{ $t->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label style="font-size:12px;color:var(--muted);display:flex;gap:6px;align-items:center;">
                    <input type="checkbox" name="is_super_admin" value="1">
                    Super-administrateur (accès à tous les tenants)
                </label>
                <button type="submit" style="align-self:flex-start;">Créer</button>
            </form>
        </div>
    </div>
@endsection
