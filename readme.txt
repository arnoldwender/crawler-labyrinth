=== Crawler Labyrinth ===
Contributors: arnoldwender
Tags: security, robots, honeypot, crawler, tarpit
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Local deterministic crawler tarpit and RFC 9309 robots.txt honeypot with hybrid HTTP 429/403 quarantine and HMAC-chained hit logging.

== Description ==

Crawler Labyrinth defends WordPress sites against automated scrapers that ignore `robots.txt` directives, without external proxies, third-party APIs, or outbound HTTP latency.

1. **RFC 9309 Multi-Group `robots.txt` Injection:** Automatically injects `Disallow: /<prefix>/` into every `User-agent:` group so explicit `Allow: /` rules for search or citation crawlers cannot shadow the disallow rule.
2. **Accessible-Inert Footer Bait:** Prints `<div hidden inert aria-hidden="true" data-crawler-labyrinth="1">` with signed depth-1 trap links carrying `rel="nofollow noopener"`, `tabindex="-1"`, and `aria-hidden="true"` on unauthenticated public frontend pages only.
3. **Deterministic Local Labyrinth, Dry-Run & Hybrid Quarantine:** `DecoyGenerator` synthesizes archival cataloguing pages (`HTML5` or `text/plain`) using local HMAC-SHA256 derivation. `TrapRouter` intercepts trapped requests early on `init` (priority `1`, skipping `is_admin()`) before `WP_Query` and sheds traffic floods exceeding `crawler_labyrinth_burst_limit` hits/minute with HTTP `429` (`X-Crawler-Labyrinth: burst-shed`). In `hybrid` mode (default), scrapers receive bounded-depth decoy pages up to `crawler_labyrinth_strike_threshold` hits and are then quarantined with HTTP `429` (`Retry-After`) or `403` for `crawler_labyrinth_block_ttl_minutes`. Modes `labyrinth`, `block`, and `observe` (dry-run audit logging) are also available.
4. **Search & Citation Crawler Exemptions:** Optional opt-in training-UA interception (`crawler_labyrinth_intercept_training_ua`) is disabled by default and strictly exempts search, citation, and preview bots (`Googlebot`, `Bingbot`, `Applebot`, `OAI-SearchBot`, `ChatGPT-User`, `Claude-SearchBot`, `Claude-User`, `PerplexityBot`, `Perplexity-User`, `MistralAI-User`, `Slackbot`, `Storebot-Google`, `DuckDuckBot`).
5. **Tamper-Evident Audit Chain & Privacy:** Hits are logged to `{prefix}crawler_labyrinth_hits` using daily rotating keyed HMAC-SHA256 IP digests (raw IPs are never persisted) chained with `prev_hash` / `row_hash`. Scheduled daily retention redacts expired `user_agent_excerpt` fields while keeping chain verification intact.

**No Support & Zero Liability ("AS IS"):** This plugin comes with **zero support** and **no private support**. Do not contact the author privately via email, `arnoldwender.com`, or direct messages. Reproducible bugs must be reported exclusively at `https://github.com/arnoldwender/crawler-labyrinth/issues` (with no guaranteed response or fix). You install and run this code strictly at your own risk; under GPLv2+ Sections 11-12 it is provided **"AS IS"** without warranty, and the author assumes **zero responsibility or liability** for any damages, downtime, false positives, or crawler/indexing outcomes.

== Installation ==

1. Upload the `crawler-labyrinth` folder to `/wp-content/plugins/crawler-labyrinth/` (or install the release ZIP via **Plugins -> Add New**).
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Open **Crawler Labyrinth** in the WordPress Admin menu to inspect activity, verify the HMAC chain, or adjust thresholds.

== Frequently Asked Questions ==

= Does this plugin make outbound requests or rely on external tarpit servers? =

No. All decoy documents, link tokens, and IP digests are generated locally inside PHP with HMAC-SHA256.

= Will search engines or screen readers follow the hidden link? =

Search engines that honor `robots.txt` are disallowed from `/<prefix>/` across every `User-agent:` group, and decoy responses also include `X-Robots-Tag` and `<meta name="robots">` with `noindex, nofollow, noarchive, nosnippet, noai, noimageai` alongside `DONOTCACHEPAGE`. Assistive technology and keyboard navigation skip `<div hidden inert aria-hidden="true">` with `tabindex="-1"`.

= Are stored options and logs removed when uninstalling? =

By default, options and `{prefix}crawler_labyrinth_hits` are preserved across deletion. Enable **Delete plugin options and audit log table on uninstall** on the Telemetry & Audit tab to purge everything on uninstall.

= Is technical or private support provided? =

