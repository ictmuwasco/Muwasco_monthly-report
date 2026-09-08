#!/usr/bin/env bash
# MUWASCO Monthly Report — one-command deployment setup (run with sudo).
#
# Installs the Apache vhost that serves the built React SPA and proxies
# /api to the Laravel backend on 127.0.0.1:8000, then starts XAMPP Apache.
#
# Usage:  sudo ./deploy/setup-apache.sh
set -euo pipefail

CONF_SRC="$(cd "$(dirname "$0")" && pwd)/apache-monthlyreport.conf"
HTTPD_CONF="/opt/lampp/etc/httpd.conf"
VHOSTS="/opt/lampp/etc/extra/httpd-vhosts.conf"

[ -f "$CONF_SRC" ] || { echo "vhost config not found: $CONF_SRC"; exit 1; }

# 1. Install the vhost.
cp "$CONF_SRC" "$VHOSTS.new" && mv "$VHOSTS.new" "$VHOSTS"
echo "✔ vhost installed → $VHOSTS"

# 2. Enable the vhost include.
if grep -q '^#Include etc/extra/httpd-vhosts.conf' "$HTTPD_CONF"; then
    sed -i 's|^#Include etc/extra/httpd-vhosts.conf|Include etc/extra/httpd-vhosts.conf|' "$HTTPD_CONF"
    echo "✔ vhost include enabled"
else
    echo "• vhost include already enabled (or not found — check $HTTPD_CONF)"
fi

# 3. Enable proxy modules (required for /api proxying).
for mod in proxy_module proxy_http_module; do
    if grep -q "^#LoadModule ${mod} " "$HTTPD_CONF"; then
        sed -i "s|^#LoadModule ${mod} |LoadModule ${mod} |" "$HTTPD_CONF"
        echo "✔ enabled $mod"
    else
        echo "• $mod already enabled"
    fi
done

# 4. Add a hosts entry for local testing.
if ! grep -q 'monthlyreport.local' /etc/hosts; then
    echo "127.0.0.1 monthlyreport.local" >> /etc/hosts
    echo "✔ hosts entry added"
fi

# 5. Build the SPA (if npm is available and dist is missing/stale).
cd "$(dirname "$0")/../frontend-react"
if [ ! -d dist ]; then
    npm run build
    echo "✔ SPA built → frontend-react/dist"
fi

# 6. Start Apache (MySQL must already be running for the backend).
/opt/lampp/lampp startapache || true
echo
echo "Deployment complete:  http://monthlyreport.local"
echo "Backend (separate terminal):  cd backend-laravel && php artisan serve --port=8000"
