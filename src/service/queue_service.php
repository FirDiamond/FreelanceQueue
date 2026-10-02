<?php
// The queue lifecycle - the only module allowed to change `status`.
//
// Every transition is written inside write_tx() and expressed as a conditional
// UPDATE (`... WHERE id = ? AND status = 'waiting'`), so it either happens
// exactly once or reports that it did not. That is what makes a double-clicked
// "Call next" or two open dashboard tabs safe: the second one changes zero rows
// and is told so, rather than corrupting the queue.
//
// Notifications are RETURNED as event records for the caller to dispatch,
// because deciding *who to email* belongs here while *how to email* does not.

declare(strict_types=1);

const DEFAULT_NOTIFY_THRESHOLD = 2;
const MAX_NOTE_LENGTH          = 280;

// Events the notifier knows how to render. Listed here because this module
// decides which ones fire.
const EV_JOINED    = 'queue_joined';
const EV_NEW_ENTRY = 'freelancer_new_entry';
const EV_ALMOST    = 'almost_your_turn';
const EV_STARTED   = 'turn_started';
const EV_COMPLETED = 'job_completed';
const EV_CANCELLED = 'entry_cancelled';
const EV_NO_SHOW   = 'no_show';
const EV_SKIPPED   = 'queue_cleared';

// Wording shown to users for each stored status. Terminal statuses read as the
// *outcome* rather than the database value.
const STATUS_LABELS = [
    'waiting'   => 'Waiting',
    'serving'   => 'Being served',
    'done'      => 'Completed',
    'skipped'   => 'Not reached',
    'no_show'   => 'No-show',
    'cancelled' => 'Cancelled',
];

/**
 * A refused action with a message fit to show the user. `entry_id` is set when
 * the refusal has somewhere useful to send them - "you already hold a ticket
 * here" is far better as a redirect than as an error.
 */
class QueueError extends RuntimeException
{
    public ?int $entry_id;

    public function __construct(string $message, ?int $entry_id = null)
    {
        parent::__construct($message);
        $this->entry_id = $entry_id;
    }
}

/** An event record: ['name' => string, 'entry_id' => int]. */
function qs_event(string $name, int $entry_id): array
{
    return ['name' => $name, 'entry_id' => $entry_id];
}

// --- shared helpers ---------------------------------------------------------

/** First initial of the freelancer's name, so tickets read 'A-014'. */
function qs_ticket_letter(?string $full_name): string
{
    foreach (str_split(trim((string)$full_name)) as $ch) {
        if (ctype_alpha($ch)) {
            return strtoupper($ch);
        }
    }
    return 'Q';
}

function qs_owned_entry(PDO $db, int $entry_id, int $freelancer_id): array
{
    $entry = queue_get_entry($db, $entry_id);
    if ($entry === null || (int)$entry['freelancer_id'] !== $freelancer_id) {
        throw new QueueError('That queue entry no longer exists.');
    }
    return $entry;
}

/**
 * Flag and collect anyone who has just come within `threshold` of the front.
 * Marking happens in the same transaction as the read, so re-evaluating the
 * queue a second later - which every mutation does - cannot double-send.
 *
 * @return array<int,array> event records
 */
function qs_soon_events(PDO $db, int $freelancer_id, int $threshold): array
{
    $rows = queue_waiting_needing_soon_notice($db, $freelancer_id, $threshold);
    if (!$rows) {
        return [];
    }
    $ids = array_map(static fn($r) => (int)$r['id'], $rows);
    queue_mark_notified_soon($db, $ids);
    return array_map(static fn($id) => qs_event(EV_ALMOST, $id), $ids);
}

// --- client actions ---------------------------------------------------------

