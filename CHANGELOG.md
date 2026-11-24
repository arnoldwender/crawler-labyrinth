# Changelog — Crawler Labyrinth

All notable changes to **Crawler Labyrinth** are documented in this file following [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.1.1] - 2026-11-24

### Fixed
- **Zero-Overhead Hot-Path Short-Circuit (`TrapRouter`):** Checks `crawler_labyrinth_active_quarantine_max` (`> $now`) and defers `TokenSigner::ensure_secret()`, IP/UA HMAC digests, and `get_transient()` lookups when zero active bans exist (or in `observe` mode).
- **Dry-Run `observe` Contract Parity:** Skips HTTP `429 burst-shed` responses in `mode === 'observe'`.
- **Extended Trap Probe Matching (`TrapRouter`):** Captures multi-segment paths, `.html` suffixes, newline probe tails (`#is`), and `>96`-char tokens under `/<route_prefix>/...` while stripping control bytes and classifying unsigned probes as `robots_probe`.
- **RFC 9309 `robots.txt` Group Continuity (`RobotsInjector`):** Preserves active `User-agent:` group state across blank lines and mid-group `Sitemap:` directives so existing `Disallow: /<prefix>/` rules are never duplicated, while still flushing empty `User-agent:` blocks cleanly.
- **Route Prefix Sanitization Parity (`Settings`):** Rejects dots and internal slashes before stripping in `Settings::sanitize_route_prefix()` (e.g. `robots.txt`, `api/v1`).

### Added
- **Admin Telemetry UI & Test Coverage (`AdminDashboard`, tests):** Surfaces `Observed` KPI counters in the dashboard widget and Telemetry overview, displays parsed `entry_vector` values in the activity table, regenerates `languages/crawler-labyrinth.pot`, and expands regression tests (`63 PASSED`) and mutation suites (`51/51 RED`).

## [1.1.0] - 2026-11-23

### Added
- **Dry-Run `observe` Mode:** Serves deterministic decoys and records `'observed'` rows in `{prefix}crawler_labyrinth_hits` without emitting HTTP `429`/`403` quarantines, allowing administrators to audit crawler behavior before enabling active blocks.
- **Global Burst Circuit Breaker (`crawler_labyrinth_burst_limit`):** Counts global trap requests per 60-second UTC window in a single transient (`crawler_labyrinth_burst_win`); once exceeded (default `120`/min, `0` disables), sheds flood requests immediately with HTTP `429` (`Retry-After: 60`, `X-Crawler-Labyrinth: burst-shed`) without touching MySQL or creating per-IP transients.
- **Entry Vector Telemetry (`meta_json`):** Classifies each trapped request as `signed_bait`, `robots_probe`, or `ua_guard` alongside strike count inside `meta_json`.
- **`X-Crawler-Labyrinth` Response Header:** Emits `decoy`, `quarantined`, `observed`, or `burst-shed` on handled responses so Nginx, Caddy, or reverse-proxy logs can react without storing plaintext IPs in WordPress.

### Changed
- **Pre-Query Request Interception:** Moved `TrapRouter::boot()` from `template_redirect` to `init` (priority `1`) with an explicit `is_admin()` guard so trapped requests short-circuit before WordPress executes `parse_request` and the main `WP_Query`.
- Updated `HitLogger::get_stats()`, `AdminDashboard`, and `wp crawler-labyrinth status` to surface `observed` hits and `burst_limit`.

## [1.0.3] - 2026-11-23

### Changed
- `README.md` notes that the plugin was built with an agentic workflow; `SUPPORT.md` section 4 adds that logs, previews, audit chains and `robots.txt` output are not legal, security or compliance advice. No code changes.

## [1.0.2] - 2026-11-19

