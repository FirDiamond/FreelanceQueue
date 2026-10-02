<div class="page-head">
  <div>
    <h1>Find a freelancer</h1>
    <p>Join a queue and watch your position from your own device.</p>
  </div>
</div>

<?php if ($active): ?>
  <div class="card" style="border-color: var(--series-1);">
    <div class="card-head">
      <h2>You're already in a queue</h2>
      <div class="card-head-actions">
        <a class="btn btn-primary sm"
           href="<?= e(url('client.ticket', ['entry_id' => $active['id']])) ?>">View ticket</a>
      </div>
    </div>
    <p class="dim" style="margin:0;">
      <span class="ticket"><?= e($active['ticket_no']) ?></span>
      <?= e($active['service_name']) ?> with <?= e($active['freelancer_name']) ?>.
    </p>
  </div>
<?php endif; ?>

<form method="get" action="<?= e(url('client.browse')) ?>" class="card">
  <div class="field" style="margin:0;">
    <label for="q">Search by name or what they do</label>
    <div class="btn-row">
      <input type="search" id="q" name="q" value="<?= e($search) ?>"
             placeholder="e.g. design, tax, Priya" style="flex:1;">
      <button type="submit" class="btn btn-primary">Search</button>
      <?php if ($search !== ''): ?>
        <a class="btn" href="<?= e(url('client.browse')) ?>">Clear</a>
      <?php endif; ?>
    </div>
  </div>
</form>

<?php if ($freelancers): ?>
  <div class="grid cols-2">
    <?php foreach ($freelancers as $f): ?>
      <?php $__bookable = (int)$f['queue_open'] && (int)$f['service_count']; ?>
      <div class="card">
        <div class="card-head">
          <h2><?= e($f['full_name']) ?></h2>
          <?php if ((int)$f['queue_open']): ?>
            <span class="badge open"><span class="dot"></span>Open</span>
          <?php else: ?>
            <span class="badge closed"><span class="dot"></span>Closed</span>
          <?php endif; ?>
        </div>

        <p class="dim"><?= e($f['headline'] ?: 'No description yet.') ?></p>

        <p class="muted" style="font-size:.9rem;">
          <?= e($f['waiting_count']) ?> waiting &middot;
          <?= e($f['service_count']) ?> service<?= (int)$f['service_count'] === 1 ? '' : 's' ?>
        </p>

        <a class="btn <?= $__bookable ? 'btn-primary' : '' ?>"
           href="<?= e(url('client.freelancer_detail', ['freelancer_id' => $f['id']])) ?>">
          <?= $__bookable ? 'View and join' : 'View details' ?>
        </a>
      </div>
    <?php endforeach; ?>
  </div>
<?php else: ?>
  <div class="empty">
    <?php if ($search !== ''): ?>
      Nobody matches &ldquo;<?= e($search) ?>&rdquo;.
    <?php else: ?>
      No freelancers have signed up yet.
    <?php endif; ?>
  </div>
<?php endif; ?>
