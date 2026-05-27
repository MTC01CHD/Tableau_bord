@extends('layouts.app')

@php
    $currentUser   = request()->attributes->get('user');
    $currentTenant = request()->attributes->get('tenant');
    $allTenants    = $currentUser?->isSuperAdmin()
        ? \App\Models\Tenant::where('is_active', true)->orderBy('name')->get()
        : collect();
@endphp

@section('content')
    <nav style="background:var(--panel2);padding:8px 16px;border-radius:6px;margin-bottom:16px;display:flex;gap:14px;align-items:center;flex-wrap:wrap;">
        <strong style="color:var(--accent);">Admin</strong>
        <a href="{{ route('dashboard') }}" style="color:var(--text);text-decoration:none;font-size:13px;">Dashboard</a>
        <a href="{{ route('admin.hfsql.edit') }}" style="color:{{ request()->routeIs('admin.hfsql.edit') ? 'var(--accent)' : 'var(--text)' }};text-decoration:none;font-size:13px;">Connexion HFSQL</a>
        <a href="{{ route('admin.hfsql.tables') }}" style="color:{{ request()->routeIs('admin.hfsql.tables') ? 'var(--accent)' : 'var(--text)' }};text-decoration:none;font-size:13px;">Tables à synchroniser</a>
        <a href="{{ route('admin.sync') }}" style="color:{{ request()->routeIs('admin.sync') ? 'var(--accent)' : 'var(--text)' }};text-decoration:none;font-size:13px;">Historique sync</a>
        <span style="flex:1;"></span>

        @if ($currentUser?->isSuperAdmin())
            <form method="POST" action="{{ route('super.switch-tenant') }}" style="margin:0;display:flex;gap:6px;align-items:center;">
                @csrf
                <select name="tenant_id" onchange="this.form.submit()" style="background:var(--panel);color:var(--text);border:1px solid var(--border);border-radius:4px;padding:3px 6px;font-size:12px;">
                    @foreach ($allTenants as $t)
                        <option value="{{ $t->id }}" @selected($t->id === $currentTenant?->id)>{{ $t->name }}</option>
                    @endforeach
                </select>
            </form>
            <a href="{{ route('super.tenants.index') }}" style="color:var(--muted);text-decoration:none;font-size:12px;">⚙ Super</a>
        @else
            <span class="muted" style="font-size:12px;">Tenant : <strong>{{ $currentTenant?->name }}</strong></span>
        @endif

        <span class="muted" style="font-size:12px;">{{ session('admin_name') }}</span>
        <form method="POST" action="{{ route('logout') }}" style="margin:0;">
            @csrf
            <button type="submit" style="font-size:12px;padding:4px 10px;background:var(--panel);color:var(--muted);">Déconnexion</button>
        </form>
    </nav>

    @yield('admin')
@endsection
