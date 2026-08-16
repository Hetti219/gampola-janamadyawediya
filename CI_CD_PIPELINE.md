# CI/CD Pipeline Plan — Gampola Janamadyawediya

**Status:** PLAN — nothing in this file is implemented yet.
**Date:** 2026-08-16

Goal: `git push` from the dev machine is the *only* action needed to put any
code change (theme, mu-plugin, nginx config, PHP config, compose file) into
production — automatically, with a health check, auto-rollback on failure,
and email notification.

---

## 1. Locked decisions

| Decision | Choice |
|---|---|
| Production server state | Nothing exists yet — plan covers provisioning from zero |
| Git platform | GitHub (private repo) |
| CI/CD | GitHub Actions |
| Environments | Push to `main` → straight to production. No staging server. |
| Deploy safety | Health check after every deploy; auto-rollback to previous commit if unhealthy |
| Notifications | Email (GitHub built-in on failure; optional explicit email on success) |
| Rollback | Automatic (health-based). Manual = push a revert commit. |

---

## 2. Scope: what the pipeline deploys vs. what it never touches

**Deploys via git (this is the code):**

| Path | Effect after `git push` |
|---|---|
| `theme/gampola-janamadyawediya/` | Bind-mounted into WP + Nginx containers → live immediately on file change |
| `mu-plugins/` | Bind-mounted → live immediately |
| `nginx/prod/` | Deploy script reloads nginx |
| `php/uploads.ini` | Deploy script runs `docker compose up -d` (recreates WP container) |
| `docker-compose.yml` | Deploy script runs `docker compose up -d` (applies new config) |
| `scripts/deploy-site.sh` | The deploy logic itself — versioned |
| `ui/` | Design mockups. **Kept in the repo for history, never deployed.** |

**Never in git — lives only on the server:**

| Thing | Why |
|---|---|
| WordPress content (posts, media, users, comments, settings) | Lives in the MariaDB DB + `wordpress_data` volume. Publishing from the WP app/dashboard is already instant — content is not a code change. |
| WordPress core + plugins installed from wp-admin (GeneratePress, Site Kit, Rank Math…) | Live in the `wordpress_data` volume. WP auto-updates handle them. Backups cover them (`backup.sh`). |
| `.env` | Contains DB passwords. Gitignored. Created once on the server. |
| `nginx/certs/*.pem`, `*.key` | Cloudflare origin cert — a private key. Gitignored. Created once on the server. |

One consequence to understand: **code and content have separate lifecycles.**
A deploy never touches the database or uploads; publishing an article never
touches git. A failed deploy rolls back *code only* — articles published in
the meantime are unaffected.

---

## 3. Architecture

```
[Dev machine]                 [GitHub]                    [GitHub Actions runner]
git push ──────────────────►  push event on main ──────►  Job 1 (lint):
                                                          · php -l on all theme/mu-plugin PHP
                                                          · docker compose config -q
                                                          Job 2 (deploy, only if lint passed):
                                                          · SSH to VPS with private key
                                                          · pipe scripts/deploy-site.sh into ssh

                                                                   │ SSH (port 22, direct to VPS IP)
                                                                   ▼
                                                          [VPS]
                                                          deploy-site.sh:
                                                            1. record current SHA
                                                            2. git fetch + reset --hard origin/main
                                                            3. rm -f docker-compose.override.yml
                                                            4. docker compose up -d
                                                            5. nginx reload + Redis flush
                                                            6. poll container health (≤2 min)
                                                            7. healthy  → done, exit 0
                                                            8. unhealthy → reset to old SHA,
                                                               compose up -d, exit 1

Failure at any step (lint, SSH, deploy, health) → workflow fails → email.
```

Notes:
- The SSH connection goes to the **VPS's raw IP**, not the domain — Cloudflare's
  proxy only covers HTTP/HTTPS, not SSH.
- The runner executes the version of `deploy-site.sh` from the commit being
  pushed (piped over SSH from the checkout), so deploy logic and code change
  together. The server only needs the repo cloned at a fixed path.
