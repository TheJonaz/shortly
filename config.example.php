<?php
// Copy this file to config.php and edit. config.php is gitignored.
//
// shortly runs on any LAMP/LEMP setup with PHP 8.1+ and either pdo_sqlite
// (default, zero-config, recommended for small deployments) or pdo_mysql.

return [
    // Public URL where the app is reachable. No trailing slash.
    'public_url' => 'https://example.com',

    // If the app is mounted at a sub-path (e.g. http://host/shortly), set
    // base_path to '/shortly'. Leave empty when serving from the document
    // root (most production deployments).
    // 'base_path' => '/shortly',

    // Database. Default is SQLite (file-based, zero-config). Switch to MySQL
    // by uncommenting the second block.
    'db' => [
        'driver' => 'sqlite',
        'path'   => __DIR__ . '/data/shortly.db',

        // 'driver'   => 'mysql',
        // 'host'     => 'localhost',
        // 'port'     => 3306,
        // 'database' => 'shortly',
        // 'username' => 'shortly',
        // 'password' => 'change-me',
        // 'charset'  => 'utf8mb4',
    ],

    // Salt for hashing client IPs in the click log. Make this random and
    // never commit the production value.
    'ip_salt' => 'change-me-to-random',

    // Session cookie name. Change only if it clashes with another app on the
    // same domain.
    'session_cookie' => 'shortly_sess',

    // How long a login lasts (seconds).
    'session_ttl' => 30 * 24 * 60 * 60,

    // Cookie domain. null = host-only (correct for IP/local dev). Set to
    // '.example.com' if you want the session cookie to be valid across
    // subdomains.
    'cookie_domain' => null,

    // Trust X-Forwarded-For for client IP detection. Set true ONLY when running
    // behind a known reverse proxy that sets the header (e.g. shared hosting
    // that terminates TLS upstream, or nginx → php-fpm with proxy_set_header).
    'trust_proxy' => false,

    // Shared secret for /api/internal/stats. Send as `Authorization: Bearer …`.
    // Leave empty to disable the endpoint.
    'internal_secret' => '',

    // ─── Admin panel ──────────────────────────────────────────────────────
    // Email allow-list. Any signed-in user whose email appears here can
    // reach /admin. Empty array → /admin returns 403 for everyone; the
    // panel is effectively disabled. Add/remove emails to grant/revoke
    // access (case-insensitive match).
    'admin_emails' => [
        // 'you@example.com',
    ],

    // Stripe Product the admin panel manages Prices under. If blank, the
    // plans page falls back to listing every Price on the account. Set
    // this once you have a Product called e.g. "Pro" in Stripe Dashboard.
    'stripe_product_id' => '',

    // ─── Optional integrations ────────────────────────────────────────────
    // All of the following are OFF by default. Leave the keys empty/unset
    // to disable each feature. Fill in to enable.

    // Cross-domain SSO. When set, shortly will, on requests with no local
    // session, forward the configured cookie to this URL and trust a JSON
    // response of the form `{"ok": true, "member": {"id": <int>, "email": "<addr>"}}`
    // to auto-create/sign-in a user. Useful when you run shortly on a
    // subdomain of a site that already has its own auth. Empty = no SSO.
    'sso_whoami_url'    => '',
    'sso_cookie_name'   => '',  // e.g. 'parent_sess' — name of the cookie set by the parent site

    // Optional analytics beacon URL. When set, the consent banner's "Accept"
    // button fires a navigator.sendBeacon to this URL on every page view
    // (declined visitors never trigger it). Empty = no beacon.
    'consent_beacon_url' => '',

    // Stripe billing. Required for Pro upgrades; if any of these are blank
    // the /api/billing/* endpoints return billing_unavailable. Get from
    // https://dashboard.stripe.com:
    //   secret_key:     Developers > API keys > Secret key
    //   webhook_secret: Developers > Webhooks > Add endpoint at
    //                   <public_url>/api/webhooks/stripe.
    //                   Subscribe to: checkout.session.completed,
    //                   customer.subscription.{created,updated,deleted}.
    //                   "Reveal" the signing secret after creation.
    //   price ids:      Products > "Pro" > pricing > Copy ID.
    'stripe_secret_key'     => '',
    'stripe_webhook_secret' => '',
    'stripe_price_monthly'  => '',
    'stripe_price_yearly'   => '',

    // PayPal billing. Runs in parallel with Stripe — users pick "Pay with
    // PayPal" on the upgrade panel and follow PayPal's approval flow.
    // Stripe Checkout can't host PayPal subscriptions (their PayPal integration
    // is one-time only), so this is a separate provider end-to-end.
    //
    // Setup at https://developer.paypal.com:
    //   1. Create an App under "Apps & Credentials" (separate sandbox / live).
    //      Copy the Client ID + Secret.
    //   2. Create a Product + Plan under Subscriptions (or via the API):
    //         Plan A: monthly, currency SEK/EUR/USD, recurring 1 month
    //         Plan B: yearly,  same currency,        recurring 1 year
    //      Copy each plan's id (looks like P-XXXXXXX). One plan per currency
    //      isn't supported in PayPal — pick one currency per environment, or
    //      create separate plans and switch via your own logic.
    //   3. Configure a webhook on the app: URL = <public_url>/api/webhooks/paypal,
    //      events = BILLING.SUBSCRIPTION.* (activated/updated/cancelled/expired).
    //      Copy the webhook id.
    //   4. Set paypal_mode = 'live' only once 1–3 are done on the LIVE app —
    //      sandbox creds will not work against live API and vice versa.
    'paypal_mode'           => 'sandbox',  // 'sandbox' | 'live'
    'paypal_client_id'      => '',
    'paypal_secret'         => '',
    'paypal_webhook_id'     => '',
    'paypal_plan_monthly'   => '',
    'paypal_plan_yearly'    => '',

    // Cloudflare Turnstile bot challenge for anonymous link creation. Free
    // tier from https://dash.cloudflare.com → Turnstile → Add site. Both
    // required (or both blank to disable).
    'turnstile_site_key' => '',
    'turnstile_secret'   => '',

    // Google Safe Browsing v4 lookup at link-create time. Free tier is
    // 10k requests/day; verdicts are cached for 24h per URL hash. Empty key
    // disables the check (the URLhaus blocklist still applies).
    // Get a key from https://console.cloud.google.com (enable Safe
    // Browsing API on your project).
    'safebrowsing_api_key' => '',

    // ─── Outgoing mail ────────────────────────────────────────────────────
    // Email verification uses PHP's mail(). On dev where mail() can't
    // deliver, set 'mail_dev_log' => true to log codes to error_log.
    // NEVER enable this in prod — codes would land in the access log.
    'mail_from'    => 'noreply@example.com',
    'mail_dev_log' => false,

    // ─── Footer (entirely optional) ───────────────────────────────────────
    // The footer template renders whatever you put here. Drop the whole
    // 'footer' key to get a minimal "report / consent / © year" footer.
    //
    // Any string-valued field (brand_tagline, column headings, link labels,
    // contact lines, copyright, legal_line) can be a plain string OR a
    // per-language array like ['sv' => 'Tjänster', 'en' => 'Services'].
    // The footer resolves it against the active language with English
    // fallback.
    'footer' => [
        'brand_name'    => 'shortly',
        'brand_tagline' => '',
        'logo_svg'      => '',  // raw <svg>…</svg> — trusted, comes from your config
        'bis_badge'     => '',  // path/URL to a 100×100 "Based In Sweden" image; '' = hide badge
        'bis_href'      => 'https://www.basedinsweden.se',
        'columns'       => [
            // ['heading' => 'Project', 'links' => [
            //     ['label' => 'GitHub', 'href' => 'https://github.com/you/shortly'],
            //     ['label' => 'Issues', 'href' => 'https://github.com/you/shortly/issues'],
            // ]],
        ],
        'contact_lines' => [
            // 'GDPR-compliant',
        ],
        'copyright'  => '© ' . date('Y') . ' shortly',
        'version'    => '',
        'legal_line' => '',
    ],
];
