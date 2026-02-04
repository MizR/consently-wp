# Consently WordPress Plugin

WordPress plugin that connects sites to [Consently.net](https://consently.net) for cookie consent management, GDPR/CCPA compliance, and privacy banner display.

## Architecture

Singleton-based WordPress plugin with two main systems:

1. **Banner System** — Injects `consently.js` CDN script with `data-bannerid` attribute
2. **Plugin Audit** — Two-phase scanning to detect cookies, tracking scripts, and third-party services

### Two-Phase Audit Architecture

- **Phase 1 (Static Analysis)**: Server-side PHP, runs instantly. Scans active plugins against `data/known-plugins.json`, inspects enqueued scripts against tracking domains, scans options table for tracking IDs, and checks theme files.
- **Phase 2 (Live Scan)**: Browser-based. Loads pages in hidden iframes via `admin-scan.js` orchestrator. Cookie collector (`scan-cookies.js`) captures cookies/localStorage/sessionStorage per page, posts to REST API. Server-side HTML parser detects social media, third-party services, analytics, and tracking IDs.

Both phases triggered by single "Run Audit" button. Results shown only after both phases complete.

## File Structure

```
consently.php                    # Entry point, constants, activation/deactivation hooks
uninstall.php                    # Cleanup on uninstall

includes/
  class-core.php                 # Singleton core, connection state, cache detection
  class-api.php                  # Consently API client, encrypted key storage
  class-admin.php                # Admin pages, AJAX handlers, asset enqueuing
  class-audit.php                # Phase 1 static analysis (4 detection methods)
  class-live-scan.php            # Phase 2 REST endpoints for cookie/HTML data
  class-page-crawler.php         # Builds page list for live scan (max 20 pages)
  class-html-parser.php          # Parses HTML for embeds, social, analytics
  class-script.php               # CDN script injection, scan mode detection
  class-wp-consent.php           # WP Consent API bridge

admin/
  views/connection.php           # Connection tab (API key, status, diagnostics)
  views/audit.php                # Audit tab (button, progress bar, results container)
  views/settings.php             # Settings tab (banner toggle, cache compat)
  assets/admin.js                # All admin JS (AJAX, audit rendering, progress)
  assets/admin.css               # Admin styles

assets/js/
  scan-cookies.js                # Iframe cookie collector (3s delay after load)
  admin-scan.js                  # Live scan orchestrator (15s timeout per page)
  ads.js                         # Ad blocker detection

data/
  known-plugins.json             # 150+ plugins with cookies, domains, heuristics
```

## Key Constants

```php
CONSENTLY_VERSION          = '0.0.4'
CONSENTLY_API_URL          = 'https://api.consently.net/v1'
CONSENTLY_APP_URL          = 'https://app.consently.net'
CONSENTLY_CDN_SCRIPT       = 'https://app.consently.net/consently.js'
CONSENTLY_TEST_MODE        = true   // Bypass API validation in dev
CONSENTLY_TEST_BANNER_ID   = '6981c589faa5693ee3072986'
```

## WordPress Data

**Options** (all `autoload=false`): `consently_site_id`, `consently_plan`, `consently_canonical_domain`, `consently_api_key_encrypted`, `consently_encryption_method`, `consently_banner_enabled`, `consently_show_to_admins`

**Transients**: `consently_audit_results` (1h), `consently_audit_phase1` (7d), `consently_audit_phase2` (7d), `consently_live_scan_results` (1h), `consently_enqueued_scripts` (1h), `consently_plugin_hash` (1h), `consently_rate_limit` (10m)

## REST API Endpoints

- `POST /consently/v1/store-scan-cookies` — Receives cookie data from iframe collector
- `POST /consently/v1/parse-pages-html` — Triggers HTML parsing across scanned pages
- `GET /consently/v1/scan-status` — Returns scan progress

## Development Notes

- Requires PHP 7.4+, WordPress 5.8+
- No custom database tables — uses options and transients only
- API key encrypted via libsodium → OpenSSL → XOR fallback chain
- Rate limiting: 5 connection attempts per 10 minutes
- Consent plugins (CookieYes, Complianz, iubenda, etc.) are excluded from audit scanning
- PHP source scanning (`scan_php_sources()`) exists in class-audit.php but is not surfaced in UI — too many false positives from admin-only cookies
- Version must be bumped in both plugin header and `CONSENTLY_VERSION` constant on every commit
- known-plugins.json includes: `plugins`, `tracking_domains`, `option_keys`, `wordpress_core_cookies`, `cookie_heuristics`
