#!/usr/bin/env bash
# Run this once on a fresh Ubuntu 24.04 VPS, as a sudo-capable non-root user.
# It hardens the server, installs Docker, sets up swap, and configures
# the wp-cron host cron job. It does NOT start the site — that happens
# with `docker compose up -d` after this finishes.
set -euo pipefail

echo "== 1. System update =="
sudo apt update && sudo apt upgrade -y

echo "== 2. Firewall: allow only SSH, HTTP, HTTPS =="
sudo apt install -y ufw
sudo ufw allow OpenSSH
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
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
    CRON_CMD="curl -sS \"http://localhost/wp-cron.php?doing_wp_cron\" -H \"Host: $DOMAIN\" > /dev/null 2>&1"
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
