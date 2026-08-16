#!/usr/bin/env bash
# Run this once on a fresh Ubuntu 24.04 VPS, as a sudo-capable non-root user.
# It hardens the server and installs Docker. It does NOT start the site —
# that happens with `docker compose up -d` after this finishes.
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

echo ""
echo "Done. Log out and back in (or run 'newgrp docker') so your user"
echo "picks up docker-group membership, then continue with the README's"
echo "'Launch the stack' step."
