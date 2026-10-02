<div class="page-head">
  <div>
    <h1><?= e($freelancer['full_name']) ?></h1>
    <p>
      <?= e($freelancer['headline'] ?: 'No description yet.') ?>
      <?php if ((int)$freelancer['queue_open']): ?>
        <span class="badge open"><span class="dot"></span>Queue open</span>
      <?php else: ?>
        <span class="badge closed"><span class="dot"></span>Queue closed</span>
      <?php endif; ?>
    </p>
  </div>
  <div class="page-head-actions">
    <a class="btn sm" href="<?= e(url('client.browse')) ?>">Back to list</a>
  </div>
</div>

<div class="grid cols-3" style="margin-bottom:1rem;">
  <?= stat_tile('People waiting', $waiting_count) ?>
  <?= stat_tile('Being served now',
        $serving ? $serving['ticket_no'] : 'Nobody',
        $serving ? $serving['service_name'] : 'The chair is free') ?>
  <?= stat_tile('If you joined now', fmt_mins($wait_if_joining),
        'Before your own appointment starts') ?>
</div>

<?php /* --- the one thing this page exists for --- */ ?>
<?php if ($mine): ?>
  <div class="card" style="border-color: var(--series-1);">
    <div class="card-head">
      <h2>You're in this queue</h2>
      <div class="card-head-actions">
        <a class="btn btn-primary sm"
           href="<?= e(url('client.ticket', ['entry_id' => $mine['id']])) ?>">Open my ticket</a>
      </div>
    </div>
    <p class="dim" style="margin:0;">
      <span class="ticket"><?= e($mine['ticket_no']) ?></span> for <?= e($mine['service_name']) ?>.
    </p>
  </div>

<?php elseif ($active_elsewhere): ?>
  <div class="card" style="border-color: var(--warning);">
    <div class="card-head"><h2>Finish your current ticket first</h2></div>
    <p class="dim">
      You're already waiting for <?= e($active_elsewhere['freelancer_name']) ?>
      (<span class="ticket"><?= e($active_elsewhere['ticket_no']) ?></span>). Cancel that
      one if you'd rather queue here instead - holding two places at once would
      cost somebody else their turn.
    </p>
    <a class="btn" href="<?= e(url('client.ticket', ['entry_id' => $active_elsewhere['id']])) ?>">
      View that ticket
    </a>
  </div>

<?php elseif (!(int)$freelancer['queue_open']): ?>
  <div class="card">
    <div class="card-head"><h2>Not taking anyone right now</h2></div>
    <p class="dim" style="margin:0;">
      <?= e($freelancer['full_name']) ?> has closed their queue. Their usual hours are
      below - try again then.
    </p>
  </div>

<?php elseif (!$services): ?>
  <div class="card">
    <div class="card-head"><h2>Nothing bookable yet</h2></div>
    <p class="dim" style="margin:0;">
      <?= e($freelancer['full_name']) ?> hasn't published any services.
    </p>
  </div>

<?php else: ?>
  <div class="card">
    <div class="card-head"><h2>Join the queue</h2></div>
    <form method="post"
          action="<?= e(url('client.join', ['freelancer_id' => $freelancer['id']])) ?>">
      <?= csrf_input() ?>

      <div class="field">
        <span class="field-label">What do you need?</span>
        <div class="role-choice">
          <?php foreach ($services as $__i => $service): ?>
            <label>
              <input type="radio" name="service_id" value="<?= e($service['id']) ?>"
                     <?= $__i === 0 ? 'checked' : '' ?> required>
              <?= e($service['name']) ?>
              <span class="role-desc">
                <?= e(fmt_money($service['price'])) ?> &middot; about <?= e(fmt_mins($service['est_minutes'])) ?>
              </span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="field">
        <label for="note">Anything they should know? (optional)</label>
        <input type="text" id="note" name="note" maxlength="200"
               placeholder="e.g. Following up on last week's draft">
      </div>

      <button type="submit" class="btn btn-primary">
        Join the queue<?php if ($waiting_count): ?> - you'd be number <?= e($waiting_count + 1) ?><?php endif; ?>
      </button>
      <p class="muted" style="margin-bottom:0;font-size:.85rem;">
        You'll get a ticket number and an email when you're nearly up.
      </p>
    </form>
  </div>
<?php endif; ?>

<div class="card">
  <div class="card-head"><h2>Usual hours</h2></div>
  <?php if ($hours): ?>
    <?php foreach ($hours as $row): ?>
      <div class="week-row" style="grid-template-columns: 10rem 1fr;">
        <div class="day <?= (int)$row['weekday'] === $today_weekday ? 'dim' : '' ?>">
          <?= e($weekday_names[$row['weekday']]) ?>
          <?php if ((int)$row['weekday'] === $today_weekday): ?><span class="muted">(today)</span><?php endif; ?>
        </div>
        <div class="<?= (int)$row['is_open'] ? 'dim' : 'muted' ?>">
          <?php if ((int)$row['is_open']): ?><?= e($row['open_time']) ?> - <?= e($row['close_time']) ?><?php else: ?>Closed<?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  <?php else: ?>
    <div class="empty">No hours published.</div>
  <?php endif; ?>
</div>
