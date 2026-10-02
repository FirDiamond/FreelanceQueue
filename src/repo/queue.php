<?php
// All SQL touching queue_entries.
//
//   * seq is a per-freelancer, per-day counter allocated inside write_tx() and
//     protected by UNIQUE (freelancer_id, queue_date, seq).
//   * sort_key is a DOUBLE with deliberate gaps. Reordering writes ONE midpoint
//     value rather than renumbering, so concurrent reorders never contend.
//
// Minute deltas use TIMESTAMPDIFF(SECOND, a, b)/60.0 (keeping float precision),
// which replaces SQLite's (julianday(b) - julianday(a)) * 1440.0.

declare(strict_types=1);

const SORT_GAP = 1024.0;
// Below this gap between neighbours, repeated midpoint inserts lose precision,
// so we renumber. Reachable only after ~50 inserts into the same slot.
const MIN_SORT_GAP = 1e-6;

const ACTIVE_STATUSES   = ['waiting', 'serving'];
const TERMINAL_STATUSES = ['done', 'skipped', 'no_show', 'cancelled'];

/** Joined columns every queue view needs: who, what service, how long. */
function queue_entry_select(): string
{
    return "SELECT q.*,
                   s.name        AS service_name,
                   s.est_minutes AS est_minutes,
                   s.price       AS service_price,
                   c.full_name   AS client_name,
                   c.email       AS client_email,
                   f.full_name   AS freelancer_name,
                   f.email       AS freelancer_email
              FROM queue_entries q
              JOIN services s ON s.id = q.service_id
              JOIN users    c ON c.id = q.client_id
              JOIN users    f ON f.id = q.freelancer_id";
}

// --- reads ------------------------------------------------------------------

