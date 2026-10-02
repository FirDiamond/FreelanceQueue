<?php
// Shapes report data for the page and the CSV export.
//
// The repo layer answers questions; this layer decides which questions the page
// asks and fills the gaps between them - notably the zero-days that SQL GROUP BY
// cannot return. A line chart that skips empty days draws a slope between Monday
// and Friday and implies work on Wednesday. So the date axis is built here,
// densely, and the query results are mapped onto it.

declare(strict_types=1);

const REPORT_DEFAULT_PRESET = '30d';

const REPORT_PRESETS = [
    ['key' => 'today', 'label' => 'Today'],
    ['key' => '7d',    'label' => 'Last 7 days'],
    ['key' => '30d',   'label' => 'Last 30 days'],
    ['key' => '90d',   'label' => 'Last 90 days'],
    ['key' => 'mtd',   'label' => 'Month to date'],
];

// Beyond this many services the bar chart stops being readable, so the tail is
// rolled into one "Other" bar rather than drawn as ever-thinner slivers.
const MAX_SERVICE_BARS = 8;

// The heatmap shows the working window that actually has arrivals, widened to
// this minimum so a single busy hour does not render as one lonely column.
const MIN_HEAT_COLUMNS = 8;

const REPORT_MONTHS = [
    'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
    'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec',
];

/**
 * [start, end, label] for a preset key, or a validated custom range.
 * Unrecognised or malformed input silently falls back to the default: a
 * hand-edited query string should not break a read-only page.
 */
function report_resolve_range(string $preset, array $args): array
{
    $today = local_date_str();

    if ($preset === 'custom') {
        $start = trim((string)($args['start'] ?? ''));
        $end   = trim((string)($args['end'] ?? ''));
        $re = '/^\d{4}-\d{2}-\d{2}$/';
        if (preg_match($re, $start) && preg_match($re, $end) && $start <= $end) {
            return [$start, $end, "{$start} to {$end}"];
        }
        $preset = REPORT_DEFAULT_PRESET;
    }

    if ($preset === 'today') {
        return [$today, $today, 'Today'];
    }
    if ($preset === '7d') {
        return [days_ago_date_str(6), $today, 'Last 7 days'];
    }
    if ($preset === '90d') {
        return [days_ago_date_str(89), $today, 'Last 90 days'];
    }
    if ($preset === 'mtd') {
        return [local_month_start(), $today, 'Month to date'];
    }
    return [days_ago_date_str(29), $today, 'Last 30 days'];
}

/** '04 Sep' - short enough for an axis tick. */
function report_day_label(string $date_str): string
{
    return substr($date_str, 8, 2) . ' ' . REPORT_MONTHS[(int)substr($date_str, 5, 2) - 1];
}

/** @param array<int,string> $days */
function report_completed_series(array $rows, array $days): array
{
    $by_date = [];
    foreach ($rows as $r) {
        $by_date[$r['queue_date']] = $r;
    }
    $out = [];
    foreach ($days as $day) {
        $row = $by_date[$day] ?? null;
        $out[] = [
            'date'   => $day,
            'label'  => report_day_label($day),
            'value'  => $row !== null ? (int)$row['done_count'] : 0,
            'earned' => $row !== null ? (float)($row['earned'] ?? 0) : 0.0,
        ];
    }
    return $out;
}

function report_service_bars(array $rows): array
{
    $items = [];
    foreach ($rows as $r) {
        $items[] = [
            'label'       => $r['service_name'],
            'value'       => (float)($r['earned'] ?? 0),
            'count'       => (int)$r['done_count'],
            'avg_minutes' => $r['avg_service_minutes'],
        ];
    }
    if (count($items) <= MAX_SERVICE_BARS) {
        return $items;
    }

    $head = array_slice($items, 0, MAX_SERVICE_BARS - 1);
    $tail = array_slice($items, MAX_SERVICE_BARS - 1);
    $head[] = [
        'label'       => 'Other (' . count($tail) . ' services)',
        'value'       => array_sum(array_column($tail, 'value')),
        'count'       => array_sum(array_column($tail, 'count')),
        'avg_minutes' => null,
    ];
    return $head;
}