function qs_join_queue(
    PDO $db,
    int $client_id,
    int $freelancer_id,
    int $service_id,
    ?string $note = null,
    int $notify_threshold = DEFAULT_NOTIFY_THRESHOLD
): array {
    if ($client_id === $freelancer_id) {
        throw new QueueError('You cannot join your own queue.');
    }

    $freelancer = users_get_by_id($db, $freelancer_id);
    if ($freelancer === null || $freelancer['role'] !== 'freelancer') {
        throw new QueueError('That freelancer no longer exists.');
    }
    if (!$freelancer['queue_open']) {
        throw new QueueError($freelancer['full_name'] . "'s queue is closed right now.");
    }

    $service = services_get($db, $service_id);
    if ($service === null
        || (int)$service['freelancer_id'] !== $freelancer_id
        || !$service['is_active']) {
        throw new QueueError('That service is not available.');
    }

    $existing = queue_active_entry_for_client($db, $client_id, $freelancer_id);
    if ($existing !== null) {
        throw new QueueError('You already hold a ticket in this queue.', (int)$existing['id']);
    }

    $note = trim((string)$note);
    $note = $note === '' ? null : mb_substr($note, 0, MAX_NOTE_LENGTH);
    $queue_date = local_date_str();
    $letter = qs_ticket_letter($freelancer['full_name']);

    // Two clients tapping Join in the same instant both compute the same next
    // seq; the UNIQUE index rejects the loser, who simply recomputes. Bounded
    // at two attempts because a second collision would mean something else is
    // wrong, and silently looping is worse than surfacing it.
    $entry_id = 0;
    $events = [];
    for ($attempt = 1; $attempt <= 2; $attempt++) {
        try {
            [$entry_id, $events] = write_tx(function (PDO $db) use (
                $freelancer_id, $client_id, $service_id, $queue_date, $letter, $note, $notify_threshold
            ) {
                $seq = queue_next_seq($db, $freelancer_id, $queue_date);
                $id = queue_insert_entry(
                    $db,
                    $freelancer_id,
                    $client_id,
                    $service_id,
                    $queue_date,
                    sprintf('%s-%03d', $letter, $seq),
                    $seq,
                    queue_next_sort_key($db, $freelancer_id),
                    $note
                );
                $evs = [qs_event(EV_JOINED, $id), qs_event(EV_NEW_ENTRY, $id)];
                foreach (qs_soon_events($db, $freelancer_id, $notify_threshold) as $ev) {
                    if ($ev['entry_id'] !== $id) { // the join email already says this
                        $evs[] = $ev;
                    }
                }
                return [$id, $evs];
            });
            break;
        } catch (PDOException $e) {
            if (!is_unique_violation($e) || $attempt === 2) {
                if (is_unique_violation($e)) {
                    throw new QueueError('The queue is busy - please try again.');
                }
                throw $e;
            }
        }
    }

    return ['entry' => queue_get_entry($db, $entry_id), 'events' => $events];
}

/**
 * Client withdraws. Only from `waiting` - once you are being served it is the
 * freelancer's call how the job ends.
 */
function qs_cancel_entry(PDO $db, int $entry_id, int $client_id, int $notify_threshold = DEFAULT_NOTIFY_THRESHOLD): array
{
    $entry = queue_get_entry($db, $entry_id);
    if ($entry === null || (int)$entry['client_id'] !== $client_id) {
        throw new QueueError('That ticket no longer exists.');
    }
    if ($entry['status'] === 'serving') {
        throw new QueueError('You are being served right now - talk to the freelancer.');
    }
    if ($entry['status'] !== 'waiting') {
        throw new QueueError('That ticket is already closed.');
    }

    $events = write_tx(function (PDO $db) use ($entry_id, $entry, $notify_threshold) {
        if (!queue_close_entry($db, $entry_id, 'cancelled', ['waiting'])) {
            throw new QueueError('That ticket was just updated - reload the page.');
        }
        $evs = [qs_event(EV_CANCELLED, $entry_id)];
        return array_merge($evs, qs_soon_events($db, (int)$entry['freelancer_id'], $notify_threshold));
    });

    return ['entry' => queue_get_entry($db, $entry_id), 'events' => $events];
}

// --- freelancer actions -----------------------------------------------------

