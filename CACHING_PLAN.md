# Aggressive Caching Plan — Gampola Janamadyawediya

**Status: PLAN — awaiting approval, not yet implemented.**
**Date: 2026-08-19**
**Reviewed: 2026-08-19** — review findings (P0/P1/P2) integrated inline below.

## Context

The site targets up to ~166,000 hits/day on a 2GB Linode (1 shared vCPU), upgradeable later via Linode resize. The current stack serves every page as an uncached PHP render (~15–25 DB queries on the front page), which buckles well before that target. The requirement: aggressive caching **without delaying new content** — a published story must appear essentially immediately.

Exploration findings that shape the design:

- Cloudflare is **already** in front (proxied, Full-strict, origin certs) — the edge layer exists but has no caching rules.
- Redis is provisioned but dormant (Redis Object Cache plugin not yet activated).
- The theme is an ideal cache target: **zero JS, zero REST/ajax/XML-RPC calls, zero view counters, zero user-state rendering**. Anonymous pages are 100% URL-keyed static HTML. The only dynamic server-rendered elements are the Sinhala date, "X ago" chips, news ticker, hero grid, and menus/footer widgets.
- The stock `nginx:1.30.4-alpine` image has no `ngx_cache_purge` module — the plan deliberately avoids needing it.
- **Existing mu-plugins are cache-safe.** `graphic-content-gate.php` (sensitive-content overlay) is purely client-side JS/sessionStorage — it works identically from cached HTML. `graphic-content-auto-blur.php` bakes the blurred OG image URL into the HTML at render time via `save_post` / `set_object_terms` hooks. Both survive full-page caching without changes, but hook ordering with the new purge plugin matters (see §7).

## Architecture: three cache layers + tuned baseline

```
Visitor ──► Cloudflare edge (already in front!) ──► nginx micro-cache ──► PHP-FPM ──► Redis object cache ──► MariaDB
              full-page cache, purge-on-publish   10–300s TTL, no PHP    anonymous    WP plugin (activate)       256M pool
```

No new services are introduced. Each layer absorbs a share of traffic:

- **Edge (Cloudflare):** absorbs the overwhelming majority of hits (~90%+). Purged instantly on publish via the purge-by-URL API.
- **Origin (nginx fastcgi_cache):** protects PHP from edge re-fetches and bursts; short TTLs make it self-healing.
- **Object cache (Redis):** cuts DB load ~50% for the (rare) uncached renders.
- **Baseline tuning:** MariaDB capped, swap safety net, wp-cron moved off page loads.

## Freshness guarantee

| Event | Delay until visible |
|---|---|
| Publish new post | **instant** — mu-plugin purges post + home + feed + category archives at Cloudflare |
| If purge API fails or is unconfigured | ≤ ~10s — origin front-page TTL self-heals |
| "මිනිත්තු Xකට පෙර" chips / Sinhala date | ≤ 60s worst case at edge — imperceptible |
| Editor previewing a draft | instant — logged-in requests bypass all caches |

Instant invalidation lives at the **edge** (Cloudflare purge-by-URL API: 5 req/min token bucket, 25 burst — the 30/month cap applies only to purge-everything). Origin TTLs are so short they self-heal, so the missing `ngx_cache_purge` module never matters.

## TTL table

| Page type | Origin (`X-Accel-Expires`) | Edge (`s-maxage`) | Browser (`max-age`) |
|---|---|---|---|
| Front page | 10s | 60s | 10s |
| Category/archive | 60s | 120s | 60s |
| Single post | 300s | 600s | 300s |
| Feed | 60s | 60s | 60s |
| Search, wp-admin, wp-json, wp-cron, previews | **bypass** | bypass rules | no-store |

Capacity math: at 166k/day the origin renders the front page at most ~6×/min even during a spike; a hot single post renders once per 5 min per URL. Uncacheable loads (searches, logged-in sessions) are rare enough for 2GB.

## File-by-file changes

### 1. `nginx/prod/wordpress.conf` — core rewrite

- `fastcgi_cache_path /var/cache/nginx/wp levels=1:2 keys_zone=WP:32m max_size=200m inactive=60m;`
  (valid inside conf.d, since it's included in the image's `http{}` block; container-local fs — wiped on container recreate, which is fine, deploys already flush Redis)
