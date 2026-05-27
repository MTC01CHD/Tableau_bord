"""
Tableau_bord — Agent relai HFSQL (Python + PowerShell ODBC)
============================================================
Version 3.2 — patchée pour stabilité :
  * ThreadingHTTPServer (concurrence : un /rows long ne bloque plus /tables)
  * List<T> préallouée côté PowerShell (au lieu de $rows += $row, O(n²))
  * MAX_ROWS=1000 par défaut (au lieu de 5000) pour éviter OOM côté PS
  * Support filtre incrémental : ?since=YYYY-MM-DD&date_col=Modification_date
  * Logs d'erreur PowerShell visibles côté console

PRÉREQUIS :
  1. Python 3.8+
  2. PowerShell 5+ (inclus dans Windows 10/11)
  3. Driver HFSQL installé (WinDev Runtime / PC SOFT)

INSTALLATION :
  1. Modifiez les constantes AGENT_KEY, HFSQL_* ci-dessous
  2. Ouvrez une invite admin et lancez :
       python hfsql-agent.py
  3. Pare-feu Windows :
       netsh advfirewall firewall add rule name="HFSQL Agent" dir=in action=allow protocol=TCP localport=8181
  4. Dans Tableau_bord → Admin → HFSQL : URL agent + Clé API
"""

import json
import re
import socket
import subprocess
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlparse, parse_qs

# ─── Configuration ────────────────────────────────────────────────────────────

AGENT_KEY      = "CHANGEZ-MOI-CLÉ-SECRÈTE-LONGUE"
HFSQL_SERVER   = "localhost"
HFSQL_PORT     = "4900"
HFSQL_DATABASE = "treport_mtc"
HFSQL_USER     = "Laravel"
HFSQL_PASS     = ""
HFSQL_DRIVER   = "HFSQL"
HFSQL_DSN      = ""   # Laisser vide pour connexion directe
LISTEN_HOST    = "0.0.0.0"
LISTEN_PORT    = 8181
MAX_ROWS       = 1000   # réduit pour limiter la mémoire PowerShell

# ─── DSN HFSQL WD28 C/S ──────────────────────────────────────────────────────

def build_dsn():
    if HFSQL_DSN:
        return f"DSN={HFSQL_DSN};UID={HFSQL_USER};PWD={HFSQL_PASS};"
    return (
        f"Driver={{{HFSQL_DRIVER}}};"
        f"Server Name={HFSQL_SERVER};Server Port={HFSQL_PORT};"
        f"Database={HFSQL_DATABASE};"
        f"UID={HFSQL_USER};PWD={HFSQL_PASS};"
        f"IntegrityCheck=1;"
    )

# ─── Couche PowerShell/.NET ODBC ─────────────────────────────────────────────

_PS_HEADER = (
    "$OutputEncoding = [System.Text.Encoding]::UTF8; "
    "[Console]::OutputEncoding = [System.Text.Encoding]::UTF8; "
    "$ErrorActionPreference = 'Stop'; "
)

def ps(script, timeout=120):
    try:
        r = subprocess.run(
            ["powershell", "-NonInteractive", "-NoProfile", "-Command", _PS_HEADER + script],
            capture_output=True, text=True, encoding="utf-8", errors="replace",
            timeout=timeout, creationflags=0x08000000,
        )
        out = r.stdout.strip()
        if out:
            try:
                return json.loads(out)
            except Exception as e:
                # On affiche l'erreur côté console pour debug
                print(f"[ps json-decode error] {e} | stdout={out[:200]}")
                return {"ok": False, "error": f"JSON invalide : {out[:200]}"}
        err = r.stderr.strip()
        if err:
            print(f"[ps stderr] {err[:500]}")
            return {"ok": False, "error": err.splitlines()[-1]}
        return {"ok": False, "error": "PowerShell n'a rien renvoyé"}
    except subprocess.TimeoutExpired:
        return {"ok": False, "error": f"Timeout ODBC ({timeout}s)"}
    except FileNotFoundError:
        return {"ok": False, "error": "PowerShell introuvable"}
    except Exception as e:
        return {"ok": False, "error": str(e)}


