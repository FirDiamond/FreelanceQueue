<div class="page-head">
  <div>
    <h1>My tickets</h1>
    <p>Everywhere you've queued, most recent first. Active ones are at the top.</p>
  </div>
  <div class="page-head-actions">
    <a class="btn sm" href="<?= e(url('client.browse')) ?>">Find a freelancer</a>
  </div>
</div>

<?php if ($entries): ?>
  <div class="card">
    <table>
      <thead>
        <tr>
          <th>Ticket</th><th>Freelancer</th><th>Service</th><th>Status</th>
          <th class="tabular">Joined</th><th class="tabular right">Charged</th><th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($entries as $entry): ?>
          <?php $__active = in_array($entry['status'], ['waiting', 'serving'], true); ?>
          <tr>
            <td><span class="ticket"><?= e($entry['ticket_no']) ?></span></td>
            <td><?= e($entry['freelancer_name']) ?></td>
            <td><?= e($entry['service_name']) ?></td>
            <td><?= status_badge($entry['status']) ?></td>
            <td class="tabular nowrap"><?= e(fmt_localdatetime($entry['joined_at'])) ?></td>
            <td class="tabular right"><?= e(fmt_money($entry['price_charged'])) ?></td>
            <td class="right">
              <a class="btn sm <?= $__active ? 'btn-primary' : '' ?>"
                 href="<?= e(url('client.ticket', ['entry_id' => $entry['id']])) ?>">
                <?= $__active ? 'Watch' : 'View' ?>
              </a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php else: ?>
  <div class="empty">
    You haven't joined a queue yet.
    <br>
    <a class="btn btn-primary" href="<?= e(url('client.browse')) ?>"
       style="margin-top:0.75rem;">Find a freelancer</a>
  </div>
<?php endif; ?>