- `map` blocks producing one combined `$skip_cache` (cookie `wordpress_logged_in` / `comment_author` / `wp-postpass`, `POST`, args `preview=` / `s=`, URIs `/wp-admin` `/wp-json` `/wp-login` `/wp-cron` / `xmlrpc.php`), applied to **both** `fastcgi_no_cache` and `fastcgi_cache_bypass`
- In the PHP location:
  - `fastcgi_cache_key "$scheme$request_method$host$request_uri";`
  - `fastcgi_cache_valid 200 60s;` `301 302 10m;` `404 10s;` (per-page TTLs override via `X-Accel-Expires`) — **[P1 review fix]** 404 TTL lowered from 30s to 10s: a pre-publication link visited before publish would cache a 404 at the nginx layer, and unlike Cloudflare the nginx cache has no purge-by-URL, so the 404 would persist until TTL expiry even after the purge hook fires. 10s keeps this window negligible.
  - `fastcgi_cache_use_stale error timeout updating invalid_header http_500 http_502 http_503;`
  - `fastcgi_cache_background_update on;` — **[P2 review addition]** combined with `use_stale updating`, this serves a stale response immediately while fetching a fresh copy in the background, eliminating the latency spike on cache expiry for the unlucky request that triggers the refresh. Supported in nginx 1.27.
  - `fastcgi_cache_lock on;` `fastcgi_cache_lock_timeout 5s;` (no thundering herd on expiry)
  - `add_header X-FastCGI-Cache $upstream_cache_status always;` for observability
  - `add_header Vary Cookie always;` — **[P1 review addition]** ensures any intermediate proxy (or direct-to-origin traffic during Cloudflare outage) doesn't serve a logged-in response to an anonymous user. Belt-and-braces: Cloudflare strips `Vary` for HTML at edge, so this only matters for non-Cloudflare paths.
  - **Re-declare the 3 existing security headers inside this location** — the `add_header` inheritance gotcha would silently drop them otherwise
- Extend the static-asset regex: add `svg ico avif woff ttf eot pdf webmanifest` — **[P2 review note]** these files are served directly by nginx via `try_files` and never reach PHP/fastcgi_cache; the change adds proper browser-level `expires` and `Cache-Control` headers for them (they were previously served with no caching headers).
- `gzip on` + text/JSON/XML/SVG types (Cloudflare does brotli at edge; origin gzip is free)

### 2. `nginx/local/local.conf`

Same caching blocks with a smaller cache zone — dev parity so HIT/BYPASS can be verified on `localhost:8080`.

### 3. `docker-compose.yml`

- Mount `./mariadb/conf.d:/etc/mysql/conf.d:ro`
- Redis: `command: redis-server --maxmemory 128mb --maxmemory-policy allkeys-lru`
- Pass `CF_ZONE_ID` + `CF_API_TOKEN` environment through to wordpress
- Add `define('DISABLE_WP_CRON', true);` to `WORDPRESS_CONFIG_EXTRA` (**critical**: with full-page caching, page-load wp-cron would almost never fire and scheduled posts would publish late)
- Retarget nginx healthcheck from `/` to `/healthz` — **[P0 review fix]** the original plan targeted `/wp-json/wp/v2/types`, but that endpoint depends on the REST API subsystem being enabled. If any security plugin disables REST API (common hardening step), the healthcheck fails and Docker restarts the container in a loop. A dedicated lightweight `mu-plugins/healthcheck.php` (see §8) is more robust and proves PHP-FPM is alive, WordPress loaded, and the mu-plugins mount is working.
- **[P1 review note]** `docker-compose.override.yml` also overrides `WORDPRESS_CONFIG_EXTRA` entirely (it uses a full `|` block for `WP_SITEURL`/`WP_HOME`). When adding `DISABLE_WP_CRON` to production, also add it to the override file for local dev parity — otherwise local dev will have caching (per §2) but wp-cron still firing on page loads, creating a divergence.

### 4. `mariadb/conf.d/60-tune.cnf` — new

```
innodb_buffer_pool_size = 256M   # [P0 review fix] see RAM budget below; bump to 384M on 4GB
performance_schema      = OFF    # saves ~150–200MB on MariaDB 11
max_connections         = 50
table_open_cache        = 400
```

**[P0 review fix]** `innodb_buffer_pool_size` lowered from 384M to 256M. RAM budget on 2GB:

| Consumer | Estimated RSS |
|---|---|
| MariaDB (256M buffer pool + ~100M overhead) | ~370 MB |
| PHP-FPM (4–8 workers × ~40–60 MB each) | ~200–480 MB |
| Nginx | ~20 MB |
| Redis (128 MB cap) | ~150 MB |
| OS + kernel | ~300 MB |
| **Total** | **1,040–1,320 MB** |

