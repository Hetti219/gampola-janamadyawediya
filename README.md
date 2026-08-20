# News site — deployment guide

Self-hosted WordPress on a VPS you control, fronted by Cloudflare, built for:
a solo non-technical operator publishing from phone or laptop, occasional
graphic/sensitive news content behind a warning gate, AdSense monetization,
and Sinhala-language content.

Read this top to bottom once before running anything. It's ordered as an
actual runbook.

---

## 1. Decisions this build assumes

| Decision | Choice | Why |
|---|---|---|
| Domain | `.com` | Instant registration, no paperwork, globally trusted by AdSense/search engines. Add a `.lk` later and redirect it if the operation formalizes into a registered business. |
| Hosting | Self-managed VPS + Docker | Cheapest ongoing cost (~$5-12/mo total), matches your existing Linux/Docker background, full control over the content-warning gate and image pipeline that managed hosts won't let you customize. |
| Video | Self-hosted on the VPS | Full control, no dependency on another platform's rules. Costs more in storage/bandwidth — planned for below. |
| CMS | WordPress (self-hosted, not WordPress.com) | Non-technical operator can publish from the mobile app or the web dashboard; you maintain the server so he never touches infrastructure. |
| Monetization | Google AdSense via Site Kit plugin | Required (paywall was ruled out). Note: pages in the "graphic content" category will get **restricted ad serving** under Google's Shocking Content policy — expect little/no ad revenue on those specific articles. Revenue is carried by the general content, not the graphic posts. |

---

## 2. Server specs

Start here, scale up only if traffic demands it:

| Tier | Specs | Approx. cost | When |
|---|---|---|---|
| Starter | 2 vCPU / 4 GB RAM / 80 GB SSD | ~$24/mo (Linode 4 GB Shared) | Launch, low-to-moderate traffic |
| Scale-up | 4 vCPU / 8 GB RAM | ~$48/mo (Linode 8 GB Shared) | If a story goes viral and the starter tier chokes |

Don't go below 4 GB RAM. WordPress + MariaDB + Redis + Nginx + occasional
video handling in Docker needs the headroom — a 1-2 GB box will swap and
slow the site down under any real load.

**Provider:** Linode (Akamai). The 4 GB Shared plan includes 4 TB transfer —
enough headroom for self-hosted video early on, with per-GB overage if a
story spikes. Choose a data center in Singapore (`ap-south`) or Mumbai
(`in-ma`) for the lowest latency to Sri Lanka.

**Host OS:** Ubuntu Server 24.04 LTS. Since everything runs inside Docker
containers, the host OS barely matters functionally — Rocky Linux would
work identically — but Ubuntu has the widest community documentation for
troubleshooting, and matches the package ecosystem you already use.

---

## 3. Step-by-step deployment

### 3.1 Register the domain
Buy the `.com` through Cloudflare Registrar (sold at wholesale cost, ~$9-10/yr,
no markup) or Namecheap (~$10-15/yr). Either is fine.

### 3.2 Provision the VPS
In the Linode Cloud Manager, create a new Linode (Ubuntu 24.04, specs
above). SSH access defaults to root — fine for now, `deploy.sh` runs with
`sudo`. Note its public IP.

### 3.3 Harden the server
SSH in as your sudo user, copy this project to the server, then run:
```bash
chmod +x deploy.sh
./deploy.sh
```
This sets up the firewall (only SSH/HTTP/HTTPS open), fail2ban, automatic
security patches, and installs Docker. Log out and back in afterward so
your user picks up Docker group permissions.

Optional but cheap: Linode's free Cloud Firewall is a second layer in front
of ufw — create one attached to this Linode allowing only TCP 22, 80, 443
inbound. The host-level ufw stays as the primary defense.

### 3.4 Point the domain at Cloudflare
1. Create a free Cloudflare account, add your domain.
2. Update your domain's nameservers at the registrar to the ones Cloudflare gives you.
3. In Cloudflare DNS, add an `A` record: `@` → your VPS IP, proxied (orange cloud ON).
4. Add another `A` record: `www` → your VPS IP, proxied ON.

