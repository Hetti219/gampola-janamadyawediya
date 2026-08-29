#!/usr/bin/env bash
# Deployed code. GitHub Actions pipes this over SSH and runs it on the server
# as the 'deployer' user (see CI_CD_PIPELINE.md §5.4–5.5, §8).
# Safe to run any number of times — every step is idempotent, and content
# (database, uploads, wp-content volume) is never touched.
set -euo pipefail

REPO_DIR="/home/deployer/gmp-jm-prod"
cd "$REPO_DIR"

PREV_SHA=$(git rev-parse HEAD)

echo "== Deploying $(git rev-parse --short "$PREV_SHA") -> origin/main =="
git fetch origin main
git reset --hard origin/main

# Local-dev override must never exist on the server.
rm -f docker-compose.override.yml

echo "== Recreating containers if config changed =="
docker compose pull
docker compose up -d

echo "== Reload nginx + flush object cache =="
docker exec news_nginx nginx -s reload || true
docker exec news_redis redis-cli FLUSHDB || true

echo "== Health check (up to 2 minutes) =="
healthy() {
  for c in news_nginx news_wordpress news_mariadb news_redis; do
    [ "$(docker inspect --format '{{.State.Health.Status}}' "$c" 2>/dev/null)" = "healthy" ] || return 1
  done
}
ok=0
for _ in $(seq 1 12); do
  if healthy; then ok=1; break; fi
  sleep 10
done

if [ "$ok" -ne 1 ]; then
  echo "!! Health check FAILED — rolling back to $PREV_SHA"
  git reset --hard "$PREV_SHA"
  rm -f docker-compose.override.yml
  docker compose up -d
  docker exec news_nginx nginx -s reload || true

  echo "== Validating rollback health =="
  rollback_ok=0
  for _ in $(seq 1 12); do
    if healthy; then rollback_ok=1; break; fi
    sleep 10
  done
  
  if [ "$rollback_ok" -ne 1 ]; then
    echo "CRITICAL: Rollback failed health check! The site is DOWN."
    exit 1
  fi

  echo "!! Rolled back. Investigate before pushing again."
  exit 1
fi

echo "== Deploy OK: $(git rev-parse --short HEAD) is live =="
exit 0
