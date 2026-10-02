<div class="page-head">
  <div>
    <p class="eyebrow" style="margin-bottom:0.35rem"><span class="ascii-pulse" data-ascii="pulse">· live</span></p>
    <h1>Your queue</h1>
    <p>
      <?php if ((int)$user['queue_open']): ?>
        <span class="badge open"><span class="dot"></span>Open</span>
        Clients can join right now.
      <?php else: ?>
        <span class="badge closed"><span class="dot"></span>Closed</span>
        Nobody new can join. Anyone already waiting keeps their place.
      <?php endif; ?>
    </p>
  </div>
  <div class="page-head-actions">
    <form method="post" action="<?= e(url('freelancer.set_queue_open')) ?>">
      <?= csrf_input() ?>
      <input type="hidden" name="open" value="<?= (int)$user['queue_open'] ? '0' : '1' ?>">
      <button type="submit" class="btn <?= (int)$user['queue_open'] ? '' : 'btn-good' ?>">
        <?= (int)$user['queue_open'] ? 'Close queue' : 'Open queue' ?>
      </button>
    </form>
  </div>
</div>

<?php if (!$services): ?>
  <div class="card" style="border-color: var(--warning);">
    <div class="card-head">
      <h2>Add a service first</h2>
    </div>
    <p class="dim">
      Clients pick a service when they join, so your queue stays closed to them
      until you have at least one.
    </p>
    <a class="btn btn-primary" href="<?= e(url('freelancer.services')) ?>">Add a service</a>
  </div>
<?php endif; ?>

<div class="grid cols-4" style="margin-bottom: 1rem;">
  <?= stat_tile('Waiting now', $waiting_count, null, 'stat-waiting') ?>
  <?= stat_tile('Completed today', $stats['done_count'] ?: 0, null, 'stat-done') ?>
  <?= stat_tile('Avg wait today', fmt_mins($stats['avg_wait_minutes']), null, 'stat-wait') ?>
  <?= stat_tile('Earned today', fmt_money($stats['earned'] ?: 0), null, 'stat-earned') ?>
</div>

<?php /* --- now serving --- */ ?>
<?php if ($serving): ?>
  <div class="now-serving">
    <div class="ns-top">
      <span class="ticket"><?= e($serving['ticket_no']) ?></span>
      <span class="ns-name"><?= e($serving['client_name']) ?></span>
      <?= status_badge('serving') ?>
      <?php /* Ticks client-side off started_at - no request per second. */ ?>
      <span class="ns-timer" id="ns-timer" data-started="<?= e(iso_utc($serving['started_at'])) ?>">--:--</span>
    </div>
    <div class="ns-meta">
      <?= e($serving['service_name']) ?> &middot; about
      <span id="ns-remaining"><?= e(fmt_mins($serving_remaining)) ?></span> left
      &middot; started <?= e(fmt_localtime($serving['started_at'])) ?>
      <?php if ($serving['note']): ?><br>Note: <?= e($serving['note']) ?><?php endif; ?>
    </div>

    <div class="ns-actions btn-row">
      <form method="post" action="<?= e(url('freelancer.mark_done', ['entry_id' => $serving['id']])) ?>"
            class="btn-row">
        <?= csrf_input() ?>
        <label for="price_charged" class="muted" style="align-self:center;font-size:.85rem;">
          Charge
        </label>
        <input type="text" id="price_charged" name="price_charged"
               value="<?= e(sprintf('%.0f', (float)$serving['service_price'])) ?>"
               inputmode="decimal" style="width: 8rem;"
               aria-label="Amount charged">
        <button type="submit" class="btn btn-good">Mark done</button>
      </form>

      <form method="post" action="<?= e(url('freelancer.skip_current')) ?>" class="inline-form">
        <?= csrf_input() ?>
        <button type="submit" class="btn">Skip to back</button>
      </form>

      <form method="post" action="<?= e(url('freelancer.mark_no_show', ['entry_id' => $serving['id']])) ?>"
            class="inline-form">
        <?= csrf_input() ?>
        <button type="submit" class="btn btn-danger">No-show</button>
      </form>
    </div>
  </div>
<?php else: ?>
  <div class="card">
    <div class="card-head">
      <h2>Nobody being served</h2>
      <div class="card-head-actions">
        <form method="post" action="<?= e(url('freelancer.call_next')) ?>" class="inline-form">
          <?= csrf_input() ?>
          <button type="submit" class="btn btn-primary" <?= !$waiting ? 'disabled' : '' ?>>
            Call next
          </button>
        </form>
      </div>
    </div>
    <p class="dim" style="margin:0;">
      <?php if ($waiting): ?>
        <?= e($waiting[0]['row']['client_name']) ?> is up next for <?= e($waiting[0]['row']['service_name']) ?>.
      <?php else: ?>
        The queue is empty.
      <?php endif; ?>
    </p>
  </div>