Proxied mode is what gives you the CDN, DDoS protection, and WAF for free —
this matters for a site that may host controversial content and could draw
hostile traffic.

### 3.5 Get a Cloudflare Origin Certificate
This encrypts traffic between Cloudflare and your VPS without needing
Let's Encrypt/Certbot renewal automation (Origin certs last 15 years).

1. Cloudflare dashboard → SSL/TLS → Origin Server → **Create Certificate**.
2. Accept the defaults, list both `example.com` and `*.example.com`.
3. Save the certificate as `nginx/certs/cloudflare-origin.pem` and the
   private key as `nginx/certs/cloudflare-origin.key` on the server.
4. Cloudflare dashboard → SSL/TLS → Overview → set mode to **Full (strict)**.

### 3.6 Configure the project
```bash
cp .env.example .env
nano .env   # set real DB_PASSWORD and DB_ROOT_PASSWORD
```
Generate strong passwords with `openssl rand -base64 24`.

> **Note:** Do not change `COMPOSE_PROJECT_NAME` in `.env` — the backup
> script depends on the volume name `news-site-deploy_wordpress_data` that
> this value produces.

Edit `nginx/prod/wordpress.conf` and replace `example.com` with your real
domain (two places).

### 3.7 Launch the stack
```bash
rm docker-compose.override.yml   # remove the local-dev override — it must never exist on the server
docker compose up -d
docker compose ps   # confirm all 4 containers are "Up (healthy)"
```

All four containers include health checks. Docker Compose waits for MariaDB
and Redis to be healthy before starting WordPress, and for WordPress to be
healthy before starting Nginx. On a cold start this may take 30-60 seconds.

### 3.8 Run the WordPress install wizard
Visit `https://yourdomain.com`. WordPress's setup screen appears —
site title, admin username/password, and **set the site language to
Sinhala (සිංහල)** here, or later under Settings → General.

### 3.9 Local development (optional)
To run the site locally before deploying: keep `docker-compose.override.yml`
in the project (it serves the stack at `http://localhost:8080` with no SSL),
run `docker compose up -d`, and go through the install wizard as above.

Then install **GeneratePress** once (Appearance → Themes → Add New, search
"GeneratePress") and activate **Gampola Janamadyawediya**. The child theme
cannot activate without its parent theme installed — until then WordPress
falls back to its default theme and the site looks like a plain unstyled
page.

### 3.10 Deploying code changes (CI/CD)

Once the site is live, code changes (theme, mu-plugins, nginx configs,
PHP config, compose files) deploy automatically: `git push` to `main`
triggers a GitHub Actions workflow that lints the change, SSHes into the
server, applies it, health-checks the stack, and rolls back automatically
if anything is unhealthy. Content (posts, uploads, plugin settings) is
never touched by deploys. Full setup and runbook: `CI_CD_PIPELINE.md`.

---

## 4. WordPress configuration

### 4.1 Theme
Install **GeneratePress** (free, in the WordPress theme directory) — it's
one of the lightest-weight themes available, which matters because you're
self-hosting on a modest VPS and every KB saved is bandwidth and load time
saved. It renders Sinhala Unicode correctly out of the box.

If you later want built-in breaking-news tickers, trending-post widgets,
and more magazine-style layouts without custom-building them, a premium
news theme (e.g. Newspaper, ~$59 one-time) is a reasonable upgrade — but
start free and only pay for this once the site is proven out.

### 4.2 Essential plugins
Install these from the WordPress plugin directory:

- **Site Kit by Google** — connects Search Console, Analytics, and AdSense
  (with Auto ads, which places ads automatically without manual ad-zone
  coding). This is your AdSense integration path.
- **Rank Math** or **Yoast SEO** — sitemap generation (needed for
  Google/AdSense to index your content), and per-post custom social-share
  image (needed for the blurred-teaser workflow, see 4.4).
