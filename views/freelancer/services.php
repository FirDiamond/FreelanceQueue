<div class="page-head">
  <div>
    <h1>Services</h1>
    <p>What clients can book, what it costs, and how long it usually takes.</p>
  </div>
  <div class="page-head-actions">
    <a class="btn sm" href="<?= e(url('freelancer.hours')) ?>">Availability</a>
  </div>
</div>

<div class="card">
  <div class="card-head"><h2>Add a service</h2></div>
  <form method="post" action="<?= e(url('freelancer.create_service')) ?>">
    <?= csrf_input() ?>
    <div class="grid cols-3">
      <div class="field">
        <label for="name">Name</label>
        <input type="text" id="name" name="name" maxlength="80" required
               placeholder="e.g. Logo concept review">
      </div>
      <div class="field">
        <label for="price">Price</label>
        <input type="text" id="price" name="price" inputmode="decimal" value="0">
      </div>
      <div class="field">
        <label for="est_minutes">Typical duration (minutes)</label>
        <input type="number" id="est_minutes" name="est_minutes" min="1" max="480"
               value="30" required>
        <div class="hint">Used for wait estimates until there's real history.</div>
      </div>
    </div>
    <button type="submit" class="btn btn-primary">Add service</button>
  </form>
</div>

<div class="card">
  <div class="card-head">
    <h2>Your services <span class="muted tabular">(<?= e(count($services)) ?>)</span></h2>
  </div>

  <?php if ($services): ?>
    <table>
      <thead>
        <tr>
          <th>Service</th>
          <th class="tabular">Price</th>
          <th class="tabular">Duration</th>
          <th class="tabular">Booked</th>
          <th>Visible to clients</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($services as $service): ?>
          <tr>
            <td colspan="6" style="padding: 0; border: none;">
              <form method="post"
                    action="<?= e(url('freelancer.update_service', ['service_id' => $service['id']])) ?>">
                <?= csrf_input() ?>
                <div class="week-row"
                     style="grid-template-columns: 2fr 1fr 1fr 1fr auto; border-bottom: 1px solid var(--grid);">
                  <input type="text" name="name" value="<?= e($service['name']) ?>"
                         maxlength="80" required aria-label="Service name">
                  <input type="text" name="price" inputmode="decimal"
                         value="<?= e(sprintf('%.0f', (float)$service['price'])) ?>" aria-label="Price">
                  <input type="number" name="est_minutes" min="1" max="480"
                         value="<?= e($service['est_minutes']) ?>" aria-label="Duration in minutes">
                  <div class="tabular muted">
                    <?= e($service['usage_count']) ?> booking<?= (int)$service['usage_count'] === 1 ? '' : 's' ?>
                    <br>
                    <?php if ((int)$service['is_active']): ?>
                      <span class="badge open"><span class="dot"></span>Bookable</span>
                    <?php else: ?>
                      <span class="badge closed"><span class="dot"></span>Hidden</span>
                    <?php endif; ?>
                  </div>
                  <div class="btn-row">
                    <button type="submit" class="btn sm">Save</button>
                  </div>
                </div>
              </form>

              <div class="btn-row" style="padding: 0 0 0.7rem 0;">
                <form method="post"
                      action="<?= e(url('freelancer.toggle_service', ['service_id' => $service['id']])) ?>"
                      class="inline-form">
                  <?= csrf_input() ?>
                  <button type="submit" class="btn sm">
                    <?= (int)$service['is_active'] ? 'Hide from clients' : 'Make bookable' ?>
                  </button>
                </form>
                <?php if ((int)$service['usage_count'] === 0): ?>
                  <form method="post"
                        action="<?= e(url('freelancer.delete_service', ['service_id' => $service['id']])) ?>"
                        class="inline-form"
                        onsubmit="return confirm('Delete <?= e($service['name']) ?>?');">
                    <?= csrf_input() ?>
                    <button type="submit" class="btn sm btn-danger">Delete</button>
                  </form>
                <?php else: ?>
                  <span class="muted" style="align-self:center;font-size:.8rem;">
                    Booked before, so it can only be hidden - deleting it would
                    rewrite past tickets.
                  </span>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php else: ?>
    <div class="empty">No services yet. Add your first one above.</div>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card-head">
    <h2>This week</h2>
    <div class="card-head-actions">
      <a class="btn sm" href="<?= e(url('freelancer.hours')) ?>">Edit availability</a>
    </div>
  </div>
  <?php foreach ($hours as $row): ?>
    <div class="week-row" style="grid-template-columns: 8rem 1fr;">
      <div class="day"><?= e($weekday_names[$row['weekday']]) ?></div>
      <div class="<?= (int)$row['is_open'] ? 'dim' : 'muted' ?>">
        <?php if ((int)$row['is_open']): ?><?= e($row['open_time']) ?> - <?= e($row['close_time']) ?><?php else: ?>Closed<?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>
