# Graph Report - gampola-journalist  (2026-09-10)

## Corpus Check
- Corpus is ~38,629 words - fits in a single context window. You may not need a graph.

## Summary
- 176 nodes · 238 edges · 20 communities (14 shown, 1 thin omitted)
- Extraction: 81% EXTRACTED · 18% INFERRED · 0% AMBIGUOUS · INFERRED: 44 edges (avg confidence: 0.85)
- Token cost: 161,940 input · 0 output

## Community Hubs (Navigation)
- Breaking News Manager
- Theme Bootstrap & SEO
- CI/CD & Infra Config
- Content Warnings & Ads
- Docker Stack & Conventions
- Cloudflare Cache Purge
- Caching & Tests
- OG Image Auto-Blur
- Brand Logo
- Deployment & Security
- Site Banner Branding
- Front Page Hero
- Favicon Roundel
- Backup & Sync
- UI CSS Build

## God Nodes (most connected - your core abstractions)
1. `News site - deployment guide (README)` - 11 edges
2. `wordpress Service (news_wordpress)` - 11 edges
3. `ncg_cf_purge_post_urls()` - 9 edges
4. `Deploy to production Workflow` - 6 edges
5. `content_flag Taxonomy (Graphic Content, Sensitive Topic)` - 6 edges
6. `Docker Compose Stack (Nginx, WordPress, MariaDB, Redis)` - 6 edges
7. `nginx Service (news_nginx)` - 6 edges
8. `Gampola Janamadyawediya Logo (circular badge)` - 6 edges
9. `gjn_render_header_ui()` - 5 edges
10. `gjn_top_bar()` - 5 edges

## Surprising Connections (you probably didn't know these)
- `Code Map (theme = application source, .github = project configuration)` --semantically_similar_to--> `README Section 9 - Project Structure Map`  [INFERRED] [semantically similar]
  AGENTS.md → README.md
- `Decision: Self-Hosted WordPress on a Docker VPS` --references--> `Docker Compose Stack (Nginx, WordPress, MariaDB, Redis)`  [INFERRED]
  README.md → docker-compose.yml
- `Dependabot docker Ecosystem (weekly)` --conceptually_related_to--> `Docker Compose Stack (Nginx, WordPress, MariaDB, Redis)`  [INFERRED]
  .github/dependabot.yml → docker-compose.yml
- `Cloudflare Origin Certificate + Full (strict) SSL` --conceptually_related_to--> `nginx Service (news_nginx)`  [INFERRED]
  README.md → docker-compose.yml
- `gjn_render_breaking_news_meta_box()` --calls--> `wp_date()`  [INFERRED]
  mu-plugins/breaking-news-manager.php → tests/run.php

## Import Cycles
- None detected.

## Hyperedges (group relationships)
- **Shared CI Lint and Validation Flow (PHP lint + tests/run.php + compose config)** — github_workflows_deploy_lint, github_workflows_pr_lint, tests_run, docker_compose [INFERRED 0.95]
- **Healthcheck-Gated Container Startup Chain** — docker_compose_nginx, docker_compose_wordpress, docker_compose_mariadb, docker_compose_redis, docker_compose_healthcheck_gating [EXTRACTED 1.00]
- **Graphic/Sensitive Content Warning System (flag -> gate -> blurred share image)** — readme_content_flag_taxonomy, readme_content_warning_gate, readme_graphic_content_auto_blur, readme_censored_teaser_workflow, mu_plugins_content_flag_taxonomy, mu_plugins_graphic_content_gate, mu_plugins_graphic_content_auto_blur, mu_plugins_sensitive_topic_notice [EXTRACTED 1.00]
- **Banner Brand Identity System (logo device, wordmark, tagline, domain)** — theme_gampola_janamadyawediya_assets_img_banner_eye_pen_logo, theme_gampola_janamadyawediya_assets_img_banner_bilingual_wordmark, theme_gampola_janamadyawediya_assets_img_banner_truth_and_trust_tagline, theme_gampola_janamadyawediya_assets_img_banner_domain_branding [INFERRED 0.85]
- **Gampola Janamadyawediya Brand Identity Elements** — theme_gampola_janamadyawediya_assets_img_favicon_eye_and_pen_mark, theme_gampola_janamadyawediya_assets_img_favicon_camera_aperture, theme_gampola_janamadyawediya_assets_img_favicon_truth_and_trust, theme_gampola_janamadyawediya_assets_img_favicon_news_beats_ring, theme_gampola_janamadyawediya_assets_img_favicon_red_blue_split [INFERRED 0.85]
- **Gampola Janamadyawediya Brand Lockup** — theme_gampola_janamadyawediya_assets_img_logo_gampola_janamadyawediya, theme_gampola_janamadyawediya_assets_img_logo_gampolajanamadyawediyacom, theme_gampola_janamadyawediya_assets_img_logo_truth_and_trust, theme_gampola_janamadyawediya_assets_img_logo_eye_aperture_pen_mark, theme_gampola_janamadyawediya_assets_img_logo_editorial_sections, theme_gampola_janamadyawediya_assets_img_logo_crimson_navy_palette [EXTRACTED 1.00]

