<?php
/**
 * T-REPORT — Agent relai HFSQL
 * ============================
 * Déployez ce fichier sur le serveur Windows qui a accès à la base HFSQL.
 * Il expose les données HFSQL en REST/JSON vers T-REPORT (hébergé dans le cloud).
 *
 * INSTALLATION :
 *   1. Copiez ce fichier dans un répertoire servi par IIS ou Apache/XAMPP.
 *   2. Modifiez les constantes AGENT_KEY, HFSQL_* ci-dessous.
 *   3. Assurez-vous que php_pdo_odbc.dll (ou php_odbc.dll) est activé dans php.ini.
 *   4. Le driver "HyperFileSQL Client/Server" doit être installé (WinDev Runtime).
 *   5. Ouvrez le port de l'agent dans votre pare-feu (ex: 8080).
 *   6. Dans T-REPORT → Admin → HFSQL → mode "API REST" → URL = http://VOTRE_IP:PORT/hfsql-agent.php
 *
 * SÉCURITÉ :
 *   Changez AGENT_KEY par une clé longue et aléatoire. Ne jamais exposer sans clé.
 */

// ─── Configuration ──────────────────────────────────────────────────────────

define('AGENT_KEY',      'CHANGEZ-MOI-CLÉ-SECRÈTE-LONGUE');   // Clé API (à copier dans T-REPORT)
define('HFSQL_HOST',     'localhost');                          // Serveur HFSQL C/S (IP ou hostname)
define('HFSQL_PORT',     '4900');                               // Port HFSQL C/S (défaut 4900)
define('HFSQL_DATABASE', 'MaBase');                             // Nom de la base HFSQL
define('HFSQL_USER',     '');                                   // Utilisateur HFSQL (vide si non requis)
define('HFSQL_PASS',     '');                                   // Mot de passe HFSQL
define('HFSQL_DRIVER',   'HFSQL');                              // Nom du driver ODBC (WD30+ = "HFSQL", ancien = "HyperFileSQL Client/Server")

define('MAX_ROWS', 5000);   // Limite sécurité par requête

// ─── Bootstrap ──────────────────────────────────────────────────────────────

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, X-API-Key, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// Auth
$key = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['HTTP_X_API_KEY'] ?? '';
$key = preg_replace('/^Bearer\s+/i', '', $key);
if ($key !== AGENT_KEY) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Routing
$path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path   = '/' . ltrim(preg_replace('#^.*hfsql-agent\.php#', '', $path), '/');
$method = $_SERVER['REQUEST_METHOD'];

try {
    $pdo = getPdo();
    route($path, $method, $pdo);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

// ─── Routing ─────────────────────────────────────────────────────────────────

function route(string $path, string $method, PDO $pdo): void
{
    // /ping ou /
    if ($path === '/' || $path === '/ping') {
        echo json_encode(['ok' => true, 'agent' => 'T-REPORT HFSQL Agent', 'version' => '1.0']);
        return;
    }

    // /tables — via métadonnées PDO (pas de INFORMATION_SCHEMA)
    if ($path === '/tables') {
        // Essai 1 : méthode catalogue PDO ODBC
        try {
            $stmt = $pdo->query("SELECT HFSQLTableName FROM SYS.TABLES ORDER BY HFSQLTableName");
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable $e) {
            // Essai 2 : requête HF native
            try {
                $stmt = $pdo->query("SELECT TABLE_NAME FROM HFSQL_TABLES ORDER BY TABLE_NAME");
                $tables = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
            } catch (Throwable $e2) {
                $tables = [];
            }
        }
        echo json_encode(['tables' => $tables]);
        return;
    }

    // /tables/{table}/columns
    if (preg_match('#^/tables/([^/]+)/columns$#', $path, $m)) {
        $table = $m[1];
        try {
            $stmt = $pdo->prepare("SELECT COLUMN_NAME AS name, DATA_TYPE AS type FROM HFSQL_COLUMNS WHERE TABLE_NAME = ? ORDER BY ORDINAL_POSITION");
            $stmt->execute([$table]);
            $cols = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $cols = [];
        }
        echo json_encode(['columns' => $cols]);
        return;
    }

    // /tables/{table}/rows  (GET avec ?limit=&offset=)
    if (preg_match('#^/tables/([^/]+)/rows$#', $path, $m)) {
        $table  = preg_replace('/[^a-zA-Z0-9_]/', '', $m[1]);
        $limit  = min((int)($_GET['limit']  ?? 100), MAX_ROWS);
        $offset = max((int)($_GET['offset'] ?? 0), 0);
        try {
            $stmt = $pdo->prepare("SELECT * FROM {$table} LIMIT ? OFFSET ?");
            $stmt->execute([$limit, $offset]);
        } catch (Throwable $e) {
            $stmt = $pdo->query("SELECT TOP {$limit} * FROM {$table}");
        }
        echo json_encode(['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []]);
        return;
    }

    http_response_code(404);
    echo json_encode(['error' => 'Route inconnue : ' . $path]);
}

// ─── PDO ODBC ────────────────────────────────────────────────────────────────

function getPdo(): PDO
{
    // Nom du driver tel qu'enregistré dans le gestionnaire ODBC Windows.
    // Vérifiez avec : Get-OdbcDriver | Select-Object Name  (PowerShell)
    // Valeurs courantes : "HFSQL" (WD30+) ou "HyperFileSQL Client/Server" (versions antérieures)
    // Format HFSQL WD28 C/S : "Server Name" et "Server Port" avec espaces, IntegrityCheck=1
    $driver = defined('HFSQL_DRIVER') ? HFSQL_DRIVER : 'HFSQL';
    $dsn = "odbc:Driver={" . $driver . "};"
         . "Server Name=" . HFSQL_HOST . ";"
         . "Server Port=" . HFSQL_PORT . ";"
         . "Database="    . HFSQL_DATABASE . ";"
         . "IntegrityCheck=1;";

    return new PDO($dsn, HFSQL_USER, HFSQL_PASS, [
        PDO::ATTR_ERRMODE    => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT    => 10,
    ]);
}
