<?php
// All SQL touching services and business_hours.

declare(strict_types=1);

const DEFAULT_OPEN  = '09:00';
const DEFAULT_CLOSE = '17:00';

function services_create(PDO $db, int $freelancer_id, string $name, float $price, int $est_minutes): int
{
    $stmt = $db->prepare(
        'INSERT INTO services (freelancer_id, name, price, est_minutes, is_active, created_at)
         VALUES (?, ?, ?, ?, 1, ?)'
    );
    $stmt->execute([$freelancer_id, trim($name), $price, $est_minutes, utc_now()]);
    return (int)$db->lastInsertId();
}

function services_list(PDO $db, int $freelancer_id, bool $active_only = false): array
{
    $sql = 'SELECT s.*,
                   (SELECT COUNT(*) FROM queue_entries q WHERE q.service_id = s.id) AS usage_count
              FROM services s
             WHERE s.freelancer_id = ?';
    if ($active_only) {
        $sql .= ' AND s.is_active = 1';
    }
    $sql .= ' ORDER BY s.is_active DESC, s.name';
    $stmt = $db->prepare($sql);
    $stmt->execute([$freelancer_id]);
    return $stmt->fetchAll();
}

function services_get(PDO $db, int $service_id): ?array
{
    $stmt = $db->prepare('SELECT * FROM services WHERE id = ?');
    $stmt->execute([$service_id]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function services_update(PDO $db, int $service_id, int $freelancer_id, string $name, float $price, int $est_minutes): void
{
    // freelancer_id in the WHERE clause is the authorization check.
    $stmt = $db->prepare(
        'UPDATE services SET name = ?, price = ?, est_minutes = ?
          WHERE id = ? AND freelancer_id = ?'
    );
    $stmt->execute([trim($name), $price, $est_minutes, $service_id, $freelancer_id]);
}

function services_set_active(PDO $db, int $service_id, int $freelancer_id, bool $active): void
{
    $stmt = $db->prepare('UPDATE services SET is_active = ? WHERE id = ? AND freelancer_id = ?');
    $stmt->execute([$active ? 1 : 0, $service_id, $freelancer_id]);
}

/** {service_id: est_minutes} for the given ids. */
function services_est_minutes_map(PDO $db, array $service_ids): array
{
    if (!$service_ids) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($service_ids), '?'));
    $stmt = $db->prepare("SELECT id, est_minutes FROM services WHERE id IN ({$placeholders})");
    $stmt->execute(array_values($service_ids));
    $map = [];
    foreach ($stmt->fetchAll() as $r) {
        $map[(int)$r['id']] = (int)$r['est_minutes'];
    }
    return $map;
}

function services_usage_count(PDO $db, int $service_id): int
{
    $stmt = $db->prepare('SELECT COUNT(*) AS n FROM queue_entries WHERE service_id = ?');
    $stmt->execute([$service_id]);
    return (int)$stmt->fetch()['n'];
}

/** Hard-delete. Callers must check services_usage_count() first. */
function services_delete(PDO $db, int $service_id, int $freelancer_id): void
{
    $stmt = $db->prepare('DELETE FROM services WHERE id = ? AND freelancer_id = ?');
    $stmt->execute([$service_id, $freelancer_id]);
}

// --- business hours ---------------------------------------------------------

function services_seed_default_hours(PDO $db, int $freelancer_id): void
{
    $stmt = $db->prepare(
        'INSERT IGNORE INTO business_hours (freelancer_id, weekday, is_open, open_time, close_time)
         VALUES (?, ?, ?, ?, ?)'
    );
    for ($weekday = 0; $weekday < 7; $weekday++) {
        $stmt->execute([$freelancer_id, $weekday, $weekday < 5 ? 1 : 0, DEFAULT_OPEN, DEFAULT_CLOSE]);
    }
}

function services_get_business_hours(PDO $db, int $freelancer_id): array
{
    $stmt = $db->prepare('SELECT * FROM business_hours WHERE freelancer_id = ? ORDER BY weekday');
    $stmt->execute([$freelancer_id]);
    return $stmt->fetchAll();
}

function services_get_hours_for_weekday(PDO $db, int $freelancer_id, int $weekday): ?array
{
    $stmt = $db->prepare('SELECT * FROM business_hours WHERE freelancer_id = ? AND weekday = ?');
    $stmt->execute([$freelancer_id, $weekday]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/**
 * Upsert the whole week. Each row is [weekday, is_open(bool), open_time, close_time].
 */
function services_save_business_hours(PDO $db, int $freelancer_id, array $week): void
{
    $stmt = $db->prepare(
        'INSERT INTO business_hours (freelancer_id, weekday, is_open, open_time, close_time)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            is_open = VALUES(is_open),
            open_time = VALUES(open_time),
            close_time = VALUES(close_time)'
    );
    foreach ($week as [$weekday, $is_open, $open_time, $close_time]) {
        $stmt->execute([$freelancer_id, $weekday, $is_open ? 1 : 0, $open_time, $close_time]);
    }
}
