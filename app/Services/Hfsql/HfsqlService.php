<?php

namespace App\Services\Hfsql;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Http;
use PDO;

/**
 * Service de lecture HFSQL.
 *
 * Configuration prioritaire :
 *   1. platform_settings (DB)  ← édité via /admin/hfsql
 *   2. config/hfsql.php → .env (fallback)
 *
 * Supporte le mode REST (agent hfsql-agent.py côté Windows) et ODBC (driver HFSQL).
 */
class HfsqlService
{
    /** Lit un paramètre depuis platform_settings, fallback sur config. */
    private function cfg(string $key, string $configPath, mixed $default = null): mixed
    {
        try {
            $v = PlatformSetting::get($key);
            if ($v !== null && $v !== '') return $v;
        } catch (\Throwable) {
            // Table pas encore migrée — on tombe sur la config par défaut
        }
        return config($configPath, $default);
    }

    public function mode(): string
    {
        $m = (string) $this->cfg('hfsql_mode', 'hfsql.mode', 'rest');
        return $m === 'webdev' ? 'rest' : $m;
    }

    // ── Connection test ────────────────────────────────────────────────────

    public function testConnection(): array
    {
        try {
            return $this->mode() === 'rest'
                ? $this->testRestConnection()
                : $this->testOdbcConnection();
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    private function testOdbcConnection(): array
    {
        if (!$this->odbcAvailable()) {
            return [
                'ok' => false,
                'message' => 'Extension PHP ODBC non chargée (installer php-odbc + unixODBC + driver HFSQL).',
            ];
        }
        $this->odbcPdo()->query('SELECT 1');
        return ['ok' => true, 'message' => 'Connexion ODBC établie avec succès.'];
    }

    private function testRestConnection(): array
    {
        $url = $this->restUrl();
        if (!$url) {
            return ['ok' => false, 'message' => "URL de l'API REST HFSQL non configurée (HFSQL_API_URL)."];
        }

        foreach (['/ping', '/tables'] as $endpoint) {
            $r = Http::timeout(10)->withHeaders($this->restHeaders())->get($url . $endpoint);
            if ($r->successful()) {
                return ['ok' => true, 'message' => 'API REST HFSQL accessible (' . $endpoint . ').'];
            }
        }
        return ['ok' => false, 'message' => 'API REST HFSQL inaccessible.'];
    }

    // ── Listing tables ─────────────────────────────────────────────────────

    public function getTables(): array
    {
        return $this->mode() === 'rest' ? $this->getRestTables() : $this->getOdbcTables();
    }

    private function getOdbcTables(): array
    {
        if (!$this->odbcAvailable()) return [];
        $stmt = $this->odbcPdo()->query(
            "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE='TABLE' ORDER BY TABLE_NAME"
        );
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    private function getRestTables(): array
    {
        // L'agent peut mettre 10-15s à énumérer les ~350 tables ODBC.
        // On met un timeout généreux et on cache 5 min pour ne pas re-payer ce coût.
        return cache()->remember('hfsql.tables.list', 300, function () {
            $r = Http::timeout(30)->withHeaders($this->restHeaders())->get($this->restUrl() . '/tables');
            if (!$r->successful()) return [];
            $d = $r->json();
            if (!is_array($d)) return [];
            if (isset($d['tables'])) return $d['tables'];
            if (isset($d[0])) return is_string($d[0]) ? $d : array_column($d, 'name');
            return [];
        });
    }

    // ── Columns of a table ─────────────────────────────────────────────────

    public function getColumns(string $table): array
    {
        self::quoteIdentifier($table);
        return $this->mode() === 'rest' ? $this->getRestColumns($table) : $this->getOdbcColumns($table);
    }

    private function getOdbcColumns(string $table): array
    {
        if (!$this->odbcAvailable()) return [];
        $stmt = $this->odbcPdo()->prepare(
            "SELECT COLUMN_NAME as name, DATA_TYPE as type FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = ? ORDER BY ORDINAL_POSITION"
        );
        $stmt->execute([$table]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function getRestColumns(string $table): array
    {
        $r = Http::timeout(15)->withHeaders($this->restHeaders())
            ->get($this->restUrl() . '/tables/' . $table . '/columns');
        if (!$r->successful()) return [];
        $d = $r->json();
        if (isset($d[0]['name'])) return $d;
        if (isset($d['columns'])) return $d['columns'];
        $rows = $this->fetchRows($table, 1, 0);
        if ($rows) return array_map(fn($k) => ['name' => $k, 'type' => 'varchar'], array_keys($rows[0]));
        return [];
    }

    // ── Read rows ──────────────────────────────────────────────────────────

    public function fetchRows(string $table, int $limit = 100, int $offset = 0, ?string $since = null, ?string $dateCol = null, ?string $until = null): array
    {
        self::quoteIdentifier($table);
        return $this->mode() === 'rest'
            ? $this->fetchRestRows($table, $limit, $offset, $since, $dateCol, $until)
            : $this->fetchOdbcRows($table, $limit, $offset);
    }

    private function fetchOdbcRows(string $table, int $limit, int $offset): array
    {
        if (!$this->odbcAvailable()) return [];
        $quoted = self::quoteIdentifier($table);
        $stmt = $this->odbcPdo()->prepare("SELECT * FROM {$quoted} LIMIT ? OFFSET ?");
        $stmt->execute([$limit, $offset]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function fetchRestRows(string $table, int $limit, int $offset, ?string $since = null, ?string $dateCol = null, ?string $until = null): array
    {
        $query = ['limit' => $limit, 'offset' => $offset];
        if ($dateCol && ($since || $until)) {
            $query['date_col'] = $dateCol;
            if ($since) $query['since'] = $since;
            if ($until) $query['until'] = $until;
        }

        // Retry sur erreurs transitoires ngrok (404 endpoint offline, 502/503 gateway, timeout).
        $r = Http::timeout(config('hfsql.rest.timeout', 120))
            ->retry(3, 2000, function ($exception, $request) {
                if ($exception instanceof \Illuminate\Http\Client\ConnectionException) return true;
                $resp = $exception instanceof \Illuminate\Http\Client\RequestException ? $exception->response : null;
                return $resp && in_array($resp->status(), [404, 408, 429, 502, 503, 504], true);
            }, throw: false)
            ->withHeaders($this->restHeaders())
            ->get($this->restUrl() . '/tables/' . $table . '/rows', $query);

        if (!$r->successful()) {
            throw new \RuntimeException(
                "Agent HFSQL HTTP {$r->status()} sur /tables/{$table}/rows : "
                . substr(strip_tags((string) $r->body()), 0, 200)
            );
        }

        $ct = strtolower((string) $r->header('Content-Type'));
        $body = (string) $r->body();
        if (!str_contains($ct, 'json')) {
            $hint = str_contains($body, 'ERR_NGROK_3200')
                ? 'tunnel ngrok hors ligne (ERR_NGROK_3200)'
                : (str_contains($body, 'ERR_NGROK_') ? 'erreur ngrok' : 'réponse non-JSON');
            throw new \RuntimeException(
                "Agent HFSQL : {$hint} pour /tables/{$table}/rows (Content-Type={$ct})"
            );
        }

        $d = $r->json();
        if (!is_array($d)) {
            throw new \RuntimeException("Agent HFSQL : JSON inattendu pour /tables/{$table}/rows");
        }
        if (isset($d['error'])) {
            throw new \RuntimeException("Agent HFSQL : " . $d['error']);
        }
        if (isset($d['rows'])) return $d['rows'];
        if (isset($d[0]) && is_array($d[0])) return $d;
        return [];
    }

    /**
     * Lecture paginée complète (évite les timeouts sur grosses tables).
     */
    public function fetchAllRows(string $table, ?int $batchSize = null, int $max = 0, ?string $since = null, ?string $dateCol = null, ?string $until = null): array
    {
        $batchSize = $batchSize ?: config('hfsql.sync.batch_size', 500);
        $all    = [];
        $offset = 0;
        do {
            $batch = $this->fetchRows($table, $batchSize, $offset, $since, $dateCol, $until);
            $all   = array_merge($all, $batch);
            $offset += $batchSize;
            if ($max > 0 && count($all) >= $max) break;
        } while (count($batch) === $batchSize);
        return $max > 0 ? array_slice($all, 0, $max) : $all;
    }

    /**
     * Itérateur paginé : ne charge pas tout en mémoire.
     *
     * @return iterable<int, array<string,mixed>>
     */
    public function streamRows(string $table, ?int $batchSize = null, int $max = 0, ?string $since = null, ?string $dateCol = null, ?string $until = null): iterable
    {
        $batchSize = $batchSize ?: config('hfsql.sync.batch_size', 500);
        $offset = 0;
        $yielded = 0;
        do {
            $batch = $this->fetchRows($table, $batchSize, $offset, $since, $dateCol, $until);
            foreach ($batch as $row) {
                yield $row;
                $yielded++;
                if ($max > 0 && $yielded >= $max) return;
            }
            $offset += $batchSize;
        } while (count($batch) === $batchSize);
    }

    /**
     * Valide et quote un identifiant HFSQL (table ou colonne).
     */
    public static function quoteIdentifier(string $name): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $name)) {
            throw new \InvalidArgumentException("Unsafe HFSQL identifier: {$name}");
        }
        return '"' . $name . '"';
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function restUrl(): string
    {
        return rtrim((string) $this->cfg('hfsql_api_url', 'hfsql.rest.url', ''), '/');
    }

    private function restHeaders(): array
    {
        $h = [
            'Accept'                     => 'application/json',
            'Content-Type'               => 'application/json',
            'ngrok-skip-browser-warning' => 'true',
        ];
        $key = $this->cfg('hfsql_api_key', 'hfsql.rest.api_key');
        if (!empty($key)) {
            $h['Authorization'] = 'Bearer ' . $key;
            $h['X-API-Key']     = $key;
        }
        return $h;
    }

    private function odbcAvailable(): bool
    {
        return extension_loaded('odbc') || extension_loaded('pdo_odbc');
    }

    private function buildOdbcDsn(): string
    {
        $dsn = (string) $this->cfg('hfsql_dsn', 'hfsql.odbc.dsn', '');
        if ($dsn !== '') {
            if (!str_contains($dsn, '=')) return 'odbc:DSN=' . $dsn . ';';
            return str_starts_with($dsn, 'odbc:') ? $dsn : 'odbc:' . $dsn;
        }
        $host   = $this->cfg('hfsql_host',     'hfsql.odbc.host');
        $port   = $this->cfg('hfsql_port',     'hfsql.odbc.port');
        $db     = $this->cfg('hfsql_database', 'hfsql.odbc.database');
        $driver = $this->cfg('hfsql_driver',   'hfsql.odbc.driver');
        return "odbc:Driver={{$driver}};Server Name={$host};Server Port={$port};Database={$db};IntegrityCheck=1;";
    }

    private function odbcPdo(): PDO
    {
        return new PDO(
            $this->buildOdbcDsn(),
            (string) $this->cfg('hfsql_username', 'hfsql.odbc.username'),
            (string) $this->cfg('hfsql_password', 'hfsql.odbc.password'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }
}
