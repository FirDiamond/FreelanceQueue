<?php
// Database access: one PDO connection per request, plus the write_tx helper.
//
// Every query in src/repo/* runs through prepared statements on this handle.
// PDO is configured with EMULATE_PREPARES off (true server-side prepares),
// exceptions on error, and associative fetches to match the repo code.

declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $s = settings();
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $s->db_host,
        $s->db_port,
        $s->db_name
    );

    $pdo = new PDO($dsn, $s->db_user, $s->db_pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        // InnoDB defaults to autocommit; write_tx() wraps explicit ones.
    ]);
    // UTC everywhere at rest; timeutil.php handles local conversion in PHP.
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}

/**
 * Run $fn inside a transaction, committing on success and rolling back on any
 * exception. Mirrors the Flask write_tx(): the callable does the work, the
 * wrapper owns BEGIN/COMMIT/ROLLBACK. Nested calls reuse the open transaction.
 *
 * @template T
 * @param callable(PDO):T $fn
 * @return T
 */
function write_tx(callable $fn)
{
    $pdo = db();
    $owns = !$pdo->inTransaction();
    if ($owns) {
        $pdo->beginTransaction();
    }
    try {
        $result = $fn($pdo);
        if ($owns) {
            $pdo->commit();
        }
        return $result;
    } catch (Throwable $e) {
        if ($owns && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** True when a PDOException is a duplicate-key (unique constraint) violation. */
function is_unique_violation(PDOException $e): bool
{
    // MySQL error 1062 (SQLSTATE 23000) = duplicate entry for a unique key.
    return ($e->errorInfo[1] ?? null) === 1062;
}

/** Execute schema.sql against the current database (used by seed.php). */
function run_schema(): void
{
    $sql = file_get_contents(SCHEMA_PATH);
    if ($sql === false) {
        throw new RuntimeException('Could not read ' . SCHEMA_PATH);
    }
    db()->exec($sql);
}
