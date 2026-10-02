<?php
// All SQL touching users and login_attempts.
//
// Nothing above this layer writes SQL. Every statement is a prepared statement
// with bound parameters - no user value is ever concatenated into SQL.

declare(strict_types=1);

function users_create(
    PDO $db,
    string $email,
    string $full_name,
    string $role,
    string $pwd_hash,
    ?string $headline = null
): int {
    $stmt = $db->prepare(
        'INSERT INTO users (email, full_name, role, pwd_hash, headline, queue_open, created_at)
         VALUES (?, ?, ?, ?, ?, 0, ?)'
    );
    $stmt->execute([trim($email), trim($full_name), $role, $pwd_hash, $headline, utc_now()]);
    return (int)$db->lastInsertId();
}

function users_get_by_email(PDO $db, string $email): ?array
{
    // utf8mb4_unicode_ci makes this match case-insensitively.
    $stmt = $db->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([trim($email)]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function users_get_by_id(PDO $db, int $user_id): ?array
{
    $stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$user_id]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function users_email_exists(PDO $db, string $email): bool
{
    $stmt = $db->prepare('SELECT 1 FROM users WHERE email = ?');
    $stmt->execute([trim($email)]);
    return $stmt->fetch() !== false;
}

function users_update_profile(PDO $db, int $user_id, string $full_name, ?string $headline): void
{
    $stmt = $db->prepare('UPDATE users SET full_name = ?, headline = ? WHERE id = ?');
    $stmt->execute([trim($full_name), $headline, $user_id]);
}

function users_set_queue_open(PDO $db, int $freelancer_id, bool $is_open): void
{
    $stmt = $db->prepare("UPDATE users SET queue_open = ? WHERE id = ? AND role = 'freelancer'");
    $stmt->execute([$is_open ? 1 : 0, $freelancer_id]);
}

/** Freelancers for the client browse page, open queues first. */
function users_list_freelancers(PDO $db, ?string $search = null): array
{
    $sql = "SELECT u.id, u.full_name, u.headline, u.queue_open,
                   (SELECT COUNT(*) FROM queue_entries q
                     WHERE q.freelancer_id = u.id AND q.status = 'waiting') AS waiting_count,
                   (SELECT COUNT(*) FROM services s
                     WHERE s.freelancer_id = u.id AND s.is_active = 1) AS service_count
              FROM users u
             WHERE u.role = 'freelancer'";
    $params = [];
    if ($search !== null && $search !== '') {
        $sql .= " AND (u.full_name LIKE ? OR IFNULL(u.headline, '') LIKE ?)";
        $like = '%' . trim($search) . '%';
        $params[] = $like;
        $params[] = $like;
    }
    $sql .= ' ORDER BY u.queue_open DESC, u.full_name';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// --- login throttling -------------------------------------------------------
// Recorded in the database so the limit survives a restart and applies across
// every worker process.

function users_record_login_attempt(PDO $db, string $email, ?string $ip, bool $success): void
{
    $stmt = $db->prepare(
        'INSERT INTO login_attempts (email, ip, attempted_at, success) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([trim($email), $ip, utc_now(), $success ? 1 : 0]);
}

/** Failed attempts since a cutoff, counted for this email OR this IP. */
function users_count_recent_failures(PDO $db, string $email, ?string $ip, string $since): int
{
    $stmt = $db->prepare(
        'SELECT COUNT(*) AS n FROM login_attempts
          WHERE success = 0 AND attempted_at >= ?
            AND (email = ? OR (ip IS NOT NULL AND ip = ?))'
    );
    $stmt->execute([$since, trim($email), $ip]);
    return (int)$stmt->fetch()['n'];
}

/** Drop failure history after a success so the user is not still locked out. */
function users_clear_login_failures(PDO $db, string $email, ?string $ip = null): void
{
    $stmt = $db->prepare(
        'DELETE FROM login_attempts
          WHERE success = 0 AND (email = ? OR (ip IS NOT NULL AND ip = ?))'
    );
    $stmt->execute([trim($email), $ip]);
}
