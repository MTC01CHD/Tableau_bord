@extends('layouts.app')

@section('content')
    <nav style="background:var(--panel2);padding:8px 16px;border-radius:6px;margin-bottom:16px;display:flex;gap:14px;align-items:center;flex-wrap:wrap;">
        <strong style="color:var(--accent);">Admin</strong>
        <a href="{{ route('admin.hfsql.edit') }}" style="color:{{ request()->routeIs('admin.hfsql.edit') ? 'var(--accent)' : 'var(--text)' }};text-decoration:none;font-size:13px;">Connexion HFSQL</a>
        <a href="{{ route('admin.hfsql.tables') }}" style="color:{{ request()->routeIs('admin.hfsql.tables') ? 'var(--accent)' : 'var(--text)' }};text-decoration:none;font-size:13px;">Tables à synchroniser</a>
        <a href="{{ route('admin.sync') }}" style="color:{{ request()->routeIs('admin.sync') ? 'var(--accent)' : 'var(--text)' }};text-decoration:none;font-size:13px;">Historique sync</a>
        <span style="flex:1;"></span>
        <span class="muted" style="font-size:12px;">{{ session('admin_name') }}</span>
        <form method="POST" action="{{ route('logout') }}" style="margin:0;">
            @csrf
            <button type="submit" style="font-size:12px;padding:4px 10px;background:var(--panel);color:var(--muted);">Déconnexion</button>
        </form>
    </nav>

    @yield('admin')
@endsection
