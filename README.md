# Veldra — Privacy-first WordPress Analytics

**Cookie-free, GDPR-compliant website analytics for WordPress. Zero personal data collection, EU-hosted cloud endpoint.**

Veldra is a lightweight WordPress analytics plugin that delivers actionable website insights without collecting personal data — no cookies, no IP storage, no cross-site tracking. Built, hosted, and legally anchored in the EU.

> *"Veldra doesn't just avoid GDPR fines — it removes the consent banner that costs you 30–50% of your traffic data on every page load."*

---

## Features

### Core
- **Cookie-free tracking** — Pure vanilla JS tracker (< 2.5 KB gzipped), no cookies, no localStorage, no fingerprinting
- **No IP storage** — IP is read transiently in server memory, daily-salted SHA-256 hashed, never written to disk
- **EU data sovereignty** — Self-hosted on your WordPress server; Premium cloud on Hetzner (Germany) / OVH (France)
- **GDPR legal tooling** — One-click DPA generator, privacy policy snippet, compliance status widget

### Dashboard (WP-Admin)
- Traffic overview (unique sessions, pageviews, bounce rate, avg. session duration)
- Top content, referrers, devices, geographic breakdown
- 7 / 30 / 90-day presets + custom date ranges (including multi-year)
- All charts rendered client-side with Chart.js — no external API calls

### Data Retention
| Layer | GDPR Status | Retention |
|-------|-------------|-----------|
| Raw session data | Pseudonymous — subject to storage limitation | 90 days (Free) / 13 months (Premium), then hard-deleted |
| Aggregate summaries | Genuinely anonymous — outside GDPR scope | **Indefinite** — enables year-on-year comparisons |

### Auto-detected Events
- 404 error pages
- File downloads (.pdf, .zip, .docx, .xlsx, .mp4, configurable)
- Outbound link clicks
- Custom goals via no-code CSS selector builder

---

## Architecture

```
[Visitor Browser]
  │  Vanilla JS < 2.5 KB (no cookies, no identifiers)
  │  Payload: path, referrer, viewport, timestamp
  ▼
[WordPress Server — First-Party Proxy]
  │  POST /wp-json/veldra/v1/track
  │  Server reads IP transiently in RAM; generates daily-salted hash
  │  Raw IP is never written to disk or DB
  ▼
[Veldra EU Cloud Endpoint] (Premium tier only)
  │  Receives anonymised, hashed session token + aggregated event payload
  │  ISO 27001 host: Hetzner (Germany) or OVH (France)
  ▼
[veldra_pageviews / veldra_events / veldra_daily_summary tables]
  Aggregated display only. No row-level PII.
```

### Data Processing Rules
- **Anonymised IP Processing** — IP addresses read in transient server memory solely for geolocation (self-hosted MaxMind GeoLite2). Never written to disk, database, or log files.
- **No Fingerprinting / No Persistent IDs** — User-agent + server-side IP hashed with a daily-rotating salt. Session ID expires at midnight UTC. Cryptographically impossible to link a visitor across days or across sites.
- **First-Party Proxy Routing** — Tracking script served from your own domain (`mysite.com/wp-content/plugins/veldra/...`). Ad-blockers can't block it without breaking your site.
- **Two-Layer Retention** — Raw rows (pseudonymous) pruned on schedule. Aggregate rows (anonymous, no identifiers) retained indefinitely for unlimited trend comparisons.

---

## Build Sequence

This project is structured as discrete, independently testable vertical slices:

| # | Phase | Description | Status |
|---|-------|-------------|--------|
| 1 | **Schema + Migrator** | Database tables created on plugin activation (`veldra_pageviews`, `veldra_events`, `veldra_daily_summary`). Nightly aggregation + retention pruning. | ✅ |
| 2 | **SessionHasher** | Daily salt + SHA-256 hashing logic. Unit-testable with no WP dependency. | ✅ |
| 3 | **GeoResolver** | MaxMind MMDB geolocation lookup. Unit-testable with fixture. | ✅ |
| 4 | **REST Endpoint** | `POST /wp-json/veldra/v1/track` — rate-limited, device parsing, UTM capture. | ✅ |
| 5 | **Frontend Tracker** | Vanilla JS payload sender. **798 bytes gzipped** (target < 2.5 KB). No cookies, no jQuery. | ✅ |
| 6 | **Dashboard Data API** | Internal REST endpoints powering the charts. Queries aggregate table only. | ✅ |
| 7 | **Dashboard UI** | Chart.js charts + admin page rendering. Compliance widget included. | ✅ |
| 8 | **Compliance Tools** | DPA generator (GDPR Art. 28), Privacy Policy snippet (Art. 13). | ✅ |
| 9 | **EU Cloud Endpoint** | Fastify service (standalone, deployable to Hetzner/OVH). JWT auth, Drizzle ORM. | ✅ |
| 10 | **Premium Sync** | Plugin client that routes daily aggregates to the EU cloud endpoint. | ✅ |

---

## Tech Stack