function report_heatmap(array $rows): array
{
    $counts = [];              // "weekday:hour" => n
    $hours_present = [];
    foreach ($rows as $r) {
        $weekday = (int)$r['weekday'];
        $hour = (int)$r['hour'];
        $counts["{$weekday}:{$hour}"] = (int)$r['n'];
        $hours_present[$hour] = true;
    }
    $hours_with_data = array_keys($hours_present);
    sort($hours_with_data);

    if ($hours_with_data) {
        $low = $hours_with_data[0];
        $high = $hours_with_data[count($hours_with_data) - 1];
    } else {
        $low = 9;
        $high = 16;
    }
    // Widen symmetrically to the minimum width, staying inside 0-23.
    while ($high - $low + 1 < MIN_HEAT_COLUMNS) {
        if ($low > 0) {
            $low--;
        }
        if ($high < 23 && $high - $low + 1 < MIN_HEAT_COLUMNS) {
            $high++;
        }
        if ($low === 0 && $high === 23) {
            break;
        }
    }

    $hours = range($low, $high);
    $grid = [];
    for ($weekday = 0; $weekday < 7; $weekday++) {
        $cells = [];
        foreach ($hours as $hour) {
            $cells[] = ['hour' => $hour, 'value' => $counts["{$weekday}:{$hour}"] ?? 0];
        }
        $grid[] = [
            'weekday' => $weekday,
            'name'    => WEEKDAY_NAMES[$weekday],
            'short'   => substr(WEEKDAY_NAMES[$weekday], 0, 3),
            'cells'   => $cells,
        ];
    }
    return [
        'hours' => $hours,
        'rows'  => $grid,
        'max'   => $counts ? max($counts) : 0,
        'total' => array_sum($counts),
    ];
}

/** @param array<int,string> $days */
function report_wait_vs_service(array $rows, array $days): array
{
    $by_date = [];
    foreach ($rows as $r) {
        $by_date[$r['queue_date']] = $r;
    }
    $wait = [];
    $service = [];
    foreach ($days as $day) {
        $row = $by_date[$day] ?? null;
        $wait[] = [
            'date'  => $day,
            'label' => report_day_label($day),
            'value' => $row === null ? null : ($row['avg_wait_minutes'] === null ? null : (float)$row['avg_wait_minutes']),
        ];
        $service[] = [
            'date'  => $day,
            'label' => report_day_label($day),
            'value' => $row === null ? null : ($row['avg_service_minutes'] === null ? null : (float)$row['avg_service_minutes']),
        ];
    }
    return ['wait' => $wait, 'service' => $service];
}

// --- chart scaffolding ------------------------------------------------------
// Axis bounds and line breaks are computed here, not in the view: both are
// properties of the data - where the numbers stop, and where they are missing.

/**
 * A rounded-up upper bound and evenly spaced tick values. Axes end on a number
 * a person would choose - 10, 25, 500 - rather than on the largest data point.
 */
function report_axis_scale(array $values, int $ticks = 4): array
{
    $numbers = [];
    foreach ($values as $v) {
        if ($v !== null) {
            $numbers[] = (float)$v;
        }
    }
    $peak = $numbers ? max($numbers) : 0.0;
    if ($peak <= 0) {
        return ['max' => 1.0, 'ticks' => [0.0, 1.0]];
    }

    // Round up to 1, 2, 2.5, 5 or 10 times a power of ten.
    $magnitude = 10.0 ** (strlen((string)(int)$peak) - 1);
    $top = 10.0 * $magnitude;
    foreach ([1.0, 2.0, 2.5, 5.0, 10.0] as $step) {
        if ($peak <= $step * $magnitude) {
            $top = $step * $magnitude;
            break;
        }
    }
    $tick_values = [];
    for ($i = 0; $i <= $ticks; $i++) {
        $tick_values[] = $top * $i / $ticks;
    }
    return ['max' => $top, 'ticks' => $tick_values];
}

/**
 * Split a series into runs of consecutive points that have a value. A day with
 * no jobs has no average wait - that is missing, not zero - and drawing through
 * the gap would invent a measurement. Each run is drawn as its own line.
 */