function qs_call_next(PDO $db, int $freelancer_id, int $notify_threshold = DEFAULT_NOTIFY_THRESHOLD): array
{
    if (queue_get_serving($db, $freelancer_id) !== null) {
        throw new QueueError('Finish or skip the current client first.');
    }

    try {
        [$entry_id, $events] = write_tx(function (PDO $db) use ($freelancer_id, $notify_threshold) {
            $waiting = queue_list_waiting($db, $freelancer_id);
            if (!$waiting) {
                throw new QueueError('Nobody is waiting.');
            }
            $id = (int)$waiting[0]['id'];
            if (!queue_start_serving($db, $id)) {
                throw new QueueError('The queue just changed - try again.');
            }
            $evs = [qs_event(EV_STARTED, $id)];
            return [$id, array_merge($evs, qs_soon_events($db, $freelancer_id, $notify_threshold))];
        });
    } catch (PDOException $e) {
        // The unique index on serving_key fired: another tab or a double-click
        // got there first. Not an error worth alarming anyone with.
        if (is_unique_violation($e)) {
            throw new QueueError('You are already serving someone.');
        }
        throw $e;
    }

    return ['entry' => queue_get_entry($db, $entry_id), 'events' => $events];
}

function qs_mark_done(
    PDO $db,
    int $entry_id,
    int $freelancer_id,
    ?float $price_charged = null,
    int $notify_threshold = DEFAULT_NOTIFY_THRESHOLD
): array {
    $entry = qs_owned_entry($db, $entry_id, $freelancer_id);
    if ($entry['status'] !== 'serving') {
        throw new QueueError('That client is not currently being served.');
    }

    // The service price can change later; what was charged must not.
    $price = $price_charged === null ? (float)$entry['service_price'] : (float)$price_charged;
    if ($price < 0) {
        throw new QueueError('Amount charged cannot be negative.');
    }

    $events = write_tx(function (PDO $db) use ($entry_id, $price, $freelancer_id, $notify_threshold) {
        if (!queue_finish_entry($db, $entry_id, $price)) {
            throw new QueueError('That entry was just updated - reload the page.');
        }
        $evs = [qs_event(EV_COMPLETED, $entry_id)];
        return array_merge($evs, qs_soon_events($db, $freelancer_id, $notify_threshold));
    });

    return ['entry' => queue_get_entry($db, $entry_id), 'events' => $events];
}

/**
 * Send the client being served back to the end of the queue. For the client
 * who stepped out for coffee: they keep their ticket and their place in the
 * day, just not at the front.
 */
function qs_skip_current(PDO $db, int $freelancer_id, int $notify_threshold = DEFAULT_NOTIFY_THRESHOLD): array
{
    $serving = queue_get_serving($db, $freelancer_id);
    if ($serving === null) {
        throw new QueueError('Nobody is being served.');
    }
    $entry_id = (int)$serving['id'];

    $events = write_tx(function (PDO $db) use ($entry_id, $freelancer_id, $notify_threshold) {
        $sort_key = queue_next_sort_key($db, $freelancer_id);
        if (!queue_requeue_entry($db, $entry_id, $sort_key)) {
            throw new QueueError('That entry was just updated - reload the page.');
        }
        return qs_soon_events($db, $freelancer_id, $notify_threshold);
    });

    return ['entry' => queue_get_entry($db, $entry_id), 'events' => $events];
}

function qs_mark_no_show(
    PDO $db,
    int $entry_id,
    int $freelancer_id,
    int $notify_threshold = DEFAULT_NOTIFY_THRESHOLD
): array {
    $entry = qs_owned_entry($db, $entry_id, $freelancer_id);
    if (!in_array($entry['status'], ['waiting', 'serving'], true)) {
        throw new QueueError('That ticket is already closed.');
    }

    $events = write_tx(function (PDO $db) use ($entry_id, $freelancer_id, $notify_threshold) {
        if (!queue_close_entry($db, $entry_id, 'no_show', ['waiting', 'serving'])) {
            throw new QueueError('That entry was just updated - reload the page.');
        }
        $evs = [qs_event(EV_NO_SHOW, $entry_id)];
        return array_merge($evs, qs_soon_events($db, $freelancer_id, $notify_threshold));
    });

    return ['entry' => queue_get_entry($db, $entry_id), 'events' => $events];
}