- **Redis Object Cache** — activate it once installed; it auto-connects to
  the `redis` container via the config already set in `docker-compose.yml`.
  Reduces database load under traffic spikes.
- **ShortPixel** or **Smush** — automatic image compression on upload.
  Important for a site with many photos.
- **Limit Login Attempts Reloaded** — blocks brute-force login attempts.
  Lighter on server resources than Wordfence, which is overkill for a
  4 GB box.
- **Cloudflare** — the official Cloudflare WordPress plugin. Automatically
  purges the Cloudflare edge cache when you publish or update a post, so
  readers always see the latest version without you manually clearing the
  cache. Install it, enter your Cloudflare API token (generate one in your
  Cloudflare dashboard → My Profile → API Tokens), and enable Automatic
  Cache Management.

### 4.3 Legal pages (required before applying for AdSense)
Create these as regular Pages: **About**, **Contact**, **Privacy Policy**,
**Terms of Use**. AdSense reviewers check for these. A free WordPress
privacy-policy generator plugin (built into WP core under Settings →
Privacy) covers the baseline.

### 4.4 Taxonomy: categories, tags, and content flags

The site uses a three-layer taxonomy. Each layer serves a different
purpose — don't mix them.

**Layer 1 — Categories (editorial sections, powers the header menu):**
Every post gets one primary category. Set these up under Posts →
Categories:
- **දේශීය පුවත් / Local News** — slug `local-news`
- **දේශපාලනය / Politics** — slug `politics`
- **අධිකරණ/පොලිස් / Crime & Courts** — slug `crime-courts`
- **ක්‍රීඩා / Sports** — slug `sports`
- **වෙළඳ/ව්‍යාපාරික / Business** — slug `business`
- **විදේශ / World** — slug `world`
- **ප්‍රාදේශීය / Regional** — slug `regional`
- **විශේෂාංග / Features** — slug `features`
- **කතුවැකිය / Editorial** — slug `editorial`
- **වීඩියෝ / Video** — slug `video`

Then go to Appearance → Menus, create a menu with these categories
(plus a Home link), and assign it to the "Primary Menu" location.
Rename the built-in "Uncategorized" to "General" under Settings →
Writing.

**Layer 2 — Tags (cross-referencing, powers "related articles"):**
Use tags for specific entities, locations, and recurring topics that
cut across categories. Examples: `gampola`, `kandy`, `police`,
`elections`, `floods`. Aim for 2-5 tags per post. Never create a tag
that will only be used once.

**Layer 3 — Content flags (content warnings, separate taxonomy):**
The `content_flag` taxonomy is registered automatically by the
`content-flag-taxonomy` mu-plugin. It appears in the post editor
sidebar as a "Content Flags" checkbox panel — separate from
categories. Two flags exist:

- **Graphic Content** (slug `graphic-content`) — triggers the
  full-screen warning gate + auto-blurred social-share image.
- **Sensitive Topic** (slug `sensitive-topic`) — triggers a softer
  dismissible notice banner above the article. No blur.

Content flags are **not** shown in the navigation menu. They are
metadata attributes, not editorial sections. A fatal accident post
belongs in the **Crime & Courts** category and has the **Graphic
Content** flag ticked — the journalist doesn't have to choose between
them.

### 4.5 The censored-teaser-to-full-article workflow
For a post that needs the "blurred on Facebook, full version on the
website behind a warning" treatment:

1. Write the article normally, with the real uncensored image/video in
   the post body.
2. **Set a featured image** — this is required for the auto-blur fallback
   (see below).
3. In the **Content Flags** panel, tick **Graphic Content** — the
   warning gate applies automatically.
4. **Social share image (choose one):**
   - **Manual (best quality):** In Rank Math/Yoast's **Social** tab for
     that post, upload a separate *blurred/censored* version of the image.
   - **Automatic fallback:** If you skip the manual upload, the
     `graphic-content-auto-blur` mu-plugin will automatically generate a
     heavily blurred + pixelated copy of the featured image and set it as
     the `og:image` tag. It also overrides Rank Math's/Yoast's default
     output (which would otherwise use the real featured image), so even
     if the operator forgets to create a censored version, Facebook will
     never show the raw graphic image in link previews.
