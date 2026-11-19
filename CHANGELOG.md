# Changelog — Crawler Labyrinth

All notable changes to **Crawler Labyrinth** are documented in this file following [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.0.0] - 2026-11-19

### Added
- **Local Deterministic Decoy Engine:** `DecoyGenerator` synthesizes bounded-depth archival HTML5 and plain-text folios using HMAC-SHA256 with `noindex, nofollow, noarchive, nosnippet, noai, noimageai` directives and zero outbound network requests.
- **RFC 9309 Multi-Group `robots.txt` Injector:** `RobotsInjector` injects `Disallow: /<prefix>/` into every `User-agent:` group (preventing explicit `Allow: /` rules from bypassing wildcard directives).
- **Accessible-Inert Footer Bait Renderer:** `BaitRenderer` renders hidden trap anchors on unauthenticated public frontend responses with `hidden`, `aria-hidden="true"`, `tabindex="-1"`, `rel="nofollow noopener"`, and `wp_kses()` sanitization.
- **Hybrid Trap Router & Quarantine:** `TrapRouter` supports `hybrid`, `labyrinth`, and `block` modes, HTTP `429` (`Retry-After`) or `403` quarantine, and optional opt-in training-UA interception that explicitly spares search, citation, and link-preview bots.
- **Tamper-Evident Audit Log & Pseudonymizing Retention:** `HitLogger` stores hits in `{prefix}crawler_labyrinth_hits` with daily rotating keyed IP digests, constant-time `hash_equals()` chain verification, and daily retention cron (`crawler_labyrinth_daily_purge`).
- **Standalone Admin UI & Dashboard Widget:** Tokenized stylesheet (`assets/css/crawler-labyrinth-admin.css`) with zero external dependencies, four admin tabs, and telemetry dashboard widget.
- **REST API, WP-CLI, Filters & Opt-In Uninstaller:** Authenticated `crawler-labyrinth/v1` endpoints, `wp crawler-labyrinth` CLI commands, developer hooks (`crawler_labyrinth_*`), and opt-in `uninstall.php`.
