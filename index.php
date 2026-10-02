<?php
// Front controller. Every non-asset request lands here (see .htaccess):
// bootstrap the app, start the session, enforce CSRF on non-safe methods, then
// dispatch to a handler. HttpError anywhere in the stack becomes a clean error
// page (or JSON for /api/) instead of a stack trace.

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

start_session();

try {
    verify_csrf();
    dispatch(request_method(), request_path());
} catch (HttpError $e) {
    $status = $e->status;
    if (wants_json()) {
        json_out(['error' => $e->getMessage() ?: 'error'], $status);
    }
    render('error', [
        'title'           => "Error {$status}",
        'status'          => $status,
        'message'         => $e->getMessage(),
        'container_class' => 'narrow',
    ], $status);
} catch (Throwable $e) {
    // Anything unhandled is a bug or an outage: log it in full server-side, show
    // the client nothing but a generic 500.
    error_log('Unhandled ' . get_class($e) . ': ' . $e->getMessage() . ' @ '
        . $e->getFile() . ':' . $e->getLine());
    if (wants_json()) {
        json_out(['error' => 'internal error'], 500);
    }
    render('error', [
        'title'           => 'Error 500',
        'status'          => 500,
        'message'         => '',
        'container_class' => 'narrow',
    ], 500);
}