### Plugin Core (WordPress)
| Layer | Technology |
|-------|-----------|
| Language | PHP 8.2 (strict types, OOP, PSR-4) |
| Autoloading | Composer + PSR-4 (`Veldra\` → `src/`) |
| Database | `$wpdb` abstraction + custom tables |
| Testing | PHPUnit 10 + WP_Mock |
| Static analysis | PHPStan level 8 |
| Code style | PHP-CS-Fixer (PSR-12 + WP ruleset) |

### Frontend Tracker
| Layer | Technology |
|-------|-----------|
| Language | Vanilla JavaScript (ES2020) |
| Minification | esbuild |
| Output | **< 2.5 KB gzipped** (verified in CI) |

### WP-Admin Dashboard
| Layer | Technology |
|-------|-----------|
| Charts | Chart.js 4 (loaded in admin only) |
| Admin UI | Native WP Settings API + React-free components |
| Styles | Plain CSS (BEM methodology) |

### EU Cloud Endpoint (Premium Tier)
| Layer | Technology |
|-------|-----------|
| Runtime | Node.js 22 LTS + Fastify 5 |
| Database | PostgreSQL 16 on Hetzner (Frankfurt) |
| ORM | Drizzle ORM |
| Geolocation | MaxMind GeoLite2 MMDB (self-hosted) |
| Auth | Short-lived JWT (15-min expiry, RS256) |
| Hosting | Hetzner Cloud (Frankfurt, CPX21) + OVH France (failover) |

### CI/CD
- Git + GitHub
- GitHub Actions (linting, tests, build size check)
- wp-env (local WordPress for integration testing)
- Playwright (E2E tests for WP-Admin dashboard)
- Dependabot (automated dependency updates)

---

## Code Conventions

### PHP
- `strict_types=1`, PSR-12, `Veldra\` namespace
- All DB via `$wpdb` abstraction with `$wpdb->prepare()` — never raw interpolation
- Sanitize all input: `sanitize_text_field()`, `absint()`, `esc_url_raw()`
- Use `wp_remote_post()` for outbound HTTP, not `curl` directly

### JavaScript
- ES2020, no frameworks, no jQuery
- No cookies, no `localStorage`, no `sessionStorage`
- No fingerprinting inputs collected client-side

### Data Retention (MANDATORY — do not add admin settings that disable these)
- Raw rows (`veldra_pageviews`, `veldra_events`): 90 days (Free) / 13 months (Premium), then hard-deleted
- Aggregate rows (`veldra_daily_summary`): retained indefinitely — no `session_hash` field
- Nightly WP-Cron at 00:15 UTC: aggregate FIRST, then delete raw rows. Never disableable.
- Never query `veldra_daily_summary` with any join to `veldra_pageviews` — layers must remain architecturally separate

### Domain Rules
- No data may leave the EU. Cloud endpoint: Hetzner Frankfurt (primary), OVH France (failover).
- The daily salt rotates at 00:00 UTC via WP-Cron. Never make it static.
- All geolocation uses the self-hosted MaxMind MMDB — never an external API.
- Never store IP addresses anywhere — hash them immediately in `SessionHasher`.

---

## Development

### Prerequisites
- PHP 8.2+
- Composer
- Node.js 22+
- WordPress 6.4+ (for local dev with wp-env)

### Setup
```bash
# Install PHP dependencies
composer install

# Install JS dev tooling
npm install

# Build frontend assets
npm run build

# Run tests
composer test

# Run static analysis
composer analyse

# Check tracker bundle size
npm run build
# Verify: ls -lh build/tracker.min.js.gz
```

### Project Structure
```
veldra/
├── aura-analytics.php      ← Plugin entry point (renamed during build)
├── composer.json
├── package.json            ← esbuild + dev tooling
├── src/                    ← PHP source (PSR-4, Veldra\)
│   ├── Plugin.php          ← Bootstrap and hook registration
│   ├── Tracker/
│   │   ├── RestEndpoint.php     ← POST /wp-json/veldra/v1/track
│   │   ├── SessionHasher.php    ← Daily salt + SHA-256 hashing
│   │   └── GeoResolver.php      ← MaxMind MMDB lookup
│   ├── Database/
│   │   ├── Migrator.php         ← Schema creation on activation
│   │   ├── PageviewRepository.php
│   │   └── EventRepository.php
│   ├── Dashboard/
│   │   ├── AdminPage.php        ← WP-Admin menu + rendering
│   │   └── DataApiController.php ← Internal REST endpoints for charts
│   ├── Compliance/
│   │   ├── DpaGenerator.php     ← PDF DPA generation
│   │   └── PrivacySnippet.php   ← Art. 13 policy text generator
│   └── Cloud/
│       └── PremiumSync.php      ← EU endpoint client (Premium only)
├── assets/
│   ├── tracker/tracker.js       ← Source for the < 2.5 KB frontend script
│   └── admin/
│       ├── dashboard.js         ← Chart.js dashboard logic
│       └── dashboard.css
├── build/                      ← esbuild output (gitignored)
├── tests/
│   ├── Unit/                   ← PHPUnit unit tests
│   └── E2E/                    ← Playwright tests
├── cloud/                      ← EU endpoint service (separate deployable)
│   ├── src/
│   │   ├── server.ts           ← Fastify app
│   │   ├── routes/track.ts
│   │   └── db/schema.ts        ← Drizzle schema
│   └── package.json
└── .github/workflows/
    ├── ci.yml                  ← Lint, test, build size check
    └── deploy-cloud.yml        ← Deploy to Hetzner on tag
```

---

## Testing
```bash
# Run PHPUnit tests
composer test

# Run Playwright E2E tests (requires wp-env running)
npm run test:e2e

# Build tracker — check output is < 2.5 KB gzipped
npm run build
```

---

## License

GPL v2 or later

---

## Monetisation

| Feature | Free (Self-Hosted) | Premium (EU Managed Cloud) |
|---------|-------------------|---------------------------|
| Pageview storage | Local WP database | Veldra EU server array |
| Raw data retention | 90 days → hard-deleted | 13 months → hard-deleted |
| Historical trend data | **Unlimited** — retained indefinitely | **Unlimited** — retained indefinitely |
| Custom goal events | 3 goals | Unlimited |
| Pricing | Free forever | From €9/month (≤ 50k views) |
