#!/usr/bin/env bash
# Run nightly via cron, e.g.:
#   crontab -e
#   0 2 * * * /home/deployer/news-site-deploy/backup.sh >> /home/deployer/backup.log 2>&1
set -euo pipefail

cd "$(dirname "$0")"
source .env

TIMESTAMP=$(date +%F_%H%M)
BACKUP_DIR="$HOME/backups"
mkdir -p "$BACKUP_DIR"

echo "[$TIMESTAMP] Starting backup"

# 1. Dump the database from inside the running mariadb container.
#    (MariaDB 12+ renamed mysqldump → mariadb-dump.)
docker exec news_mariadb sh -c "exec mariadb-dump -u root -p\"${DB_ROOT_PASSWORD}\" --all-databases" \
  > "$BACKUP_DIR/db_$TIMESTAMP.sql"

# 2. Archive wp-content (uploads, themes, plugins, mu-plugins) from the named volume.
VOLUME_NAME="${COMPOSE_PROJECT_NAME}_wordpress_data"
docker run --rm \
  -v "${VOLUME_NAME}:/data" \
  -v "$BACKUP_DIR":/backup \
  alpine tar czf "/backup/wpcontent_$TIMESTAMP.tar.gz" -C /data wp-content

# 3. Keep only the last 7 days locally — disk is limited.
find "$BACKUP_DIR" -name "db_*.sql" -mtime +7 -delete
find "$BACKUP_DIR" -name "wpcontent_*.tar.gz" -mtime +7 -delete

echo "[$TIMESTAMP] Backup complete: $BACKUP_DIR"

# 4. OPTIONAL but strongly recommended: sync to off-site storage (Backblaze B2).
# A local-only backup does not survive the VPS itself being lost or compromised.
# 1) Install rclone: curl https://rclone.org/install.sh | sudo bash
# 2) Configure a B2 remote once: rclone config  (choose "b2", follow prompts)
# 3) Uncomment the line below:
# rclone copy "$BACKUP_DIR" b2:your-bucket-name/backups --min-age 1h