This leaves 680–960 MB headroom — enough for PHP memory spikes during image processing (the `graphic-content-auto-blur.php` does 40-pass Gaussian blur in GD). The original 384M left only 550–830 MB, which is tight when GD allocates a full-resolution image buffer. A single-site WordPress DB working set fits comfortably in 256M.

### 5. `php/opcache.ini` — new (same mount pattern as `uploads.ini`)

```
opcache.memory_consumption = 128
opcache.interned_strings_buffer = 16
opcache.max_accelerated_files = 8000
opcache.revalidate_freq = 60    # timestamps stay validated — the theme is bind-mounted
```

### 6. `mu-plugins/cache-headers.php` — new

`send_headers` hook emits the per-page-type `X-Accel-Expires` + `Cache-Control: public, max-age=…, s-maxage=…` per the TTL table; logged-in/comment-author requests get `no-store` (belt-and-braces on top of the nginx bypass). One hook drives both cache layers. Skips admin, REST, AJAX, feeds get their own row in the table.

### 7. `mu-plugins/cloudflare-purge.php` — new

- Hooks: `transition_post_status` (any transition touching `publish`), `edit_post`, `deleted_post`, `set_object_terms`, `comment_post` / `wp_set_comment_status` / `edit_comment`
- **[P0 review fix]** All hooks must run at priority **99 or higher** — the existing `graphic-content-auto-blur.php` hooks `save_post` and `set_object_terms` at priority 20 to generate a blurred OG image for sensitive posts. If the purge fires first, Cloudflare re-fetches and caches the page *before* the blurred image is attached, leaking the uncensored featured image as the `og:image` tag into Facebook/Twitter link previews. Priority 99+ guarantees the blur runs first.
- Purges post permalink + home + feed + category archive links (deduped, ≤30 URLs → one API call) via `purge_cache`
- **[P1 review note]** Comment hooks (`comment_post`, `wp_set_comment_status`, `edit_comment`) — the child theme never renders comments. If comments are disabled site-wide in GeneratePress settings, these hooks fire for nothing. Consider guarding with `comments_open( $post_id )` or documenting "remove comment hooks if comments are permanently disabled" to reduce unnecessary API calls.
- Reads `CF_ZONE_ID` / `CF_API_TOKEN` from env; no-ops with an `error_log` notice when unset
- Fire-and-forget, short timeout; if Cloudflare is unreachable, TTLs self-heal (≤10s front page)

### 8. `mu-plugins/healthcheck.php` — new **[P0 review addition]**

A minimal mu-plugin that registers a `/healthz` rewrite rule returning `200 OK` in plain text. Proves PHP-FPM is alive, WordPress loaded, and the mu-plugins volume mount is working — without depending on the REST API subsystem. The nginx healthcheck (§3) targets this instead of `/wp-json/wp/v2/types`. Excluded from caching via the `$skip_cache` map (URI `/healthz`).

### 9. `deploy.sh` — idempotent additions

- 2GB swapfile + `vm.swappiness=10` (OOM safety net on 2GB)
- Host cron every 15 min: `curl -sS "http://localhost/wp-cron.php?doing_wp_cron" -H "Host: $DOMAIN"` (bypasses Cloudflare, no DNS dependency), reading `DOMAIN` from `.env`; skips with a warning if unset
- **[P2 review addition]** Post-deploy cache warm: `curl -sS https://$DOMAIN/ https://$DOMAIN/category/… > /dev/null` for the front page and top category pages. Prevents the first-visitor cold-hit penalty after a deploy that wipes the container-local nginx cache. `fastcgi_cache_lock` prevents thundering herd, but the locking request itself is still slow; warming avoids it entirely.

### 10. `.env.example`

Add `DOMAIN`, `CF_ZONE_ID`, `CF_API_TOKEN` placeholders (real values live in the gitignored server `.env`).

### 11. `README.md` + `CI_CD_PIPELINE.md`

New "Caching" section: architecture, TTL table, and the manual steps below.

## Manual steps (documented — cannot be done from git)

1. **Activate the Redis Object Cache plugin** in wp-admin (infra already wired via `WP_REDIS_HOST`).
2. **Cloudflare dashboard:**
   - 1 Page Rule `*domain/*` → Cache Level: Cache Everything, headers respected (Page Rules are proven to cache HTML on the free plan; Cache Rules have a known DYNAMIC quirk there — verify with `cf-cache-status` either way)
   - ~3 Cache Rules to bypass: URIs `/wp-admin` | `/wp-login` | `/wp-cron`; `/wp-json`; cookie contains `wordpress_logged_in` / `comment_author`
   - Optional: a Cache Rule stripping UTM/tracking query params from the cache key
