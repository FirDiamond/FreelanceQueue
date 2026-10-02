<?php
// Configuration loading.
//
// Everything tunable lives in config.ini (copied from config.example.ini).
// The file is optional: with no config.ini the app still runs on the defaults
// below and the offline email backend, so a fresh clone is immediately
// demoable.

declare(strict_types=1);

const PROJECT_ROOT = __DIR__ . '/..';
const CONFIG_PATH  = PROJECT_ROOT . '/config.ini';
const DATA_DIR     = PROJECT_ROOT . '/data';
const OUTBOX_DIR   = PROJECT_ROOT . '/data/outbox';
const SCHEMA_PATH  = PROJECT_ROOT . '/schema.sql';

/** Flat, typed view over config.ini. */
final class Settings
{
    // [db]
    public string $db_host;
    public int    $db_port;
    public string $db_name;
    public string $db_user;
    public string $db_pass;

    // [smtp]
    public bool   $smtp_enabled;
    public string $smtp_host;
    public int    $smtp_port;
    public bool   $smtp_use_tls;
    public string $smtp_username;
    public string $smtp_password;
    public string $smtp_from_name;

    // [app]
    public int    $notify_when_ahead;
    public string $base_url;
    public string $currency;
    public bool   $cookie_secure;

    // [server]
    public string $server_host;
    public int    $server_port;
    public int    $server_threads;
    public bool   $behind_proxy;

    // [security]
    public string $secret_key;

    public function __construct(array $ini)
    {
        $this->db_host = self::str($ini, 'db', 'host', '127.0.0.1');
        $this->db_port = self::int($ini, 'db', 'port', 3306);
        $this->db_name = self::str($ini, 'db', 'name', 'freelancequeue');
        $this->db_user = self::str($ini, 'db', 'user', 'root');
        $this->db_pass = self::str($ini, 'db', 'pass', '');

        $this->smtp_enabled   = self::bool($ini, 'smtp', 'enabled', false);
        $this->smtp_host      = self::str($ini, 'smtp', 'host', 'localhost');
        $this->smtp_port      = self::int($ini, 'smtp', 'port', 587);
        $this->smtp_use_tls   = self::bool($ini, 'smtp', 'use_tls', true);
        $this->smtp_username  = self::str($ini, 'smtp', 'username', '');
        $this->smtp_password  = self::str($ini, 'smtp', 'password', '');
        $this->smtp_from_name = self::str($ini, 'smtp', 'from_name', 'FreelanceQueue');

        $this->notify_when_ahead = self::int($ini, 'app', 'notify_when_ahead', 2);
        $this->base_url = rtrim(self::str($ini, 'app', 'base_url', 'http://127.0.0.1:8000'), '/');
        $this->currency = self::str($ini, 'app', 'currency', 'Rp');
        $this->cookie_secure = self::bool($ini, 'app', 'cookie_secure', false);

        $this->server_host    = self::str($ini, 'server', 'host', '0.0.0.0');
        $this->server_port    = self::int($ini, 'server', 'port', 8000);
        $this->server_threads = self::int($ini, 'server', 'threads', 8);
        $this->behind_proxy   = self::bool($ini, 'server', 'behind_proxy', false);

        $this->secret_key = self::str($ini, 'security', 'secret_key', '');
    }

    /** Envelope From. Falls back to a no-reply for the offline backend. */
    public function senderAddress(): string
    {
        return $this->smtp_username !== '' ? $this->smtp_username : 'no-reply@freelancequeue.local';
    }

    /** Amounts in the configured currency. Identical string used in emails. */
    public function formatMoney($value): string
    {
        if ($value === null) {
            return '-';
        }
        return $this->currency . ' ' . number_format(round((float)$value), 0, '.', ',');
    }

    private static function str(array $ini, string $s, string $k, string $d): string
    {
        return isset($ini[$s][$k]) ? (string)$ini[$s][$k] : $d;
    }

    private static function int(array $ini, string $s, string $k, int $d): int
    {
        return isset($ini[$s][$k]) && $ini[$s][$k] !== '' ? (int)$ini[$s][$k] : $d;
    }

    private static function bool(array $ini, string $s, string $k, bool $d): bool
    {
        if (!isset($ini[$s][$k]) || $ini[$s][$k] === '') {
            return $d;
        }
        $v = strtolower(trim((string)$ini[$s][$k]));
        return in_array($v, ['1', 'true', 'yes', 'on'], true);
    }
}

function load_settings(): Settings
{
    $ini = [];
    if (is_file(CONFIG_PATH)) {
        $parsed = @parse_ini_file(CONFIG_PATH, true, INI_SCANNER_RAW);
        if ($parsed === false) {
            // Refusing to start beats starting with the wrong config.
            throw new RuntimeException(
                CONFIG_PATH . ' could not be read. Fix the file, or move it aside '
                . 'and copy config.example.ini over it.'
            );
        }
        $ini = $parsed;
    }
    return new Settings($ini);
}

/** Process-wide settings singleton. */
function settings(): Settings
{
    static $s = null;
    if ($s === null) {
        $s = load_settings();
    }
    return $s;
}
