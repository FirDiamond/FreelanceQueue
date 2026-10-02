<?php $kpi = $report['kpi']; ?>
<div class="page-head">
  <div>
    <h1>Reports</h1>
    <p><?= e($range_label) ?> &middot; <?= e($start) ?> to <?= e($end) ?></p>
  </div>
  <div class="page-head-actions">
    <a class="btn sm"
       href="<?= e(url('freelancer.export_csv', ['range' => $preset, 'start' => $start, 'end' => $end])) ?>">
      Export CSV
    </a>
  </div>
</div>

<div class="filter-row">
  <?php foreach ($presets as $item): ?>
    <a class="chip <?= $preset === $item['key'] ? 'active' : '' ?>"
       href="<?= e(url('freelancer.reports', ['range' => $item['key']])) ?>"><?= e($item['label']) ?></a>
  <?php endforeach; ?>

  <form method="get" action="<?= e(url('freelancer.reports')) ?>" class="btn-row"
        style="margin-left:auto;">
    <input type="hidden" name="range" value="custom">
    <input type="date" name="start" value="<?= e($start) ?>" aria-label="Start date"
           style="width:auto;">
    <input type="date" name="end" value="<?= e($end) ?>" aria-label="End date"
           style="width:auto;">
    <button type="submit" class="btn sm">Apply</button>
  </form>
</div>

<?php if (!$report['has_data']): ?>
  <div class="empty">
    Nothing happened between <?= e($start) ?> and <?= e($end) ?>.
    <br>
    <a class="btn" href="<?= e(url('freelancer.reports', ['range' => '90d'])) ?>"
       style="margin-top:0.75rem;">Try the last 90 days</a>
  </div>
<?php else: ?>

  <?php /* --- KPI row: four current numbers, deliberately not charts --- */ ?>
  <div class="grid cols-4" style="margin-bottom:1rem;">
    <?= stat_tile('Jobs completed', $kpi['done_count'] ?: 0,
          ($kpi['total_entries'] . ' tickets in total')) ?>
    <?= stat_tile('Earned', fmt_money($kpi['earned'] ?: 0),
          ('across ' . ($kpi['active_days'] ?: 0) . ' active days')) ?>
    <?= stat_tile('Average wait', fmt_dur($kpi['avg_wait_minutes']),
          ('longest ' . fmt_dur($kpi['max_wait_minutes']))) ?>
    <?= stat_tile('Average job length', fmt_dur($kpi['avg_service_minutes']),
          (($kpi['client_count'] ?: 0) . ' different clients')) ?>
  </div>

  <?php /* --- 1. completed over time --- */ ?>
  <div class="card">
    <div class="card-head">
      <h2>Jobs completed per day</h2>
      <div class="card-head-actions muted tabular">
        <?= e($report['completed_total']) ?> total
      </div>
    </div>
    <?= chart_line_chart($report['completed'], $report['completed_scale'], 'chart-completed') ?>
  </div>

  <?php /* --- 2. earnings by service --- */ ?>
  <div class="card">
    <div class="card-head"><h2>Earnings by service</h2></div>
    <?php if ($report['services']): ?>
      <?= chart_bar_chart($report['services'], (float)$report['services_max']) ?>
      <table style="margin-top:1rem;">
        <thead>
          <tr>
            <th>Service</th>
            <th class="tabular right">Jobs</th>
            <th class="tabular right">Average length</th>
            <th class="tabular right">Earned</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($report['services'] as $item): ?>
            <tr>
              <td><?= e($item['label']) ?></td>
              <td class="tabular right"><?= e($item['count']) ?></td>
              <td class="tabular right"><?= e(fmt_dur($item['avg_minutes'])) ?></td>
              <td class="tabular right"><?= e(fmt_money($item['value'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <div class="empty">No completed jobs in this range.</div>
    <?php endif; ?>
  </div>

  <?php /* --- 3. busiest hours --- */ ?>
  <div class="card">
    <div class="card-head">
      <h2>When clients arrive</h2>
      <div class="card-head-actions heat-scale">
        <span>Fewer</span>
        <span class="steps">
          <?php foreach ([100, 200, 300, 400, 500, 600, 700] as $step): ?>
            <i style="background: var(--seq-<?= $step ?>);"></i>
          <?php endforeach; ?>
        </span>
        <span>More</span>
      </div>
    </div>
    <?php if ($report['heat']['total']): ?>
      <?= chart_heatmap($report['heat']) ?>
      <p class="muted" style="margin-bottom:0;font-size:.85rem;">
        Local time, by the hour a client joined the queue - which is what you
        would set your availability against.
      </p>
    <?php else: ?>
      <div class="empty">Nobody joined the queue in this range.</div>
    <?php endif; ?>
  </div>

  <?php /* --- 4. wait vs service time --- */ ?>
  <div class="card">
    <div class="card-head"><h2>Average wait against average job length</h2></div>
    <?= chart_dual_line($report['timing']['wait'], $report['timing']['service'],
          $report['timing_segments']['wait'], $report['timing_segments']['service'],
          $report['timing_scale'],
          'Wait before starting', 'Time spent on the job') ?>
    <p class="muted" style="margin-bottom:0;font-size:.85rem;">
      Both in minutes on one scale. Gaps are days with no data, not zeros -
      the wait line breaks rather than dropping to the floor.
    </p>
  </div>

  <?php /* --- regulars --- */ ?>
  <?php if ($report['clients']): ?>
    <div class="card">
      <div class="card-head"><h2>Your regulars</h2></div>
      <table>
        <thead>
          <tr><th>Client</th><th class="tabular right">Visits</th>
              <th class="tabular right">Earned</th></tr>
        </thead>
        <tbody>
          <?php foreach ($report['clients'] as $row): ?>
            <tr>
              <td><?= e($row['client_name']) ?></td>
              <td class="tabular right"><?= e($row['visits']) ?></td>
              <td class="tabular right"><?= e(fmt_money($row['earned'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <?php /* --- the underlying rows --- */ ?>
  <div class="card">
    <div class="card-head">
      <h2>Every ticket <span class="muted tabular">(<?= e(count($report['entries'])) ?>)</span></h2>
      <div class="card-head-actions">
        <a class="btn sm"
           href="<?= e(url('freelancer.export_csv', ['range' => $preset, 'start' => $start, 'end' => $end])) ?>">
          Export CSV
        </a>
      </div>
    </div>
    <table>
      <thead>
        <tr>
          <th class="tabular">Date</th><th>Ticket</th><th>Client</th><th>Service</th>
          <th>Status</th><th class="tabular right">Wait</th>
          <th class="tabular right">Length</th><th class="tabular right">Charged</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($report['entries'] as $row): ?>
          <tr>
            <td class="tabular nowrap"><?= e($row['queue_date']) ?></td>
            <td><span class="ticket"><?= e($row['ticket_no']) ?></span></td>
            <td><?= e($row['client_name']) ?></td>
            <td><?= e($row['service_name']) ?></td>
            <td><?= status_badge($row['status']) ?></td>
            <td class="tabular right"><?= e(fmt_dur($row['wait_minutes'])) ?></td>
            <td class="tabular right"><?= e(fmt_dur($row['service_minutes'])) ?></td>
            <td class="tabular right"><?= e(fmt_money($row['price_charged'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<script src="/assets/js/chart.js"></script>
