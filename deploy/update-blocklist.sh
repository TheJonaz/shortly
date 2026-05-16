#!/usr/bin/env bash
# Refresh the malicious-domain blocklist from URLhaus.
#
# Designed to run from cron daily:
#     0 4 * * *  /path/to/shortly/deploy/update-blocklist.sh
#
# Safe to run as the web user (e.g. www-data) or the FTP user on shared hosting.
# Output is atomically renamed so a partial download never poisons the file
# read by PHP. Exits 0 on success, non-zero on transport failure.

set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
TARGET="$PROJECT_DIR/data/blocklist.txt"
TMP="$TARGET.tmp.$$"
SOURCE="https://urlhaus.abuse.ch/downloads/hostfile/"

if ! command -v curl >/dev/null; then
    echo "curl not found" >&2
    exit 2
fi

# URLhaus hostfile is a `0.0.0.0 hostname` per line. We strip comments,
# blank lines, and the IP token, leaving a deduped lowercase host list.
if ! curl -fsSL --max-time 60 "$SOURCE" \
    | awk '
        /^[[:space:]]*#/ { next }
        /^[[:space:]]*$/ { next }
        {
            for (i=1; i<=NF; i++) {
                if ($i != "0.0.0.0" && $i != "127.0.0.1" && $i != "localhost") {
                    print tolower($i)
                    break
                }
            }
        }
      ' | sort -u > "$TMP"
then
    rm -f "$TMP"
    echo "blocklist fetch failed" >&2
    exit 1
fi

# Sanity check — refuse to install an empty list (would silently disable
# the protection if URLhaus returns 200 with no content for some reason).
if [ "$(wc -l < "$TMP")" -lt 100 ]; then
    rm -f "$TMP"
    echo "blocklist suspiciously small, keeping previous version" >&2
    exit 1
fi

mv "$TMP" "$TARGET"
echo "blocklist updated: $(wc -l < "$TARGET") hosts"
