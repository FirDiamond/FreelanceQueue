<?php
// Freelancer-facing pages: dashboard, queue actions, services, availability,
// reports.
//
// Every mutation is a plain form POST that redirects, not a fetch: the queue
// keeps working with JavaScript disabled, and a redirect means a double-tapped
// button re-issues a GET rather than a second action. JavaScript here only ever
// READS (see controllers/api.php). No SQL and no business rules in this file -
// it parses forms, calls the service layer, and turns QueueError into a flash.

declare(strict_types=1);

const MAX_SERVICE_NAME = 80;
const MAX_PRICE        = 1000000000;
const MAX_EST_MINUTES  = 8 * 60;
const FREELANCER_TIME_RE = '/^([01]\d|2[0-3]):([0-5]\d)$/';

// --- dashboard --------------------------------------------------------------

function ctrl_freelancer_dashboard(): void
{
    role_required('freelancer');
    $db = db();
    $user = current_user();
    $me = (int)$user['id'];

    $serving = queue_get_serving($db, $me);
    $waiting = queue_list_waiting($db, $me);

    $service_ids = array_map(static fn($r) => (int)$r['service_id'], $waiting);
    if ($serving !== null) {
        $service_ids[] = (int)$serving['service_id'];
    }
    $estimator = est_build_estimator($db, $service_ids);

    $today = local_date_str();
    $stats = queue_today_stats($db, $me, $today);
    add_script('/assets/js/ascii.js');
    render('freelancer/dashboard', [
        'title'             => 'Dashboard',
        'user'              => $user,
        'serving'           => $serving,
        'serving_remaining' => est_remaining_for_serving($serving, $estimator),
        'waiting'           => est_annotate_waiting($waiting, $serving, $estimator),
        'waiting_count'     => count($waiting),
        'stats'             => $stats,
        'signature'         => qs_state_signature($serving, $waiting, $stats['done_count']),
        'log'               => queue_list_today($db, $me, $today),
        'services'          => services_list($db, $me, true),
        'today'             => $today,
    ]);
}

function ctrl_freelancer_set_queue_open(): void
{
    role_required('freelancer');
    $is_open = ($_POST['open'] ?? null) === '1';
    qs_set_queue_open(db(), ctrl_me(), $is_open);
    flash(
        $is_open
            ? 'Queue is open - clients can join now.'
            : 'Queue closed. Anyone already waiting keeps their place.',
        'success'
    );
    redirect(url('freelancer.dashboard'));
}

function ctrl_freelancer_call_next(): void
{
    role_required('freelancer');
    try {
        $result = qs_call_next(db(), ctrl_me(), ctrl_threshold());
        notify_dispatch(db(), $result['events']);
        flash("Now serving {$result['entry']['client_name']} ({$result['entry']['ticket_no']}).", 'success');
    } catch (QueueError $e) {
        flash($e->getMessage(), 'error');
    }
    redirect(url('freelancer.dashboard'));
}

function ctrl_freelancer_mark_done(int $entry_id): void
{
    role_required('freelancer');
    $raw = trim((string)($_POST['price_charged'] ?? ''));
    $price = null;
    if ($raw !== '') {
        $clean = str_replace(',', '', $raw);
        if (!is_numeric($clean)) {
            flash('That amount is not a number.', 'error');
            redirect(url('freelancer.dashboard'));
        }
        $price = (float)$clean;
    }

    try {
        $result = qs_mark_done(db(), $entry_id, ctrl_me(), $price, ctrl_threshold());
        notify_dispatch(db(), $result['events']);
        flash("{$result['entry']['ticket_no']} completed.", 'success');
    } catch (QueueError $e) {
        flash($e->getMessage(), 'error');
    }
    redirect(url('freelancer.dashboard'));
}

function ctrl_freelancer_skip_current(): void
{
    role_required('freelancer');
    try {
        $result = qs_skip_current(db(), ctrl_me(), ctrl_threshold());
        notify_dispatch(db(), $result['events']);
        flash("{$result['entry']['ticket_no']} moved to the back of the queue.", 'success');
    } catch (QueueError $e) {
        flash($e->getMessage(), 'error');
    }
    redirect(url('freelancer.dashboard'));
}

function ctrl_freelancer_mark_no_show(int $entry_id): void
{
    role_required('freelancer');
    try {
        $result = qs_mark_no_show(db(), $entry_id, ctrl_me(), ctrl_threshold());
        notify_dispatch(db(), $result['events']);
        flash("{$result['entry']['ticket_no']} marked as a no-show.", 'success');
    } catch (QueueError $e) {
        flash($e->getMessage(), 'error');
    }
    redirect(url('freelancer.dashboard'));
}

