<?php
// Signup and login.
//
// Password hashing is delegated to PHP's password_hash() with PASSWORD_BCRYPT.
// The algorithm, cost and salt are packed into the single stored string, so
// there is no salt column to mismanage and no parameters for this app to pick
// badly. (The Flask app used Werkzeug scrypt; this is a fresh database, so no
// cross-hash interop is needed.)

declare(strict_types=1);

const MIN_PASSWORD_LENGTH = 8;
const AUTH_ROLES          = ['freelancer', 'client'];

// Throttling: 5 failures from one email OR IP inside 15 minutes locks further
// attempts. Modest by design - enough to make online guessing useless without
// locking a real user out for a typo storm.
const MAX_FAILURES           = 5;
const FAILURE_WINDOW_MINUTES = 15;

// Every login failure returns this one string. Distinguishing "no such user"
// from "wrong password" would turn the form into an account-enumeration oracle.
const GENERIC_LOGIN_ERROR = 'Invalid email or password.';

const AUTH_EMAIL_RE = '/^[^@\s]+@[^@\s]+\.[^@\s]+$/';

/** A validation or credential problem safe to show the user verbatim. */
class AuthError extends RuntimeException {}

function auth_validate_signup(string $email, string $full_name, string $role, string $password, string $confirm): void
{
    if (trim($full_name) === '') {
        throw new AuthError('Please enter your name.');
    }
    if (!preg_match(AUTH_EMAIL_RE, trim($email))) {
        throw new AuthError('Please enter a valid email address.');
    }
    if (!in_array($role, AUTH_ROLES, true)) {
        throw new AuthError('Please choose whether you are a freelancer or a client.');
    }
    if (strlen($password) < MIN_PASSWORD_LENGTH) {
        throw new AuthError('Password must be at least ' . MIN_PASSWORD_LENGTH . ' characters long.');
    }
    if ($password !== $confirm) {
        throw new AuthError('The two passwords do not match.');
    }
}

/** Create an account and return the new user row. */
function auth_signup(
    PDO $db,
    string $email,
    string $full_name,
    string $role,
    string $password,
    string $confirm,
    ?string $headline = null
): array {
    auth_validate_signup($email, $full_name, $role, $password, $confirm);

    if (users_email_exists($db, $email)) {
        throw new AuthError('An account with that email already exists.');
    }

    $pwd_hash = password_hash($password, PASSWORD_BCRYPT);
    $headline = trim((string)$headline);
    $headline = $headline === '' ? null : $headline;

    try {
        $user_id = write_tx(function (PDO $db) use ($email, $full_name, $role, $pwd_hash, $headline) {
            $id = users_create($db, $email, $full_name, $role, $pwd_hash, $headline);
            if ($role === 'freelancer') {
                services_seed_default_hours($db, $id);
            }
            return $id;
        });
    } catch (PDOException $e) {
        // The UNIQUE index is the real guard; the check above is just a nicer
        // message. This catches two simultaneous signups for the same address.
        if (is_unique_violation($e)) {
            throw new AuthError('An account with that email already exists.');
        }
        throw $e;
    }

    return users_get_by_id($db, $user_id);
}

/** Verify credentials and return the user row, or throw AuthError. */
function auth_login(PDO $db, string $email, string $password, ?string $ip): array
{
    $email = trim($email);
    if ($email === '' || $password === '') {
        throw new AuthError(GENERIC_LOGIN_ERROR);
    }

    $cutoff = minutes_ago_utc(FAILURE_WINDOW_MINUTES);
    if (users_count_recent_failures($db, $email, $ip, $cutoff) >= MAX_FAILURES) {
        throw new AuthError(
            'Too many failed sign-in attempts. Please wait '
            . FAILURE_WINDOW_MINUTES . ' minutes and try again.'
        );
    }

    $user = users_get_by_email($db, $email);
    if ($user === null || !password_verify($password, $user['pwd_hash'])) {
        write_tx(function (PDO $db) use ($email, $ip) {
            users_record_login_attempt($db, $email, $ip, false);
        });
        throw new AuthError(GENERIC_LOGIN_ERROR);
    }

    write_tx(function (PDO $db) use ($email, $ip) {
        users_record_login_attempt($db, $email, $ip, true);
        users_clear_login_failures($db, $email, $ip);
    });
    return $user;
}
