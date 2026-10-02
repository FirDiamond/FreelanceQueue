<?php
// Session, authorization and ownership checks.
//
// Being logged in is never sufficient for a resource: ticket ids are small
// integers and trivially guessable, so handlers that return one client's data
// must also call require_owner(). Ownership failures return 404, not 403, so a
// response cannot confirm that some other client holds a given ticket id.

declare(strict_types=1);

/** Start a session. Regenerates the id and rotates CSRF (fixation guard). */
function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION = [];
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['role']    = $user['role'];
    $_SESSION['name']    = $user['full_name'];
    $_SESSION[CSRF_SESSION_KEY] = bin2hex(random_bytes(32));
}

function logout_user(): void
{
    $_SESSION = [];
    session_regenerate_id(true);
}

/** The logged-in user row, or null. Cached per request. */
function current_user(): ?array
{
    static $cached = false;
    static $user = null;
    if ($cached) {
        return $user;
    }
    $cached = true;
    $id = $_SESSION['user_id'] ?? null;
    $user = $id ? users_get_by_id(db(), (int)$id) : null;
    return $user;
}

function home_route_for(?string $role): string
{
    return $role === 'freelancer' ? 'freelancer.dashboard' : 'client.browse';
}

/** Redirect (or 401 for /api/) unless a valid session user exists. */
function login_required(): void
{
    if (empty($_SESSION['user_id'])) {
        if (wants_json()) {
            json_out(['error' => 'authentication required'], 401);
        }
        flash('Please sign in to continue.', 'warning');
        redirect(url('auth.login', ['next' => request_full_path()]));
    }
    if (current_user() === null) {
        // Session points at a user that no longer exists.
        logout_user();
        if (wants_json()) {
            json_out(['error' => 'authentication required'], 401);
        }
        flash('Please sign in again.', 'warning');
        redirect(url('auth.login'));
    }
}

/** login_required plus a role gate (403 / JSON 403 on mismatch). */
function role_required(string $role): void
{
    login_required();
    if (($_SESSION['role'] ?? null) !== $role) {
        if (wants_json()) {
            json_out(['error' => 'forbidden'], 403);
        }
        abort(403);
    }
}

/**
 * 404 unless $row exists and belongs to the current user. 404 rather than 403
 * on purpose: a 403 would confirm the row exists.
 *
 * @param array<string,mixed>|null $row
 */
function require_owner(?array $row, string $column = 'client_id'): array
{
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($row === null || (int)$row[$column] !== $uid) {
        abort(404);
    }
    return $row;
}
