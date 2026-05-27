@extends('layouts.app')

@php
    $currentUser = request()->attributes->get('user');
@endphp

@section('content')
    <nav style="background:var(--panel2);padding:8px 16px;border-radius:6px;margin-bottom:16px;display:flex;gap:14px;align-items:center;flex-wrap:wrap;">
        <strong style="color:var(--accent);">Super-admin</strong>
        <a href="{{ route('super.tenants.index') }}" style="color:{{ request()->routeIs('super.tenants.*') ? 'var(--accent)' : 'var(--text)' }};text-decoration:none;font-size:13px;">Tenants</a>
        <a href="{{ route('super.users.index') }}" style="color:{{ request()->routeIs('super.users.*') ? 'var(--accent)' : 'var(--text)' }};text-decoration:none;font-size:13px;">Utilisateurs</a>
        <a href="{{ route('dashboard') }}" style="color:var(--text);text-decoration:none;font-size:13px;">→ Aller au dashboard</a>
        <span style="flex:1;"></span>
        <span class="muted" style="font-size:12px;">{{ $currentUser?->name ?? session('admin_name') }} (super-admin)</span>
        <form method="POST" action="{{ route('logout') }}" style="margin:0;">
            @csrf
            <button type="submit" style="font-size:12px;padding:4px 10px;background:var(--panel);color:var(--muted);">Déconnexion</button>
        </form>
    </nav>

    @if ($errors->any())
        <div class="alert err">
            @foreach ($errors->all() as $e) <div>{{ $e }}</div> @endforeach
        </div>
    @endif

    @yield('super')
@endsection
