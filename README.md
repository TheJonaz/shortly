# shortly

A small, self-hosted URL shortener for plain PHP hosting. Single front
controller, no frameworks, SQLite or MySQL.

Designed to fit on the kind of cheap shared hosting where you have FTP and
PHP 8.1+ but not much else — no Composer required, no build step, no
background workers.

## Features

**Links**
- Custom slugs, expiry dates, password gates per link
- Per-link click stats with referrer + device-type breakdown, CSV export
- QR code generator (bundled JS — no external CDN)
- Anonymous shortening too (rate-limited, auto-expiring)

**Accounts**
- Email-verified sign-up (6-digit code, single-use, 15 min TTL)
- Forgot-password flow (signed, single-use, 1 h TTL — wipes all sessions on reset)
- API keys for programmatic link creation, per-key rate limits
- Optional cross-domain SSO via a parent site

**Billing (Pro tier)**
- Stripe Checkout subscriptions with **multi-currency** support (SEK / EUR /
  USD) — user picks currency from a header widget, Stripe charges the
  matching `currency_options` entry on the Price
- **Klarna** automatically offered on SEK/EUR sessions when enabled in
  Stripe Dashboard (Stripe doesn't support Klarna recurring in USD)
- **PayPal Subscriptions** as a parallel provider end-to-end (Stripe
  Checkout cannot host PayPal recurring, so this runs alongside via the
  PayPal API directly)
- Webhook-driven tier sync for both providers; one cancel endpoint that
  dispatches per provider
- Stripe Customer Portal (manage / change card / cancel) for Stripe
  subscribers; in-app cancel for PayPal subscribers

**Admin panel** (`/admin`, allow-list auth)
- User list with email search + per-user tier toggle + wipe-sessions
- Stripe Plans CRUD via Stripe API (list, create, archive, unarchive)
  with `currency_options` editor for adding SEK/EUR/USD on one Price
- Auto-hides in the dashboard nav for non-admin users

**Other**
- Bio / link-in-bio pages
- Light/dark theme, English + Swedish UI strings
- GDPR-style consent banner gating an optional analytics beacon
- Cloudflare Turnstile + Google Safe Browsing + URLhaus blocklist for
  abuse mitigation on anonymous link creation
- Per-target rate-limit + internal-host / self-target rejection
- CSP with per-request nonces, hashed-IP click logs, signed sessions
- `.htaccess` (or shipped nginx snippet) blocks `lib/`, `views/`, `data/`,
  any `.git`/`.svn`/`.hg` path, dotfiles, and known sensitive extensions

## Quick start (local dev)

Requirements: PHP 8.1+ with `pdo_sqlite` (or `pdo_mysql` if you'd rather).

```bash
cp config.example.php config.php
# edit config.php — at minimum, set public_url and ip_salt

php -S localhost:8000
```

Open <http://localhost:8000>. The schema is created on the first request.

If SQLite isn't enabled in your PHP build:

```bash
sudo apt install php8.3-sqlite3 && sudo systemctl reload php8.3-fpm
```

For a real nginx + PHP-FPM setup on the dev host, see
`deploy/setup-local.sh` and `deploy/nginx-shortly.conf` (edit the `root`
to point at the parent of your checkout).

## Layout

```
.htaccess           Apache rewrites + FilesMatch / Rewrite denies
index.php           Front controller / router
config.example.php  Copy to config.php, edit, never commit
favicon.svg
robots.txt
assets/             css/, js/ (incl. bundled QR module + admin bundles)
lib/                db, auth, links, util, ratelimit, email,
                    registration, password_reset, security_headers,
                    stripe, paypal, billing, bio, tags, apikeys,
                    blocklist, safebrowsing, abuse, devices, turnstile,
                    tier, lang
views/              PHP templates (incl. forgot/reset, admin_users,
                    admin_plans, _theme_toggle, _lang_switch, _consent)
data/               SQLite DB lives here  (web-blocked via .htaccess)
deploy/             nginx snippet for local dev + helper scripts
deploy.sh           Example FTP-deploy script (see "Deployment")
```

`lib/`, `views/`, and `data/` are blocked from web access by `.htaccess`.

## Configuration

Everything lives in `config.php` (gitignored). See `config.example.php`
for the full schema. The interesting keys:

| Key                    | Purpose                                                         |
|------------------------|-----------------------------------------------------------------|
| `public_url`           | Canonical URL, no trailing slash. Used in emails and self-loop checks. |
| `db`                   | `sqlite` (default) or `mysql` — example shows both.             |
| `ip_salt`              | Random salt for hashing client IPs in the click log + reset tokens. |
| `trust_proxy`          | True if behind a TLS-terminating reverse proxy.                 |
| `cookie_domain`        | `null` for host-only; `.example.com` to share sessions across subdomains. |
| `sso_whoami_url`       | Optional cross-domain SSO endpoint. Empty = SSO off.            |
| `admin_emails`         | Allow-list. Signed-in users with a matching email see `/admin`. Empty = panel disabled. |
| `consent_beacon_url`   | Optional analytics beacon. Empty = no beacon (banner stays honest about functional cookies). |
| `stripe_*`             | Stripe billing. Empty = `/api/billing/checkout` returns `billing_unavailable`. |
| `stripe_product_id`    | Stripe Product the admin Plans page manages. Empty = list every Price on the account. |
| `paypal_*`             | PayPal billing. Empty = `/api/billing/paypal/*` returns `paypal_unavailable`. |
| `turnstile_*`          | Cloudflare Turnstile bot challenge on anonymous create + sign-up. |
| `safebrowsing_api_key` | Google Safe Browsing v4 lookup at link-create time.             |
| `footer`               | Brand, columns (per-language arrays supported), contact lines, BIS badge. Drop the key for a minimal footer. |

### Stripe billing (Pro tier + Klarna + multi-currency)

`/api/billing/checkout` sends `currency` (`sek` / `eur` / `usd`) on the
Checkout session so visitors are charged in their selected currency.
A currency picker lives next to the language flag in the header and
writes a cookie consumed by `lib/lang.php::detect_currency`. To make
this work end-to-end:

1. In the Stripe Dashboard, create a `Product` (e.g. "Pro") and one
   `Price` per plan (monthly + yearly). Set `stripe_product_id` so the
   admin Plans page can scope to it.
2. On each Price, add **currency_options** entries for the currencies
   you want to offer. Without this Stripe rejects the session with
   `currency not enabled on price`. You can do this from the admin
   panel (`/admin/plans → Create new price`) too.
3. Under **Settings → Payment methods**, enable **Klarna**. Stripe will
   surface it automatically on Checkout sessions whose currency matches
   a Klarna-supported region (SEK / EUR). USD sessions fall back to
   cards — Klarna recurring is not offered in USD.
4. Add a Webhook endpoint at `<public_url>/api/webhooks/stripe`,
   subscribed to `checkout.session.completed` and
   `customer.subscription.{created,updated,deleted}`. Copy the signing
   secret into `stripe_webhook_secret`.

### PayPal billing

PayPal runs **parallel** to Stripe. Stripe Checkout cannot host PayPal
recurring (Stripe's PayPal integration is one-time-payments only), so
this is a separate billing path end-to-end.

1. Create a PayPal Developer app at <https://developer.paypal.com>
   (sandbox first, separate live app later). Copy Client ID + Secret.
2. Create a `Product` + one `Plan` per billing interval in PayPal
   (Subscriptions → Plans). One currency per plan — PayPal doesn't
   support `currency_options`. Copy the plan ids (`P-XXXXX`).
3. Configure a webhook on the app: URL =
   `<public_url>/api/webhooks/paypal`, events = all
   `BILLING.SUBSCRIPTION.*`. Copy the webhook id.
4. Fill `paypal_*` keys in `config.php`. Start with
   `paypal_mode => 'sandbox'`; switch to `'live'` only after re-doing
   steps 1–3 against the live app.

The upgrade panel shows two CTAs per plan ("Card / Klarna" → Stripe,
"PayPal" → PayPal). Either provider auto-hides if its config is blank.

### Admin panel

`/admin` is allow-list-gated by `config.admin_emails`. Anyone listed
gets:

- **Users** — paginated list, search by email, change tier (free/pro)
  manually, wipe all sessions for a user. Manual tier flips are
  overridden by the next Stripe / PayPal webhook for active paying
  customers, so use them for comping accounts, not downgrading.
- **Plans** — Stripe Prices CRUD via the Stripe API: list active and
  archived prices, create new prices (with `currency_options` for SEK
  / EUR / USD on a single Price), archive / unarchive. Stripe forbids
  editing a Price's amount or currency — the supported "edit" pattern
  is archive-and-replace via `lookup_key`.

API endpoints under `/api/admin/*` require a session cookie matching an
admin email; API-key auth is rejected so a leaked key can't grant
elevation.

## Deployment

shortly is just PHP files — copy them to your web root, point Apache or
nginx at `index.php` as the front controller, you're done. No build step.

### Apache (shared hosting)

The shipped `.htaccess` handles the rewrites and locks down hidden /
config / DB / VCS files. Upload everything except `.ftp-password`,
`config.php`, `.envrc`, and `data/*.db*` to your `public_html/`.

`deploy.sh` is an **example** lftp-based mirror. Host / user / remote
dir come from `FTP_HOST` / `FTP_USER` / `FTP_REMOTE_DIR` env vars (keep
a local `.envrc` outside of git for your own deploy target), with
generic placeholder fallbacks. The password is read from `FTP_PASSWORD`
env, a gitignored `.ftp-password` file, or an interactive prompt.

On the very first deploy you'll need a `config.php` on the server —
upload it once manually.

### nginx (local dev or VPS)

A snippet that mounts shortly at a sub-path on a local nginx lives in
`deploy/nginx-shortly.conf` (edit the `root` placeholder); activate via
`sudo ./deploy/setup-local.sh`. For a real VPS, point your existing
PHP-FPM vhost at `index.php` and ensure `lib/`, `views/`, and `data/`
are not served.

## Notes

- Sessions live in the DB and last 30 days (configurable).
- IP addresses are salted+hashed before being stored on click events.
- Anonymous visitors can shorten links too (rate-limited per IP); only
  signed-in users see history and stats.
- Email (verification + password reset) uses PHP's `mail()`. On dev,
  set `'mail_dev_log' => true` in config.php to log codes / reset URLs
  via `error_log` instead of trying a non-existent MTA. **Never** set
  that flag in production.
- The QR generator is bundled in `assets/js/vendor/`; no external CDN.
- Inter is loaded from `rsms.me`. Self-host or remove if you want zero
  third-party requests.
- Security headers (CSP, X-Frame, HSTS-when-HTTPS, etc.) are set in PHP
  via `lib/security_headers.php` so they apply regardless of the web
  server in front.
- Schema migrations are idempotent ALTERs inside `lib/db.php::db_migrate()`,
  run on every request. Safe to forget about — adding a column locally
  picks itself up on prod after the next deploy.

## License

[MIT](LICENSE) — see LICENSE for the full text.

## Contributing

PRs welcome. See [CONTRIBUTING.md](CONTRIBUTING.md) for the (very short)
ground rules.
