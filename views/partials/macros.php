<?php
// View helpers - the PHP equivalents of the Flask/Jinja template filters and
// the stat_tile / status_badge macros. Loaded for web requests by bootstrap so
// every view can call them. Output helpers return already-escaped HTML; the
// scalar formatters return plain strings meant to be passed through e().

declare(strict_types=1);

/** Amount in the configured currency (identical string used in emails). */
function fmt_money($value): string
{
    return settings()->formatMoney($value);
}

/** Minutes, rounded to 5 (e.g. "about 15 min"); "-" for null. */
function fmt_mins($value): string
{
    return fmt_minutes($value);
}

/** Exact human duration, e.g. "1h 12m". */
function fmt_dur($value): string
{
    return fmt_duration_exact($value);
}

/** Gap between two stored timestamps (joined -> started), rounded to 5 min. */
function fmt_wait_to(?string $start, ?string $end): string
{
    return fmt_minutes(minutes_between($start, $end));
}

/** Local wall-clock time "HH:MM", or "-". */
function fmt_localtime(?string $value): string
{
    $dt = to_local($value);
    return $dt ? $dt->format('H:i') : '-';
}

/** Local date "05 Sep 2026", or "-". */
function fmt_localdate(?string $value): string
{
    $dt = to_local($value);
    return $dt ? $dt->format('d M Y') : '-';
}

/** Local date+time "05 Sep 2026, 14:03", or "-". */
function fmt_localdatetime(?string $value): string
{
    $dt = to_local($value);
    return $dt ? $dt->format('d M Y, H:i') : '-';
}

/** Human status label. */
function fmt_status_label(?string $value): string
{
    return STATUS_LABELS[$value] ?? ($value ?: '-');
}

/**
 * A KPI tile. Deliberately not a chart: a single current number is read faster
 * as a number than as any mark you could draw for it. Returns escaped HTML.
 */
function stat_tile(string $label, $value, ?string $note = null, ?string $value_id = null): string
{
    $id = $value_id !== null ? ' id="' . e($value_id) . '"' : '';
    $out  = '<div class="stat-tile">';
    $out .= '<div class="stat-label">' . e($label) . '</div>';
    $out .= '<div class="stat-value tabular"' . $id . '>' . e($value) . '</div>';
    if ($note !== null && $note !== '') {
        $out .= '<div class="stat-note">' . e($note) . '</div>';
    }
    $out .= '</div>';
    return $out;
}

/**
 * Status pill. The dot is decorative; the word carries the meaning, so this
 * still reads correctly in greyscale or for a colourblind reader.
 */
function status_badge(?string $status): string
{
    return '<span class="badge ' . e($status) . '"><span class="dot"></span>'
        . e(fmt_status_label($status)) . '</span>';
}
