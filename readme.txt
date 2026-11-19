=== Crawler Labyrinth ===
Contributors: arnoldwender
Tags: security, robots, honeypot, crawler, tarpit
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Local deterministic crawler tarpit and RFC 9309 robots.txt honeypot with hybrid HTTP 429/403 quarantine and HMAC-chained hit logging.

== Description ==

Crawler Labyrinth defends WordPress sites against automated scrapers that ignore `robots.txt` directives, without external proxies, third-party APIs, or outbound HTTP latency.

1. **RFC 9309 Multi-Group `robots.txt` Injection:** Automatically injects `Disallow: /<prefix>/` into every `User-agent:` group so explicit `Allow: /` rules for search or citation crawlers cannot shadow the disallow rule.
2. **Accessible-Inert Footer Bait:** Prints `<div hidden inert aria-hidden="true" data-crawler-labyrinth="1">` with signed depth-1 trap links carrying `rel="nofollow noopener"`, `tabindex="-1"`, and `aria-hidden="true"` on unauthenticated public frontend pages only.
3. **Deterministic Local Labyrinth & Hybrid Quarantine:** `DecoyGenerator` synthesizes archival cataloguing pages (`HTML5` or `text/plain`) using local HMAC-SHA256 derivation. In `hybrid` mode (default), scrapers receive bounded-depth decoy pages up to `crawler_labyrinth_strike_threshold` hits and are then quarantined with HTTP `429` (`Retry-After`) or `403` for `crawler_labyrinth_block_ttl_minutes`. Modes `labyrinth` and `block` are also available.
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

= 1.0.1 =
* Explicit zero-support, GitHub-only bug reporting, no-private-support, and no-warranty / zero-liability ("AS IS") notices added to `SUPPORT.md`, `README.md`, `readme.txt`, `SECURITY.md`, `CONTRIBUTING.md`, and GitHub issue templates.

= 1.0.0 =
* Initial public release: local deterministic decoy generator, RFC 9309 multi-group robots.txt injector, accessible-inert footer bait renderer, hybrid HTTP 429/403 trap router, HMAC-chained audit table (`crawler_labyrinth_hits`), pseudonymizing retention cron, standalone accessible admin UI and dashboard widget, REST routes (`crawler-labyrinth/v1/*`), WP-CLI (`wp crawler-labyrinth *`), developer filters/actions, and opt-in uninstaller.
