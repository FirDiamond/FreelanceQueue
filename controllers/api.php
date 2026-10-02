<?php
// JSON endpoints for the pollers.
//
// Read-only by design. Every mutation goes through a form POST elsewhere, so
// nothing here can change state - which removes a whole class of CSRF concern
// from the polling layer and means a stale tab can never act.
//
// Both endpoints derive their subject from the session:
//   * /api/queue takes no id at all - a freelancer only ever sees their own.
//   * /api/ticket/{id} does take one, so it goes through require_owner, which
//     404s rather than 403s. Ticket ids are small integers; a 403 would confirm
//     that some other client holds the one you guessed.
//
// started_at is emitted through iso_utc(): the stored DATETIME is UTC wall-clock
// with no zone marker, and poll.js's elapsed timer needs an ISO-8601 instant.

declare(strict_types=1);

function ctrl_api_queue_state(): void
{
    role_required('freelancer');
    $db = db();
    $me = ctrl_me();

    $serving = queue_get_serving($db, $me);
    $waiting = queue_list_waiting($db, $me);

    $ids = array_map(static fn($r) => (int)$r['service_id'], $waiting);
    if ($serving !== null) {
        $ids[] = (int)$serving['service_id'];
    }
    $estimator = est_build_estimator($db, $ids);

    $stats = queue_today_stats($db, $me, local_date_str());
    $settings = settings();

    json_out([
        'signature'     => qs_state_signature($serving, $waiting, $stats['done_count']),
        'waiting_count' => count($waiting),
        'serving'       => $serving === null ? null : [
            'ticket_no'      => $serving['ticket_no'],
            'client_name'    => $serving['client_name'],
            'service_name'   => $serving['service_name'],
            'started_at'     => iso_utc($serving['started_at']),
            'remaining_text' => fmt_minutes(est_remaining_for_serving($serving, $estimator)),
        ],
        // Pre-formatted here rather than in poll.js: the currency and rounding
        // rules live in Settings, and a second implementation in JavaScript
        // would eventually disagree with the server-rendered page beside it.
        'stats' => [
            'done_count'    => $stats['done_count'] ?: 0,
            'earned_text'   => $settings->formatMoney($stats['earned'] ?: 0),
            'avg_wait_text' => fmt_minutes($stats['avg_wait_minutes']),
        ],
    ]);
}

function ctrl_api_ticket_state(int $entry_id): void
{
    role_required('client');
    $db = db();
    $entry = require_owner(queue_get_entry($db, $entry_id));

    $freelancer_id = (int)$entry['freelancer_id'];
    $serving  = queue_get_serving($db, $freelancer_id);
    $position = queue_position_of($db, $entry);
    $eta      = est_eta_for_entry($db, $entry, $serving);

    json_out([
        'status'          => $entry['status'],
        'status_label'    => STATUS_LABELS[$entry['status']] ?? $entry['status'],
        'ticket_no'       => $entry['ticket_no'],
        'position'        => $position,
        'phrase'          => est_status_phrase($position, $serving !== null),
        'eta_minutes'     => $eta,
        'eta_text'        => $eta !== null ? fmt_minutes($eta) : null,
        'waiting_count'   => queue_count_waiting($db, $freelancer_id),
        'is_mine_serving' => $serving !== null && (int)$serving['id'] === $entry_id,
        'started_at'      => iso_utc($entry['started_at']),
        'serving_ticket'  => $serving === null ? null : $serving['ticket_no'],
    ]);
}
