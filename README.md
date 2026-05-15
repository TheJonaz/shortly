# shortly

A small, self-hosted URL shortener for plain PHP hosting. Single front
controller, no frameworks, SQLite or MySQL.

Designed to fit on the kind of cheap shared hosting where you have FTP and
PHP 8.1+ but not much else — no Composer required, no build step, no
background workers.

## Features

- Custom slugs, expiry dates, password gates per link
- Per-link click stats (count, last 30 days, recent referrers)
- Email-verified accounts (sign up → 6-digit code → confirm)
- API keys for programmatic link creation, with per-key rate limits
- Optional Stripe billing for a "Pro" tier
- Cloudflare Turnstile + Google Safe Browsing + URLhaus blocklist for
  abuse mitigation on anonymous link creation
- GDPR-style consent banner gating an optional analytics beacon
- Bio / link-in-bio pages
- Light/dark theme, English + Swedish UI strings
- CSP with per-request nonces, hashed-IP click logs, signed sessions

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

## Layout

```
.htaccess           Apache rewrites + FilesMatch denies
index.php           Front controller / router
config.example.php  Copy to config.php, edit, never commit
favicon.svg
robots.txt
assets/             css/, js/ (incl. bundled QR module)
lib/                db / auth / links / util / ratelimit / email /
                    registration / security_headers, etc.
views/              PHP templates
data/               SQLite DB lives here  (web-blocked via .htaccess)
deploy/             nginx snippet for local dev + helper scripts
deploy.sh           Example FTP-deploy script (see "Deployment")
```

`lib/`, `views/`, and `data/` are blocked from web access by `.htaccess`.

## Configuration

Everything lives in `config.php` (gitignored). See `config.example.php`
for the full schema. The interesting toggles:

| Key                    | Purpose                                                         |
|------------------------|-----------------------------------------------------------------|
| `public_url`           | Canonical URL, no trailing slash. Used in emails and self-loop checks. |
| `db`                   | `sqlite` (default) or `mysql` — example shows both.             |
| `ip_salt`              | Random salt for hashing client IPs in the click log.            |
| `trust_proxy`          | True if behind a TLS-terminating reverse proxy.                 |
| `cookie_domain`        | `null` for host-only; `.example.com` to share sessions across subdomains. |
| `sso_whoami_url`       | Optional cross-domain SSO endpoint. Empty = SSO off.            |
| `consent_beacon_url`   | Optional analytics beacon. Empty = no beacon (banner stays honest about functional cookies). |
| `stripe_*`             | Stripe billing. Empty = `/api/billing/*` returns `billing_unavailable`. |
| `turnstile_*`          | Cloudflare Turnstile bot challenge on anonymous create.         |
| `safebrowsing_api_key` | Google Safe Browsing v4 lookup at link-create time.             |
| `footer`               | Brand, columns, contact lines. Drop the key for a minimal footer. |

### Stripe billing (Pro tier + Klarna)

`/api/billing/checkout` sends `currency` (`sek` / `eur` / `usd`) on the
session so visitors are charged in their selected currency. To make that
work end-to-end:

1. In the Stripe Dashboard, create one `Price` per plan (monthly + yearly).
2. On each Price, add **currency_options** entries for the currencies you
   want to offer. Without this Stripe rejects the session with
   `currency not enabled on price`.
3. Under **Settings → Payment methods**, enable **Klarna**. Stripe will
   surface it automatically on Checkout sessions whose currency matches a
   Klarna-supported region (SEK / EUR). USD sessions fall back to cards —
   Klarna recurring is not offered in USD.

The visible currency picker lives next to the language flag in the header
and writes a `currency` cookie consumed by `lib/lang.php::detect_currency`.

## Deployment

shortly is just PHP files — copy them to your web root, point Apache or
nginx at `index.php` as the front controller, you're done. No build step.

### Apache (shared hosting)

The shipped `.htaccess` handles the rewrites and locks down hidden /
config / DB files. Upload everything except `.ftp-password`, `config.php`
(if you keep prod secrets out of source), and `data/*.db*` to your
`public_html/`.

`deploy.sh` is an **example** lftp-based mirror used against one
specific shared-hosting provider. Adapt the host / credentials / excludes
for your own environment, or replace it with `rsync`, `scp`, CI, etc. On
the very first deploy you'll need a `config.php` on the server — upload
it once manually.

### nginx (local dev or VPS)

A snippet that mounts shortly at a sub-path on a local nginx lives in
`deploy/nginx-shortly.conf`; activate via `sudo ./deploy/setup-local.sh`.
For a real VPS, point your existing PHP-FPM vhost at `index.php` and
ensure `lib/`, `views/`, and `data/` are not served.

## Notes

- Sessions live in the DB and last 30 days (configurable).
- IP addresses are salted+hashed before being stored on click events.
- Anonymous visitors can shorten links too (rate-limited per IP); only
  signed-in users see history and stats.
- Email verification uses PHP's `mail()`. On dev, set
  `'mail_dev_log' => true` in config.php to log codes via `error_log`
  instead of trying a non-existent MTA.
- The QR generator is bundled in `assets/js/vendor/`; no external CDN.
- Inter is loaded from `rsms.me`. Self-host or remove if you want zero
  third-party requests.
- Security headers (CSP, X-Frame, HSTS-when-HTTPS, etc) are set in PHP
  via `lib/security_headers.php` so they apply regardless of the web
  server in front.

## License

[MIT](LICENSE) — see LICENSE for the full text.

## Contributing

PRs welcome. See [CONTRIBUTING.md](CONTRIBUTING.md) for the (very short)
ground rules.