### Fixed
- **Subdirectory Routing, IP Normalization & Cache-Race Hardening:** Added `Settings::base_path()` normalization and segment-bounded route exclusions, switched to `wp_parse_url()`, restricted client IP resolution to `REMOTE_ADDR` with `%zone` stripping, `inet_pton`/`inet_ntop` IPv6 canonicalization, and `::ffff:` IPv4 extraction, bounded route depth (`1..16`), suppressed `HEAD` bodies and `Retry-After` on `403` responses, and cleared `'options'`/`'notoptions'` object-cache entries during concurrent `TokenSigner::ensure_secret()` initialization.
- **Locked Audit Chain & Multi-Batch Retention:** Serialized `HitLogger::record()` appends with MySQL `GET_LOCK`/`RELEASE_LOCK`, upgraded `verify_chain()` to keyset pagination validating `prev_hash` linkage and unauthorized `[REDACTED]` mutations, added `mb_substr(..., 0, 191, 'UTF-8')` truncation, and made `purge_expired()` iterate batches while deleting expired quarantine transients.
- **RFC 9309 `robots.txt` & Generator Parity:** Preserved path case sensitivity in `RobotsInjector` (`normalize_rule_compare()`), placed `Disallow` directives before trailing `#` comments above blank lines, and aligned empty/uppercase seed canonicalization with `archroot0001`.
- **Admin UI, A11y & Input Guards:** Enforced `.cl-admin .cl-input` selector specificity (`#64748b` border, `44px` height), `min-width: 0` / `overflow-wrap: anywhere` on `320px` viewports, `forced-colors: active` active tab border, `aria-hidden="true"` on `<hr class="wp-header-end">`, early `?tab=` fallback clamping before telemetry queries, and strict `\z` depth validation in `RestController`.

### Changed
- Expanded `tests/test-suite.php` to 54 standalone tests and `tests/mutations.php` to 39 mutation probes (`39/39 RED`), updated `.gitignore`, refreshed admin UI screenshots (`docs/images/wp-admin-{telemetry,settings,preview}.png`), and regenerated `languages/crawler-labyrinth.pot`.

## [1.0.1] - 2026-11-19

### Changed
- Added explicit zero-support, GitHub-Issues-only bug reporting, no-private-support, and no-warranty / zero-liability ("AS IS") terms in `SUPPORT.md`, `README.md`, `readme.txt`, `SECURITY.md`, `CONTRIBUTING.md`, and GitHub issue templates.

## [1.0.0] - 2026-11-19

### Added
- **Local Deterministic Decoy Engine:** `DecoyGenerator` synthesizes bounded-depth archival HTML5 and plain-text folios using HMAC-SHA256 with `noindex, nofollow, noarchive, nosnippet, noai, noimageai` directives and zero outbound network requests.
- **RFC 9309 Multi-Group `robots.txt` Injector:** `RobotsInjector` injects `Disallow: /<prefix>/` into every `User-agent:` group (preventing explicit `Allow: /` rules from bypassing wildcard directives).
- **Accessible-Inert Footer Bait Renderer:** `BaitRenderer` renders hidden trap anchors on unauthenticated public frontend responses with `hidden`, `aria-hidden="true"`, `tabindex="-1"`, `rel="nofollow noopener"`, and `wp_kses()` sanitization.
- **Hybrid Trap Router & Quarantine:** `TrapRouter` supports `hybrid`, `labyrinth`, and `block` modes, HTTP `429` (`Retry-After`) or `403` quarantine, and optional opt-in training-UA interception that explicitly spares search, citation, and link-preview bots.
- **Tamper-Evident Audit Log & Pseudonymizing Retention:** `HitLogger` stores hits in `{prefix}crawler_labyrinth_hits` with daily rotating keyed IP digests, constant-time `hash_equals()` chain verification, and daily retention cron (`crawler_labyrinth_daily_purge`).
- **Standalone Admin UI & Dashboard Widget:** Tokenized stylesheet (`assets/css/crawler-labyrinth-admin.css`) with zero external dependencies, four admin tabs, and telemetry dashboard widget.
- **REST API, WP-CLI, Filters & Opt-In Uninstaller:** Authenticated `crawler-labyrinth/v1` endpoints, `wp crawler-labyrinth` CLI commands, developer hooks (`crawler_labyrinth_*`), and opt-in `uninstall.php`.
