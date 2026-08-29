#!/usr/bin/env bash
# Run this once on a fresh Ubuntu 24.04 VPS, as a sudo-capable non-root user.
# It hardens the server, installs Docker, sets up swap, and configures
# the wp-cron host cron job. It does NOT start the site — that happens
# with `docker compose up -d` after this finishes.
set -euo pipefail

echo "== 1. System update =="
sudo apt update && sudo apt upgrade -y

echo "== 2. Firewall: allow only SSH and Cloudflare edge IPs for HTTP/HTTPS =="
sudo apt install -y ufw
sudo ufw allow OpenSSH

# Remove old open-world rules if they exist
sudo ufw delete allow 80/tcp || true
sudo ufw delete allow 443/tcp || true

echo "Configuring Cloudflare IPv4 rules..."
for ip in 173.245.48.0/20 103.21.244.0/22 103.22.200.0/22 103.31.4.0/22 141.101.64.0/18 108.162.192.0/18 190.93.240.0/20 188.114.96.0/20 197.234.240.0/22 198.41.128.0/17 162.158.0.0/15 104.16.0.0/13 104.24.0.0/14 172.64.0.0/13 131.0.72.0/22; do
  sudo ufw allow from "$ip" to any port 80 proto tcp > /dev/null
  sudo ufw allow from "$ip" to any port 443 proto tcp > /dev/null
done

echo "Configuring Cloudflare IPv6 rules..."
for ip in 2400:cb00::/32 2606:4700::/32 2803:f800::/32 2405:b500::/32 2405:8100::/32 2a06:98c0::/29 2c0f:f248::/32; do
  sudo ufw allow from "$ip" to any port 80 proto tcp > /dev/null
  sudo ufw allow from "$ip" to any port 443 proto tcp > /dev/null
done

sudo ufw --force enable

echo "== 3. fail2ban: block repeated SSH login attempts =="
sudo apt install -y fail2ban
sudo systemctl enable --now fail2ban

echo "== 4. Install Docker Engine + Compose plugin =="
curl -fsSL https://get.docker.com -o get-docker.sh
sudo sh get-docker.sh
sudo usermod -aG docker "$USER"
rm get-docker.sh

echo "== 5. Unattended security updates (patches CVEs automatically) =="
sudo apt install -y unattended-upgrades
sudo dpkg-reconfigure -f noninteractive unattended-upgrades

# --- Caching plan additions (idempotent) ---

echo "== 6. Swap (OOM safety net on 2GB) =="
SWAPFILE="/swapfile"
if [ ! -f "$SWAPFILE" ]; then
    sudo fallocate -l 2G "$SWAPFILE"
    sudo chmod 600 "$SWAPFILE"
    sudo mkswap "$SWAPFILE"
    sudo swapon "$SWAPFILE"
    echo "$SWAPFILE none swap sw 0 0" | sudo tee -a /etc/fstab > /dev/null
    echo "Swap created."
else
    echo "Swap already exists — skipping."
fi
# Keep swappiness low: prefer RAM, use swap only as a safety net.
sudo sysctl -w vm.swappiness=10
grep -q 'vm.swappiness' /etc/sysctl.conf \
    || echo 'vm.swappiness=10' | sudo tee -a /etc/sysctl.conf > /dev/null

echo "== 7. wp-cron host cron (every 15 min) =="
# With full-page caching, page-load wp-cron is unreliable — a host-level
# cron job ensures scheduled posts publish on time.
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
if [ -f "$SCRIPT_DIR/.env" ]; then
    # shellcheck source=/dev/null
    source "$SCRIPT_DIR/.env"
fi
if [ -z "${DOMAIN:-}" ]; then
    echo "WARNING: DOMAIN not set in .env — skipping wp-cron cron job."
    echo "         Set DOMAIN in .env and re-run this script."
else
    CRON_CMD="curl -fsS \"https://$DOMAIN/wp-cron.php?doing_wp_cron\" --resolve \"$DOMAIN:443:127.0.0.1\" >/dev/null"
    if [ -n "${HEALTHCHECKS_CRON_URL:-}" ]; then
        CRON_CMD="$CRON_CMD && curl -fsS \"$HEALTHCHECKS_CRON_URL\" >/dev/null"
    fi
    CRON_LINE="*/15 * * * * $CRON_CMD"
    ( crontab -l 2>/dev/null | grep -v 'wp-cron.php' ; echo "$CRON_LINE" ) | crontab -
    echo "wp-cron cron job installed (every 15 min)."
fi

echo "== 8. Post-deploy cache warm =="
# Warm the front page and feed after deploy to avoid cold-hit latency
# for the first real visitor. Runs only if DOMAIN is set.
if [ -n "${DOMAIN:-}" ]; then
    echo "Warming cache for $DOMAIN..."
    curl -sS "https://$DOMAIN/" > /dev/null 2>&1 || true
    curl -sS "https://$DOMAIN/feed/" > /dev/null 2>&1 || true
    echo "Cache warm complete."
else
    echo "WARNING: DOMAIN not set — skipping cache warm."
fi

echo ""
echo "Done. Log out and back in (or run 'newgrp docker') so your user"
echo "picks up docker-group membership, then continue with the README's"
echo "'Launch the stack' step."
