<?php
// Wait-time estimation.
//
// The honest version of "how long will I be waiting?" is: however long the
// person currently being served has left, plus the quoted-and-corrected
// duration of everyone ahead of you. This module computes that from the
// freelancer's OBSERVED pace where there is enough history to trust, which is
// what makes the number worth showing.
//
// It reads, it arithmetics, it returns floats - no writes, no HTTP.

declare(strict_types=1);

// Weight on measured history once we have enough of it. The remaining 0.3 on
// the freelancer's own quoted duration keeps one pathological job - a client
// who turned up with a completely different problem - from poisoning it.
const OBSERVED_WEIGHT = 0.7;
const MIN_SAMPLES     = 3;

/**
 * Duration lookup for one freelancer's services, built once per request so the
 * two queries behind it run once per page render, not once per queue row.
 */
final class Estimator
{
    /** @param array<int,int> $quoted @param array<int,array{0:int,1:float}> $observed */
    public function __construct(private array $quoted, private array $observed) {}

    /** Best estimate, in minutes, of how long this service actually takes. */
    public function duration(int $service_id): float
    {
        $quoted = (float)($this->quoted[$service_id] ?? 0);
        [$samples, $average] = $this->observed[$service_id] ?? [0, 0.0];
        if ($samples >= MIN_SAMPLES && $average > 0) {
            return OBSERVED_WEIGHT * $average + (1.0 - OBSERVED_WEIGHT) * $quoted;
        }
        return $quoted;
    }

    /** Whether this estimate rests on history rather than on the quote alone. */
    public function is_measured(int $service_id): bool
    {
        [$samples, $average] = $this->observed[$service_id] ?? [0, 0.0];
        return $samples >= MIN_SAMPLES && $average > 0;
    }
}

/** @param int[] $service_ids */
function est_build_estimator(PDO $db, array $service_ids): Estimator
{
    $unique = array_values(array_unique(array_map('intval', $service_ids)));
    sort($unique);
    return new Estimator(
        services_est_minutes_map($db, $unique),
        queue_service_duration_stats($db, $unique)
    );
}

/**
 * Minutes left on the job in progress - never negative. An overrunning job
 * clamps to 0: "they are already over, so you are next" is the truthful
 * reading, and a negative number would shorten everyone else's estimate.
 */
function est_remaining_for_serving(?array $serving, Estimator $estimator): float
{
    if ($serving === null) {
        return 0.0;
    }
    $expected = $estimator->duration((int)$serving['service_id']);
    return max(0.0, $expected - elapsed_minutes($serving['started_at']));
}

/** Estimated minutes until this waiting entry is called. null if not waiting. */
function est_eta_for_entry(PDO $db, array $entry, ?array $serving = null, ?Estimator $estimator = null): ?float
{
    if ($entry['status'] !== 'waiting') {
        return null;
    }
    $ahead = queue_services_ahead_of($db, $entry);
    if ($serving === null) {
        $serving = queue_get_serving($db, (int)$entry['freelancer_id']);
    }
    if ($estimator === null) {
        $needed = $ahead;
        if ($serving !== null) {
            $needed[] = (int)$serving['service_id'];
        }
        $estimator = est_build_estimator($db, $needed);
    }
    $total = est_remaining_for_serving($serving, $estimator);
    foreach ($ahead as $service_id) {
        $total += $estimator->duration($service_id);
    }
    return $total;
}

/**
 * Turn the waiting list into render-ready rows with a cumulative ETA. One pass
 * accumulating durations: the wait for row N is the wait for row N-1 plus row
 * N-1's duration.
 *
 * @param array<int,array> $waiting
 * @return list<array{row:array,position:int,eta_minutes:float,duration_minutes:float,measured:bool}>
 */
function est_annotate_waiting(array $waiting, ?array $serving, Estimator $estimator): array
{
    $running = est_remaining_for_serving($serving, $estimator);
    $out = [];
    foreach ($waiting as $row) {
        $service_id = (int)$row['service_id'];
        $out[] = [
            'row'              => $row,
            'position'         => (int)$row['position'],
            'eta_minutes'      => $running,
            'duration_minutes' => $estimator->duration($service_id),
            'measured'         => $estimator->is_measured($service_id),
        ];
        $running += $estimator->duration($service_id);
    }
    return $out;
}

/** The one-line answer a client actually wants on their ticket page. */
function est_status_phrase(?int $position, bool $serving_busy): string
{
    if ($position === null) {
        return '';
    }
    if ($position === 1 && !$serving_busy) {
        return "You're next - go on in";
    }
    if ($position === 1) {
        return "You're next after the current client";
    }
    return ($position - 1) . ' ahead of you';
}
