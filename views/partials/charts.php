<?php
// Inline-SVG chart builders - the PHP port of macros/charts.html.
//
// Server-rendered on purpose: the charts are in the HTML, so they print, they
// survive JavaScript being blocked, and there is no CDN in the page. Every mark
// carries a <title> child (a native hover tooltip); chart.js only adds a
// crosshair on top, reading the .hit circles' data-label / data-value and cx/cy.
//
// Geometry only. Axis bounds and line breaks arrive precomputed from
// report_service (see report_axis_scale() and report_line_segments()).

declare(strict_types=1);

/** Round to 2dp for SVG coordinates (matches the templates' | round(2)). */
function chart_n(float $v): string
{
    return rtrim(rtrim(number_format(round($v, 2), 2, '.', ''), '0'), '.');
}

/** Jinja truncate(length, killwords=true, end) with the default leeway of 5. */
function chart_truncate(string $s, int $length, string $end = '...', int $leeway = 5): string
{
    if (mb_strlen($s) <= $length + $leeway) {
        return $s;
    }
    return mb_substr($s, 0, $length - mb_strlen($end)) . $end;
}

/** x projection shared by the line charts. */
function chart_px(int $i, int $n, float $left, float $plot_w): float
{
    return $n > 1 ? $left + ($plot_w * $i / ($n - 1)) : $left + $plot_w / 2;
}

/**
 * 1. Single-series line. Completed jobs over time. No legend: the card heading
 * names the series, and a legend for one series is furniture.
 */
function chart_line_chart(array $series, array $scale, string $id = 'line', string $unit = ''): string
{
    $w = 720; $h = 240;
    $left = 46; $right = 12; $top = 14; $bottom = 30;
    $plot_w = $w - $left - $right;
    $plot_h = $h - $top - $bottom;
    $n = count($series);
    $max = (float)$scale['max'];

    ob_start();
    ?>
<svg class="chart" viewBox="0 0 <?= $w ?> <?= $h ?>" role="img"
     id="<?= e($id) ?>" data-chart="line"
     aria-label="Line chart of <?= $n ?> days">

  <?php foreach ($scale['ticks'] as $tick): ?>
    <?php $ty = $top + $plot_h - ($plot_h * $tick / $max); ?>
    <line class="grid-line" x1="<?= $left ?>" y1="<?= chart_n($ty) ?>"
          x2="<?= $w - $right ?>" y2="<?= chart_n($ty) ?>"/>
    <text x="<?= $left - 8 ?>" y="<?= chart_n($ty + 4) ?>" text-anchor="end"><?= (int)round((float)$tick) ?></text>
  <?php endforeach; ?>

  <line class="axis-line" x1="<?= $left ?>" y1="<?= $top + $plot_h ?>"
        x2="<?= $w - $right ?>" y2="<?= $top + $plot_h ?>"/>

  <?php if ($n > 1): ?>
    <polyline class="series-line s1" points="<?php foreach ($series as $i => $point):
        $px = $left + ($plot_w * $i / ($n - 1));
        $py = $top + $plot_h - ($plot_h * $point['value'] / $max);
        echo chart_n($px) . ',' . chart_n($py) . ' ';
      endforeach; ?>"/>
  <?php endif; ?>

  <?php foreach ($series as $i => $point): ?>
    <?php $px = chart_px($i, $n, $left, $plot_w); $py = $top + $plot_h - ($plot_h * $point['value'] / $max); ?>
    <?php if ($n <= 40): ?>
      <circle class="marker s1" cx="<?= chart_n($px) ?>" cy="<?= chart_n($py) ?>"/>
    <?php endif; ?>
    <circle class="hit" cx="<?= chart_n($px) ?>" cy="<?= chart_n($py) ?>" r="12"
            data-label="<?= e($point['label']) ?>"
            data-value="<?= e($point['value'] . $unit) ?>">
      <title><?= e($point['label']) ?>: <?= e($point['value'] . $unit) ?></title>
    </circle>
  <?php endforeach; ?>

  <?php $half = intdiv($n, 2); ?>
  <?php foreach ($series as $i => $point): ?>
    <?php if ($n <= 10 || $i === 0 || $i === $n - 1 || $i === $half): ?>
      <?php $px = chart_px($i, $n, $left, $plot_w); ?>
      <text x="<?= chart_n($px) ?>" y="<?= $h - 10 ?>"
            text-anchor="<?= $i === 0 ? 'start' : ($i === $n - 1 ? 'end' : 'middle') ?>"><?= e($point['label']) ?></text>
    <?php endif; ?>
  <?php endforeach; ?>
</svg>
    <?php
    return (string)ob_get_clean();
}