5. Post the FB teaser (headline + link) manually on Facebook as usual.
   The blurred image (manual or auto-generated) is what appears in the
   link preview — the real image stays inside the gated article.

> **Session memory:** Once a reader clicks "I understand" on the content
> gate, the decision is remembered for that browser session (using
> `sessionStorage`). They won't be re-prompted if they reload the page or
> navigate back to it. Closing and reopening the browser resets the gate.
>
> **Gate language:** The gate text follows the site language — Sinhala
> when WordPress is set to සිංහල, English otherwise. Developers can
> override individual strings via the `ncg_gate_texts` filter.

For sensitive-topic posts (no graphic imagery but difficult subject
matter): tick **Sensitive Topic** instead. The reader sees a dismissible
notice banner, not a full gate, and no social-image blurring occurs.

For routine small posts with no sensitive content: publish only to
Facebook as before. Nothing changes, no extra work. The website is for the
subset of posts that actually need it — not a mirror of everything.

### 4.6 Publishing: mobile vs. laptop
- **Routine posts (safe content):** the WordPress mobile app (Android/iOS)
  — write, add a photo, publish. Same effort as posting to Facebook.
- **Graphic-content posts:** use the laptop with the full WP dashboard.
  The extra steps (content flag, custom social image, reviewing the gate)
  are fiddly on a phone screen — doing this occasionally on a laptop is
  far less friction than fighting the mobile interface every time.

---

## 5. Applying for AdSense

1. Publish at least 15-20 original articles first — Google checks for
   real content before approving.
2. Confirm the legal pages (4.3) are live and linked in the site footer/menu.
3. In Site Kit, connect AdSense and follow its application flow.
4. Once approved, enable **Auto ads** — Google places ad units automatically,
   no manual placement needed.
5. Expect a review period of days to a few weeks. Approval isn't guaranteed
   on the first attempt — if rejected, Google states the reason; fix it and
   reapply.

Remember: articles with the Graphic Content flag will likely show fewer
or no ads due to Google's Shocking Content policy. That's expected, not a
bug — don't spend time trying to "fix" it.

---

## 6. Backups

`backup.sh` dumps the database and archives `wp-content` (themes, plugins,
uploads) nightly, keeping 7 days locally. Set it up with cron:
```bash
chmod +x backup.sh
crontab -e
# add this line:
0 2 * * * /home/YOUR_USER/news-site-deploy/backup.sh >> /home/YOUR_USER/backup.log 2>&1
```

> **Important:** The backup script assumes the Docker volume is named
> `news-site-deploy_wordpress_data`. This name is controlled by the
> `COMPOSE_PROJECT_NAME=news-site-deploy` variable in `.env`. Do not
> change it without also updating `backup.sh`.

**Do the off-site sync step too** (commented out at the bottom of
`backup.sh`, using Backblaze B2 — a few cents per GB/month). A backup that
only lives on the same VPS doesn't survive that VPS being lost, hacked, or
accidentally destroyed. This is not optional for a site that becomes
someone's income.

---

## 7. Monitoring

Set up a free account on **UptimeRobot** (or similar) and add an HTTPS
monitor pointed at your domain, checking every 5 minutes. You'll get an
email/SMS the moment the site goes down instead of finding out from an
angry reader.

---

## 8. Security checklist