## Communities (20 total, 1 thin omitted)

### Community 0 - "Breaking News Manager"
Cohesion: 0.13
Nodes (16): gjn_get_breaking_posts(), gjn_render_breaking_news_meta_box(), current_time(), wp_date(), gjn_custom_latest_news_sidebar(), gjn_logo_url(), gjn_navigation(), gjn_news_ticker() (+8 more)

### Community 1 - "Theme Bootstrap & SEO"
Cohesion: 0.10
Nodes (4): gjn_theme_setup(), Gampola Janamadyawediya Theme Screenshot, Placeholder Theme Preview Screenshot, Gampola Janamadyawediya Theme (Latin-script theme name shown in preview)

### Community 2 - "CI/CD & Infra Config"
Cohesion: 0.15
Nodes (19): Code Map (theme = application source, .github = project configuration), Docker Compose Stack (Nginx, WordPress, MariaDB, Redis), Local Development Compose Override, !override YAML Tag Replacing the Production Port List, Dependabot Configuration, Dependabot docker Ecosystem (weekly), Dependabot github-actions Ecosystem (weekly), Deploy to production Workflow (+11 more)

### Community 3 - "Content Warnings & Ads"
Cohesion: 0.12
Nodes (9): Google AdSense Authorized Seller Record (pub-3501283147480868), Google AdSense Monetization via Site Kit, Censored-Teasers-to-Full-Article Workflow (blurred on Facebook, full on site), content_flag Taxonomy (Graphic Content, Sensitive Topic), Graphic Content Warning Gate (click-through, session memory), Auto-Blur Social Share Image Fallback, Legal Pages Required Before AdSense Application, Sinhala (සිංහල) Language Site (+1 more)

### Community 4 - "Docker Stack & Conventions"
Cohesion: 0.25
Nodes (12): Convention: require with get_stylesheet_directory(), Healthcheck-Gated Startup Ordering, mariadb Service (news_mariadb), news_net Bridge Network, nginx Service (news_nginx), Local Dev Stack at localhost:8080 Without SSL, redis Service (news_redis), WP_REDIS_HOST Wiring in WORDPRESS_CONFIG_EXTRA (+4 more)

### Community 5 - "Cloudflare Cache Purge"
Cohesion: 0.29
Nodes (11): CF_ZONE_ID / CF_API_TOKEN Environment Wiring, ncg_cf_purge_on_comment(), ncg_cf_purge_on_comment_edit(), ncg_cf_purge_on_comment_status(), ncg_cf_purge_on_delete(), ncg_cf_purge_on_edit(), ncg_cf_purge_on_status_change(), ncg_cf_purge_on_terms() (+3 more)

### Community 6 - "Caching & Tests"
Cohesion: 0.22
Nodes (4): ncg_cache_send_headers(), ncg_has_comment_author_cookie(), is_admin(), gjn_clear_right_sidebar_widgets()

### Community 7 - "OG Image Auto-Blur"
Cohesion: 0.36
Nodes (9): ncg_autoblur_og_tag(), ncg_autoblur_on_save(), ncg_autoblur_on_terms_set(), ncg_delete_blurred_file(), ncg_filter_plugin_og_image(), ncg_filter_rankmath_image_array(), ncg_generate_blurred_image(), ncg_memory_limit_bytes() (+1 more)

### Community 8 - "Brand Logo"
Cohesion: 0.39
Nodes (8): Gampola Janamadyawediya Logo (circular badge), Crimson and Navy Split-Circle Palette, Editorial Section Ring (Local News, International News, Art and Culture, Sports, Cinema, Gossip, Education, Entertainment, Politics, Technology), Eye, Camera-Aperture Iris and Fountain Pen Mark, Gampola Janamadyawediya (publication name), gampolajanamadyawediya.com (web address), Ring Section Read as 'NOTION' (uncertain - possibly 'Motion' or a transposed label), Truth and Trust (tagline)

