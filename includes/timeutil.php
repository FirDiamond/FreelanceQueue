<?php
// The only module allowed to convert between UTC and local time.
//
// The rule for this codebase: every *_at column holds a UTC DATETIME
// ('Y-m-d H:i:s'), while queue_date and business hours are LOCAL wall-clock
// values. A client in another timezone still belongs to the freelancer's
// business day, and keeping the conversion in one place stops that distinction
// leaking into random queries. "Local" is PHP's default timezone (set in
// bootstrap.php).

declare(strict_types=1);

const WEEKDAY_NAMES = [
    'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday',
];

const STORE_FMT = 'Y-m-d H:i:s'; // how every *_at column is written.

function utc_zone(): DateTimeZone
{
    static $z = null;
    return $z ??= new DateTimeZone('UTC');
}

function local_zone(): DateTimeZone
{
    return new DateTimeZone(date_default_timezone_get());
}

function now_utc(): DateTimeImmutable
{
    return new DateTimeImmutable('now', utc_zone());
}

/** Timestamp in the exact form every *_at column stores. */
function utc_now(): string
{
    return now_utc()->format(STORE_FMT);
}

/** A cutoff timestamp in the stored form, N minutes before now. */
function minutes_ago_utc(int $minutes): string
{
    return now_utc()->sub(new DateInterval('PT' . $minutes . 'M'))->format(STORE_FMT);
}

/** Parse a stored UTC timestamp back to an aware UTC datetime. */
function parse_stored(?string $value): ?DateTimeImmutable
{
    if ($value === null || $value === '') {
        return null;
    }
    // Accept both the stored 'Y-m-d H:i:s' and any ISO-8601 form.
    $dt = date_create_immutable($value, utc_zone());
    if ($dt === false) {
        return null;
    }
    return $dt->setTimezone(utc_zone());
}

/** Convert a stored UTC timestamp to the server's local timezone. */
function to_local(?string $value): ?DateTimeImmutable
{
    $dt = parse_stored($value);
    return $dt?->setTimezone(local_zone());
}

/** ISO-8601 UTC string for the JSON API, .eml headers and data-started. */
function iso_utc(?string $value): ?string
{
    $dt = parse_stored($value);
    return $dt?->format('Y-m-d\TH:i:sP'); // 2026-09-25T14:03:07+00:00
}

/** The 'YYYY-MM-DD' local business day a timestamp belongs to (now if null). */
function local_date_str(?string $value = null): string
{
    $dt = $value !== null ? to_local($value) : new DateTimeImmutable('now', local_zone());
    return ($dt ?? new DateTimeImmutable('now', local_zone()))->format('Y-m-d');
}

/** Local weekday index, 0 = Monday (matches business_hours.weekday). */
function local_weekday(?string $value = null): int
{
    $dt = $value !== null ? to_local($value) : new DateTimeImmutable('now', local_zone());
    $dt ??= new DateTimeImmutable('now', local_zone());
    return (int)$dt->format('N') - 1; // N: 1=Mon..7=Sun
}

/** Local 'YYYY-MM-DD' for N days back - used by the report date presets. */
function days_ago_date_str(int $days): string
{
    return (new DateTimeImmutable('now', local_zone()))
        ->sub(new DateInterval('P' . $days . 'D'))
        ->format('Y-m-d');
}

/** Local 'YYYY-MM-DD' of the first day of the current month. */
function local_month_start(): string
{
    return (new DateTimeImmutable('now', local_zone()))->format('Y-m-01');
}

/**
 * Every local date from $start to $end inclusive. Report charts need a
 * continuous axis, so a day with no jobs appears as a zero rather than being
 * skipped. $limit stops an absurd hand-typed range from generating a huge axis.
 *
 * @return string[]
 */
function date_range(string $start, string $end, int $limit = 400): array
{
    $first = date_create_immutable($start . ' 00:00:00', utc_zone());
    $last  = date_create_immutable($end . ' 00:00:00', utc_zone());
    if ($first === false || $last === false || $last < $first) {
        return [];
    }
    $days = (int)$first->diff($last)->format('%a');
    $span = min($days, $limit - 1);
    $out = [];
    for ($i = 0; $i <= $span; $i++) {
        $out[] = $first->add(new DateInterval('P' . $i . 'D'))->format('Y-m-d');
    }
    return $out;
}

/** Minutes from one stored timestamp to another, or null if either is unset. */
function minutes_between(?string $start, ?string $end): ?float
{
    $a = parse_stored($start);
    $b = parse_stored($end);
    if ($a === null || $b === null) {
        return null;
    }
    return ($b->getTimestamp() - $a->getTimestamp()) / 60.0;
}

/** Minutes from a stored timestamp until now. 0 if unset. */
function elapsed_minutes(?string $since): float
{
    $dt = parse_stored($since);
    if ($dt === null) {
        return 0.0;
    }
    return max(0.0, (now_utc()->getTimestamp() - $dt->getTimestamp()) / 60.0);
}

/** Human wait time, rounded to 5-minute granularity. */
function fmt_minutes($minutes): string
{
    if ($minutes === null) {
        return '-';
    }
    $minutes = (int)max(0, (int)round((float)$minutes / 5.0) * 5);
    if ($minutes === 0) {
        return 'under 5 min';
    }
    $hours = intdiv($minutes, 60);
    $mins = $minutes % 60;
    if ($hours && $mins) {
        return "{$hours} h {$mins} min";
    }
    if ($hours) {
        return "{$hours} h";
    }
    return "{$mins} min";
}

/** Precise duration for reports, where the underlying number is measured. */
function fmt_duration_exact($minutes): string
{
    if ($minutes === null) {
        return '-';
    }
    $total = (int)round((float)$minutes);
    $hours = intdiv($total, 60);
    $mins = $total % 60;
    if ($hours) {
        return sprintf('%dh %02dm', $hours, $mins);
    }
    return "{$mins}m";
}
