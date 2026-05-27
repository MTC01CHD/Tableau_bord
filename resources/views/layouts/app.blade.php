<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('app.name'))</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
    <style>
        :root { --bg:#0f172a; --panel:#1e293b; --panel2:#334155; --text:#e2e8f0; --muted:#94a3b8;
                --accent:#38bdf8; --ok:#22c55e; --warn:#f59e0b; --err:#ef4444; --border:#334155; }
        * { box-sizing: border-box; }
        body { margin:0; font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
               background:var(--bg); color:var(--text); }
        header { background:var(--panel); padding:14px 24px; display:flex; align-items:center;
                 justify-content:space-between; border-bottom:1px solid var(--border); }
        header h1 { margin:0; font-size:18px; font-weight:600; }
        main { padding:24px; max-width:1400px; margin:0 auto; }
        .grid { display:grid; gap:16px; }
        .grid-2 { grid-template-columns: repeat(auto-fit, minmax(380px,1fr)); }
        .card { background:var(--panel); border:1px solid var(--border); border-radius:8px; padding:16px; }
        .card h2 { margin:0 0 12px; font-size:14px; text-transform:uppercase; letter-spacing:.5px; color:var(--muted); }
        table { width:100%; border-collapse:collapse; font-size:13px; }
        th, td { padding:8px 10px; text-align:left; border-bottom:1px solid var(--border); }
        th { font-weight:600; color:var(--muted); font-size:11px; text-transform:uppercase; letter-spacing:.5px; }
        tbody tr:hover { background: rgba(56,189,248,.05); }
        .badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:600; }
        .badge.ok { background:rgba(34,197,94,.15); color:var(--ok); }
        .badge.err { background:rgba(239,68,68,.15); color:var(--err); }
        .badge.run { background:rgba(245,158,11,.15); color:var(--warn); }
        button, .btn { background:var(--accent); color:#0f172a; border:none; border-radius:6px;
                       padding:6px 14px; font-weight:600; cursor:pointer; font-size:13px; }
        button:hover { opacity:.9; }
        .alert { padding:10px 14px; border-radius:6px; margin-bottom:16px; font-size:14px; }
        .alert.ok { background:rgba(34,197,94,.1); color:var(--ok); border:1px solid rgba(34,197,94,.3); }
        .alert.err { background:rgba(239,68,68,.1); color:var(--err); border:1px solid rgba(239,68,68,.3); }
        canvas { max-height:300px; }
        .muted { color:var(--muted); font-size:12px; }
        .kpi { display:flex; flex-direction:column; gap:4px; }
        .kpi-value { font-size:28px; font-weight:700; color:var(--accent); }
        .kpi-label { font-size:11px; text-transform:uppercase; letter-spacing:.5px; color:var(--muted); }

        /* Laravel pagination (par défaut basé sur Tailwind, absent ici) */
        nav[role="navigation"] { display:flex; align-items:center; gap:6px; flex-wrap:wrap; margin-top:12px; font-size:13px; }
        nav[role="navigation"] svg { width:14px; height:14px; }
        nav[role="navigation"] a,
        nav[role="navigation"] span.relative,
        nav[role="navigation"] > div > span,
        nav[role="navigation"] [aria-current] span {
            padding:5px 10px; border-radius:5px; background:var(--panel2);
            color:var(--text); text-decoration:none; line-height:1; min-width:28px; text-align:center;
            display:inline-flex; align-items:center; justify-content:center; border:1px solid var(--border);
        }
        nav[role="navigation"] a:hover { background:var(--accent); color:#0f172a; border-color:var(--accent); }
        nav[role="navigation"] [aria-current] span { background:var(--accent); color:#0f172a; border-color:var(--accent); font-weight:600; }
        nav[role="navigation"] [aria-disabled="true"] span { opacity:.4; cursor:not-allowed; }
        nav[role="navigation"] p { margin:0 10px 0 4px; color:var(--muted); font-size:12px; }
        nav[role="navigation"] .hidden { display:inline-flex !important; }
        nav[role="navigation"] .sm\:hidden { display:none !important; }
    </style>
</head>
<body>
    <header>
        <h1>{{ config('app.name') }}</h1>
        <nav><a href="{{ route('dashboard') }}" style="color:var(--muted);text-decoration:none;font-size:13px;">Dashboard</a></nav>
    </header>
    <main>
        @if (session('status'))
            <div class="alert ok">{{ session('status') }}</div>
        @endif
        @yield('content')
    </main>
</body>
</html>
