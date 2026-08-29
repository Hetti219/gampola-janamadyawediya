#!/usr/bin/env bash
# Run nightly via cron, e.g.:
#   crontab -e
#   0 2 * * * /home/deployer/gampola-janamadyawediya/backup.sh >> /home/deployer/backup.log 2>&1
set -euo pipefail

cd "$(dirname "$0")"
source .env

if [ -z "${BACKUP_PASSPHRASE:-}" ]; then
  echo "ERROR: BACKUP_PASSPHRASE is not set in .env. Backups MUST be encrypted."
  exit 1
fi

TIMESTAMP=$(date +%F_%H%M)
BACKUP_DIR="$HOME/backups"
mkdir -p "$BACKUP_DIR"

echo "[$TIMESTAMP] Starting backup"

# 1. Dump the database with single-transaction for consistency
docker exec news_mariadb sh -c "exec mariadb-dump -u root -p\"${DB_ROOT_PASSWORD}\" --single-transaction \"${DB_NAME}\"" \
  > "$BACKUP_DIR/db_$TIMESTAMP.sql"

# Verify dump is not empty
test -s "$BACKUP_DIR/db_$TIMESTAMP.sql" || { echo "DB dump failed (empty)!"; exit 1; }

# 2. Archive wp-content from the named volume
VOLUME_NAME="${COMPOSE_PROJECT_NAME}_wordpress_data"
docker run --rm \
  -v "${VOLUME_NAME}:/data" \
  -v "$BACKUP_DIR":/backup \
  alpine tar czf "/backup/wpcontent_$TIMESTAMP.tar.gz" -C /data wp-content

# Verify tar is not empty
test -s "$BACKUP_DIR/wpcontent_$TIMESTAMP.tar.gz" || { echo "wp-content tar failed (empty)!"; exit 1; }

# 3. Verification: Dummy restore into scratch DB
docker exec news_mariadb sh -c "mariadb -u root -p\"${DB_ROOT_PASSWORD}\" -e 'CREATE DATABASE IF NOT EXISTS scratch_test;'"
docker exec -i news_mariadb sh -c "mariadb -u root -p\"${DB_ROOT_PASSWORD}\" scratch_test" < "$BACKUP_DIR/db_$TIMESTAMP.sql" || { echo "DB restore drill failed!"; exit 1; }
docker exec news_mariadb sh -c "mariadb -u root -p\"${DB_ROOT_PASSWORD}\" -e 'DROP DATABASE scratch_test;'"
echo "[$TIMESTAMP] Dummy restore verification passed."

# 4. Package and Encrypt (DB, files, and .env)
cp .env "$BACKUP_DIR/env_$TIMESTAMP.txt"
tar -czf "$BACKUP_DIR/backup_$TIMESTAMP.tar.gz" -C "$BACKUP_DIR" "db_$TIMESTAMP.sql" "wpcontent_$TIMESTAMP.tar.gz" "env_$TIMESTAMP.txt"
openssl enc -aes-256-cbc -salt -pbkdf2 -k "$BACKUP_PASSPHRASE" -in "$BACKUP_DIR/backup_$TIMESTAMP.tar.gz" -out "$BACKUP_DIR/backup_$TIMESTAMP.tar.gz.enc"

# Cleanup raw unencrypted files
rm -f "$BACKUP_DIR/db_$TIMESTAMP.sql" "$BACKUP_DIR/wpcontent_$TIMESTAMP.tar.gz" "$BACKUP_DIR/env_$TIMESTAMP.txt" "$BACKUP_DIR/backup_$TIMESTAMP.tar.gz"

# 5. Tiered Retention Cleanup
# Keep: daily (14), weekly (8, Sundays), monthly (12, 1st of month)
for file in "$BACKUP_DIR"/backup_*.tar.gz.enc; do
  [ -f "$file" ] || continue
  file_ts=$(stat -c %Y "$file")
  now=$(date +%s)
  age_days=$(( (now - file_ts) / 86400 ))
  day_of_week=$(date -d "@$file_ts" +%u) # 1=Mon, 7=Sun
  day_of_month=$(date -d "@$file_ts" +%d)
  
  if [ "$age_days" -le 14 ]; then
    continue # Keep daily x 14
  elif [ "$age_days" -le 56 ] && [ "$day_of_week" -eq 7 ]; then
    continue # Keep weekly x 8 (Sundays)
  elif [ "$age_days" -le 365 ] && [ "$day_of_month" -eq 01 ]; then
    continue # Keep monthly x 12 (1st of month)
  else
    rm -f "$file"
  fi
done

echo "[$TIMESTAMP] Backup complete, encrypted, and rotated: $BACKUP_DIR"

# 6. OPTIONAL but strongly recommended: sync to off-site storage (Backblaze B2).
# A local-only backup does not survive the VPS itself being lost or compromised.
# 1) Install rclone: curl https://rclone.org/install.sh | sudo bash
# 2) Configure a B2 remote once: rclone config  (choose "b2", follow prompts)
# 3) Uncomment the line below:
# rclone copy "$BACKUP_DIR" b2:your-bucket-name/backups --min-age 1h

# 7. Ping Healthchecks.io on success
if [ -n "${HEALTHCHECKS_BACKUP_URL:-}" ]; then
  curl -fsS "$HEALTHCHECKS_BACKUP_URL" >/dev/null
fi
