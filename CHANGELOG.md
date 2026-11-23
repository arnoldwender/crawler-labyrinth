# Changelog — Crawler Labyrinth

All notable changes to **Crawler Labyrinth** are documented in this file following [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

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
