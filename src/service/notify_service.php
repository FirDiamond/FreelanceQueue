<?php
// Maps queue lifecycle events onto emails, then sends them.
//
// The one place that answers "who gets told what". queue_service decides that
// an event happened; mailer.php knows how to put bytes on a wire; this sits
// between them - the only module that knows a client hears about their own
// ticket and a freelancer about their whole queue.
//
// The Flask app drained events on a background thread; here dispatch() runs
// synchronously, called by the route AFTER the queue transaction has committed,
// so every row it describes is guaranteed to be visible. A send that fails is
// logged to email_log and swallowed - being served matters more than being
// emailed about it.

declare(strict_types=1);

// Everything else is addressed to the client whose ticket it is.
const FREELANCER_EVENTS = ['freelancer_new_entry'];

/**
 * Hand a list of event records (['name','entry_id']) to the mailer. Never
 * raises: a notification failing must not fail the queue action behind it.
 */
function notify_dispatch(PDO $db, array $events): void
{
    if (!$events) {
        return;
    }
    $settings = settings();
    foreach ($events as $event) {
        try {
            $messages = notify_build_messages($db, $event['name'], (int)$event['entry_id'], $settings);
            foreach ($messages as $message) {
                $log_id = email_log_queued(
                    $db,
                    $message['entry_id'],
                    $message['to'],
                    $message['event'],
                    $message['subject']
                );
                notify_deliver($db, $log_id, $message);
            }
        } catch (Throwable $e) {
            error_log('notify_dispatch failed for ' . ($event['name'] ?? '?') . ': ' . $e->getMessage());
        }
    }
}

/** Send one message with bounded retries, recording the outcome in email_log. */
function notify_deliver(PDO $db, int $log_id, array $message): void
{
    for ($attempt = 1; $attempt <= MAILER_MAX_ATTEMPTS; $attempt++) {
        try {
            mailer_send($message);
            email_log_mark_sent($db, $log_id, $attempt);
            return;
        } catch (MailerPermanentError $e) {
            error_log('Permanent send failure for ' . $message['to'] . ': ' . $e->getMessage());
            email_log_mark_failed($db, $log_id, $attempt, $e->getMessage());
            return;
        } catch (Throwable $e) {
            if ($attempt === MAILER_MAX_ATTEMPTS) {
                email_log_mark_failed($db, $log_id, $attempt, $e->getMessage());
                return;
            }
            sleep(MAILER_RETRY_DELAYS[$attempt - 1] ?? 2);
        }
    }
}

/**
 * Render one event into the messages it should produce. Returns [] rather than
 * raising when there is nothing to send - an entry deleted between the action
 * and the send is a normal race, not a failure.
 *
 * @return array<int,array> message records
 */
function notify_build_messages(PDO $db, string $event_name, int $entry_id, Settings $settings): array
{
    $entry = queue_get_entry($db, $entry_id);
    if ($entry === null) {
        return [];
    }

    $rendered = notify_render($event_name, notify_context($db, $entry, $settings));
    if ($rendered === null) {
        return [];
    }
    [$subject, $body] = $rendered;

    $recipient = in_array($event_name, FREELANCER_EVENTS, true)
        ? $entry['freelancer_email']
        : $entry['client_email'];
    if (!$recipient) {
        return [];
    }

    return [mailer_message($recipient, $subject, $body, $event_name, $entry_id)];
}

function notify_position_text(?int $position, int $waiting_total): string
{
    if ($position === null) {
        return '-';
    }
    if ($position === 1) {
        return 'next in line';
    }
    return $position . ' of ' . $waiting_total;
}

/**
 * Everything the templates interpolate, recomputed live from the entry so the
 * email reflects the queue at send time rather than at event time.
 */
function notify_context(PDO $db, array $entry, Settings $settings): array
{
    $freelancer_id = (int)$entry['freelancer_id'];
    $position = queue_position_of($db, $entry);
    $waiting_count = queue_count_waiting($db, $freelancer_id);

    $eta_text = '-';
    if ($position !== null) {
        $eta = est_eta_for_entry($db, $entry);
        $eta_text = fmt_minutes($eta);
    }

    $price = $entry['price_charged'] !== null ? $entry['price_charged'] : $entry['service_price'];

    return [
        'client_name'     => $entry['client_name'],
        'freelancer_name' => $entry['freelancer_name'],
        'service_name'    => $entry['service_name'],
        'ticket_no'       => $entry['ticket_no'],
        'position_text'   => notify_position_text($position, $waiting_count),
        'eta_text'        => $eta_text,
        'price_text'      => $settings->formatMoney($price),
        'duration_text'   => fmt_duration_exact(minutes_between($entry['started_at'], $entry['ended_at'])),
        'waiting_count'   => $waiting_count,
        'note'            => $entry['note'],
        'ticket_url'      => $settings->base_url . '/ticket/' . (int)$entry['id'],
        'dashboard_url'   => $settings->base_url . '/dashboard',
    ];
}

