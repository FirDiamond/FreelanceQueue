<?php
// Demo data, so the app has something real to show on first run.
//
// The Python original (seed.py) seeded a SQLite file; this is the MySQL port.
// The RNG is seeded with a constant so every `php seed.php --reset` produces the
// same dataset - a KPI tile that then changes is a regression, not noise. Note
// that PHP's RNG is not Python's, so the exact totals differ from seed.py's
// "45 jobs / Rp 18,900,000"; this script prints the totals it actually made.
//
// This is the one place that writes SQL outside src/repo/*. The reason is
// narrow: every repo write stamps utc_now(), because production code has no
// business inventing timestamps in the past - and seeding history is exactly
// that, so it does its own INSERT.

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

const SEED_PASSWORD      = 'demo1234';
const SEED_TICKET_LETTER = 'M';
const SEED_HISTORY_DAYS  = 28;

// Arrivals cluster mid-morning and mid-afternoon, which gives the heatmap a
// shape instead of an even wash.
const SEED_ARRIVAL_HOURS = [9, 10, 10, 11, 11, 13, 14, 14, 15, 15, 16];

$SEED_FREELANCER = [
    'email'     => 'maya@example.com',
    'full_name' => 'Maya Putri',
    'headline'  => 'Brand and product design - logos, audits and honest feedback',
];

$SEED_CLIENTS = [
    ['email' => 'arif@example.com',  'full_name' => 'Arif Rahman'],
    ['email' => 'sinta@example.com', 'full_name' => 'Sinta Dewi'],
    ['email' => 'budi@example.com',  'full_name' => 'Budi Santoso'],
];

// Deliberately different durations: the ETA maths only means something if a
// 90-minute audit ahead of you costs more than a 20-minute review.
$SEED_SERVICES = [
    ['name' => 'Quick design review',  'price' => 150000, 'est_minutes' => 20],
    ['name' => 'Portfolio feedback',   'price' => 250000, 'est_minutes' => 30],
    ['name' => 'Logo concept session', 'price' => 450000, 'est_minutes' => 60],
    ['name' => 'Website audit',        'price' => 900000, 'est_minutes' => 90],
];

// --- deterministic RNG helpers (mt_* seeded once in seed_main) --------------

/** Uniform float in [0, 1), like Python's random.random(). */
function seed_random(): float
{
    return mt_rand() / (mt_getrandmax() + 1);
}

/** Uniform float in [$a, $b), like Python's random.uniform() (near enough). */
function seed_uniform(float $a, float $b): float
{
    return $a + ($b - $a) * seed_random();
}

/** Random int in [$a, $b), like Python's random.randrange(a, b). */
function seed_randrange(int $a, int $b): int
{
    return $a + (int)floor(seed_random() * ($b - $a));
}

/** A random element of a 0-indexed list, like Python's random.choice(). */
function seed_choice(array $items)
{
    return $items[seed_randrange(0, count($items))];
}

/** A local wall-clock moment as the UTC string every *_at column stores. */
function seed_store(DateTimeImmutable $local): string
{
    return $local->setTimezone(utc_zone())->format(STORE_FMT);
}

// --- schema / outbox management --------------------------------------------

/** True if $table exists in the configured database. */
function seed_table_exists(PDO $db, string $table): bool
{
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema = DATABASE() AND table_name = ?'
    );
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

/** Drop every app table (FK order made irrelevant), then recreate from schema.sql. */
function seed_reset_schema(PDO $db): void
{
    $db->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (['login_attempts', 'email_log', 'queue_entries',
              'business_hours', 'services', 'users'] as $table) {
        $db->exec("DROP TABLE IF EXISTS {$table}");
    }
    $db->exec('SET FOREIGN_KEY_CHECKS = 1');
    run_schema();
}

/** Empty the file outbox; email_log lives in the DB, so the two reset together. */
function seed_empty_outbox(): int
{
    $removed = 0;
    foreach (glob(OUTBOX_DIR . '/*.eml') ?: [] as $file) {
        if (@unlink($file)) {
            $removed++;
        }
    }
    return $removed;
}