No. This plugin is provided with zero support (`0` support). Private inquiries via email, `arnoldwender.com`, or direct messages are never answered. Reproducible bugs must be submitted solely through GitHub Issues (`https://github.com/arnoldwender/crawler-labyrinth/issues`), with no guarantee of response or resolution, and you run the plugin at your own sole risk with zero author responsibility or liability.

== Screenshots ==

1. Telemetry & Audit tab showing honeypot status, hit counters, HMAC chain validity badge, recent trapped requests with keyed IP digests, and retention controls.
2. Trap Settings tab with RFC 9309 robots.txt toggle, opt-in training scraper filter, and hybrid quarantine threshold configuration.
3. Decoy & robots.txt Preview tab inspecting generated synthetic HTML5 entries and multi-group Disallow injection.

== Changelog ==

= 1.1.1 =
* Hot-path optimization (`TrapRouter`): short-circuits normal human requests before secret reads, HMAC digests, or transient queries when no active quarantines exist (or in `observe` mode).
* `observe` dry-run parity: skips HTTP `429 burst-shed` throttling during `observe` dry-run monitoring.
* Extended trap path matching: captures multi-segment paths, `.html` suffixes, newline tails, and `>96`-character probes under `/<route_prefix>/...` as `robots_probe`.
* RFC 9309 `robots.txt` continuity: keeps active `User-agent:` group state across blank lines and mid-group `Sitemap:` lines without duplicating `Disallow: /<prefix>/`.
* Telemetry UI & validation: surfaces `Observed` counts and `entry_vector` in the Admin UI (`languages/crawler-labyrinth.pot` updated), rejects dots/slashes in `Settings::sanitize_route_prefix()`, and expands unit/mutation coverage.

= 1.1.0 =
* Early pre-query interception: switched `TrapRouter::boot()` from `template_redirect` to `init` (priority `1`) with an explicit `is_admin()` guard so trapped requests short-circuit before `WP_Query`.
* Global burst circuit breaker: added `crawler_labyrinth_burst_limit` (default `120`/minute, `0` disables) using a single 60-second transient window (`crawler_labyrinth_burst_win`) to shed flood bursts with HTTP `429` (`Retry-After: 60`, `X-Crawler-Labyrinth: burst-shed`) without hitting MySQL.
* Dry-run `observe` mode, `entry_vector` metadata (`signed_bait`, `robots_probe`, `ua_guard`), and `X-Crawler-Labyrinth` state headers (`decoy`, `quarantined`, `observed`, `burst-shed`) surfaced in Admin UI, `HitLogger::get_stats()`, and `wp crawler-labyrinth status`.

= 1.0.3 =
* Documentation only: README notes that the plugin was built with an agentic workflow, and SUPPORT.md states that logs, previews, audit chains and robots.txt output are not legal, security or compliance advice. No code changes.

= 1.0.2 =
* Security and request routing: hardened subdirectory (`base_path`) normalization and segment-bounded route exclusions, switched to `wp_parse_url()`, enforced remote IP parsing without trusting spoofable forwarding headers by default, bounded verified trap depth (`<= 16`), added HTTP `HEAD` empty-body responses, removed `Retry-After` from `403` responses, and cleared object-cache `notoptions` entries on concurrent HMAC secret initialization.
* Audit log and retention: added MySQL `GET_LOCK`/`RELEASE_LOCK` append serialization, keyset-paginated `verify_chain()` with strict `prev_hash` linkage validation, multi-batch `purge_expired()`, `[REDACTED]` User-Agent spoof filtering, and UTF-8 `mb_substr()` truncation matching `VARCHAR(191)`.
* Compatibility and accessibility: fixed `RobotsInjector` RFC 9309 case-sensitive path matching, `#` comment ordering before blank lines, lowercase/empty seed canonicalization parity, `.cl-admin .cl-input` border contrast (`#64748b`), `44px` touch targets, `320px` reflow guards, `forced-colors` active tab highlight, safe `?tab=` fallback clamping, and expanded standalone tests (54 passing) plus 39 mutation checks.

= 1.0.1 =
* Explicit zero-support, GitHub-only bug reporting, no-private-support, and no-warranty / zero-liability ("AS IS") notices added to `SUPPORT.md`, `README.md`, `readme.txt`, `SECURITY.md`, `CONTRIBUTING.md`, and GitHub issue templates.

= 1.0.0 =
* Initial public release: local deterministic decoy generator, RFC 9309 multi-group robots.txt injector, accessible-inert footer bait renderer, hybrid HTTP 429/403 trap router, HMAC-chained audit table (`crawler_labyrinth_hits`), pseudonymizing retention cron, standalone accessible admin UI and dashboard widget, REST routes (`crawler-labyrinth/v1/*`), WP-CLI (`wp crawler-labyrinth *`), developer filters/actions, and opt-in uninstaller.
