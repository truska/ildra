<?php
declare(strict_types=1);

function record_sql_error(string $context, PDOException $e, ?string $statement = null): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    if (!isset($_SESSION['sql_errors']) || !is_array($_SESSION['sql_errors'])) {
        $_SESSION['sql_errors'] = [];
    }
    $_SESSION['sql_errors'][] = [
        'time' => date('H:i:s'),
        'context' => $context,
        'message' => $e->getMessage(),
        'statement' => $statement,
    ];
    $max = 200;
    $count = count($_SESSION['sql_errors']);
    if ($count > $max) {
        $_SESSION['sql_errors'] = array_slice($_SESSION['sql_errors'], $count - $max);
    }
}

class LoggedPDOStatement extends PDOStatement
{
    protected function __construct()
    {
        // Intentionally empty; PDO may pass constructor args when instantiating.
    }

    public function execute($params = null): bool
    {
        try {
            return $params === null ? parent::execute() : parent::execute($params);
        } catch (PDOException $e) {
            record_sql_error('execute', $e, $this->queryString ?? null);
            throw $e;
        }
    }
}

class LoggedPDO extends PDO
{
    public function exec(string $statement): int|false
    {
        try {
            return parent::exec($statement);
        } catch (PDOException $e) {
            record_sql_error('exec', $e, is_string($statement) ? $statement : null);
            throw $e;
        }
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        try {
            if ($fetchMode === null) {
                return parent::query($query);
            }
            return parent::query($query, $fetchMode, ...$fetchModeArgs);
        } catch (PDOException $e) {
            record_sql_error('query', $e, is_string($query) ? $query : null);
            throw $e;
        }
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        try {
            return parent::prepare($query, $options);
        } catch (PDOException $e) {
            record_sql_error('prepare', $e, is_string($query) ? $query : null);
            throw $e;
        }
    }
}

function createPdo(array $config, array &$alerts): ?PDO
{
    $db = $config['db'] ?? [];
    $host = $db['host'] ?? 'localhost';
    $port = $db['port'] ?? '3306';
    $name = $db['name'] ?? '';
    $user = $db['user'] ?? '';
    $pass = $db['pass'] ?? '';
    $charset = $db['charset'] ?? 'utf8mb4';
    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";

    try {
        $pdo = new LoggedPDO($dsn, $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [LoggedPDOStatement::class, []]);
        return $pdo;
    } catch (PDOException $e) {
        record_sql_error('connect', $e, $dsn);
        $alerts[] = ['type' => 'danger', 'message' => 'Database connection failed.'];
        return null;
    }
}