### Community 9 - "Deployment & Security"
Cohesion: 0.33
Nodes (6): deploy.sh script, Cloudflare Proxied DNS (CDN, DDoS, WAF), Cloudflare Origin Certificate + Full (strict) SSL, Decision: .com Domain (with .lk later), UptimeRobot HTTPS Monitoring, Security Hardening Checklist

### Community 10 - "Site Banner Branding"
Cohesion: 0.52
Nodes (7): Gampola Janamadyawediya Site Banner, Bilingual Wordmark (GAMPOLA JANAMADYAWEDIYA / ගම්පොළ ජනමාධ්‍යවේදියා), Gampola Janamadyawediya Brand Identity, gampolajanamadyawediya.com Domain Branding, Eye and Fountain Pen Nib Logo Device, News Category Taxonomy (Local, International, Art and Culture, Sports, Cinema, Technology, Politics, Entertainment), Truth and Trust Tagline

### Community 11 - "Front Page Hero"
Cohesion: 0.33
Nodes (3): gjn_render_hero_fallback(), gjn_time_chip(), gjn_watermark()

### Community 12 - "Favicon Roundel"
Cohesion: 0.47
Nodes (6): Gampola Janamadyawediya Site Favicon / Roundel Logo, Camera Aperture Lens Pupil (Photojournalism Motif), Eye and Pen Emblem (Watchdog Journalism Motif), Coverage Beats Ring (Local News, Politics, Sports, Cinema, Gossip, Education, Technology, Art and Culture, International News, Entertainment, Breaking), Red and Blue Split-Disc Color Scheme, "NEWS - Truth and Trust" Tagline

### Community 13 - "Backup & Sync"
Cohesion: 0.50
Nodes (4): backup.sh script, wordpress_data Named Volume, Nightly Backup + Off-Site Sync (Backblaze B2), COMPOSE_PROJECT_NAME / Volume-Name Coupling with backup.sh

## Ambiguous Edges - Review These
- `Editorial Section Ring (Local News, International News, Art and Culture, Sports, Cinema, Gossip, Education, Entertainment, Politics, Technology)` → `Ring Section Read as 'NOTION' (uncertain - possibly 'Motion' or a transposed label)`  [AMBIGUOUS]
  theme/gampola-janamadyawediya/assets/img/logo.jpeg · relation: references

## Knowledge Gaps
- **12 isolated node(s):** `backup.sh script`, `build-ui-css.sh script`, `deploy.sh script`, `TruffleHog Secret Scan Job`, `Three-Layer Taxonomy (categories, tags, content flags)` (+7 more)
  These have ≤1 connection - possible missing edges or undocumented components. (Counts symbols only; 57 node(s) total have ≤1 connection when file, concept and rationale nodes are included.)
- **1 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **What is the exact relationship between `Editorial Section Ring (Local News, International News, Art and Culture, Sports, Cinema, Gossip, Education, Entertainment, Politics, Technology)` and `Ring Section Read as 'NOTION' (uncertain - possibly 'Motion' or a transposed label)`?**
  _Edge tagged AMBIGUOUS (relation: references) - confidence is low._
- **Why does `News site - deployment guide (README)` connect `CI/CD & Infra Config` to `Deployment & Security`, `Content Warnings & Ads`, `Backup & Sync`, `OG Image Auto-Blur`?**
  _High betweenness centrality (0.289) - this node is a cross-community bridge._
- **Why does `Docker Compose Stack (Nginx, WordPress, MariaDB, Redis)` connect `CI/CD & Infra Config` to `Docker Stack & Conventions`?**
  _High betweenness centrality (0.256) - this node is a cross-community bridge._
- **Why does `Deploy Lint Job` connect `CI/CD & Infra Config` to `Caching & Tests`?**
  _High betweenness centrality (0.213) - this node is a cross-community bridge._
- **Are the 2 inferred relationships involving `Deploy to production Workflow` (e.g. with `Code Map (theme = application source, .github = project configuration)` and `Dependabot github-actions Ecosystem (weekly)`) actually correct?**
  _`Deploy to production Workflow` has 2 INFERRED edges - model-reasoned connections that need verification._
- **What connects `backup.sh script`, `build-ui-css.sh script`, `deploy.sh script` to the rest of the system?**
  _12 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `Breaking News Manager` be split into smaller, more focused modules?**
  _Cohesion score 0.13043478260869565 - nodes in this community are weakly interconnected._