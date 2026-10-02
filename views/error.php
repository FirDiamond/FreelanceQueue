<?php
// Generic error page. Never shows a stack trace or SQL - just the status, a
// human line, and a way back. The 500 branch in index.php passes an empty
// message on purpose so nothing internal leaks.

$__titles = [
    400 => 'Bad request',
    401 => 'Sign in required',
    403 => 'Not allowed',
    404 => 'Not found',
    405 => 'Method not allowed',
    500 => 'Something went wrong',
];
$__defaults = [
    400 => 'That request could not be processed.',
    401 => 'Please sign in to continue.',
    403 => 'You do not have access to that page.',
    404 => 'We could not find what you were looking for.',
    405 => 'That action is not available here.',
    500 => 'An unexpected error occurred. Please try again.',
];
$__heading = $__titles[$status] ?? 'Error';
$__line    = ($message ?? '') !== '' ? $message : ($__defaults[$status] ?? 'An error occurred.');
$__home    = home_route_for(current_user()['role'] ?? null);
?>
<div class="error-page">
  <div class="code"><?= e($status) ?></div>
  <h1><?= e($__heading) ?></h1>
  <p class="dim"><?= e($__line) ?></p>
  <a class="btn btn-primary" href="<?= e(url($__home)) ?>">Back to safety</a>
</div>
