# FreelanceQueue

A queue-management web app for freelancers — built from scratch in plain **PHP 8**
on top of **MySQL/MariaDB**, with no framework, no ORM, no front-end build step
and no CDN.

A freelancer opens their queue for the day; clients sign in from their own phone
or laptop, pick a service, and join. Everyone sees the same queue from their own
side: the freelancer gets a live "now serving" card and a waiting list they can
reorder, and each client gets a ticket number, their position, and an estimated
wait that updates on its own. Emails go out at the moments that matter, so nobody
has to sit and refresh.

The whole thing is a small, readable MVC codebase you can lift the skeleton out
of — a front controller, a named-route table, a strict controller → service →
repository split, CSRF, session auth, and config loading, all in a few hundred
lines of dependency-free PHP.

---

## Features

| | |
| --- | --- |
| **Sign up / sign in** | One account system, two roles (freelancer, client). Passwords hashed with bcrypt, CSRF on every form, login throttling, hardened session cookies. |
| **Freelancer dashboard** | Now-serving card with a live elapsed timer, ordered waiting list, call next / done / skip / no-show, move up/down, today's stat tiles, open/close the queue. |
| **Client booking & live position** | Browse freelancers, join a queue, get a ticket, watch position and ETA update without refreshing. Leave the queue whenever. |
| **Service catalog & availability** | Per-service price and expected duration, active/inactive, plus a weekly business-hours editor. |
| **Email notifications** | Joined, almost-your-turn, turn started, completed, cancelled, no-show, queue cleared — sent over SMTP, or written to `data/outbox/` when you have no credentials. |
| **Analytics & reports** | KPI tiles, jobs-per-day line, earnings by service, an arrivals heatmap, wait-vs-service-time, date-range presets and a CSV export. Charts are server-rendered inline SVG and work with JavaScript off. |

---

## Tech stack & requirements

- **PHP 8.1+** with `pdo_mysql` (uses typed properties, constructor promotion, `never`, `str_contains`).
- **MySQL 5.7+ / MariaDB 10.2+** (generated columns, `CHECK` constraints, `utf8mb4`).
- **Apache + mod_rewrite** for the front-controller routing in `.htaccess` (bundled with XAMPP).
- **Optional:** Composer + `phpmailer/phpmailer`, only if you want real SMTP email. The default offline outbox needs nothing.

No Composer install is required to run the app on its defaults.

---

## Quick start (XAMPP)

1. **Put the project where Apache can serve it** — e.g. `C:\xampp\htdocs\FreelanceQueue`
   on Windows, or symlink it into your `htdocs`/`DocumentRoot`. `AllowOverride All`
   must be on for the directory so `.htaccess` takes effect (XAMPP's default vhost
   already allows it).

2. **Start Apache and MySQL/MariaDB** from the XAMPP control panel.

3. **Create the database** (phpMyAdmin, or the shell):

   ```sql
   CREATE DATABASE freelancequeue CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

4. **Point the app at it.** The defaults already match XAMPP's MariaDB
   (`127.0.0.1:3306`, user `root`, empty password). To change anything, copy the
   template over the live config:

   ```bash
   cp config.example.ini config.ini
   ```

5. **Create the schema and demo data:**

   ```bash
   php seed.php
   ```

   `seed.php` builds the tables from `schema.sql` on a fresh database and loads a
   demo freelancer, three clients, a service catalog, ~28 days of history and a
   live queue for today. (If you'd rather start empty, import `schema.sql`
   yourself and skip the seed.)

6. **Open it** at the URL Apache serves the folder on, e.g.
   `http://localhost/FreelanceQueue/`.

### Demo accounts

All four seeded accounts use the password **`demo1234`**:

| Role | Email |
| --- | --- |
| Freelancer | `maya@example.com` |
| Client | `arif@example.com`, `sinta@example.com`, `budi@example.com` |

`seed.php` refuses to touch a database that already has accounts in it. Run
`php seed.php --reset` to wipe every table and the outbox and start over — the RNG
is seeded with a fixed number, so each reset produces the identical dataset. It
prints the totals it actually generated at the end.

