<?php
// Client-facing pages: find a freelancer, join a queue, watch your ticket.
//
// The ticket page is the one screen a client keeps open, so it renders correct
// without JavaScript and merely fresher with it - poll.js patches position and
// ETA in place, but everything it patches is already in the HTML.

declare(strict_types=1);

/** notify_when_ahead threshold from config. Shared by the queue controllers. */
function ctrl_threshold(): int
{
    return settings()->notify_when_ahead;
}

/** The current user's id. Shared by the client/freelancer/api controllers. */
function ctrl_me(): int
{
    return (int)current_user()['id'];
}

function ctrl_client_browse(): void
{
    role_required('client');
    $db = db();
    $search = trim((string)($_GET['q'] ?? ''));
    render('client/browse', [
        'title'       => 'Find a freelancer',
        'freelancers' => users_list_freelancers($db, $search !== '' ? $search : null),
        'search'      => $search,
        'active'      => queue_active_entry_for_client($db, ctrl_me()),
    ]);
}

function ctrl_client_freelancer_detail(int $freelancer_id): void
{
    role_required('client');
    $db = db();
    $freelancer = users_get_by_id($db, $freelancer_id);
    if ($freelancer === null || $freelancer['role'] !== 'freelancer') {
        abort(404);
    }

    $services = services_list($db, $freelancer_id, true);
    $serving  = queue_get_serving($db, $freelancer_id);
    $waiting  = queue_list_waiting($db, $freelancer_id);

    // "If I joined now, how long?" - the wait ahead of a new arrival is the
    // whole current queue, then their own service on top.
    $ids = array_map(static fn($r) => (int)$r['service_id'], $waiting);
    foreach ($services as $s) {
        $ids[] = (int)$s['id'];
    }
    if ($serving !== null) {
        $ids[] = (int)$serving['service_id'];
    }
    $estimator = est_build_estimator($db, $ids);

    $wait_if_joining = est_remaining_for_serving($serving, $estimator);
    foreach ($waiting as $row) {
        $wait_if_joining += $estimator->duration((int)$row['service_id']);
    }

    render('client/freelancer', [
        'title'            => $freelancer['full_name'],
        'freelancer'       => $freelancer,
        'services'         => $services,
        'serving'          => $serving,
        'waiting_count'    => count($waiting),
        'wait_if_joining'  => $wait_if_joining,
        'hours'            => services_get_business_hours($db, $freelancer_id),
        'weekday_names'    => WEEKDAY_NAMES,
        'today_weekday'    => local_weekday(),
        'mine'             => queue_active_entry_for_client($db, ctrl_me(), $freelancer_id),
        'active_elsewhere' => queue_active_entry_for_client($db, ctrl_me()),
    ]);
}

function ctrl_client_join(int $freelancer_id): void
{
    role_required('client');

    $raw = trim((string)($_POST['service_id'] ?? ''));
    if ($raw === '' || !ctype_digit($raw)) {
        flash('Pick a service first.', 'error');
        redirect(url('client.freelancer_detail', ['freelancer_id' => $freelancer_id]));
    }
    $service_id = (int)$raw;

    try {
        $result = qs_join_queue(
            db(),
            ctrl_me(),
            $freelancer_id,
            $service_id,
            $_POST['note'] ?? null,
            ctrl_threshold()
        );
    } catch (QueueError $e) {
        flash($e->getMessage(), 'error');
        if ($e->entry_id) {
            // They already hold a ticket here - showing it beats explaining it.
            redirect(url('client.ticket', ['entry_id' => $e->entry_id]));
        }
        redirect(url('client.freelancer_detail', ['freelancer_id' => $freelancer_id]));
    }

    notify_dispatch(db(), $result['events']);
    flash("You're in - ticket {$result['entry']['ticket_no']}. We'll email you when you're nearly up.", 'success');
    redirect(url('client.ticket', ['entry_id' => (int)$result['entry']['id']]));
}

function ctrl_client_tickets(): void
{
    role_required('client');
    render('client/tickets', [
        'title'   => 'My tickets',
        'entries' => queue_list_client_entries(db(), ctrl_me()),
    ]);
}

function ctrl_client_ticket(int $entry_id): void
{
    role_required('client');
    $db = db();
    $entry = require_owner(queue_get_entry($db, $entry_id));

    $freelancer_id = (int)$entry['freelancer_id'];
    $serving  = queue_get_serving($db, $freelancer_id);
    $position = queue_position_of($db, $entry);
    $eta      = est_eta_for_entry($db, $entry, $serving);

    render('client/ticket', [
        'title'           => 'Ticket ' . $entry['ticket_no'],
        'container_class' => 'narrow',
        'entry'           => $entry,
        'serving'         => $serving,
        'is_mine_serving' => $serving !== null && (int)$serving['id'] === $entry_id,
        'position'        => $position,
        'eta_minutes'     => $eta,
        'phrase'          => est_status_phrase($position, $serving !== null),
        'waiting_count'   => queue_count_waiting($db, $freelancer_id),
    ]);
}

function ctrl_client_cancel(int $entry_id): void
{
    role_required('client');
    try {
        $result = qs_cancel_entry(db(), $entry_id, ctrl_me(), ctrl_threshold());
    } catch (QueueError $e) {
        flash($e->getMessage(), 'error');
        redirect(url('client.ticket', ['entry_id' => $entry_id]));
    }

    notify_dispatch(db(), $result['events']);
    flash("Ticket cancelled. You've left the queue.", 'success');
    redirect(url('client.tickets'));
}
