# Consently WordPress Plugin

WordPress scanner plugin that detects cookies, tracking scripts, and third-party services. Results can be exported as JSON matching the remote scanner schema for unified database storage.

## Architecture

Singleton-based WordPress plugin with a two-phase scanning system and JSON export.

### Two-Phase Audit Architecture

- **Phase 1 (Static Analysis)**: Server-side PHP, runs instantly. Scans active plugins against `data/known-plugins.json`, inspects enqueued scripts against tracking domains, scans options table for tracking IDs, and checks theme files.
- **Phase 2 (Live Scan)**: Browser-based. Loads pages in hidden iframes via `admin-scan.js` orchestrator. Cookie collector (`scan-cookies.js`) captures cookies/localStorage/sessionStorage per page, posts to REST API. Server-side HTML parser detects social media, third-party services, analytics, and tracking IDs.

Both phases triggered by single "Run Audit" button. Results shown after both phases complete. Export JSON button appears after scan completes.

### JSON Export

`Consently_JSON_Export` transforms Phase 1 + Phase 2 scan data into JSON matching the remote scanner schema. Includes WP-specific extension fields (`wpDetectionMethod`, `wpPluginSource`, `wpPluginSlug`, `wpMatchType`, `wpPagesFound`) and a top-level `wpMeta` block with WordPress environment details.

## File Structure

```
consently.php                    # Entry point, constants, activation/deactivation hooks
uninstall.php                    # Cleanup on uninstall

includes/
  class-core.php                 # Singleton core, scan mode injection, cache detection
  class-admin.php                # Admin page, AJAX handlers, asset enqueuing
  class-audit.php                # Phase 1 static analysis (4 detection methods)
  class-live-scan.php            # Phase 2 REST endpoints for cookie/HTML data
  class-page-crawler.php         # Builds page list for live scan (max 30 pages, template/shortcode/archive-aware)
  class-html-parser.php          # Parses HTML for embeds, social, analytics
  class-json-export.php          # Transforms scan data to remote scanner schema JSON

admin/
  views/audit.php                # Scanner page (button, progress bar, results, export)
  assets/admin.js                # Admin JS (AJAX, audit rendering, progress, export)
  assets/admin.css               # Admin styles

assets/js/
  scan-cookies.js                # Iframe cookie collector (adaptive 2-8s polling delay)
  admin-scan.js                  # Live scan orchestrator (3 parallel iframes, 20s timeout, retry logic)

data/
  known-plugins.json             # 150+ plugins with cookies, domains, heuristics
```

## Key Constants

```php
CONSENTLY_VERSION          = '0.1.0'
CONSENTLY_PLUGIN_DIR       = plugin directory path
CONSENTLY_PLUGIN_URL       = plugin URL
CONSENTLY_PLUGIN_BASENAME  = plugin basename for action links
```

## WordPress Data

**Transients**: `consently_audit_phase1` (7d), `consently_audit_phase2` (7d), `consently_live_scan_results` (1h), `consently_enqueued_scripts` (1h), `consently_plugin_hash` (1h), `consently_scan_started_at` (1d)

## REST API Endpoints

- `POST /consently/v1/store-scan-cookies` — Receives cookie data from iframe collector
- `POST /consently/v1/parse-pages-html` — Triggers HTML parsing across scanned pages
- `GET /consently/v1/scan-status` — Returns scan progress

## Development Notes

- Requires PHP 7.4+, WordPress 5.8+
- No custom database tables — uses transients only
- Single admin page under Settings > Consently Scanner (no tabs)
- Consent plugins (CookieYes, Complianz, iubenda, etc.) are excluded from audit scanning
- PHP source scanning (`scan_php_sources()`) exists in class-audit.php but is not surfaced in UI — too many false positives from admin-only cookies
- Version must be bumped in both plugin header and `CONSENTLY_VERSION` constant on every commit
- known-plugins.json includes: `plugins`, `tracking_domains`, `option_keys`, `wordpress_core_cookies`, `cookie_heuristics`
- Export filename format: `consently-scan-{domain}-{date}.json`
