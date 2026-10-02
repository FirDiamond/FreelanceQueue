<?php
// All SQL behind the reports page.
//
// Every query is filtered by queue_date BETWEEN ? AND ? - the local business
// day, not the UTC timestamp. Minute deltas use TIMESTAMPDIFF(SECOND,a,b)/60.0
// in place of SQLite's julianday()*1440.
//
// The busiest-hours heatmap needs clock time, so it is bucketed by LOCAL
// weekday/hour in PHP (see reports_busiest_hours) rather than in SQL - which
// avoids depending on MySQL time-zone tables being loaded on Windows.

declare(strict_types=1);

const REPORTS_RANGE = 'q.freelancer_id = ? AND q.queue_date BETWEEN ? AND ?';

/** One row of headline numbers for the whole range. */
function reports_summary(PDO $db, int $freelancer_id, string $start, string $end): array
{
    $sql = 'SELECT
                COUNT(*) AS total_entries,
                SUM(CASE WHEN q.status = \'done\'      THEN 1 ELSE 0 END) AS done_count,
                SUM(CASE WHEN q.status = \'no_show\'   THEN 1 ELSE 0 END) AS no_show_count,
                SUM(CASE WHEN q.status = \'cancelled\' THEN 1 ELSE 0 END) AS cancelled_count,
                SUM(CASE WHEN q.status = \'skipped\'   THEN 1 ELSE 0 END) AS skipped_count,
                SUM(CASE WHEN q.status = \'done\' THEN IFNULL(q.price_charged, 0) ELSE 0 END) AS earned,
                AVG(CASE WHEN q.started_at IS NOT NULL
                         THEN TIMESTAMPDIFF(SECOND, q.joined_at, q.started_at) / 60.0
                    END) AS avg_wait_minutes,
                AVG(CASE WHEN q.status = \'done\' AND q.started_at IS NOT NULL AND q.ended_at IS NOT NULL
                         THEN TIMESTAMPDIFF(SECOND, q.started_at, q.ended_at) / 60.0
                    END) AS avg_service_minutes,
                MAX(CASE WHEN q.started_at IS NOT NULL
                         THEN TIMESTAMPDIFF(SECOND, q.joined_at, q.started_at) / 60.0
                    END) AS max_wait_minutes,
                COUNT(DISTINCT q.client_id) AS client_count,
                COUNT(DISTINCT q.queue_date) AS active_days
              FROM queue_entries q
             WHERE ' . REPORTS_RANGE;
    $stmt = $db->prepare($sql);
    $stmt->execute([$freelancer_id, $start, $end]);
    return $stmt->fetch();
}

function reports_completed_per_day(PDO $db, int $freelancer_id, string $start, string $end): array
{
    $sql = 'SELECT q.queue_date, COUNT(*) AS done_count, SUM(IFNULL(q.price_charged, 0)) AS earned
              FROM queue_entries q
             WHERE ' . REPORTS_RANGE . " AND q.status = 'done'
             GROUP BY q.queue_date ORDER BY q.queue_date";
    $stmt = $db->prepare($sql);
    $stmt->execute([$freelancer_id, $start, $end]);
    return $stmt->fetchAll();
}

function reports_earnings_by_service(PDO $db, int $freelancer_id, string $start, string $end): array
{
    $sql = 'SELECT s.name AS service_name, COUNT(*) AS done_count,
                   SUM(IFNULL(q.price_charged, 0)) AS earned,
                   AVG(TIMESTAMPDIFF(SECOND, q.started_at, q.ended_at) / 60.0) AS avg_service_minutes
              FROM queue_entries q
              JOIN services s ON s.id = q.service_id
             WHERE ' . REPORTS_RANGE . " AND q.status = 'done'
             GROUP BY s.id, s.name ORDER BY earned DESC, done_count DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute([$freelancer_id, $start, $end]);
    return $stmt->fetchAll();
}

/**
 * Arrivals per local weekday and hour, weekday 0 = Monday. Bucketed in PHP so
 * the conversion uses the app's local timezone, matching business_hours.
 * Returns rows of ['weekday' => int, 'hour' => int, 'n' => int].
 */
function reports_busiest_hours(PDO $db, int $freelancer_id, string $start, string $end): array
{
    $sql = 'SELECT q.joined_at FROM queue_entries q WHERE ' . REPORTS_RANGE;
    $stmt = $db->prepare($sql);
    $stmt->execute([$freelancer_id, $start, $end]);

    $counts = [];
    foreach ($stmt->fetchAll() as $r) {
        $weekday = local_weekday($r['joined_at']);          // 0 = Monday
        $hour = (int)to_local($r['joined_at'])->format('G'); // 0-23 local
        $key = $weekday . ':' . $hour;
        $counts[$key] = ($counts[$key] ?? 0) + 1;
    }
    $rows = [];
    foreach ($counts as $key => $n) {
        [$weekday, $hour] = array_map('intval', explode(':', $key));
        $rows[] = ['weekday' => $weekday, 'hour' => $hour, 'n' => $n];
    }
    return $rows;
}

function reports_wait_and_service_per_day(PDO $db, int $freelancer_id, string $start, string $end): array
{
    $sql = 'SELECT q.queue_date,
                   AVG(CASE WHEN q.started_at IS NOT NULL
                            THEN TIMESTAMPDIFF(SECOND, q.joined_at, q.started_at) / 60.0
                       END) AS avg_wait_minutes,
                   AVG(CASE WHEN q.status = \'done\' AND q.ended_at IS NOT NULL AND q.started_at IS NOT NULL
                            THEN TIMESTAMPDIFF(SECOND, q.started_at, q.ended_at) / 60.0
                       END) AS avg_service_minutes
              FROM queue_entries q
             WHERE ' . REPORTS_RANGE . '
             GROUP BY q.queue_date ORDER BY q.queue_date';
    $stmt = $db->prepare($sql);
    $stmt->execute([$freelancer_id, $start, $end]);
    return $stmt->fetchAll();
}

/** The underlying rows - the table view and the CSV export. */
function reports_completed_entries(PDO $db, int $freelancer_id, string $start, string $end): array
{
    $sql = 'SELECT q.queue_date, q.ticket_no, q.status, q.note,
                   q.joined_at, q.started_at, q.ended_at, q.price_charged,
                   s.name AS service_name,
                   c.full_name AS client_name, c.email AS client_email,
                   TIMESTAMPDIFF(SECOND, q.joined_at, q.started_at) / 60.0 AS wait_minutes,
                   TIMESTAMPDIFF(SECOND, q.started_at, q.ended_at) / 60.0 AS service_minutes
              FROM queue_entries q
              JOIN services s ON s.id = q.service_id
              JOIN users    c ON c.id = q.client_id
             WHERE ' . REPORTS_RANGE . '
             ORDER BY q.queue_date DESC, q.seq DESC';
    $stmt = $db->prepare($sql);
    $stmt->execute([$freelancer_id, $start, $end]);
    return $stmt->fetchAll();
}

function reports_busiest_clients(PDO $db, int $freelancer_id, string $start, string $end, int $limit = 5): array
{
    $limit = max(1, $limit);
    $sql = 'SELECT c.full_name AS client_name, COUNT(*) AS visits,
                   SUM(CASE WHEN q.status = \'done\' THEN IFNULL(q.price_charged, 0) ELSE 0 END) AS earned
              FROM queue_entries q
              JOIN users c ON c.id = q.client_id
             WHERE ' . REPORTS_RANGE . "
             GROUP BY c.id, c.full_name ORDER BY visits DESC, earned DESC LIMIT {$limit}";
    $stmt = $db->prepare($sql);
    $stmt->execute([$freelancer_id, $start, $end]);
    return $stmt->fetchAll();
}
