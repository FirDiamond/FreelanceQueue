<div class="page-head">
  <div>
    <h1>
      <span class="ticket"><?= e($entry['ticket_no']) ?></span>
      <?= e($entry['service_name']) ?>
    </h1>
    <p>
      with <?= e($entry['freelancer_name']) ?> &middot; joined <?= e(fmt_localdatetime($entry['joined_at'])) ?>
      &middot; <?= status_badge($entry['status']) ?>
    </p>
  </div>
  <div class="page-head-actions">
    <a class="btn sm" href="<?= e(url('client.tickets')) ?>">All my tickets</a>
  </div>
</div>

<?php if ($entry['status'] === 'waiting'): ?>
  <div class="card">
    <div class="card-head"><h2>Your place in line</h2></div>

    <div class="hero-figure">
      <span class="tabular" id="tk-position"><?= e($position) ?></span>
    </div>
    <p class="dim" style="text-align:center;font-size:1.1rem;margin-top:0;">
      <span id="tk-phrase"><?= e($phrase) ?></span>
    </p>

    <div class="grid cols-2">
      <?= stat_tile('Estimated wait', fmt_mins($eta_minutes), 'Updates as the queue moves', 'tk-eta') ?>
      <?= stat_tile('People waiting in total', $waiting_count, 'Including you', 'tk-waiting-count') ?>
    </div>

    <p class="muted" style="text-align:center;margin-bottom:0.5rem;">
      Now serving
      <span id="tk-serving"><?= e($serving ? $serving['ticket_no'] : 'Nobody yet') ?></span>
    </p>

    <p class="poll-note">
      This page updates itself every few seconds - no need to refresh. We'll
      email you when you're nearly up.
    </p>

    <form method="post" action="<?= e(url('client.cancel', ['entry_id' => $entry['id']])) ?>"
          onsubmit="return confirm('Leave the queue? You would have to rejoin at the back.');">
      <?= csrf_input() ?>
      <button type="submit" class="btn btn-danger">Leave the queue</button>
    </form>
  </div>

<?php elseif ($entry['status'] === 'serving'): ?>
  <div class="now-serving">
    <div class="ns-top">
      <span class="ticket"><?= e($entry['ticket_no']) ?></span>
      <span class="ns-name">It's your turn</span>
      <?php /* The timer counts up from started_at in the browser - no request per second. */ ?>
      <span class="ns-timer" id="ns-timer" data-started="<?= e(iso_utc($entry['started_at'])) ?>">--:--</span>
    </div>
    <div class="ns-meta">
      <?= e($entry['freelancer_name']) ?> started your <?= e($entry['service_name']) ?> at
      <?= e(fmt_localtime($entry['started_at'])) ?>.
    </div>
  </div>

<?php else: ?>
  <div class="card">
    <div class="card-head">
      <h2><?= e(fmt_status_label($entry['status'])) ?></h2>
      <div class="card-head-actions"><?= status_badge($entry['status']) ?></div>
    </div>
    <p class="dim">
      <?php if ($entry['status'] === 'done'): ?>
        Finished <?= e(fmt_localdatetime($entry['ended_at'])) ?>.
        <?php if ($entry['price_charged'] !== null): ?>
          Charged <?= e(fmt_money($entry['price_charged'])) ?>.
        <?php endif; ?>
      <?php elseif ($entry['status'] === 'cancelled'): ?>
        You left this queue on <?= e(fmt_localdatetime($entry['ended_at'])) ?>.
      <?php elseif ($entry['status'] === 'no_show'): ?>
        <?= e($entry['freelancer_name']) ?> marked this as a no-show on
        <?= e(fmt_localdatetime($entry['ended_at'])) ?>.
      <?php else: ?>
        <?= e($entry['freelancer_name']) ?> closed their queue before reaching you on
        <?= e(fmt_localdatetime($entry['ended_at'])) ?>. Nothing was charged.
      <?php endif; ?>
    </p>
    <a class="btn btn-primary"
       href="<?= e(url('client.freelancer_detail', ['freelancer_id' => $entry['freelancer_id']])) ?>">
      Join <?= e($entry['freelancer_name']) ?>'s queue again
    </a>
  </div>
<?php endif; ?>

<?php if ($entry['note']): ?>
  <div class="card">
    <div class="card-head"><h2>Your note</h2></div>
    <p class="dim" style="margin:0;"><?= e($entry['note']) ?></p>
  </div>
<?php endif; ?>

<script src="/assets/js/poll.js"></script>
<script>
  FQ.elapsedTimer("ns-timer");
  <?php if (in_array($entry['status'], ['waiting', 'serving'], true)): ?>
    FQ.pollTicket(<?= json_encode((int)$entry['id']) ?>, <?= json_encode($entry['status']) ?>);
  <?php endif; ?>
</script>