function report_line_segments(array $series): array
{
    $runs = [];
    $current = [];
    foreach ($series as $index => $point) {
        if ($point['value'] === null) {
            if ($current) {
                $runs[] = $current;
                $current = [];
            }
            continue;
        }
        $current[] = ['i' => $index, 'value' => (float)$point['value'], 'label' => $point['label']];
    }
    if ($current) {
        $runs[] = $current;
    }
    return $runs;
}

function report_build(PDO $db, int $freelancer_id, string $start, string $end): array
{
    $days = date_range($start, $end);
    $kpi = reports_summary($db, $freelancer_id, $start, $end);

    $completed = report_completed_series(
        reports_completed_per_day($db, $freelancer_id, $start, $end),
        $days
    );
    $services = report_service_bars(
        reports_earnings_by_service($db, $freelancer_id, $start, $end)
    );
    $timing = report_wait_vs_service(
        reports_wait_and_service_per_day($db, $freelancer_id, $start, $end),
        $days
    );
    $entries = reports_completed_entries($db, $freelancer_id, $start, $end);

    $timing_values = array_merge(
        array_column($timing['wait'], 'value'),
        array_column($timing['service'], 'value')
    );

    return [
        'kpi'             => $kpi,
        'has_data'        => (bool)$kpi['total_entries'],
        'days'            => $days,
        'completed'       => $completed,
        'completed_total' => array_sum(array_column($completed, 'value')),
        'completed_scale' => report_axis_scale(array_column($completed, 'value')),
        'services'        => $services,
        'services_max'    => $services ? max(array_column($services, 'value')) : 0.0,
        'heat'            => report_heatmap(reports_busiest_hours($db, $freelancer_id, $start, $end)),
        'timing'          => $timing,
        // Both series share one scale: both are minutes, and separate axes would
        // let a 6-minute difference look like a 60-minute one.
        'timing_scale'    => report_axis_scale($timing_values),
        'timing_segments' => [
            'wait'    => report_line_segments($timing['wait']),
            'service' => report_line_segments($timing['service']),
        ],
        'clients'         => reports_busiest_clients($db, $freelancer_id, $start, $end),
        'entries'         => $entries,
    ];
}

/**
 * The raw rows, one line each, for a spreadsheet. Times are local
 * 'YYYY-MM-DD HH:MM' rather than UTC ISO: the export is read by a person
 * reconciling their own day, not by a machine.
 */
function report_to_csv(array $rows, string $currency): string
{
    $local = static function ($value): string {
        $dt = to_local($value);
        return $dt ? $dt->format('Y-m-d H:i') : '';
    };
    $minutes = static function ($value): string {
        return $value === null ? '' : sprintf('%.1f', (float)$value);
    };

    $buffer = fopen('php://temp', 'r+');
    $write = static function (array $fields) use ($buffer): void {
        // fputcsv defaults to CRLF-free LF only if we set it; force LF to match
        // the Flask export byte-for-byte.
        $line = [];
        foreach ($fields as $f) {
            $s = (string)$f;
            if (preg_match('/[",\r\n]/', $s)) {
                $s = '"' . str_replace('"', '""', $s) . '"';
            }
            $line[] = $s;
        }
        fwrite($buffer, implode(',', $line) . "\n");
    };

    $write([
        'Date', 'Ticket', 'Client', 'Email', 'Service', 'Status',
        'Joined', 'Started', 'Ended', 'Wait (min)', 'Service time (min)',
        "Amount ({$currency})", 'Note',
    ]);
    foreach ($rows as $row) {
        $write([
            $row['queue_date'],
            $row['ticket_no'],
            $row['client_name'],
            $row['client_email'],
            $row['service_name'],
            STATUS_LABELS[$row['status']] ?? $row['status'],
            $local($row['joined_at']),
            $local($row['started_at']),
            $local($row['ended_at']),
            $minutes($row['wait_minutes']),
            $minutes($row['service_minutes']),
            $row['price_charged'] === null ? '' : sprintf('%.2f', (float)$row['price_charged']),
            $row['note'] ?? '',
        ]);
    }

    rewind($buffer);
    $csv = stream_get_contents($buffer);
    fclose($buffer);
    return $csv;
}