/**
 * 2. Horizontal bars. Earnings by service. Horizontal because service names are
 * words, and words read straight rather than rotated 90 degrees.
 */
function chart_bar_chart(array $items, float $max_value): string
{
    $row_h = 34; $gap = 8;
    $w = 720;
    $label_w = 210; $value_w = 110;
    $bar_w = $w - $label_w - $value_w;
    $h = count($items) * ($row_h + $gap);

    ob_start();
    ?>
<svg class="chart" viewBox="0 0 <?= $w ?> <?= $h > 0 ? $h : 1 ?>" role="img"
     data-chart="bar" aria-label="Earnings by service">
  <?php foreach ($items as $i => $item): ?>
    <?php
      $y = $i * ($row_h + $gap);
      $width = $max_value ? round($bar_w * $item['value'] / $max_value, 2) : 0;
    ?>
    <text x="0" y="<?= chart_n($y + $row_h / 2 + 4) ?>" class="value-label"><?= e(chart_truncate($item['label'], 28)) ?></text>

    <rect class="bar" x="<?= $label_w ?>" y="<?= $y + 4 ?>"
          width="<?= chart_n($width > 2 ? $width : 2) ?>" height="<?= $row_h - 8 ?>" rx="4">
      <title><?= e($item['label']) ?>: <?= e(fmt_money($item['value'])) ?> from <?= e($item['count']) ?> job<?= (int)$item['count'] === 1 ? '' : 's' ?></title>
    </rect>

    <text x="<?= $w ?>" y="<?= chart_n($y + $row_h / 2 + 4) ?>" text-anchor="end" class="value-label"><?= e(fmt_money($item['value'])) ?></text>
  <?php endforeach; ?>
</svg>
    <?php
    return (string)ob_get_clean();
}

/**
 * 3. Weekday x hour heatmap. Busiest hours. The full sequential ramp is correct
 * here: this is continuous magnitude, so the light end carries "few", not
 * "unreadable category".
 */
function chart_heatmap(array $heat): string
{
    $cell = 34; $label_w = 46; $header_h = 20;
    $hours = $heat['hours'];
    $w = $label_w + count($hours) * $cell;
    $h = $header_h + 7 * $cell;
    $max = (int)$heat['max'];

    ob_start();
    ?>
<svg class="chart" viewBox="0 0 <?= $w ?> <?= $h ?>" role="img"
     data-chart="heat" aria-label="Arrivals by weekday and hour">

  <?php foreach ($hours as $i => $hour): ?>
    <?php if (count($hours) <= 12 || $i % 2 === 0): ?>
      <text x="<?= $label_w + $i * $cell + $cell / 2 ?>" y="<?= $header_h - 6 ?>"
            text-anchor="middle"><?= sprintf('%02d', $hour) ?></text>
    <?php endif; ?>
  <?php endforeach; ?>

  <?php foreach ($heat['rows'] as $r => $row): ?>
    <?php $ry = $header_h + $r * $cell; ?>
    <text x="0" y="<?= $ry + $cell / 2 + 4 ?>"><?= e($row['short']) ?></text>
    <?php foreach ($row['cells'] as $c => $item): ?>
      <?php
        // Seven buckets across the observed range. An empty hour gets the sunken
        // page surface rather than the lightest blue, so "nothing happened" and
        // "one thing happened" are never the same colour.
        if (!$item['value']) {
            $step = 0;
        } elseif ($max <= 1) {
            $step = 4;
        } else {
            $step = 1 + (int)floor(6 * ($item['value'] - 1) / ($max - 1));
        }
        $fill = $step === 0 ? 'var(--surface-sunken)' : 'var(--seq-' . ($step * 100) . ')';
      ?>
      <rect class="cell" x="<?= $label_w + $c * $cell ?>" y="<?= $ry ?>"
            width="<?= $cell ?>" height="<?= $cell ?>" rx="3"
            fill="<?= $fill ?>">
        <title><?= e($row['name']) ?> <?= sprintf('%02d', $item['hour']) ?>:00 - <?= e($item['value']) ?> arrival<?= (int)$item['value'] === 1 ? '' : 's' ?></title>
      </rect>
      <?php if ($item['value']): ?>
        <text x="<?= $label_w + $c * $cell + $cell / 2 ?>" y="<?= $ry + $cell / 2 + 4 ?>"
              text-anchor="middle"
              fill="<?= $step >= 5 ? 'var(--heat-ink-high)' : 'var(--heat-ink-low)' ?>"><?= e($item['value']) ?></text>
      <?php endif; ?>
    <?php endforeach; ?>
  <?php endforeach; ?>
</svg>
    <?php
    return (string)ob_get_clean();
}