- [x] Firewall: only 22/80/443 open (`deploy.sh` handles this)
- [x] fail2ban blocking SSH brute-force (`deploy.sh` handles this)
- [x] Cloudflare proxy ON — hides your VPS's real IP, absorbs DDoS/WAF at the edge
- [x] Cloudflare SSL mode: Full (strict)
- [x] `xmlrpc.php` blocked at the Nginx level (a common WordPress attack vector)
- [x] PHP execution blocked inside `/wp-content/uploads/` (blocks malware-upload attacks)
- [x] Automatic security patches (`unattended-upgrades`, set up by `deploy.sh`)
- [x] Security headers: `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`
- [x] Container healthchecks: all 4 services have Docker healthchecks; WordPress waits for DB/cache readiness
- [X] Strong, unique WordPress admin password + a password manager
- [ ] Off-site backups actually configured and tested (restore-test it once)
- [ ] Two-factor authentication on the WordPress admin account (WP core supports this in recent versions, or use a lightweight 2FA plugin)

---

## 9. Project structure

```
.
├── .github/workflows/
│   └── deploy.yml               # CI/CD: push to main → lint → deploy to server
├── .gitignore                   # .env and nginx/certs/ are never committed
├── CI_CD_PIPELINE.md            # CI/CD plan and runbook
├── deploy.sh                    # One-time VPS hardening + Docker install
├── backup.sh                    # Nightly DB dump + wp-content archive
├── scripts/
│   └── deploy-site.sh           # Deploy script GitHub Actions runs on the server
├── docker-compose.yml           # WordPress + MariaDB + Redis + Nginx stack
├── docker-compose.override.yml  # Local-dev overrides (localhost:8080) — never deploy
├── .env.example                 # Template for .env (never commit .env itself)
├── php/
│   └── uploads.ini              # PHP upload limits (200 MB)
├── mu-plugins/                  # WordPress Must-Use plugins (auto-loaded)
│   ├── content-flag-taxonomy.php     # Registers content_flag taxonomy (Graphic Content, Sensitive Topic)
│   ├── graphic-content-gate.php      # Click-through warning gate for graphic-flagged posts
│   ├── graphic-content-auto-blur.php # Auto-generates blurred social share images
│   └── sensitive-topic-notice.php    # Softer dismissible notice for sensitive-topic-flagged posts
├── theme/
│   └── gampola-janamadyawediya/ # Child theme (GeneratePress parent required)
├── ui/
│   └── index.html               # Tailwind UI prototype — local dev only
└── nginx/
    ├── local/
    │   └── local.conf           # Local-dev vhost (used via the override file)
    ├── prod/
    │   └── wordpress.conf       # Production vhost (mounted by docker-compose.yml)
    └── certs/
        ├── cloudflare-origin.pem   # (you create this, see 3.5)
        └── cloudflare-origin.key   # (you create this, see 3.5)
```

---

## 10. Cost summary

| Item | Cost |
|---|---|
| Domain (.com) | ~$10-15/year |
| VPS (starter tier) | ~$24/month (Linode 4 GB Shared) |
| Theme | Free (GeneratePress) |
| Plugins | Free (all listed above have functional free tiers) |
| SSL | Free (Cloudflare Origin cert) |
| CDN/WAF | Free (Cloudflare free plan) |
| Off-site backup storage | ~$1-3/month (Backblaze B2, pay-per-GB) |
| **Total to launch** | **~$25-40 one-time + $26-28/month ongoing** |

---

## 11. Before going live — final check

- [ ] All 4 containers healthy: `docker compose ps` (should show "Up (healthy)")
- [ ] Site loads over `https://` with a valid padlock (no cert warnings)
- [ ] Test the graphic-content gate: publish a test post in that category,
      confirm the blur + click-through works
- [ ] Confirm session memory: reload the page after accepting — the gate
      should not re-appear
- [ ] Test the auto-blur fallback: publish a graphic-content post with a
      featured image but **without** a custom social-share image, then
      paste the URL into Facebook's Sharing Debugger to confirm it shows
      the blurred version
- [ ] Test the blurred social-share image: paste the post URL into
      Facebook's own link preview and confirm it shows the blurred image, not the real one
- [ ] Test posting from the WordPress mobile app on the actual phone that
      will be used day-to-day
- [ ] Confirm backup cron ran successfully at least once (`ls ~/backups`)
- [ ] Confirm UptimeRobot (or equivalent) shows the site as up
