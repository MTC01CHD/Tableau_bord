@extends('layouts.app')

@section('title', 'Connexion')

@section('content')
    <div style="max-width:400px;margin:60px auto;">
        <div class="card">
            <h2 style="margin:0 0 16px;">Connexion administrateur</h2>

            @if ($errors->any())
                <div class="alert err">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('login') }}">
                @csrf
                <div style="margin-bottom:12px;">
                    <label class="muted" style="font-size:11px;display:block;margin-bottom:4px;">Email</label>
                    <input type="email" name="email" value="{{ old('email') }}" required autofocus
                           style="width:100%;padding:8px 10px;border-radius:6px;border:1px solid var(--border);background:var(--bg);color:var(--text);">
                </div>
                <div style="margin-bottom:16px;">
                    <label class="muted" style="font-size:11px;display:block;margin-bottom:4px;">Mot de passe</label>
                    <input type="password" name="password" required
                           style="width:100%;padding:8px 10px;border-radius:6px;border:1px solid var(--border);background:var(--bg);color:var(--text);">
                </div>
                <button type="submit" style="width:100%;">Se connecter</button>
            </form>
        </div>
    </div>
@endsection
