<?php
// Routing: a named-route table, URL building (url() mirrors Flask url_for),
// and request dispatch. Path parameters are always positive integers, so the
// matcher only accepts \d+ - a garbage id falls through to 404, exactly as
// Flask's <int:...> converter did.

declare(strict_types=1);

/**
 * Route table: [methods, path-template, name, handler].
 * Path templates use {name} placeholders (integer-only).
 */
function routes(): array
{
    static $routes = null;
    if ($routes !== null) {
        return $routes;
    }
    return $routes = [
        [['GET'],          '/',                              'auth.index',        'ctrl_auth_index'],
        [['GET', 'POST'],  '/login',                         'auth.login',        'ctrl_auth_login'],
        [['GET', 'POST'],  '/signup',                        'auth.signup',       'ctrl_auth_signup'],
        [['POST'],         '/logout',                        'auth.logout',       'ctrl_auth_logout'],

        [['GET'],          '/browse',                        'client.browse',            'ctrl_client_browse'],
        [['GET'],          '/f/{freelancer_id}',             'client.freelancer_detail', 'ctrl_client_freelancer_detail'],
        [['POST'],         '/f/{freelancer_id}/join',        'client.join',              'ctrl_client_join'],
        [['GET'],          '/tickets',                       'client.tickets',           'ctrl_client_tickets'],
        [['GET'],          '/ticket/{entry_id}',             'client.ticket',            'ctrl_client_ticket'],
        [['POST'],         '/ticket/{entry_id}/cancel',      'client.cancel',            'ctrl_client_cancel'],

        [['GET'],          '/dashboard',                     'freelancer.dashboard',      'ctrl_freelancer_dashboard'],
        [['POST'],         '/queue/open',                    'freelancer.set_queue_open', 'ctrl_freelancer_set_queue_open'],
        [['POST'],         '/queue/call-next',               'freelancer.call_next',      'ctrl_freelancer_call_next'],
        [['POST'],         '/queue/{entry_id}/done',         'freelancer.mark_done',      'ctrl_freelancer_mark_done'],
        [['POST'],         '/queue/skip',                    'freelancer.skip_current',   'ctrl_freelancer_skip_current'],
        [['POST'],         '/queue/{entry_id}/no-show',      'freelancer.mark_no_show',   'ctrl_freelancer_mark_no_show'],
        [['POST'],         '/queue/{entry_id}/move',         'freelancer.move_entry',     'ctrl_freelancer_move_entry'],
        [['POST'],         '/queue/clear',                   'freelancer.clear_queue',    'ctrl_freelancer_clear_queue'],
        [['GET'],          '/services',                      'freelancer.services',       'ctrl_freelancer_services'],
        [['POST'],         '/services',                      'freelancer.create_service', 'ctrl_freelancer_create_service'],
        [['POST'],         '/services/{service_id}',         'freelancer.update_service', 'ctrl_freelancer_update_service'],
        [['POST'],         '/services/{service_id}/toggle',  'freelancer.toggle_service', 'ctrl_freelancer_toggle_service'],
        [['POST'],         '/services/{service_id}/delete',  'freelancer.delete_service', 'ctrl_freelancer_delete_service'],
        [['GET'],          '/hours',                         'freelancer.hours',          'ctrl_freelancer_hours'],
        [['POST'],         '/hours',                         'freelancer.save_hours',     'ctrl_freelancer_save_hours'],
        [['GET'],          '/reports',                       'freelancer.reports',        'ctrl_freelancer_reports'],
        [['GET'],          '/reports/export.csv',            'freelancer.export_csv',     'ctrl_freelancer_export_csv'],

        [['GET'],          '/api/queue',                     'api.queue_state',           'ctrl_api_queue_state'],
        [['GET'],          '/api/ticket/{entry_id}',         'api.ticket_state',          'ctrl_api_ticket_state'],
    ];
}

/** The path template for a route name, or null. */
function route_template(string $name): ?string
{
    foreach (routes() as [$methods, $template, $routeName, $handler]) {
        if ($routeName === $name) {
            return $template;
        }
    }
    return null;
}

/**
 * Build a URL for a named route. Placeholders are filled from $params; any
 * remaining params become the query string (skipping null/'' values, which is
 * how Flask's url_for drops empty kwargs).
 */
function url(string $name, array $params = []): string
{
    $template = route_template($name);
    if ($template === null) {
        throw new RuntimeException("Unknown route: {$name}");
    }
    $path = $template;
    $query = [];
    foreach ($params as $key => $value) {
        $placeholder = '{' . $key . '}';
        if (str_contains($path, $placeholder)) {
            $path = str_replace($placeholder, rawurlencode((string)$value), $path);
        } elseif ($value !== null && $value !== '') {
            $query[$key] = $value;
        }
    }
    if ($query) {
        $path .= '?' . http_build_query($query);
    }
    return $path;
}

/** Match method+path to a handler and invoke it. Handles 404/405. */
function dispatch(string $method, string $path): void
{
    $pathMatched = false;
    foreach (routes() as [$methods, $template, $name, $handler]) {
        // preg_quote escapes the {placeholder} braces to \{...\}; swap each of
        // those for an integer capture group.
        $quoted = preg_quote($template, '#');
        $regex = '#^' . preg_replace('/\\\\\{[a-z_]+\\\\\}/', '(\d+)', $quoted) . '$#';
        if (preg_match($regex, $path, $m)) {
            $pathMatched = true;
            if (in_array($method, $methods, true)) {
                $args = array_map('intval', array_slice($m, 1));
                $handler(...$args);
                return;
            }
        }
    }
    abort($pathMatched ? 405 : 404);
}
