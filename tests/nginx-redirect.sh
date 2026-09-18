#!/usr/bin/env bash
#
# Verifies the www → apex canonical redirect in nginx/prod/wordpress.conf.
#
# www must 301 to the apex, and the apex must keep reaching the site block.
# The failure this guards against is silent: naming www in a site block's
# server_name makes that block win over the redirect, and www quietly starts
# serving duplicate content again with no error anywhere.
#
# Usage: ./tests/nginx-redirect.sh      (requires Docker)
# Exit:  0 = all passed, 1 = one or more failures.

set -euo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
IMG="nginx:1.30.4-alpine"
NAME="gjn-nginx-redirect-test"
PORT=18443
APEX="gampolajanamadyawediya.com"
WWW="www.${APEX}"

# Inside the repo, not /tmp: Docker Desktop only bind-mounts paths under the
# shared project directory, and refuses the mount outright otherwise.
TMP="$REPO/.nginx-redirect-test"
cleanup() {
	docker rm -f "$NAME" >/dev/null 2>&1 || true
	rm -rf "$TMP"
}
trap cleanup EXIT
mkdir -p "$TMP"

# Throwaway cert so the TLS handshake and `nginx -t` both succeed. The real
# certs live in nginx/certs/ (gitignored) and are not needed to test routing.
openssl req -x509 -newkey rsa:2048 -nodes -days 1 \
	-keyout "$TMP/cloudflare-origin.key" \
	-out "$TMP/cloudflare-origin.pem" \
	-subj "/CN=$WWW" >/dev/null 2>&1

# The config fastcgi_passes to "wordpress:9000". nginx resolves upstream names
# at startup, so the name must exist even though nothing listens on it.
docker run -d --name "$NAME" \
	--add-host wordpress:127.0.0.1 \
	-p "127.0.0.1:$PORT:443" \
	-v "$REPO/nginx/prod":/etc/nginx/conf.d:ro \
	-v "$TMP":/etc/nginx/certs:ro \
	"$IMG" >/dev/null

# A bad config makes the container exit; surface that instead of timing out.
for _ in $(seq 1 40); do
	docker exec "$NAME" nginx -t >/dev/null 2>&1 && break
	if [ -z "$(docker ps -q -f "name=^${NAME}$")" ]; then
		echo "FAIL  nginx exited on startup — config is invalid:"
		docker logs "$NAME" 2>&1 | tail -20
		exit 1
	fi
	sleep 0.25
done

fail=0

# request <host> → "status<TAB>location"
request() {
	local host="$1"
	curl -sk -o /dev/null -D - --max-time 5 \
		"https://127.0.0.1:$PORT/some/path/" -H "Host: $host" 2>/dev/null |
		tr -d '\r' |
		awk 'NR==1{st=$2} tolower($1)=="location:"{loc=$2} END{print st"\t"loc}'
}

# www must 301 to the apex, preserving the path.
IFS=$'\t' read -r status loc <<<"$(request "$WWW")"
if [ "$status" = "301" ] && [ "$loc" = "https://$APEX/some/path/" ]; then
	echo "PASS  $WWW → 301 $loc"
else
	echo "FAIL  $WWW → expected 301 https://$APEX/some/path/, got ${status:-<none>} ${loc:-<none>}"
	fail=1
fi

# The apex must NOT be swallowed by the www rule. Any non-3xx proves the site
# block still owns it (502 here: no PHP upstream in this test container).
IFS=$'\t' read -r status loc <<<"$(request "$APEX")"
if [ "$status" != "301" ] && [ "$status" != "302" ]; then
	echo "PASS  $APEX → $status (site block, not redirected)"
else
	echo "FAIL  $APEX → got a redirect to ${loc:-<none>}; the www rule is capturing the apex"
	fail=1
fi

exit "$fail"
