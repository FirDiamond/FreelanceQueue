<?php
// CSRF protection.
//
// One token per session. It is validated once in the front controller for
// every non-safe method, so a new POST route cannot silently forget to opt in.
// The token arrives either in the _csrf form field or the X-CSRF-Token header
// and is compared in constant time.

declare(strict_types=1);

const CSRF_SESSION_KEY = '_csrf';
const CSRF_FORM_FIELD  = '_csrf';
const CSRF_HEADER      = 'HTTP_X_CSRF_TOKEN';
const SAFE_METHODS     = ['GET', 'HEAD', 'OPTIONS', 'TRACE'];

function csrf_token(): string
{
    if (empty($_SESSION[CSRF_SESSION_KEY])) {
        $_SESSION[CSRF_SESSION_KEY] = bin2hex(random_bytes(32));
    }
    return $_SESSION[CSRF_SESSION_KEY];
}

/** Hidden input for POST forms. */
function csrf_input(): string
{
    return '<input type="hidden" name="' . CSRF_FORM_FIELD . '" value="' . e(csrf_token()) . '">';
}

/** Abort 400 unless a valid token accompanies a non-safe request. */
function verify_csrf(): void
{
    if (in_array(request_method(), SAFE_METHODS, true)) {
        return;
    }
    $expected = $_SESSION[CSRF_SESSION_KEY] ?? '';
    $supplied = $_POST[CSRF_FORM_FIELD] ?? ($_SERVER[CSRF_HEADER] ?? '');
    if ($expected === '' || $supplied === '' || !hash_equals($expected, (string)$supplied)) {
        error_log(sprintf(
            'CSRF rejection on %s %s from %s',
            request_method(),
            request_path(),
            client_ip() ?? '?'
        ));
        abort(400, 'Your session expired or the form was tampered with.');
    }
}