- No build step exists (there's nothing to compile) — CI is lint-only, and
  that's genuinely enough for a WordPress theme.

---

## 4. Repository setup (on the dev machine)

```bash
cd "/home/sathikashervinhettiarachchi/Programming/Web Development/gampola-journalist"
git init
git branch -M main
git add .
git commit -m "Initial commit: WordPress stack, theme, mu-plugins, CI/CD plan"
```

**`.gitignore` — must exist before the first commit:**

```gitignore
# Secrets — never commit
.env
nginx/certs/

# Editor / OS noise
.vscode/
.DS_Store
*.log
```

`docker-compose.override.yml` **is** committed (it's the local-dev config,
matching the README) — the deploy script deletes it on the server at every
deploy, so it can never leak into production behavior.

Create the repo on GitHub as **private**, then:

```bash
git remote add origin git@github.com:YOUR_USER/gampola-journalist.git
git push -u origin main
```

---

## 5. Server provisioning (one-time, from zero)

Order matters: provision the server **before** the first push that you expect
to deploy, otherwise the first deploy emails a failure. Follow README §3, then
the new steps below.

1. **Domain + Cloudflare** — README §3.1, 3.4, 3.5 (register, A records proxied,
   origin cert created and saved to `nginx/certs/` on the server).
2. **VPS** — Hetzner CPX21 or DigitalOcean equivalent, Ubuntu 24.04, Singapore
   or Mumbai region. ≥4 GB RAM (README §2).
3. **Harden + Docker** — copy the project up, run `./deploy.sh` (README §3.3).
4. **Deploy user** — dedicated, low-privilege account the pipeline uses:
   ```bash
   sudo useradd -m -s /bin/bash deployer
   sudo usermod -aG docker deployer
   ```
5. **Clone the repo on the server** (path is fixed — the deploy script expects it):
   ```bash
   sudo apt install -y git
   sudo -u deployer git clone git@github.com:YOUR_USER/gampola-journalist.git /home/deployer/news-site-deploy
   ```
   Needs an SSH key that can read the repo — add the keypair to the GitHub repo's
   **Deploy keys** (read-only). Simplest for solo work: one keypair, public key
   added to *both* GitHub deploy keys *and* `/home/deployer/.ssh/authorized_keys`.
   The private key is also stored as the GitHub Actions `SSH_KEY` secret (§7).
   (Cleaner alternative if you prefer separation: two keypairs — a read-only
   deploy key for `git clone`/`pull` and a separate key for Actions SSH login.)
6. **First-run config on the server** (only these, everything else comes via git):
   ```bash
   cd /home/deployer/news-site-deploy
   cp .env.example .env        # generate real passwords: openssl rand -base64 24
   nano .env
   # put cert files in nginx/certs/ (from step 1)
   # edit nginx/prod/wordpress.conf → real domain  ← commit this change!
   rm docker-compose.override.yml
   docker compose up -d
   ```
   > The domain edit in `nginx/prod/wordpress.conf` is a *committed* file now —
   > do the edit on the dev machine, commit, push. From this point on the repo
   > is the source of truth; hand-edits on the server will be overwritten by the
   > next `reset --hard`.
7. **WordPress install wizard** (Sinhala language), GeneratePress parent theme,
   plugins, categories, AdSense flow — README §3.8, §4.
8. **Backup cron + UptimeRobot** — README §6, §7 (unchanged by this plan).

---

## 6. One compose-file change that protects the pipeline

Add `DISALLOW_FILE_EDIT` to the WordPress container's `WORDPRESS_CONFIG_EXTRA`
in `docker-compose.yml`:

```yaml
      WORDPRESS_CONFIG_EXTRA: |
        define('WP_REDIS_HOST', 'redis');
        define('WP_MEMORY_LIMIT', '256M');
        define('FS_METHOD', 'direct');
        define('DISALLOW_FILE_EDIT', true);
```

Why: the theme and mu-plugins are bind-mounted **from the git checkout**.
Without this, the WordPress admin's Appearance → Theme File Editor could modify
tracked files on the server, dirty the working tree, and the next `reset --hard`
would silently wipe the edit (or block a plain `pull`). Disabling the editor
closes that hole entirely — it also removes an attack surface.

The same define goes into `docker-compose.override.yml`'s
`WORDPRESS_CONFIG_EXTRA` (its value replaces the base one during merge), so
local dev behaves identically. Done in both files.

---

## 7. GitHub Actions workflow — `.github/workflows/deploy.yml`

**Secrets** (repo → Settings → Secrets and variables → Actions):

| Secret | Value |
|---|---|
| `SSH_HOST` | VPS raw IP |
| `SSH_USER` | `deployer` |
| `SSH_KEY` | Private key (the one whose public key is in `authorized_keys`) |
| `CF_ZONE_ID` / `CF_API_TOKEN` | *Optional* — only if enabling the cache-purge step (§10) |

```yaml
name: Deploy to production

on:
  push:
    branches: [main]
  workflow_dispatch:        # manual re-deploy button in the Actions tab

jobs:
  lint:
    name: Lint
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: PHP syntax check (theme + mu-plugins)
        run: |
          find theme mu-plugins -name '*.php' -print0 | xargs -0 -n1 php -l

      - name: Validate compose files
        run: |
          cp .env.example .env     # CI has no .env; placeholders are enough for -q
          docker compose config -q

  deploy:
    name: Deploy
    needs: lint
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Deploy via SSH
        env:
          SSH_HOST: ${{ secrets.SSH_HOST }}
          SSH_USER: ${{ secrets.SSH_USER }}
          SSH_KEY: ${{ secrets.SSH_KEY }}
        run: |
          if [ -z "$SSH_HOST" ] || [ -z "$SSH_KEY" ]; then
            echo "::warning::Deploy secrets not configured yet — skipping deploy."
            exit 0
          fi
          mkdir -p ~/.ssh
          echo "$SSH_KEY" > ~/.ssh/deploy_key
          chmod 600 ~/.ssh/deploy_key
          ssh -i ~/.ssh/deploy_key \
              -o StrictHostKeyChecking=accept-new \
              -o ConnectTimeout=15 \
              "$SSH_USER@$SSH_HOST" 'bash -s' < scripts/deploy-site.sh
```

The deploy job **skips with a warning** (exit 0, no failure email) until the
`SSH_HOST`/`SSH_KEY` secrets exist — so pushing code before the server is
provisioned doesn't spam failure notifications. Once the secrets are set, the
skip guard never triggers again.

Hardening option (recommended once it's working): replace `accept-new` with
`-o StrictHostKeyChecking=yes` and store the server's host key in a
`SSH_KNOWN_HOSTS` secret — prevents any MITM on the deploy channel.

---

## 8. Deploy script — `scripts/deploy-site.sh` (new file, committed)

```bash
#!/usr/bin/env bash
# Deployed code. GitHub Actions pipes this over SSH and runs it on the server.
# Runs as the 'deployer' user inside /home/deployer/news-site-deploy.
set -euo pipefail

REPO_DIR="/home/deployer/news-site-deploy"
cd "$REPO_DIR"

PREV_SHA=$(git rev-parse HEAD)

echo "== Deploying $(git rev-parse --short "$PREV_SHA") -> origin/main =="
git fetch origin main
git reset --hard origin/main

# Local-dev override must never exist on the server.
rm -f docker-compose.override.yml

echo "== Recreating containers if config changed =="
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
  echo "!! Rolled back. Investigate before pushing again."
  exit 1
fi

echo "== Deploy OK: $(git rev-parse --short HEAD) is live =="
exit 0
```

Why `reset --hard` instead of `pull`: the server checkout must always match the
remote exactly. `.env` and `nginx/certs/` are gitignored, so reset never touches
them. Content lives outside git entirely, so rollback is code-only and safe.

Why bind-mounted code needs no rebuild: theme/mu-plugin changes are visible to
the containers the instant files change on disk. `compose up -d` + reload are
there for compose/nginx/php-config changes and are idempotent — cheap to run
every time.

---

## 9. Rollback behavior

| Situation | What happens |
|---|---|
| Lint fails on the runner | Deploy never starts. Workflow fails → email. |
| SSH/deploy script errors mid-way | `set -euo pipefail` aborts. Workflow fails → email. State is pre-reset or partially applied; a re-push after fixing re-runs everything idempotently. |
| Containers unhealthy within 2 min | Automatic rollback to the previous SHA, containers restored, email. |
| Rollback itself fails | Workflow fails loudly → email. Server-side recovery is manual (SSH in, `docker compose up -d`, check `docker compose logs`). |

Manual rollback without SSH: push a revert commit (`git revert <bad-sha>`) —
the pipeline does the rest.

---

## 10. Notifications (email)

**Failures (automatic, no code):** GitHub emails you when a workflow run fails,
provided:
1. You **Watch** the repo (repo page → Watch → All Activity).
2. GitHub → Settings → Notifications → **Actions**: enable email for
   *failed workflow runs*.

**Successes (optional):** GitHub's built-in email only covers failures. If you
also want a "deploy succeeded" email, add a step using
`dawidd6/action-send-mail` (or similar) at the end of the `deploy` job — needs
one SMTP credential as a secret (e.g., a Gmail app password). Alternatively,
the GitHub mobile app pushes success/failure notifications for free. This can
be added later without changing anything else.

**Health-ping safety net:** keep UptimeRobot (README §7) — it catches problems
the pipeline can't (e.g., the site dies 3 hours after a *successful* deploy).

---

## 11. Optional: purge Cloudflare cache after deploy

Theme CSS changes can sit stale in Cloudflare's edge cache after a deploy.
The Cloudflare WP plugin (README §4.2) purges on *content* publishes but not on
*code* deploys. Optional end-of-deploy step in `deploy-site.sh`:

```bash
curl -sf -X POST "https://api.cloudflare.com/client/v4/zones/${CF_ZONE_ID}/purge_cache" \
  -H "Authorization: Bearer ${CF_API_TOKEN}" \
  --data '{"purge_everything":true}' || true
```

Caveat: Cloudflare's free plan caps *purge everything* at 30/month — fine for
this volume, but if you push often, either skip this and purge manually in the
dashboard when styling changes, or accept the cap. `CF_ZONE_ID`/`CF_API_TOKEN`
would need to be exported in the server's `~/.bashrc` or `.env` (server-side,
never in the repo). Marked optional — everything else works without it.

---

## 12. Interaction with existing scripts

| Script | Change |
|---|---|
| `deploy.sh` | Unchanged — still the one-time provisioning step (§5.3) |
| `backup.sh` | Unchanged — still nightly cron; volumes and DB untouched by deploys |
| `docker-compose.yml` | One addition: `DISALLOW_FILE_EDIT` (§6) |
| `README.md` | Add a short "Deploying changes" section pointing here |

---

## 13. Implementation checklist (in order)

- [x] 1. `git init`, `.gitignore`, first commit (§4)
- [x] 2. Private GitHub repo, push `main`
- [x] 3. Add `DISALLOW_FILE_EDIT` to compose (§6) — commit it
- [ ] 4. Buy domain + VPS; run `deploy.sh` (§5.1–5.3)
- [ ] 5. Create `deployer` user, keys, clone repo on server (§5.4–5.5)
- [ ] 6. Server `.env`, certs, domain edit in nginx conf (commit the conf change), first `compose up` (§5.6)
- [ ] 7. WP install wizard + theme/plugins/categories (README §3.8, §4)
- [x] 8. Add `scripts/deploy-site.sh` + `.github/workflows/deploy.yml`; push
- [ ] 9. Add Actions secrets (`SSH_HOST`, `SSH_USER`, `SSH_KEY`) (§7)
- [ ] 10. Enable failure emails + Watch the repo (§10)
- [ ] 11. Backup cron + UptimeRobot (README §6–7)
- [ ] 12. **Test the rollback:** push a deliberately broken theme file (e.g., syntax error in `functions.php` won't pass lint — better: a CSS rule that breaks layout, or a temporary `wp_die()` in the child theme), confirm the pipeline detects it, rolls back, and emails you
- [ ] 13. Optional: Cloudflare purge step, success emails, `SSH_KNOWN_HOSTS` hardening

---

## 14. Future ideas (deliberately out of scope now)

- Staging environment (a second cheap VPS or subdomain with the same workflow)
- Telegram notifications instead of/in addition to email
- Path-filtered triggers (skip deploy for docs-only pushes)
- Branch protection on `main` (requires status checks to pass)
- WP-CLI-based DB migrations in the pipeline (schema changes are currently
  manual in wp-admin, which is fine at this scale)
- Automating WP core/plugin image updates (`docker compose pull` on a schedule)
- rclone sync of backups to Backblaze B2 (README §6 — do this regardless, it's
  the off-site backup, independent of the pipeline)

---

## 15. Open items for you

1. **Domain name** — not registered yet; pick one before §5.1.
2. **VPS provider** — README recommends Hetzner; confirm.
3. **Success emails** — want them? Then which SMTP account to send from?
4. **Cloudflare purge step** — enable now or add later?
