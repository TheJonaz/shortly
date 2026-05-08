#!/usr/bin/env bash
# Deploy shortly to Inleed shared hosting via FTP using lftp.
#
# Requires: lftp (sudo apt install lftp)
# Reads FTP password from one of, in order:
#   1. environment variable  FTP_PASSWORD
#   2. file                  .ftp-password   (gitignored, single line)
#   3. interactive prompt
#
# Usage:
#   ./deploy.sh             # mirrors local → server (additive: keeps server-only files)
#   ./deploy.sh --dry       # shows what would change without uploading
#   ./deploy.sh --delete    # also removes server files not present locally
#   ./deploy.sh --dry --delete  # preview a destructive sync
#
# Notes:
#   - The data/ directory is NOT mirrored down/up: that's the live DB.
#   - config.php (live secrets) is also excluded from upload.
#   - deploy/ (this script's siblings) excluded — local-dev only.
#   - .git, node_modules, public-html-old, _archive are excluded.

set -euo pipefail

HOST="ns15.inleed.net"
USER="url@thern.io"
# FTP user is jailed — its / is the public_html for url.thern.io.
REMOTE_DIR="/"
LOCAL_DIR="$(cd "$(dirname "$0")" && pwd)"

if ! command -v lftp >/dev/null; then
    echo "lftp not found. Install it with: sudo apt install lftp" >&2
    exit 1
fi

# Resolve password.
if [[ -n "${FTP_PASSWORD:-}" ]]; then
    PASS="$FTP_PASSWORD"
elif [[ -f "$LOCAL_DIR/.ftp-password" ]]; then
    PASS="$(cat "$LOCAL_DIR/.ftp-password")"
else
    read -srp "FTP password for $USER@$HOST: " PASS
    echo
fi

DRY_RUN_FLAG=""
DELETE_FLAG=""
for arg in "$@"; do
    case "$arg" in
        --dry|-n)   DRY_RUN_FLAG="--dry-run"; echo "(dry run — nothing will be uploaded)" ;;
        --delete)   DELETE_FLAG="--delete"; echo "(--delete: will remove server files not present locally)" ;;
    esac
done

# lftp `mirror -R` does local→remote; excludes prevent uploading sensitive or
# server-only files. The data/ directory must exist on the server but its
# contents (the live SQLite DB) must NOT be overwritten.
lftp -u "$USER","$PASS" "ftp://$HOST" <<EOF
set ftp:ssl-allow yes
set ftp:ssl-protect-data yes
set ssl:verify-certificate no
set net:max-retries 3
set mirror:parallel-transfer-count 3

cd "$REMOTE_DIR"
lcd "$LOCAL_DIR"

mirror -R --verbose $DELETE_FLAG $DRY_RUN_FLAG \
    --exclude-glob '.git*' \
    --exclude-glob '.claude/' \
    --exclude-glob '.gitignore' \
    --exclude-glob '.ftp-password' \
    --exclude-glob 'node_modules/' \
    --exclude-glob 'public-html-old/' \
    --exclude-glob '_archive/' \
    --exclude-glob 'config.php' \
    --exclude-glob 'data/*.db' \
    --exclude-glob 'data/*.db-*' \
    --exclude-glob 'deploy.sh' \
    --exclude-glob 'deploy/' \
    --exclude-glob 'members.tips*' \
    --exclude-glob '.members.tips*' \
    --exclude-glob '*.log' \
    --exclude-glob '.DS_Store' \
    .
bye
EOF

echo "✓ Deploy complete."
