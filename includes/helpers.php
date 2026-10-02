<?php
// Small cross-cutting helpers: escaping, redirects, flash messages, view
// rendering, JSON responses, and the HttpError used for 4xx/5xx handling.

declare(strict_types=1);

const VIEWS_DIR = PROJECT_ROOT . '/views';

/** An HTTP error to abort the request with; caught by the front controller. */
final class HttpError extends RuntimeException
{
    public function __construct(public int $status, string $message = '')
    {
        parent::__construct($message);
    }
}

/** Stop handling and surface an HTTP status (renders error page or JSON). */
function abort(int $status, string $message = ''): never
{
    throw new HttpError($status, $message);
}

/** HTML-escape for safe output. Every dynamic value in views goes through this. */
function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function request_method(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

/** The path portion of the current request, without query string. */
function request_path(): string
{
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = parse_url($uri, PHP_URL_PATH);
    return $path !== null && $path !== false ? rawurldecode($path) : '/';
}

/** Path plus query string, e.g. "/dashboard?range=30d" (mirrors full_path). */
function request_full_path(): string
{
    $path = request_path();
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    return $qs !== '' ? $path . '?' . $qs : $path;
}

function wants_json(): bool
{
    return str_starts_with(request_path(), '/api/');
}

function client_ip(): ?string
{
    if (settings()->behind_proxy && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // First hop in the forwarded chain is the real client.
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($parts[0]) ?: null;
    }
    return $_SERVER['REMOTE_ADDR'] ?? null;
}

/** Send a redirect and stop. Relative locations are resolved by the browser. */
function redirect(string $location, int $status = 302): never
{
    header('Location: ' . $location, true, $status);
    exit;
}

/** Queue a flash message for the next rendered page. */
function flash(string $message, string $category = 'success'): void
{
    $_SESSION['_flashes'][] = ['message' => $message, 'category' => $category];
}

/** Return and clear queued flash messages. */
function get_flashes(): array
{
    $flashes = $_SESSION['_flashes'] ?? [];
    unset($_SESSION['_flashes']);
    return $flashes;
}

/** Register an extra <script> for the current page (emitted by the layout). */
function add_script(string $path): void
{
    $GLOBALS['_page_scripts'][] = $path;
}

function page_scripts(): array
{
    return $GLOBALS['_page_scripts'] ?? [];
}

/** Render a view fragment to a string (no layout). */
function render_partial(string $view, array $vars = []): string
{
    $__file = VIEWS_DIR . '/' . $view . '.php';
    if (!is_file($__file)) {
        throw new RuntimeException("View not found: {$view}");
    }
    extract($vars, EXTR_SKIP);
    ob_start();
    include $__file;
    return (string)ob_get_clean();
}

/** Render a full page: the view wrapped in layout.php. */
function render(string $view, array $vars = [], int $status = 200): void
{
    http_response_code($status);
    $title = $vars['title'] ?? 'FreelanceQueue';
    $content = render_partial($view, $vars);
    include VIEWS_DIR . '/layout.php';
}

/** Emit a JSON response and stop. */
function json_out($data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