def _ps_dsn_literal(dsn):
    return dsn.replace('"', '`"').replace('$', '`$')


def _safe_ident(name):
    return re.sub(r"[^a-zA-Z0-9_]", "", name)


def odbc_test():
    dsn = _ps_dsn_literal(build_dsn())
    return ps(f"""
try {{
    $c = New-Object System.Data.Odbc.OdbcConnection("{dsn}")
    $c.Open(); $c.Close()
    ConvertTo-Json @{{ok=$true}}
}} catch {{
    ConvertTo-Json @{{ok=$false; error=$_.Exception.Message}}
}}
""", timeout=15)


def odbc_tables():
    dsn = _ps_dsn_literal(build_dsn())
    return ps(f"""
try {{
    $c = New-Object System.Data.Odbc.OdbcConnection("{dsn}")
    $c.Open()
    $schema = $c.GetSchema("Tables")
    $tables = @($schema.Rows | Where-Object {{ $_["TABLE_TYPE"] -eq "TABLE" }} | ForEach-Object {{ $_["TABLE_NAME"] }} | Sort-Object)
    $c.Close()
    ConvertTo-Json @{{ok=$true; tables=$tables}} -Compress
}} catch {{
    ConvertTo-Json @{{ok=$false; error=$_.Exception.Message}}
}}
""", timeout=60)


def odbc_columns(table):
    dsn = _ps_dsn_literal(build_dsn())
    t = _safe_ident(table)
    return ps(f"""
try {{
    $c = New-Object System.Data.Odbc.OdbcConnection("{dsn}")
    $c.Open()
    $cmd = $c.CreateCommand()
    try {{
        $cmd.CommandText = "SELECT * FROM {t} LIMIT 1"
        $reader = $cmd.ExecuteReader()
    }} catch {{
        $cmd.CommandText = "SELECT TOP 1 * FROM {t}"
        $reader = $cmd.ExecuteReader()
    }}
    $cols = @(0..([Math]::Max(0, $reader.FieldCount - 1)) | ForEach-Object {{
        @{{name=$reader.GetName($_); type=$reader.GetFieldType($_).Name}}
    }})
    $reader.Close(); $c.Close()
    ConvertTo-Json @{{ok=$true; columns=$cols}} -Compress
}} catch {{
    ConvertTo-Json @{{ok=$false; error=$_.Exception.Message}}
}}
""", timeout=60)


def odbc_rows(table, limit, offset, since=None, until=None, date_col=None):
    """
    `since`    : date "YYYY-MM-DD" → borne inférieure (col >= since)
    `until`    : date "YYYY-MM-DD" → borne supérieure (col <= until)
    `date_col` : nom de la colonne date (ex: Modification_date)
    """
    dsn = _ps_dsn_literal(build_dsn())
    t = _safe_ident(table)
    where = ""
    if date_col and (since or until):
        col = _safe_ident(date_col)
        clauses = []
        if since: clauses.append(f"{col} >= '{{d {since}}}'")
        if until: clauses.append(f"{col} <= '{{d {until}}}'")
        where = " WHERE " + " AND ".join(clauses)
    return ps(f"""
try {{
    $c = New-Object System.Data.Odbc.OdbcConnection("{dsn}")
    $c.Open()
    $cmd = $c.CreateCommand()
    try {{
        $cmd.CommandText = "SELECT * FROM {t}{where} LIMIT {limit} OFFSET {offset}"
        $reader = $cmd.ExecuteReader()
    }} catch {{
        $cmd.CommandText = "SELECT TOP {limit} * FROM {t}{where}"
        $reader = $cmd.ExecuteReader()
    }}
    $cols = @(0..($reader.FieldCount - 1) | ForEach-Object {{ $reader.GetName($_) }})

    # IMPORTANT : List<T> au lieu de $rows += $row (O(n²) en PS).
    $rows = [System.Collections.Generic.List[object]]::new()
    while ($reader.Read()) {{
        $row = @{{}}
        foreach ($col in $cols) {{ $row[$col] = $reader[$col] }}
        $null = $rows.Add($row)
    }}
    $reader.Close(); $c.Close()
    ConvertTo-Json @{{ok=$true; rows=$rows}} -Compress -Depth 3
}} catch {{
    ConvertTo-Json @{{ok=$false; error=$_.Exception.Message}}
}}
""", timeout=180)


