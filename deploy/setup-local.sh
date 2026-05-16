#!/usr/bin/env bash
# One-shot local setup for serving the shortly app at /shortly on the host's
# existing nginx + PHP-FPM. Edit deploy/nginx-shortly.conf's `root` first.
#
# Run as root:
#   sudo ./deploy/setup-local.sh
#
# Idempotent — safe to re-run.

set -euo pipefail

if [[ $EUID -ne 0 ]]; then
    echo "Run with sudo." >&2
    exit 1
fi

PROJECT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
NGINX_SNIPPET="/etc/nginx/snippets/shortly.conf"
NGINX_SITE_DEFAULT="/etc/nginx/sites-available/localweb"
PHP_FPM_SVC="php8.3-fpm"

echo "→ Project directory: $PROJECT_DIR"

# ─── 1. PHP SQLite extension ─────────────────────────────────────────
if ! php -m | grep -qi pdo_sqlite; then
    echo "→ Installing php8.3-sqlite3 (for PDO SQLite)..."
    apt update -qq
    apt install -y php8.3-sqlite3
fi

# ─── 2. Permissions on data/ so PHP-FPM (www-data) can write the DB ──
echo "→ Setting permissions on data/ for www-data writes..."
chgrp www-data "$PROJECT_DIR/data"
chmod g+rwx    "$PROJECT_DIR/data"
if ls "$PROJECT_DIR/data/"*.db 2>/dev/null >/dev/null; then
    chgrp www-data "$PROJECT_DIR/data/"*.db* 2>/dev/null || true
    chmod g+rw    "$PROJECT_DIR/data/"*.db* 2>/dev/null || true
fi

# ─── 2b. Make config.php readable by PHP-FPM ─────────────────────────
if [[ -f "$PROJECT_DIR/config.php" ]]; then
    echo "→ Setting config.php to mode 640, group www-data..."
    chgrp www-data "$PROJECT_DIR/config.php"
    chmod 640      "$PROJECT_DIR/config.php"
fi

# ─── 3. Install the nginx snippet ─────────────────────────────────────
echo "→ Installing nginx snippet at $NGINX_SNIPPET..."
install -m 644 "$PROJECT_DIR/deploy/nginx-shortly.conf" "$NGINX_SNIPPET"

# ─── 4. Wire it into the active site if not already ──────────────────
SITE_FILE=""
for candidate in "$NGINX_SITE_DEFAULT" /etc/nginx/sites-available/default /etc/nginx/sites-enabled/localweb /etc/nginx/sites-enabled/default; do
    [[ -f $candidate ]] && { SITE_FILE="$candidate"; break; }
done

if [[ -n "$SITE_FILE" ]]; then
    if grep -q "snippets/shortly.conf" "$SITE_FILE"; then
        echo "→ $SITE_FILE already includes snippets/shortly.conf"
    else
        echo "→ NOT auto-modifying $SITE_FILE."
        echo "  Open it and, inside the matching server { ... } block, add:"
        echo
        echo "      include snippets/shortly.conf;"
        echo
        echo "  Then re-run this script (it will skip the install step)."
    fi
else
    echo "! Could not find a sites-available file to suggest the include for."
fi

# ─── 5. Restart services ─────────────────────────────────────────────
echo "→ Reloading php8.3-fpm..."
systemctl reload "$PHP_FPM_SVC"

echo "→ Testing nginx config..."
nginx -t
echo "→ Reloading nginx..."
systemctl reload nginx

HOST_IP="$(hostname -I 2>/dev/null | awk '{print $1}')"
[[ -z "$HOST_IP" ]] && HOST_IP="<host-ip>"

echo
echo "✓ Done. Visit http://${HOST_IP}/shortly/"
echo
echo "  If you get a 404, the include line is missing from your site config."
echo "  If you get a 502, check 'sudo journalctl -u $PHP_FPM_SVC -n 50'."
