<?php
// Page shell shared by every rendered view. render() populates $title and
// $content (the already-rendered inner view) and includes this file. Navigation
// is derived from the session user; the active link from the request path.

$__user = current_user();
$__role = $__user['role'] ?? null;
$__name = $__user['full_name'] ?? null;
$__home = home_route_for($__role);
$__path = request_path();
$__active = static fn(string ...$paths): string => in_array($__path, $paths, true) ? 'active' : '';
// The landing poster supplies its own header, so the global nav is suppressed
// there (chrome === false). Every other page keeps the shared nav.
$__chrome = ($vars['chrome'] ?? true) !== false;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? 'FreelanceQueue') ?></title>
<link rel="stylesheet" href="/assets/css/app.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@1,9..144,400;1,9..144,500&family=Space+Grotesk:wght@400;500;600;700&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<script>
  /* Applied before first paint so a dark-mode user never sees a light flash. */
  (function () {
    try {
      var saved = localStorage.getItem("fq-theme");
      if (saved === "dark" || saved === "light") {
        document.documentElement.setAttribute("data-theme", saved);
      }
    } catch (e) { /* private mode with storage disabled - fall back to OS */ }
  })();
</script>
</head>
<body>

<?php if ($__chrome): ?>
<nav class="nav">
  <a class="brand" href="<?= e(url($__home)) ?>">Freelance<span>Queue</span></a>

  <?php if ($__role === 'freelancer'): ?>
    <div class="nav-links">
      <a href="<?= e(url('freelancer.dashboard')) ?>" class="<?= $__active('/dashboard') ?>">Dashboard</a>
      <a href="<?= e(url('freelancer.services')) ?>" class="<?= $__active('/services') ?>">Services</a>
      <a href="<?= e(url('freelancer.hours')) ?>" class="<?= $__active('/hours') ?>">Availability</a>
      <a href="<?= e(url('freelancer.reports')) ?>" class="<?= $__active('/reports') ?>">Reports</a>
    </div>
  <?php elseif ($__role === 'client'): ?>
    <div class="nav-links">
      <a href="<?= e(url('client.browse')) ?>" class="<?= $__active('/browse') ?>">Find a freelancer</a>
      <a href="<?= e(url('client.tickets')) ?>" class="<?= str_starts_with($__path, '/ticket') ? 'active' : '' ?>">My tickets</a>
    </div>
  <?php endif; ?>

  <div class="nav-right">
    <button type="button" class="btn sm" id="theme-toggle"
            aria-label="Switch between light and dark theme">
      <span id="theme-toggle-label">Theme</span>
    </button>
    <?php if ($__name): ?>
      <span class="nav-user"><?= e($__name) ?></span>
      <form method="post" action="<?= e(url('auth.logout')) ?>" class="inline-form">
        <?= csrf_input() ?>
        <button type="submit" class="btn sm">Sign out</button>
      </form>
    <?php else: ?>
      <a class="btn sm" href="<?= e(url('auth.login')) ?>">Sign in</a>
    <?php endif; ?>
  </div>
</nav>
<?php endif; ?>

<div class="container <?= e($vars['container_class'] ?? '') ?>">
  <?php $__flashes = get_flashes(); ?>
  <?php if ($__flashes): ?>
    <div class="flashes">
      <?php $__icons = ['success' => '✓', 'error' => '✕', 'warning' => '!']; ?>
      <?php foreach ($__flashes as $__f): ?>
        <div class="flash <?= e($__f['category']) ?>" role="status">
          <span class="flash-icon" aria-hidden="true"><?= e($__icons[$__f['category']] ?? 'i') ?></span>
          <span><?= e($__f['message']) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?= $content ?>
</div>

<script>
  (function () {
    var btn = document.getElementById("theme-toggle");
    var label = document.getElementById("theme-toggle-label");
    if (!btn || !label) return; /* a chrome-less page may omit the toggle */

    function effective() {
      var explicit = document.documentElement.getAttribute("data-theme");
      if (explicit) return explicit;
      return window.matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light";
    }
    function paintLabel() {
      label.textContent = effective() === "dark" ? "Light mode" : "Dark mode";
    }
    btn.addEventListener("click", function () {
      var next = effective() === "dark" ? "light" : "dark";
      document.documentElement.setAttribute("data-theme", next);
      try { localStorage.setItem("fq-theme", next); } catch (e) {}
      paintLabel();
      document.dispatchEvent(new CustomEvent("fq:themechange"));
    });
    paintLabel();
  })();
</script>
<?php foreach (page_scripts() as $__src): ?>
<script src="<?= e($__src) ?>"></script>
<?php endforeach; ?>
</body>
</html>