<?php endif; ?>

<?php /* --- waiting list --- */ ?>
<div class="card">
  <div class="card-head">
    <h2>Waiting <span class="muted tabular">(<?= e($waiting_count) ?>)</span></h2>
    <div class="card-head-actions btn-row">
      <?php if ($serving): ?>
        <form method="post" action="<?= e(url('freelancer.call_next')) ?>" class="inline-form">
          <?= csrf_input() ?>
          <button type="submit" class="btn" disabled title="Finish the current client first">
            Call next
          </button>
        </form>
      <?php endif; ?>
      <?php if ($waiting): ?>
        <form method="post" action="<?= e(url('freelancer.clear_queue')) ?>" class="inline-form"
              onsubmit="return confirm('Close all <?= e($waiting_count) ?> waiting tickets and email everyone?');">
          <?= csrf_input() ?>
          <button type="submit" class="btn btn-danger">Clear queue</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($waiting): ?>
    <div class="queue-list">
      <?php $__last = count($waiting) - 1; ?>
      <?php foreach ($waiting as $__idx => $item): ?>
        <div class="queue-row">
          <div class="pos"><?= e($item['position']) ?></div>
          <div class="who">
            <div class="name">
              <span class="ticket"><?= e($item['row']['ticket_no']) ?></span>
              <?= e($item['row']['client_name']) ?>
            </div>
            <div class="svc">
              <?= e($item['row']['service_name']) ?> &middot; <?= e(fmt_mins($item['duration_minutes'])) ?><?php if (!$item['measured']): ?> <span class="muted">(estimated)</span><?php endif; ?>
              <?php if ($item['row']['note']): ?><br>Note: <?= e($item['row']['note']) ?><?php endif; ?>
            </div>
          </div>
          <div class="eta nowrap">~<?= e(fmt_mins($item['eta_minutes'])) ?></div>
          <div class="row-actions">
            <form method="post" action="<?= e(url('freelancer.move_entry', ['entry_id' => $item['row']['id']])) ?>"
                  class="inline-form">
              <?= csrf_input() ?>
              <input type="hidden" name="direction" value="up">
              <button type="submit" class="btn btn-icon" aria-label="Move up"
                      <?= $__idx === 0 ? 'disabled' : '' ?>>&uarr;</button>
            </form>
            <form method="post" action="<?= e(url('freelancer.move_entry', ['entry_id' => $item['row']['id']])) ?>"
                  class="inline-form">
              <?= csrf_input() ?>
              <input type="hidden" name="direction" value="down">
              <button type="submit" class="btn btn-icon" aria-label="Move down"
                      <?= $__idx === $__last ? 'disabled' : '' ?>>&darr;</button>
            </form>
            <form method="post"
                  action="<?= e(url('freelancer.mark_no_show', ['entry_id' => $item['row']['id']])) ?>"
                  class="inline-form">
              <?= csrf_input() ?>
              <button type="submit" class="btn btn-icon" aria-label="Mark as no-show"
                      title="No-show">&times;</button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <p class="poll-note" style="margin: 0.75rem 0 0;">
      Updates automatically every 5 seconds.
    </p>
  <?php else: ?>
    <div class="empty">
      Nobody is waiting.
      <?php if (!(int)$user['queue_open']): ?>Your queue is closed.<?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php /* --- today's log --- */ ?>
<div class="card">
  <div class="card-head">
    <h2>Today</h2>
    <div class="card-head-actions">
      <a class="btn sm" href="<?= e(url('freelancer.reports')) ?>">See reports</a>
    </div>
  </div>

  <?php if ($log): ?>
    <table>
      <thead>
        <tr>
          <th>Ticket</th><th>Client</th><th>Service</th><th>Status</th>
          <th class="tabular">Joined</th><th class="tabular">Wait</th>
          <th class="tabular right">Charged</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($log as $row): ?>
          <tr>
            <td><span class="ticket"><?= e($row['ticket_no']) ?></span></td>
            <td><?= e($row['client_name']) ?></td>
            <td><?= e($row['service_name']) ?></td>
            <td><?= status_badge($row['status']) ?></td>
            <td class="tabular"><?= e(fmt_localtime($row['joined_at'])) ?></td>
            <td class="tabular">
              <?php if ($row['started_at']): ?><?= e(fmt_wait_to($row['joined_at'], $row['started_at'])) ?><?php else: ?>-<?php endif; ?>
            </td>
            <td class="tabular right"><?= e(fmt_money($row['price_charged'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php else: ?>
    <div class="empty">Nothing has happened today yet.</div>
  <?php endif; ?>
</div>

<script src="/assets/js/poll.js"></script>
<script>
  FQ.elapsedTimer("ns-timer");
  FQ.pollQueue(<?= json_encode($signature) ?>);
</script>