function ctrl_freelancer_move_entry(int $entry_id): void
{
    role_required('freelancer');
    $direction = (string)($_POST['direction'] ?? '');
    try {
        $result = qs_move_entry(db(), $entry_id, ctrl_me(), $direction, ctrl_threshold());
        notify_dispatch(db(), $result['events']);
    } catch (QueueError $e) {
        flash($e->getMessage(), 'error');
    }
    redirect(url('freelancer.dashboard'));
}

function ctrl_freelancer_clear_queue(): void
{
    role_required('freelancer');
    $result = qs_clear_queue(db(), ctrl_me());
    notify_dispatch(db(), $result['events']);
    $count = count($result['events']);
    if ($count) {
        $noun = $count === 1 ? 'ticket' : 'tickets';
        flash("Queue cleared - {$count} waiting {$noun} closed and everyone emailed.", 'success');
    } else {
        flash('Nothing was waiting.', 'warning');
    }
    redirect(url('freelancer.dashboard'));
}

// --- services ---------------------------------------------------------------

/**
 * Validate the service form, throwing InvalidArgumentException with a showable
 * message. Returns [name, price, est_minutes].
 */
function ctrl_parse_service_form(array $form): array
{
    $name = trim((string)($form['name'] ?? ''));
    if ($name === '') {
        throw new InvalidArgumentException('Give the service a name.');
    }
    if (mb_strlen($name) > MAX_SERVICE_NAME) {
        throw new InvalidArgumentException('Keep the name under ' . MAX_SERVICE_NAME . ' characters.');
    }

    $price_raw = trim(str_replace(',', '', (string)($form['price'] ?? '0')));
    if ($price_raw === '') {
        $price_raw = '0';
    }
    if (!is_numeric($price_raw)) {
        throw new InvalidArgumentException('Price must be a number.');
    }
    $price = (float)$price_raw;
    if ($price < 0 || $price > MAX_PRICE) {
        throw new InvalidArgumentException('That price is out of range.');
    }

    $minutes_raw = trim((string)($form['est_minutes'] ?? ''));
    if (!preg_match('/^-?\d+$/', $minutes_raw)) {
        throw new InvalidArgumentException('Typical duration must be a whole number of minutes.');
    }
    $est_minutes = (int)$minutes_raw;
    if ($est_minutes <= 0 || $est_minutes > MAX_EST_MINUTES) {
        throw new InvalidArgumentException('Duration must be between 1 and ' . MAX_EST_MINUTES . ' minutes.');
    }

    return [$name, $price, $est_minutes];
}

function ctrl_freelancer_services(): void
{
    role_required('freelancer');
    $db = db();
    $me = ctrl_me();
    render('freelancer/services', [
        'title'         => 'Services',
        'user'          => current_user(),
        'services'      => services_list($db, $me),
        'hours'         => services_get_business_hours($db, $me),
        'weekday_names' => WEEKDAY_NAMES,
    ]);
}

function ctrl_freelancer_create_service(): void
{
    role_required('freelancer');
    try {
        [$name, $price, $est_minutes] = ctrl_parse_service_form($_POST);
    } catch (InvalidArgumentException $e) {
        flash($e->getMessage(), 'error');
        redirect(url('freelancer.services'));
    }

    $me = ctrl_me();
    try {
        write_tx(function (PDO $db) use ($me, $name, $price, $est_minutes) {
            services_create($db, $me, $name, $price, $est_minutes);
        });
    } catch (PDOException $e) {
        // UNIQUE (freelancer_id, name)
        if (is_unique_violation($e)) {
            flash("You already have a service called \"{$name}\".", 'error');
            redirect(url('freelancer.services'));
        }
        throw $e;
    }
    flash("Added {$name}.", 'success');
    redirect(url('freelancer.services'));
}

function ctrl_freelancer_update_service(int $service_id): void
{
    role_required('freelancer');
    try {
        [$name, $price, $est_minutes] = ctrl_parse_service_form($_POST);
    } catch (InvalidArgumentException $e) {
        flash($e->getMessage(), 'error');
        redirect(url('freelancer.services'));
    }

    $db = db();
    $me = ctrl_me();
    // Ownership before anything else: a forged id must not even be confirmed.
    $service = services_get($db, $service_id);
    if ($service === null || (int)$service['freelancer_id'] !== $me) {
        abort(404);
    }

    write_tx(function (PDO $db) use ($service_id, $me, $name, $price, $est_minutes) {
        services_update($db, $service_id, $me, $name, $price, $est_minutes);
    });
    flash("Updated {$name}.", 'success');
    redirect(url('freelancer.services'));
}