> **PHP's built-in server** (`php -S`) does not read `.htaccess`, so the front
> controller rewrite won't work out of the box — use Apache. If you must, run it
> with a one-line router that forwards non-file requests to `index.php`.

---

## Configuration

Everything tunable lives in `config.ini`, copied from `config.example.ini`. The
file is **optional**: with no `config.ini` at all the app runs on the built-in
defaults and the offline email backend, so a fresh clone is immediately demoable
(you only ever *need* a database to exist).

**`config.ini` is gitignored and should stay that way** — it holds your database
and SMTP passwords.

| Section | Keys | Meaning |
| --- | --- | --- |
| `[db]` | `host` `port` `name` `user` `pass` | MySQL/MariaDB connection. Defaults match XAMPP (`root`, no password). |
| `[smtp]` | `enabled` `host` `port` `use_tls` `username` `password` `from_name` | Real email. Off by default (see below). |
| `[app]` | `notify_when_ahead` | Send "almost your turn" when this many people or fewer are ahead. Default 2. |
| `[app]` | `base_url` | The address clients actually type; ticket links in emails are built from it. |
| `[app]` | `currency` | Prefix for every amount shown. Default `Rp`. |
| `[app]` | `cookie_secure` | `true` **only** when served over HTTPS — otherwise the browser drops the session cookie and nobody can log in. |
| `[server]` | `behind_proxy` | `true` when nginx/Caddy terminates TLS in front, so `X-Forwarded-For` is trusted for the client IP. |

### Email

Out of the box `[smtp] enabled = false`, and every notification is written to
`data/outbox/<timestamp>-<event>-<recipient>.eml` as a real RFC 822 message —
open one in any mail client or a text editor. The entire notification flow is
demoable with no credentials at all.

To send real mail you need PHPMailer and a relay. For Gmail:

1. `composer require phpmailer/phpmailer`
2. Turn on 2-Step Verification and create a 16-character **app password** at
   <https://myaccount.google.com/apppasswords> (Gmail rejects your normal password over SMTP).
3. In `config.ini`:

   ```ini
   [smtp]
   enabled  = true
   host     = smtp.gmail.com
   port     = 587
   use_tls  = true
   username = you@gmail.com
   password = abcd efgh ijkl mnop
   ```

4. Set `[app] base_url` to the address clients actually reach, so ticket links in
   emails point at the right host.

Sending is synchronous, after the queue transaction commits. Transient failures
are retried up to three times; authentication and bad-recipient errors fail
immediately. Every attempt — success or not — lands in the `email_log` table, and
a failed send is logged and swallowed rather than failing the queue action.

---

## Project layout

```
index.php                front controller: bootstrap, session, CSRF, dispatch
.htaccess                routing + locking server internals off the web
schema.sql               tables, constraints, indexes (MySQL/MariaDB)
seed.php                 CLI: build schema + deterministic demo data
config.example.ini       documented config template (copy to config.ini)

includes/                infrastructure, loaded first by index.php and seed.php
  bootstrap.php           wires config → helpers → db → repos → services → mailer
  config.php              config.ini loader + typed Settings
  db.php                  one PDO per request, write_tx(), unique-violation check
  router.php              named-route table, url() builder, dispatch (404/405)
  helpers.php             HttpError/abort, e(), render(), json_out(), flash()
  auth.php                login/logout, current_user, login/role/owner guards
  csrf.php                one token per session, verified in the front controller
  timeutil.php            the ONLY module converting between UTC and local time
  mailer.php              SMTP (PHPMailer) + offline-outbox backends

src/repo/                every line of SQL in the project lives here
  users.php services.php queue.php reports.php email_log.php
src/service/             business logic; no HTTP, no echo
  auth_service.php estimate_service.php queue_service.php
  report_service.php notify_service.php

controllers/             HTTP glue: read the request, call a service, render
  auth.php client.php freelancer.php api.php
views/                   plain-PHP templates
  layout.php landing.php error.php
  auth/ client/ freelancer/ partials/ (macros.php, charts.php)
assets/                  css/app.css, js/poll.js, js/chart.js, js/ascii.js
data/                    gitignored at runtime: outbox/*.eml
```