/**
 * End the day: everyone still waiting is closed as `skipped`. Deliberately its
 * own status - `no_show` would blame clients who did nothing wrong, and
 * `cancelled` would credit them with a decision they never made.
 */
function qs_clear_queue(PDO $db, int $freelancer_id): array
{
    $events = write_tx(function (PDO $db) use ($freelancer_id) {
        $evs = [];
        foreach (queue_list_waiting($db, $freelancer_id) as $row) {
            $entry_id = (int)$row['id'];
            if (queue_close_entry($db, $entry_id, 'skipped', ['waiting'])) {
                $evs[] = qs_event(EV_SKIPPED, $entry_id);
            }
        }
        return $evs;
    });

    return ['entry' => null, 'events' => $events];
}

/**
 * Move a waiting entry one place up or down. Writes a single midpoint sort_key
 * instead of renumbering, so two freelancer tabs reordering at once cannot
 * trade rows into the same slot.
 */
function qs_move_entry(
    PDO $db,
    int $entry_id,
    int $freelancer_id,
    string $direction,
    int $notify_threshold = DEFAULT_NOTIFY_THRESHOLD
): array {
    if ($direction !== 'up' && $direction !== 'down') {
        throw new QueueError('Unknown direction.');
    }

    $entry = qs_owned_entry($db, $entry_id, $freelancer_id);
    if ($entry['status'] !== 'waiting') {
        throw new QueueError('Only waiting entries can be reordered.');
    }

    $result = write_tx(function (PDO $db) use ($entry_id, $freelancer_id, $direction, $notify_threshold) {
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $entry = queue_get_entry($db, $entry_id);
            $adjacent = queue_neighbour($db, $entry, $direction);
            if ($adjacent === null) {
                // Already at the edge - a no-op, not a failure.
                return ['entry' => $entry, 'events' => []];
            }
            $beyond = queue_second_neighbour($db, $entry, $direction);

            if ($direction === 'up') {
                $high = (float)$adjacent['sort_key'];
                $low  = $beyond !== null ? (float)$beyond['sort_key'] : $high - 2 * SORT_GAP;
            } else {
                $low  = (float)$adjacent['sort_key'];
                $high = $beyond !== null ? (float)$beyond['sort_key'] : $low + 2 * SORT_GAP;
            }

            if ($high - $low >= MIN_SORT_GAP) {
                queue_set_sort_key($db, $entry_id, ($low + $high) / 2.0);
                break;
            }
            if ($attempt === 2) {
                throw new QueueError('Could not reorder the queue - please reload.');
            }
            // Gaps exhausted by repeated midpoint inserts: restore them and
            // recompute against the fresh keys.
            queue_renormalize_sort_keys($db, $freelancer_id);
        }
        return ['entry' => null, 'events' => qs_soon_events($db, $freelancer_id, $notify_threshold)];
    });

    if ($result['entry'] !== null) {
        return $result; // hit the edge no-op
    }
    return ['entry' => queue_get_entry($db, $entry_id), 'events' => $result['events']];
}

function qs_set_queue_open(PDO $db, int $freelancer_id, bool $is_open): void
{
    write_tx(function (PDO $db) use ($freelancer_id, $is_open) {
        users_set_queue_open($db, $freelancer_id, $is_open);
    });
}

/**
 * Digest of everything that changes the dashboard's *structure* - a pure
 * function over data the caller already has, so the page and the polling
 * endpoint compute it identically and the browser can tell whether a reload
 * would show anything new. Elapsed time and ETAs are deliberately excluded.
 */
function qs_state_signature(?array $serving, array $waiting, $done_count): string
{
    $ids = array_map(static fn($r) => (string)(int)$r['id'], $waiting);
    $raw = sprintf(
        '%d|%s|%d',
        $serving !== null ? (int)$serving['id'] : 0,
        implode(',', $ids),
        (int)($done_count ?? 0)
    );
    return substr(sha1($raw), 0, 16);
}