function ctrl_freelancer_toggle_service(int $service_id): void
{
    role_required('freelancer');
    $db = db();
    $me = ctrl_me();
    $service = services_get($db, $service_id);
    if ($service === null || (int)$service['freelancer_id'] !== $me) {
        abort(404);
    }

    $active = !(int)$service['is_active'];
    write_tx(function (PDO $db) use ($service_id, $me, $active) {
        services_set_active($db, $service_id, $me, $active);
    });
    flash(
        "{$service['name']} is now " . ($active ? 'bookable' : 'hidden from clients') . '.',
        'success'
    );
    redirect(url('freelancer.services'));
}

function ctrl_freelancer_delete_service(int $service_id): void
{
    role_required('freelancer');
    $db = db();
    $me = ctrl_me();
    $service = services_get($db, $service_id);
    if ($service === null || (int)$service['freelancer_id'] !== $me) {
        abort(404);
    }

    // Deleting a service that tickets point at would orphan queue history and
    // silently change past reports, so it is offered only while unused.
    if (services_usage_count($db, $service_id) > 0) {
        flash(
            "{$service['name']} has been booked before, so it cannot be deleted. "
            . 'Hide it from clients instead.',
            'error'
        );
        redirect(url('freelancer.services'));
    }

    write_tx(function (PDO $db) use ($service_id, $me) {
        services_delete($db, $service_id, $me);
    });
    flash("Deleted {$service['name']}.", 'success');
    redirect(url('freelancer.services'));
}

// --- availability -----------------------------------------------------------

function ctrl_freelancer_hours(): void
{
    role_required('freelancer');
    $db = db();
    render('freelancer/hours', [
        'title'         => 'Availability',
        'container_class' => 'narrow',
        'user'          => current_user(),
        'hours'         => services_get_business_hours($db, ctrl_me()),
        'weekday_names' => WEEKDAY_NAMES,
    ]);
}

function ctrl_freelancer_save_hours(): void
{
    role_required('freelancer');
    $week = [];
    for ($weekday = 0; $weekday < 7; $weekday++) {
        $is_open = ($_POST["open_{$weekday}"] ?? null) === '1';
        $start = trim((string)($_POST["open_time_{$weekday}"] ?? ''));
        $end   = trim((string)($_POST["close_time_{$weekday}"] ?? ''));

        if ($is_open) {
            if (!preg_match(FREELANCER_TIME_RE, $start) || !preg_match(FREELANCER_TIME_RE, $end)) {
                flash(WEEKDAY_NAMES[$weekday] . ': times must be in 24-hour HH:MM form.', 'error');
                redirect(url('freelancer.hours'));
            }
            if ($start >= $end) {
                // HH:MM strings compare correctly, which is why they are stored
                // as text rather than as minutes-since-midnight.
                flash(WEEKDAY_NAMES[$weekday] . ': closing time must be after opening time.', 'error');
                redirect(url('freelancer.hours'));
            }
        }
        $week[] = [$weekday, $is_open, $start !== '' ? $start : null, $end !== '' ? $end : null];
    }

    $me = ctrl_me();
    write_tx(function (PDO $db) use ($me, $week) {
        services_save_business_hours($db, $me, $week);
    });
    flash('Availability saved.', 'success');
    redirect(url('freelancer.hours'));
}

// --- reports ----------------------------------------------------------------

function ctrl_freelancer_reports(): void
{
    role_required('freelancer');
    $db = db();
    $me = ctrl_me();
    $preset = (string)($_GET['range'] ?? REPORT_DEFAULT_PRESET);
    [$start, $end, $label] = report_resolve_range($preset, $_GET);

    render('freelancer/reports', [
        'title'       => 'Reports',
        'user'        => current_user(),
        'preset'      => $preset,
        'presets'     => REPORT_PRESETS,
        'start'       => $start,
        'end'         => $end,
        'range_label' => $label,
        'report'      => report_build($db, $me, $start, $end),
    ]);
}

function ctrl_freelancer_export_csv(): void
{
    role_required('freelancer');
    $db = db();
    $preset = (string)($_GET['range'] ?? REPORT_DEFAULT_PRESET);
    [$start, $end] = report_resolve_range($preset, $_GET);
    $rows = reports_completed_entries($db, ctrl_me(), $start, $end);

    $csv = report_to_csv($rows, settings()->currency);
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"freelancequeue-{$start}-to-{$end}.csv\"");
    header('Content-Length: ' . strlen($csv));
    echo $csv;
    exit;
}
