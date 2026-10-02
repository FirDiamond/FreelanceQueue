<?php
// Request bootstrap: load config + infra, configure the session, set UTC-aware
// defaults, and pull in the repo/service/controller layers. Included first by
// both index.php (web) and seed.php (CLI).

declare(strict_types=1);

const IS_CLI = PHP_SAPI === 'cli';

require __DIR__ . '/config.php';

// Errors: never leak stack traces or SQL to a browser; always log server-side.
error_reporting(E_ALL);
ini_set('log_errors', '1');
if (!IS_CLI) {
    ini_set('display_errors', '0');
} else {
    ini_set('display_errors', '1');
}

// "Local" wall-clock time. Respect an explicit php.ini date.timezone; otherwise
// fall back to UTC so date math is at least internally consistent.
$__tz = ini_get('date.timezone');
date_default_timezone_set($__tz !== '' && $__tz !== false ? $__tz : 'UTC');

require __DIR__ . '/helpers.php';
require __DIR__ . '/timeutil.php';
require __DIR__ . '/db.php';
require __DIR__ . '/csrf.php';

// Repos (all SQL) then services (business logic) then mailer.
foreach (['users', 'services', 'queue', 'reports', 'email_log'] as $__r) {
    require PROJECT_ROOT . '/src/repo/' . $__r . '.php';
}
require __DIR__ . '/auth.php';
foreach (['estimate_service', 'queue_service', 'report_service', 'notify_service', 'auth_service'] as $__s) {
    require PROJECT_ROOT . '/src/service/' . $__s . '.php';
}
require __DIR__ . '/mailer.php';
require __DIR__ . '/router.php';

// Controllers (HTTP glue) and view helpers are only needed for web requests.
if (!IS_CLI) {
    require PROJECT_ROOT . '/views/partials/macros.php';
    require PROJECT_ROOT . '/views/partials/charts.php';
    foreach (['auth', 'client', 'freelancer', 'api'] as $__c) {
        require PROJECT_ROOT . '/controllers/' . $__c . '.php';
    }
}

/** Configure and start the PHP session with hardened cookie params. */
function start_session(): void
{
    $s = settings();
    session_set_cookie_params([
        'lifetime' => 7 * 24 * 3600,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => $s->cookie_secure,
    ]);
    session_name('fq_session');
    session_start();
}