/**
 * 4. Two-series line. Average wait vs average service time. Two series on ONE
 * shared minutes axis - never a second y-axis, which would let the crossover
 * point be moved anywhere the scaling chose.
 */
function chart_dual_line(
    array $series_a,
    array $series_b,
    array $segments_a,
    array $segments_b,
    array $scale,
    string $label_a = 'Series 1',
    string $label_b = 'Series 2',
    string $unit = ' min'
): string {
    $w = 720; $h = 240;
    $left = 46; $right = 12; $top = 14; $bottom = 30;
    $plot_w = $w - $left - $right;
    $plot_h = $h - $top - $bottom;
    $n = count($series_a);
    $max = (float)$scale['max'];

    ob_start();
    ?>
<svg class="chart" viewBox="0 0 <?= $w ?> <?= $h ?>" role="img"
     data-chart="line" aria-label="<?= e($label_a) ?> against <?= e($label_b) ?>">

  <?php foreach ($scale['ticks'] as $tick): ?>
    <?php $ty = $top + $plot_h - ($plot_h * $tick / $max); ?>
    <line class="grid-line" x1="<?= $left ?>" y1="<?= chart_n($ty) ?>"
          x2="<?= $w - $right ?>" y2="<?= chart_n($ty) ?>"/>
    <text x="<?= $left - 8 ?>" y="<?= chart_n($ty + 4) ?>" text-anchor="end"><?= (int)round((float)$tick) ?></text>
  <?php endforeach; ?>

  <line class="axis-line" x1="<?= $left ?>" y1="<?= $top + $plot_h ?>"
        x2="<?= $w - $right ?>" y2="<?= $top + $plot_h ?>"/>

  <?php foreach ([[$segments_a, 's1'], [$segments_b, 's2']] as [$runs, $css]): ?>
    <?php foreach ($runs as $run): ?>
      <?php if (count($run) > 1): ?>
        <polyline class="series-line <?= $css ?>" points="<?php foreach ($run as $point):
            $px = chart_px((int)$point['i'], $n, $left, $plot_w);
            $py = $top + $plot_h - ($plot_h * $point['value'] / $max);
            echo chart_n($px) . ',' . chart_n($py) . ' ';
          endforeach; ?>"/>
      <?php endif; ?>
      <?php foreach ($run as $point): ?>
        <?php $px = chart_px((int)$point['i'], $n, $left, $plot_w); $py = $top + $plot_h - ($plot_h * $point['value'] / $max); ?>
        <circle class="marker <?= $css ?>" cx="<?= chart_n($px) ?>" cy="<?= chart_n($py) ?>"<?= count($run) === 1 ? ' r="5"' : ($n > 40 ? ' r="0"' : '') ?>/>
        <circle class="hit" cx="<?= chart_n($px) ?>" cy="<?= chart_n($py) ?>" r="10"
                data-label="<?= e($point['label']) ?>"
                data-value="<?= e(chart_n(round((float)$point['value'], 1)) . $unit) ?>">
          <title><?= e($point['label']) ?> - <?= e($css === 's1' ? $label_a : $label_b) ?>: <?= e(chart_n(round((float)$point['value'], 1)) . $unit) ?></title>
        </circle>
      <?php endforeach; ?>
    <?php endforeach; ?>
  <?php endforeach; ?>

  <?php $half = intdiv($n, 2); ?>
  <?php foreach ($series_a as $i => $point): ?>
    <?php if ($n <= 10 || $i === 0 || $i === $n - 1 || $i === $half): ?>
      <?php $px = chart_px($i, $n, $left, $plot_w); ?>
      <text x="<?= chart_n($px) ?>" y="<?= $h - 10 ?>"
            text-anchor="<?= $i === 0 ? 'start' : ($i === $n - 1 ? 'end' : 'middle') ?>"><?= e($point['label']) ?></text>
    <?php endif; ?>
  <?php endforeach; ?>
</svg>

<div class="legend" style="margin-top:0.5rem;">
  <span class="key"><span class="swatch s1"></span><?= e($label_a) ?></span>
  <span class="key"><span class="swatch s2"></span><?= e($label_b) ?></span>
</div>
    <?php
    return (string)ob_get_clean();
}