3. **Create a scoped API token** (Cache Purge + Zone Read only, never the global key) → put `CF_ZONE_ID` + `CF_API_TOKEN` in the server `.env` → `docker compose up -d` to pass them into the container.
4. **Re-run `deploy.sh`** once for the swap + cron additions.

## Comments

The child theme never calls comments, but the GeneratePress parent renders them on singles if enabled in GP settings. Either way is covered: purge hooks fire on new/edited comments, and comment authors are cookie-bypassed. No action needed unless comments are disabled site-wide.

## Verification

- **CI:** new mu-plugins are auto-linted by the existing `php -l` workflow step; `docker compose config -q` still validates.
- **Local:** `curl -sI http://localhost:8080/` twice → `X-FastCGI-Cache: MISS` then `HIT`; with a fake `wordpress_logged_in_` cookie → `BYPASS`; `/wp-admin` → `BYPASS`; publish a test post → visible on home within 10s.
- **Local (graphic-content):** publish a test post in the `graphic-content` category → verify `og:image` in the HTML source contains the `ncg-blurred-*` URL (not the raw featured image) on both first and subsequent cached loads.
- **Prod:** `cf-cache-status: HIT` on the second HTML hit; publish a post → purge visible in the Cloudflare activity log → new post instantly served; `free -h` shows headroom during a spike; UptimeRobot stays green.
- **[P2 review addition] Hit-ratio monitoring:** set up a lightweight cron script (or UptimeRobot keyword monitor) that `curl -sI`s the homepage periodically and checks for `X-FastCGI-Cache: HIT` + `cf-cache-status: HIT`. Log results to a file; alert if hit ratio drops below ~90% over an hour. At 166k hits/day, a sustained cache miss spike would overload the box quickly.

## Risks

| Risk | Mitigation |
|---|---|
| Cached admin bar leaks to anonymous users | Cookie bypass + explicit verification step (P0 if wrong) |
| Date/"X ago" chips frozen | Front page TTL 10s origin / 60s edge — invisible in practice |
| Healthcheck false-positive on dead PHP | Dedicated `/healthz` mu-plugin endpoint (does not depend on REST API) |
| `add_header` inheritance drops security headers | Re-declared inside the PHP location |
| Cloudflare Cache Rules return DYNAMIC for HTML | Page Rules primary (proven), verified via `cf-cache-status` |
| Cache wipe on container recreate / deploy FLUSHDB | Both are caches; post-deploy warm-up curl; `fastcgi_cache_lock` prevents herd |
| **[P0]** Purge fires before auto-blur finishes → uncensored OG image cached | Purge hooks at priority 99+; auto-blur at priority 20 runs first |
| **[P0]** OOM on 2GB under PHP memory spikes (GD image processing) | InnoDB buffer pool 256M (not 384M); swap as safety net |
| **[P1]** REST API disabled by security plugin breaks healthcheck | Replaced with dedicated `/healthz` endpoint |
| **[P1]** Intermediate proxy serves logged-in page to anonymous user | `Vary: Cookie` header on cached responses |

## Sources

- [Nginx fastcgi cache setup & purge options](https://www.hostmycode.com/tutorials/nginx-cache-setup-guide-tutorial-2026-fastcgi-cache-wordpress-vps-safe-purge-bypass-debug)
- [WordPress admin handbook: nginx caching](https://raw.githubusercontent.com/WordPress/Advanced-administration-handbook/85d2ec2ce5486e4aaf92dfde4bc066b6a6d1076f/server/nginx.md)
- [FastCGI cache for WordPress at scale](https://stackharbor.com/en/knowledge-base/wp-cache-nginx-fastcgi-config/)
- [Cloudflare + WordPress cache rules](https://stackharbor.com/en/knowledge-base/wp-cloudflare-cache-rules-what-pays-off/)
- [Cloudflare purge API rate limits](https://developers.cloudflare.com/cache/how-to/purge-cache/)
- [Free-plan Cache Rules DYNAMIC quirk](https://dev.to/doodoolove/why-cloudflares-free-plan-cache-rules-dont-cache-your-html-and-the-page-rules-fix-that-does-24d4)
- [Cloudflare WP plugin automatic cache management](https://developers.cloudflare.com/support/third-party-software/content-management-system-cms/cloudflare-wordpress-plugin-automatic-cache-management/index.md)
- [WordPress on 2GB tuning](https://blog.27nk.com/article/118291)
- [Linode resize](https://techdocs.akamai.com/cloud-computing/docs/resize-a-compute-instance#resize-a-linode)