Three layers, strictly: **controllers contain no SQL, and services import no HTTP.**
Anything that talks to the database does it through `src/repo/`.

---

## How it works

A few decisions worth knowing before you change something:

- **A request's path through the stack.** `.htaccess` sends every non-asset URL to
  `index.php`, which bootstraps, starts the session, verifies CSRF on any non-safe
  method, then `dispatch()` matches the path against the route table in
  `router.php` and calls the controller. Path parameters are integer-only (`\d+`);
  anything else falls through to 404. `url('route.name', [...])` builds links the
  same way, so there are no hand-written paths in the views.

- **Timestamps are UTC; `queue_date` and business hours are local.** A client in
  another timezone still belongs to the freelancer's business day. Every
  conversion goes through `timeutil.php` and nothing else touches it.

- **Live updates are polling, not WebSockets.** `poll.js` fetches a small JSON
  endpoint every 5 seconds, pauses entirely on hidden tabs, backs off
  exponentially when the network is down, and never reloads the page while a field
  is focused. The page is already correct when it arrives — polling only adds
  freshness, so a blocked script costs nothing but that.

- **The queue is concurrency-safe by construction.** `queue_service.php` is the
  only module that writes `status`, and every transition is a conditional
  `UPDATE ... WHERE status = ?` inside `write_tx()`, so a double-clicked "Call
  next" or two open tabs change the row exactly once. "Two people served at once"
  is impossible because of a generated `serving_key` column under a `UNIQUE`
  index, not a check in PHP.

- **Ordering is a `DOUBLE` sort key with gaps.** Moving an entry writes one row
  (the midpoint between its new neighbours) instead of renumbering the queue;
  exhausted gaps trigger a renormalize-and-retry.

- **"Almost your turn" is evaluated after every queue mutation**, never on a
  timer, and `notified_soon` is set in the same transaction as the send, so nobody
  gets the same email twice. Being skipped resets it.

- **Wait estimates blend history with the quote**: with three or more completed
  jobs for a service, `0.7 × observed average + 0.3 × the quoted duration`,
  displayed rounded to 5 minutes.

- **Charts are server-rendered SVG.** `chart.js` only adds a nicer tooltip on top
  of the `<title>` elements that already work without it; line charts break across
  days with no data rather than drawing through the gap.

---

## Routes

| Method | Path | Role | Purpose |
| --- | --- | --- | --- |
| GET | `/` | — | Landing, or redirect to your home |
| GET/POST | `/login`, `/signup` | — | Auth |
| POST | `/logout` | any | Sign out |
| GET | `/browse` | client | List freelancers |
| GET | `/f/{id}` | client | A freelancer's services + queue |
| POST | `/f/{id}/join` | client | Join the queue |
| GET | `/tickets`, `/ticket/{id}` | client | Your tickets / one live ticket |
| POST | `/ticket/{id}/cancel` | client | Leave the queue |
| GET | `/dashboard` | freelancer | Live queue |
| POST | `/queue/open`, `/queue/call-next`, `/queue/clear`, `/queue/skip` | freelancer | Queue control |
| POST | `/queue/{id}/done`, `/queue/{id}/no-show`, `/queue/{id}/move` | freelancer | Per-entry actions |
| GET/POST | `/services`, `/services/{id}…` | freelancer | Service catalog |
| GET/POST | `/hours` | freelancer | Weekly business hours |
| GET | `/reports`, `/reports/export.csv` | freelancer | Analytics + CSV |
| GET | `/api/queue`, `/api/ticket/{id}` | role-scoped | JSON for the pollers (read-only) |

---

## Database

Six tables (`schema.sql`), InnoDB / `utf8mb4_unicode_ci`:

- **`users`** — one table for both roles; `role` CHECK-constrained, email unique.
- **`services`** — per-freelancer catalog with price and `est_minutes`.
- **`business_hours`** — one row per weekday (0 = Monday), with a CHECK that an
  open day has a valid window.
- **`queue_entries`** — the heart of it: `queue_date` scopes a daily `seq`/ticket
  number, `sort_key` orders the waiting list, and the generated `serving_key`
  column enforces one serving entry per freelancer. Statuses: `waiting`, `serving`,
  `done`, `skipped`, `no_show`, `cancelled`.