# ─── Handler HTTP ─────────────────────────────────────────────────────────────

class HFSQLHandler(BaseHTTPRequestHandler):

    def log_message(self, format, *args):
        print(f"[{self.address_string()}] {format % args}")

    def send_json(self, data, status=200):
        body = json.dumps(data, ensure_ascii=False, default=str).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Access-Control-Allow-Origin", "*")
        self.end_headers()
        self.wfile.write(body)

    def send_error_json(self, message, status=500):
        self.send_json({"error": message}, status)

    def check_auth(self):
        auth = self.headers.get("Authorization", "")
        key  = self.headers.get("X-API-Key", "")
        if auth.startswith("Bearer "):
            auth = auth[7:]
        return auth == AGENT_KEY or key == AGENT_KEY

    def do_OPTIONS(self):
        self.send_response(204)
        self.send_header("Access-Control-Allow-Origin", "*")
        self.send_header("Access-Control-Allow-Headers", "Authorization, X-API-Key")
        self.end_headers()

    def do_GET(self):
        if not self.check_auth():
            self.send_error_json("Unauthorized", 401)
            return

        parsed = urlparse(self.path)
        path   = parsed.path.rstrip("/") or "/"
        params = parse_qs(parsed.query)

        if path in ("/", "/ping"):
            self.send_json({"ok": True, "agent": "Tableau_bord HFSQL Agent", "version": "3.3"})
            return

        if path == "/debug":
            dsn = build_dsn()
            info = {
                "dsn": dsn.replace(HFSQL_PASS, "***") if HFSQL_PASS else dsn,
                "server": HFSQL_SERVER, "port": HFSQL_PORT, "database": HFSQL_DATABASE,
            }
            try:
                s = socket.create_connection((HFSQL_SERVER, int(HFSQL_PORT)), timeout=5)
                s.close()
                info["tcp_port_open"] = True
            except Exception as e:
                info["tcp_port_open"] = False
                info["tcp_error"] = str(e)
            result = odbc_test()
            info["odbc_ok"]    = result.get("ok")
            info["odbc_error"] = result.get("error")
            self.send_json(info)
            return

        if path == "/tables":
            r = odbc_tables()
            self.send_json({"tables": r.get("tables", [])}) if r.get("ok") else self.send_error_json(r.get("error", "?"))
            return

        m = re.match(r"^/tables/([^/]+)/columns$", path)
        if m:
            r = odbc_columns(m.group(1))
            self.send_json({"columns": r.get("columns", [])}) if r.get("ok") else self.send_error_json(r.get("error", "?"))
            return

        m = re.match(r"^/tables/([^/]+)/rows$", path)
        if m:
            table  = m.group(1)
            limit  = min(int(params.get("limit",  [100])[0]), MAX_ROWS)
            offset = max(int(params.get("offset", [0])[0]),   0)
            since  = (params.get("since",    [None])[0]) or None
            until  = (params.get("until",    [None])[0]) or None
            datec  = (params.get("date_col", [None])[0]) or None
            r = odbc_rows(table, limit, offset, since=since, until=until, date_col=datec)
            self.send_json({"rows": r.get("rows", [])}) if r.get("ok") else self.send_error_json(r.get("error", "?"))
            return

        self.send_error_json(f"Route inconnue : {path}", 404)


# ─── Entrypoint ───────────────────────────────────────────────────────────────

if __name__ == "__main__":
    # ThreadingHTTPServer : un /rows long ne bloque pas /ping ni /tables.
    server = ThreadingHTTPServer((LISTEN_HOST, LISTEN_PORT), HFSQLHandler)
    print(f"Tableau_bord HFSQL Agent v3.3 (threaded, since+until) — http://{LISTEN_HOST}:{LISTEN_PORT}")
    print(f"Base : {HFSQL_SERVER}:{HFSQL_PORT}/{HFSQL_DATABASE}")
    print(f"Clé  : {AGENT_KEY[:8]}... (max_rows={MAX_ROWS})")
    print("Ctrl+C pour arrêter")
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("\nArrêt.")
