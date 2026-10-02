<div class="page-head">
  <div>
    <h1>Availability</h1>
    <p>
      The hours clients see on your profile. This is guidance, not a lock -
      the Open/Closed switch on your dashboard is what actually gates joining.
    </p>
  </div>
</div>

<form method="post" action="<?= e(url('freelancer.save_hours')) ?>">
  <?= csrf_input() ?>
  <div class="card">
    <div class="card-head"><h2>Your week</h2></div>

    <?php foreach ($hours as $row): ?>
      <div class="week-row">
        <label class="day" for="open_<?= e($row['weekday']) ?>">
          <input type="checkbox" id="open_<?= e($row['weekday']) ?>"
                 name="open_<?= e($row['weekday']) ?>" value="1"
                 <?= (int)$row['is_open'] ? 'checked' : '' ?>>
          <?= e($weekday_names[$row['weekday']]) ?>
        </label>

        <div class="field" style="margin:0;">
          <label for="open_time_<?= e($row['weekday']) ?>" class="muted">Opens</label>
          <input type="time" id="open_time_<?= e($row['weekday']) ?>"
                 name="open_time_<?= e($row['weekday']) ?>"
                 value="<?= e($row['open_time'] ?: '09:00') ?>">
        </div>

        <div class="field" style="margin:0;">
          <label for="close_time_<?= e($row['weekday']) ?>" class="muted">Closes</label>
          <input type="time" id="close_time_<?= e($row['weekday']) ?>"
                 name="close_time_<?= e($row['weekday']) ?>"
                 value="<?= e($row['close_time'] ?: '17:00') ?>">
        </div>
      </div>
    <?php endforeach; ?>

    <div class="btn-row" style="margin-top: 1rem;">
      <button type="submit" class="btn btn-primary">Save availability</button>
      <a class="btn" href="<?= e(url('freelancer.dashboard')) ?>">Back to queue</a>
    </div>
    <p class="muted" style="margin-bottom:0;font-size:.85rem;">
      Times are 24-hour and in this server's local timezone. Unchecked days show
      as closed; their times are kept so you can switch a day back on without
      retyping them.
    </p>
  </div>
</form>