- **`email_log`** — every notification attempt and its outcome.
- **`login_attempts`** — backs the login throttle.

---

## Security

- **Passwords** hashed with `password_hash(..., PASSWORD_BCRYPT)` — algorithm,
  cost and salt live in the one stored string.
- **CSRF** on every non-safe method, validated once in the front controller (via
  the `_csrf` field or `X-CSRF-Token` header) and compared in constant time, so a
  new POST route cannot forget to opt in.
- **Login throttling** — 5 failures from one email or IP in 15 minutes locks
  further attempts, and every failure returns the same generic message, so the
  form is not an account-enumeration oracle.
- **Ownership checks return 404, not 403.** Ticket ids are small integers; a 403
  would confirm the row exists. A client cannot read another client's ticket page
  or API endpoint.
- **Sessions** are hardened: `HttpOnly`, `SameSite=Lax`, id regenerated on login,
  `Secure` when `cookie_secure` is on.
- **SQL** is entirely prepared statements with real server-side prepares; the repo
  layer is the only place SQL exists.
- **The web root is locked down** — `.htaccess` denies direct access to
  `includes/`, `src/`, `views/`, `data/`, and to `*.ini`/`*.sql`/`*.md`/`*.log`
  and `seed.php`, disables directory listings, and caps request bodies. Stack
  traces never reach the browser; unhandled errors log server-side and render a
  generic 500.

> **Before exposing this to the public internet:** put it behind a reverse proxy
> that terminates TLS, set `cookie_secure = true` and `[server] behind_proxy = true`,
> and be aware that single-writer MySQL on one box is the scaling ceiling.

---

## Verifying a change

This project has no automated test suite, which puts the weight on a manual pass.
Start from clean demo data (`php seed.php --reset`), then use two browsers (or one
normal and one private window — two tabs of the same browser share a cookie jar).

1. Sign in as the freelancer: one job in progress, two waiting.
2. As a client in the second window, browse and join — the dashboard picks it up
   within ~5 seconds with no manual refresh, and the client sees a position + ETA.
3. Call next → the client's page flips to "being served" and the timer starts.
4. Reorder the waiting list; positions and ETAs follow on the next poll.
5. Mark done → stat tiles rise and `data/outbox/` gains the joined / started /
   completed messages. Exercise skip, no-show, and a client cancelling.
6. Background a client tab for a minute, then return — polling stops while hidden
   and catches up immediately on focus.
7. **Security spot-checks:** open another client's `/ticket/{id}` and
   `/api/ticket/{id}` → both **404**; open `/dashboard` as a client → refused;
   delete the `_csrf` input and submit → 400; fail a login six times → throttled.

---

## Troubleshooting

| Symptom | Cause |
| --- | --- |
| `config.ini could not be read` on startup | The file is malformed. Fix it, or move it aside and copy `config.example.ini` over it. |
| `SQLSTATE[HY000] [1049] Unknown database` | The `freelancequeue` database doesn't exist yet — create it (step 3). |
| `could not find driver` | The `pdo_mysql` extension is off. Enable it in `php.ini`. |
| Blank page / 404 for every route | `mod_rewrite` is off or `AllowOverride` isn't `All`, so `.htaccess` isn't applied. |
| CSS/JS 404, pages unstyled | The app isn't at the document root you think — `assets/` is served relative to it. |
| Log in, bounced straight back to login | `cookie_secure = true` while serving over plain HTTP; the browser drops the cookie. |
| Emails not arriving | Check the `email_log` table. Gmail needs an app password and PHPMailer installed, with `[smtp] enabled = true`. |
| Ticket links in emails point at the wrong host | `[app] base_url` is still the default. |

---

## A note on the stack

FreelanceQueue is a faithful PHP port of an earlier Flask/SQLite application; the
source comments reference that heritage where a design choice was inherited (the
route table mirrors Flask's `url_for`, `write_tx()` mirrors its transaction
helper, and so on). The behaviour and layering are the same; the implementation
is plain PHP + MySQL as described above.

## License

No license file is included yet. Add one before publishing if you intend others
to reuse it.