function queue_get_entry(PDO $db, int $entry_id): ?array
{
    $stmt = $db->prepare(queue_entry_select() . ' WHERE q.id = ?');
    $stmt->execute([$entry_id]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function queue_get_serving(PDO $db, int $freelancer_id): ?array
{
    $stmt = $db->prepare(
        queue_entry_select() . " WHERE q.freelancer_id = ? AND q.status = 'serving'"
    );
    $stmt->execute([$freelancer_id]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/** The waiting list in queue order, each row carrying its 1-based position. */
function queue_list_waiting(PDO $db, int $freelancer_id): array
{
    $stmt = $db->prepare(
        "SELECT q.*,
                s.name AS service_name, s.est_minutes, s.price AS service_price,
                c.full_name AS client_name, c.email AS client_email,
                ROW_NUMBER() OVER (ORDER BY q.sort_key) AS position
           FROM queue_entries q
           JOIN services s ON s.id = q.service_id
           JOIN users    c ON c.id = q.client_id
          WHERE q.freelancer_id = ? AND q.status = 'waiting'
          ORDER BY q.sort_key"
    );
    $stmt->execute([$freelancer_id]);
    return $stmt->fetchAll();
}

/** 1-based position among that freelancer's waiting entries. */
function queue_position_of(PDO $db, array $entry): ?int
{
    if ($entry['status'] !== 'waiting') {
        return null;
    }
    $stmt = $db->prepare(
        "SELECT COUNT(*) + 1 AS pos FROM queue_entries
          WHERE freelancer_id = ? AND status = 'waiting' AND sort_key < ?"
    );
    $stmt->execute([$entry['freelancer_id'], $entry['sort_key']]);
    return (int)$stmt->fetch()['pos'];
}

/** service_id of every waiting entry ordered before this one. */
function queue_services_ahead_of(PDO $db, array $entry): array
{
    $stmt = $db->prepare(
        "SELECT service_id FROM queue_entries
          WHERE freelancer_id = ? AND status = 'waiting' AND sort_key < ?
          ORDER BY sort_key"
    );
    $stmt->execute([$entry['freelancer_id'], $entry['sort_key']]);
    return array_map(static fn($r) => (int)$r['service_id'], $stmt->fetchAll());
}

/**
 * Observed service time per service: {service_id: [sample_count, avg_minutes]}.
 * Uses the most recent 20 completions per service (one windowed query, since
 * this runs on every dashboard poll).
 */
function queue_service_duration_stats(PDO $db, array $service_ids): array
{
    if (!$service_ids) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($service_ids), '?'));
    $stmt = $db->prepare(
        "SELECT service_id, COUNT(*) AS n, AVG(minutes) AS avg_minutes
           FROM (
             SELECT service_id,
                    TIMESTAMPDIFF(SECOND, started_at, ended_at) / 60.0 AS minutes,
                    ROW_NUMBER() OVER (
                        PARTITION BY service_id ORDER BY ended_at DESC
                    ) AS rn
               FROM queue_entries
              WHERE status = 'done'
                AND started_at IS NOT NULL
                AND ended_at IS NOT NULL
                AND service_id IN ({$placeholders})
           ) ranked
          WHERE rn <= 20
          GROUP BY service_id"
    );
    $stmt->execute(array_values($service_ids));
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[(int)$r['service_id']] = [(int)$r['n'], (float)$r['avg_minutes']];
    }
    return $out;
}

/** A client's current waiting/serving entry, if any. */
function queue_active_entry_for_client(PDO $db, int $client_id, ?int $freelancer_id = null): ?array
{
    $sql = queue_entry_select() . " WHERE q.client_id = ? AND q.status IN ('waiting', 'serving')";
    $params = [$client_id];
    if ($freelancer_id !== null) {
        $sql .= ' AND q.freelancer_id = ?';
        $params[] = $freelancer_id;
    }
    $sql .= ' ORDER BY q.joined_at DESC';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function queue_list_client_entries(PDO $db, int $client_id, int $limit = 50): array
{
    // $limit is an internal int, not user input, so inlining it is safe and
    // sidesteps PDO's LIMIT-placeholder quirk under real prepares.
    $limit = max(1, $limit);
    $stmt = $db->prepare(
        queue_entry_select() . "
          WHERE q.client_id = ?
          ORDER BY CASE q.status WHEN 'serving' THEN 0 WHEN 'waiting' THEN 1 ELSE 2 END,
                   q.joined_at DESC
          LIMIT {$limit}"
    );
    $stmt->execute([$client_id]);
    return $stmt->fetchAll();
}

function queue_count_waiting(PDO $db, int $freelancer_id): int
{
    $stmt = $db->prepare(
        "SELECT COUNT(*) AS n FROM queue_entries WHERE freelancer_id = ? AND status = 'waiting'"
    );
    $stmt->execute([$freelancer_id]);
    return (int)$stmt->fetch()['n'];
}

/** Completed count, earnings and average real wait for one business day. */
function queue_today_stats(PDO $db, int $freelancer_id, string $queue_date): array
{
    $stmt = $db->prepare(
        "SELECT
            SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END) AS done_count,
            SUM(CASE WHEN status = 'done' THEN IFNULL(price_charged, 0) ELSE 0 END) AS earned,
            AVG(CASE WHEN started_at IS NOT NULL
                     THEN TIMESTAMPDIFF(SECOND, joined_at, started_at) / 60.0
                END) AS avg_wait_minutes
          FROM queue_entries
         WHERE freelancer_id = ? AND queue_date = ?"
    );
    $stmt->execute([$freelancer_id, $queue_date]);
    return $stmt->fetch();
}

/** Everything that happened today, newest activity first - the day's log. */
function queue_list_today(PDO $db, int $freelancer_id, string $queue_date): array
{
    $stmt = $db->prepare(
        queue_entry_select() . '
          WHERE q.freelancer_id = ? AND q.queue_date = ?
          ORDER BY q.seq DESC'
    );
    $stmt->execute([$freelancer_id, $queue_date]);
    return $stmt->fetchAll();
}

// --- writes -----------------------------------------------------------------
// Every function below expects to be called inside write_tx().

function queue_next_seq(PDO $db, int $freelancer_id, string $queue_date): int
{
    $stmt = $db->prepare(
        'SELECT COALESCE(MAX(seq), 0) + 1 AS next FROM queue_entries
          WHERE freelancer_id = ? AND queue_date = ?'
    );
    $stmt->execute([$freelancer_id, $queue_date]);
    return (int)$stmt->fetch()['next'];
}

function queue_next_sort_key(PDO $db, int $freelancer_id): float
{
    $stmt = $db->prepare(
        "SELECT COALESCE(MAX(sort_key), 0) AS top FROM queue_entries
          WHERE freelancer_id = ? AND status = 'waiting'"
    );
    $stmt->execute([$freelancer_id]);
    return (float)$stmt->fetch()['top'] + SORT_GAP;
}

function queue_insert_entry(
    PDO $db,
    int $freelancer_id,
    int $client_id,
    int $service_id,
    string $queue_date,
    string $ticket_no,
    int $seq,
    float $sort_key,
    ?string $note
): int {
    $stmt = $db->prepare(
        "INSERT INTO queue_entries
            (freelancer_id, client_id, service_id, queue_date, ticket_no, seq,
             sort_key, status, note, joined_at, notified_soon)
         VALUES (?, ?, ?, ?, ?, ?, ?, 'waiting', ?, ?, 0)"
    );
    $stmt->execute([$freelancer_id, $client_id, $service_id, $queue_date, $ticket_no, $seq, $sort_key, $note, utc_now()]);
    return (int)$db->lastInsertId();
}

/** waiting -> serving. Returns rows changed (0 means it was not waiting). */
function queue_start_serving(PDO $db, int $entry_id): int
{
    $stmt = $db->prepare(
        "UPDATE queue_entries SET status = 'serving', started_at = ?, notified_soon = 1
          WHERE id = ? AND status = 'waiting'"
    );
    $stmt->execute([utc_now(), $entry_id]);
    return $stmt->rowCount();
}

/** serving -> done, snapshotting the price actually charged. */
function queue_finish_entry(PDO $db, int $entry_id, ?float $price_charged): int
{
    $stmt = $db->prepare(
        "UPDATE queue_entries SET status = 'done', ended_at = ?, price_charged = ?
          WHERE id = ? AND status = 'serving'"
    );
    $stmt->execute([utc_now(), $price_charged, $entry_id]);
    return $stmt->rowCount();
}

/** serving -> waiting, sent to the back. Clears started_at (the wait resumes). */
function queue_requeue_entry(PDO $db, int $entry_id, float $sort_key): int
{
    $stmt = $db->prepare(
        "UPDATE queue_entries
            SET status = 'waiting', started_at = NULL, sort_key = ?, notified_soon = 0
          WHERE id = ? AND status = 'serving'"
    );
    $stmt->execute([$sort_key, $entry_id]);
    return $stmt->rowCount();
}

/** Move an entry to a terminal status from one of $from_statuses. */
function queue_close_entry(PDO $db, int $entry_id, string $status, array $from_statuses): int
{
    $placeholders = implode(',', array_fill(0, count($from_statuses), '?'));
    $stmt = $db->prepare(
        "UPDATE queue_entries SET status = ?, ended_at = ?
          WHERE id = ? AND status IN ({$placeholders})"
    );
    $stmt->execute([$status, utc_now(), $entry_id, ...array_values($from_statuses)]);
    return $stmt->rowCount();
}

function queue_set_sort_key(PDO $db, int $entry_id, float $sort_key): void
{
    $stmt = $db->prepare('UPDATE queue_entries SET sort_key = ? WHERE id = ?');
    $stmt->execute([$sort_key, $entry_id]);
}

/** The adjacent waiting entry above ('up') or below ('down') this one. */
function queue_neighbour(PDO $db, array $entry, string $direction): ?array
{
    if ($direction === 'up') {
        $sql = "SELECT id, sort_key FROM queue_entries
                 WHERE freelancer_id = ? AND status = 'waiting' AND sort_key < ?
                 ORDER BY sort_key DESC LIMIT 1";
    } else {
        $sql = "SELECT id, sort_key FROM queue_entries
                 WHERE freelancer_id = ? AND status = 'waiting' AND sort_key > ?
                 ORDER BY sort_key ASC LIMIT 1";
    }
    $stmt = $db->prepare($sql);
    $stmt->execute([$entry['freelancer_id'], $entry['sort_key']]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/** The entry one beyond the immediate neighbour - the far side of the slot. */
function queue_second_neighbour(PDO $db, array $entry, string $direction): ?array
{
    if ($direction === 'up') {
        $sql = "SELECT id, sort_key FROM queue_entries
                 WHERE freelancer_id = ? AND status = 'waiting' AND sort_key < ?
                 ORDER BY sort_key DESC LIMIT 1 OFFSET 1";
    } else {
        $sql = "SELECT id, sort_key FROM queue_entries
                 WHERE freelancer_id = ? AND status = 'waiting' AND sort_key > ?
                 ORDER BY sort_key ASC LIMIT 1 OFFSET 1";
    }
    $stmt = $db->prepare($sql);
    $stmt->execute([$entry['freelancer_id'], $entry['sort_key']]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/** Rewrite waiting sort_keys as 1024, 2048, ... restoring the gaps. */
function queue_renormalize_sort_keys(PDO $db, int $freelancer_id): void
{
    $stmt = $db->prepare(
        "SELECT id FROM queue_entries
          WHERE freelancer_id = ? AND status = 'waiting' ORDER BY sort_key"
    );
    $stmt->execute([$freelancer_id]);
    $rows = $stmt->fetchAll();
    $upd = $db->prepare('UPDATE queue_entries SET sort_key = ? WHERE id = ?');
    foreach ($rows as $i => $r) {
        $upd->execute([($i + 1) * SORT_GAP, $r['id']]);
    }
}

/** Waiting entries now close enough to the front to warrant a heads-up. */
function queue_waiting_needing_soon_notice(PDO $db, int $freelancer_id, int $threshold): array
{
    $stmt = $db->prepare(
        "SELECT * FROM (
            SELECT q.*, s.name AS service_name,
                   c.full_name AS client_name, c.email AS client_email,
                   f.full_name AS freelancer_name,
                   ROW_NUMBER() OVER (ORDER BY q.sort_key) AS position
              FROM queue_entries q
              JOIN services s ON s.id = q.service_id
              JOIN users    c ON c.id = q.client_id
              JOIN users    f ON f.id = q.freelancer_id
             WHERE q.freelancer_id = ? AND q.status = 'waiting'
         ) ranked
          WHERE position <= ? AND notified_soon = 0"
    );
    $stmt->execute([$freelancer_id, $threshold]);
    return $stmt->fetchAll();
}

function queue_mark_notified_soon(PDO $db, array $entry_ids): void
{
    if (!$entry_ids) {
        return;
    }
    $upd = $db->prepare('UPDATE queue_entries SET notified_soon = 1 WHERE id = ?');
    foreach ($entry_ids as $id) {
        $upd->execute([$id]);
    }
}