function seed_user_count(PDO $db): int
{
    return (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn();
}

/** Direct INSERT of a history row, bypassing the repo's utc_now() stamping. */
function seed_insert_entry(PDO $db, array $row): void
{
    static $stmt = null;
    if ($stmt === null) {
        $stmt = $db->prepare(
            'INSERT INTO queue_entries
                (freelancer_id, client_id, service_id, queue_date, ticket_no, seq,
                 sort_key, status, note, price_charged, joined_at, started_at,
                 ended_at, notified_soon)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
    }
    $stmt->execute([
        $row['freelancer_id'], $row['client_id'], $row['service_id'],
        $row['queue_date'], $row['ticket_no'], $row['seq'], $row['sort_key'],
        $row['status'], $row['note'], $row['price_charged'], $row['joined_at'],
        $row['started_at'], $row['ended_at'], $row['notified_soon'],
    ]);
}

// --- seeding ----------------------------------------------------------------

/** Create the freelancer and clients via the real signup path. */
function seed_create_accounts(PDO $db, array $freelancer, array $clients): array
{
    $created_freelancer = auth_signup(
        $db, $freelancer['email'], $freelancer['full_name'], 'freelancer',
        SEED_PASSWORD, SEED_PASSWORD, $freelancer['headline']
    );
    $created_clients = [];
    foreach ($clients as $client) {
        $created_clients[] = auth_signup(
            $db, $client['email'], $client['full_name'], 'client',
            SEED_PASSWORD, SEED_PASSWORD
        );
    }
    return [$created_freelancer, $created_clients];
}

/** Create services and set the working week. Returns rows with their new ids. */
function seed_create_services(PDO $db, int $freelancer_id, array $services): array
{
    return write_tx(function (PDO $db) use ($freelancer_id, $services) {
        $created = [];
        foreach ($services as $service) {
            $id = services_create(
                $db, $freelancer_id, $service['name'],
                (float)$service['price'], $service['est_minutes']
            );
            $created[] = $service + ['id' => $id];
        }
        // Signup seeded Mon-Fri 09:00-17:00. A half-day Saturday gives the
        // availability editor and the heatmap something to disagree about;
        // Sunday keeps its typed times while closed (unticking never erases).
        $week = [];
        for ($weekday = 0; $weekday < 5; $weekday++) {
            $week[] = [$weekday, true, '09:00', '17:00'];
        }
        $week[] = [5, true, '10:00', '14:00'];
        $week[] = [6, false, '09:00', '17:00'];
        services_save_business_hours($db, $freelancer_id, $week);
        return $created;
    });
}

/** Completed days, oldest first. Returns how many entries were written. */
function seed_history(PDO $db, int $freelancer_id, array $clients, array $services): int
{
    $today = new DateTimeImmutable('today', local_zone());
    $written = 0;

    write_tx(function (PDO $db) use ($today, $freelancer_id, $clients, $services, &$written) {
        for ($days_back = SEED_HISTORY_DAYS; $days_back > 0; $days_back--) {
            $day = $today->sub(new DateInterval('P' . $days_back . 'D'));
            $weekday = (int)$day->format('N') - 1; // 0 = Monday
            if ($weekday === 6) { // Sunday: closed.
                continue;
            }
            // Saturdays are the half day, so they are quieter.
            $count = seed_choice($weekday === 5 ? [1, 1, 2] : [1, 2, 2, 3]);

            $seq = 0;
            for ($n = 0; $n < $count; $n++) {
                $seq++;
                $service = seed_choice($services);
                $client  = seed_choice($clients);

                $joined = $day->setTime(seed_choice(SEED_ARRIVAL_HOURS), seed_randrange(0, 60));

                $roll = seed_random();
                $note = null;
                if ($roll < 0.07) {
                    $status  = 'cancelled';
                    $started = null;
                    $ended   = $joined->add(new DateInterval('PT' . seed_randrange(4, 25) . 'M'));
                    $price   = null;
                } elseif ($roll < 0.14) {
                    $status  = 'no_show';
                    $started = null;
                    $ended   = $joined->add(new DateInterval('PT' . seed_randrange(15, 45) . 'M'));
                    $price   = null;
                } else {
                    $started = $joined->add(new DateInterval('PT' . seed_randrange(3, 40) . 'M'));
                    // Real jobs run over and under quote; the 0.7/0.3 blend in
                    // estimate_service only means something if they do.
                    $actual  = (int)round($service['est_minutes'] * seed_uniform(0.75, 1.35));
                    $ended   = $started->add(new DateInterval('PT' . max(1, $actual) . 'M'));
                    $status  = 'done';
                    $price   = (float)$service['price'];
                }

                seed_insert_entry($db, [
                    'freelancer_id' => $freelancer_id,
                    'client_id'     => (int)$client['id'],
                    'service_id'    => $service['id'],
                    'queue_date'    => $day->format('Y-m-d'),
                    'ticket_no'     => sprintf('%s-%03d', SEED_TICKET_LETTER, $seq),
                    'seq'           => $seq,
                    'sort_key'      => $seq * 1024.0,
                    'status'        => $status,
                    'note'          => $note,
                    'price_charged' => $price,
                    'joined_at'     => seed_store($joined),
                    'started_at'    => $started === null ? null : seed_store($started),
                    'ended_at'      => $ended === null ? null : seed_store($ended),
                    'notified_soon' => 1,
                ]);
                $written++;
            }
        }
    });
    return $written;
}

/** A live queue for today: one job in progress and two people waiting. */
function seed_today(PDO $db, int $freelancer_id, array $clients, array $services): void
{
    $now   = new DateTimeImmutable('now', local_zone());
    $today = $now->format('Y-m-d');
    $by_name = [];
    foreach ($services as $service) {
        $by_name[$service['name']] = $service;
    }

    write_tx(function (PDO $db) use ($now, $today, $freelancer_id, $clients, $by_name) {
        $stmt = $db->prepare('UPDATE users SET queue_open = 1 WHERE id = ?');
        $stmt->execute([$freelancer_id]);

        seed_insert_entry($db, [
            'freelancer_id' => $freelancer_id,
            'client_id'     => (int)$clients[0]['id'],
            'service_id'    => $by_name['Logo concept session']['id'],
            'queue_date'    => $today,
            'ticket_no'     => sprintf('%s-001', SEED_TICKET_LETTER),
            'seq'           => 1,
            'sort_key'      => 1024.0,
            'status'        => 'serving',
            'note'          => 'Wants three directions to choose from',
            'price_charged' => null,
            'joined_at'     => seed_store($now->sub(new DateInterval('PT38M'))),
            'started_at'    => seed_store($now->sub(new DateInterval('PT12M'))),
            'ended_at'      => null,
            'notified_soon' => 1,
        ]);

        $waiting = [
            [$clients[1], 'Quick design review', 21, 'Just the homepage'],
            [$clients[2], 'Website audit',        9,  null],
        ];
        foreach ($waiting as $offset => [$client, $service_name, $minutes_ago, $note]) {
            seed_insert_entry($db, [
                'freelancer_id' => $freelancer_id,
                'client_id'     => (int)$client['id'],
                'service_id'    => $by_name[$service_name]['id'],
                'queue_date'    => $today,
                'ticket_no'     => sprintf('%s-%03d', SEED_TICKET_LETTER, $offset + 2),
                'seq'           => $offset + 2,
                'sort_key'      => ($offset + 2) * 1024.0,
                'status'        => 'waiting',
                'note'          => $note,
                'price_charged' => null,
                'joined_at'     => seed_store($now->sub(new DateInterval('PT' . $minutes_ago . 'M'))),
                'started_at'    => null,
                'ended_at'      => null,
                'notified_soon' => 0,
            ]);
        }
    });
}

// --- entry point ------------------------------------------------------------

function seed_main(array $argv): int
{
    if (!IS_CLI) {
        fwrite(STDERR, "seed.php is a command-line script.\n");
        return 1;
    }

    global $SEED_FREELANCER, $SEED_CLIENTS, $SEED_SERVICES;
    $reset = in_array('--reset', $argv, true);
    $s = settings();

    // The outbox directory must exist for the app; the reset also empties it.
    if (!is_dir(OUTBOX_DIR)) {
        @mkdir(OUTBOX_DIR, 0775, true);
    }

    $db = db();

    if ($reset) {
        echo "Resetting {$s->db_name} on {$s->db_host}:{$s->db_port}\n";
        seed_reset_schema($db);
        $removed = seed_empty_outbox();
        if ($removed) {
            echo "  removed {$removed} message(s) from " . OUTBOX_DIR . "\n";
        }
    } elseif (!seed_table_exists($db, 'users')) {
        // Fresh database with no schema yet: build it, mirroring create_app().
        echo "Creating schema in {$s->db_name}\n";
        run_schema();
    }

    if (seed_user_count($db) > 0) {
        echo "{$s->db_name} already has accounts in it. Nothing was changed.\n";
        echo "Run `php seed.php --reset` to wipe it and start over.\n";
        return 1;
    }

    mt_srand(20260911); // Fixed: the demo must be reproducible.

    [$freelancer, $clients] = seed_create_accounts($db, $SEED_FREELANCER, $SEED_CLIENTS);
    $services = seed_create_services($db, (int)$freelancer['id'], $SEED_SERVICES);
    $history  = seed_history($db, (int)$freelancer['id'], $clients, $services);
    seed_today($db, (int)$freelancer['id'], $clients, $services);

    // Totals actually produced. They differ from seed.py's figures because
    // PHP's RNG is not Python's (see the file header) - so this prints what it
    // made rather than repeating a number that no longer holds.
    $done = (int)$db->query("SELECT COUNT(*) FROM queue_entries WHERE status = 'done'")
        ->fetchColumn();
    $earned = (float)$db->query(
        "SELECT COALESCE(SUM(price_charged), 0) FROM queue_entries WHERE status = 'done'"
    )->fetchColumn();

    echo "Seeded {$history} past entries plus a live queue for today.\n\n";
    echo sprintf("  Freelancer  %s  /  %s\n", $SEED_FREELANCER['email'], SEED_PASSWORD);
    foreach ($SEED_CLIENTS as $client) {
        echo sprintf("  Client      %s  /  %s\n", $client['email'], SEED_PASSWORD);
    }
    echo "\n";
    echo sprintf("  Jobs completed  %d\n", $done);
    echo sprintf("  Total earned    %s\n", $s->formatMoney($earned));
    echo sprintf("  Database        %s@%s:%d/%s\n",
        $s->db_user, $s->db_host, $s->db_port, $s->db_name);
    echo sprintf("  Outbox          %s\n", OUTBOX_DIR);
    echo "\n";
    echo "Serve this folder through Apache (see README) and open {$s->base_url}\n";
    return 0;
}

exit(seed_main($argv));