// --- templates: subject + plain-text body for every notification ------------
// Plain text on purpose: short transactional messages read on a phone, often in
// a notification preview, so the first line carries the news.

const EMAIL_SIGNATURE = "\n\n--\nFreelanceQueue";

/** Join non-empty paragraphs, so an absent optional line leaves no gap. */
function notify_lines(string ...$parts): string
{
    return implode("\n\n", array_filter($parts, static fn($p) => $p !== ''));
}

/** (subject, body) for an event, or null if nothing is sent for it. */
function notify_render(string $event, array $c): ?array
{
    switch ($event) {
        case 'queue_joined':
            return [
                "Ticket {$c['ticket_no']} - you're in {$c['freelancer_name']}'s queue",
                notify_lines(
                    "Hi {$c['client_name']},",
                    "You've joined the queue for {$c['service_name']} with {$c['freelancer_name']}.",
                    "Ticket: {$c['ticket_no']}\nPosition: {$c['position_text']}\nEstimated wait: {$c['eta_text']}",
                    "Track your place here - it updates live:\n{$c['ticket_url']}",
                    "We'll email you again when you're nearly at the front."
                ) . EMAIL_SIGNATURE,
            ];
        case 'almost_your_turn':
            return [
                "Almost your turn - ticket {$c['ticket_no']}",
                notify_lines(
                    "Hi {$c['client_name']},",
                    "You're {$c['position_text']} in {$c['freelancer_name']}'s queue for {$c['service_name']}. Estimated wait: {$c['eta_text']}.",
                    'Now is a good moment to be ready.',
                    "Live position:\n{$c['ticket_url']}"
                ) . EMAIL_SIGNATURE,
            ];
        case 'turn_started':
            return [
                "It's your turn now - ticket {$c['ticket_no']}",
                notify_lines(
                    "Hi {$c['client_name']},",
                    "{$c['freelancer_name']} has called you for {$c['service_name']}. You're being served now.",
                    "Ticket page:\n{$c['ticket_url']}"
                ) . EMAIL_SIGNATURE,
            ];
        case 'job_completed':
            return [
                "Completed - {$c['service_name']} ({$c['ticket_no']})",
                notify_lines(
                    "Hi {$c['client_name']},",
                    "{$c['freelancer_name']} has marked {$c['service_name']} as complete.",
                    "Ticket: {$c['ticket_no']}\nAmount: {$c['price_text']}\nTime with the freelancer: {$c['duration_text']}",
                    'Thanks for using the queue.'
                ) . EMAIL_SIGNATURE,
            ];
        case 'entry_cancelled':
            return [
                "Ticket {$c['ticket_no']} cancelled",
                notify_lines(
                    "Hi {$c['client_name']},",
                    "Your ticket for {$c['service_name']} with {$c['freelancer_name']} has been cancelled and you've been removed from the queue.",
                    'You can join again at any time while the queue is open.'
                ) . EMAIL_SIGNATURE,
            ];
        case 'no_show':
            return [
                "Ticket {$c['ticket_no']} closed - marked as a no-show",
                notify_lines(
                    "Hi {$c['client_name']},",
                    "{$c['freelancer_name']} called ticket {$c['ticket_no']} for {$c['service_name']} and couldn't reach you, so it's been closed.",
                    "If that's a mistake, join the queue again and let them know."
                ) . EMAIL_SIGNATURE,
            ];
        case 'queue_cleared':
            return [
                "Queue closed for the day - ticket {$c['ticket_no']}",
                notify_lines(
                    "Hi {$c['client_name']},",
                    "{$c['freelancer_name']} has closed the queue for today, so ticket {$c['ticket_no']} for {$c['service_name']} wasn't reached.",
                    "Nothing has been charged. You're welcome to join again next time the queue opens."
                ) . EMAIL_SIGNATURE,
            ];
        case 'freelancer_new_entry':
            return [
                "New in your queue: {$c['client_name']} ({$c['ticket_no']})",
                notify_lines(
                    "{$c['client_name']} has joined your queue.",
                    "Ticket: {$c['ticket_no']}\nService: {$c['service_name']}\nWaiting: {$c['waiting_count']}",
                    !empty($c['note']) ? "Note from the client: {$c['note']}" : '',
                    "Your dashboard:\n{$c['dashboard_url']}"
                ) . EMAIL_SIGNATURE,
            ];
        default:
            return null;
    }
}
