<?php
// All SQL touching email_log.
//
// The log exists so a notification that never arrived can be explained. Written
// by the mailer as it queues, sends and (on failure) records the error.

declare(strict_types=1);

function email_log_queued(PDO $db, ?int $entry_id, string $to_email, string $event, string $subject): int
{
    $stmt = $db->prepare(
        "INSERT INTO email_log
            (entry_id, to_email, event, subject, status, attempts, created_at)
         VALUES (?, ?, ?, ?, 'queued', 0, ?)"
    );
    $stmt->execute([$entry_id, $to_email, $event, $subject, utc_now()]);
    return (int)$db->lastInsertId();
}

function email_log_mark_sent(PDO $db, int $log_id, int $attempts): void
{
    $stmt = $db->prepare(
        "UPDATE email_log
            SET status = 'sent', attempts = ?, sent_at = ?, last_error = NULL
          WHERE id = ?"
    );
    $stmt->execute([$attempts, utc_now(), $log_id]);
}

function email_log_mark_failed(PDO $db, int $log_id, int $attempts, string $error): void
{
    $stmt = $db->prepare(
        "UPDATE email_log
            SET status = 'failed', attempts = ?, last_error = ?
          WHERE id = ?"
    );
    $stmt->execute([$attempts, substr($error, 0, 500), $log_id]);
}

function email_log_recent(PDO $db, int $limit = 50, ?string $status = null): array
{
    // $limit is an internal int; inlining sidesteps PDO's LIMIT-placeholder
    // quirk under real prepares.
    $limit = max(1, $limit);
    $sql = 'SELECT * FROM email_log';
    $params = [];
    if ($status !== null && $status !== '') {
        $sql .= ' WHERE status = ?';
        $params[] = $status;
    }
    $sql .= " ORDER BY id DESC LIMIT {$limit}";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function email_log_count_by_status(PDO $db): array
{
    $stmt = $db->query('SELECT status, COUNT(*) AS n FROM email_log GROUP BY status');
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[$r['status']] = (int)$r['n'];
    }
    return $out;
}
