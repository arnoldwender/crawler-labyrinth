# Developer Guide — Crawler Labyrinth

This guide covers extending Crawler Labyrinth via WordPress hooks, integrating reverse proxies, configuring page caches, and running the local test and mutation suite.

---

## 1. Repository Layout

| Path | Responsibility |
| --- | --- |
| `crawler-labyrinth.php` | Plugin header, constants (`CRAWLER_LABYRINTH_*`), PSR-4 autoloader (`CrawlerLabyrinth\`), lifecycle hooks |
| `includes/Core/Settings.php` | Normalized option reads, route prefix sanitization, allowed/training bot filters |
| `includes/Core/Activator.php` | Schema setup (`dbDelta`), HMAC secret initialization, `crawler_labyrinth_daily_purge` cron sync |
| `includes/Core/Plugin.php` | Singleton bootstrapper wiring core, admin, REST, and WP-CLI components |
| `includes/Core/RobotsInjector.php` | Multi-group RFC 9309 `robots_txt` disallow injection |
| `includes/Core/BaitRenderer.php` | Accessible-inert footer link rendering and `wp_kses()` allowlist |
| `includes/Core/TrapRouter.php` | Request interception, strike counting, quarantine escalation, HTTP response emission |
| `includes/Security/TokenSigner.php` | HMAC token signing/verification and keyed daily IP/UA digests |
| `includes/Generator/DecoyGenerator.php` | Deterministic synthetic archival document generation |
| `includes/Audit/HitLogger.php` | Hash-chained audit log writes, verification, and pseudonymizing retention |
| `includes/Admin/AdminDashboard.php` | Admin settings page, preview/telemetry views, dashboard widget |
| `includes/API/RestController.php` | Authenticated `crawler-labyrinth/v1` REST endpoints |
| `includes/CLI/LabyrinthCommand.php` | `wp crawler-labyrinth` CLI commands |
| `assets/css/crawler-labyrinth-admin.css` | Token-scoped (`--cl-*`) accessible stylesheet |
| `uninstall.php` | Opt-in uninstaller gated by `crawler_labyrinth_delete_data_on_uninstall` |

---

## 2. Proxy & Real Client IP Resolution

By default, `TokenSigner::client_ip()` reads `$_SERVER['REMOTE_ADDR']` so untrusted `X-Forwarded-For` headers cannot be spoofed by attackers. If your WordPress site runs behind Cloudflare, Nginx, or a load balancer you control, filter `crawler_labyrinth_client_ip`:

```php
<?php
add_filter( 'crawler_labyrinth_client_ip', static function ( string $raw_ip ): string {
	// Only trust proxy headers if REMOTE_ADDR belongs to your verified proxy/CDN tier.
	if ( isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) && is_string( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
		$candidate = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
		if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
			return $candidate;
		}
	}
	return $raw_ip;
} );
```

---

## 3. Caching & CDN Configuration

Full-page caches should bypass the trap route prefix so PHP counts strikes per IP digest and switches from `200 OK` decoys to `429`/`403` quarantine responses accurately:

- **Nginx / FastCGI Cache:**
  ```nginx
  if ($request_uri ~* "^/crawler-trap/") {
      set $skip_cache 1;
  }
  ```
- **Varnish (`vcl_recv`):**
  ```vcl
  if (req.url ~ "^/crawler-trap/") {
      return (pass);
  }
  ```
- **CDN Rules (Cloudflare / Fastly):** Add a Cache Bypass rule matching URI Path starts with `/crawler-trap/`.

Because decoy pages always send `Cache-Control: no-store, no-cache, must-revalidate, private`, compliant CDNs avoid caching them automatically.

---

## 4. Customizing Bot Lists, Decoy Text, and Telemetry Events

### Exempting an Additional Crawler (`crawler_labyrinth_allowed_bots`)

```php
<?php
add_filter( 'crawler_labyrinth_allowed_bots', static function ( array $tokens ): array {
	$tokens[] = 'MyMonitoringAgent';
	return $tokens;
} );
```

### Extending the Opt-In Training UA List (`crawler_labyrinth_training_bots`)

```php
<?php
add_filter( 'crawler_labyrinth_training_bots', static function ( array $tokens ): array {
	$tokens[] = 'CustomBulkHarvester';
	return $tokens;
} );
```

### Customizing Decoy Vocabulary (`crawler_labyrinth_decoy_pools`)

```php
<?php
add_filter( 'crawler_labyrinth_decoy_pools', static function ( array $pools ): array {
	$pools['collections'][] = 'Telemetry Ledger';
	return $pools;
} );
```

### Forwarding Quarantine Events (`crawler_labyrinth_trap_triggered`)

```php
<?php
add_action( 'crawler_labyrinth_trap_triggered', static function ( string $action, string $token, int $strikes, string $ip_digest ): void {
	if ( 'quarantined' === $action ) {
		error_log( sprintf( '[crawler-labyrinth] quarantined digest=%s strikes=%d token=%s', substr( $ip_digest, 0, 16 ), $strikes, $token ) );
	}
}, 10, 4 );
```

---

## 5. Testing & Mutation Workflow

All core unit tests run directly with PHP `8.2+` and require zero database or WordPress setup:

```bash
php tests/test-suite.php
php tests/test-css-tokens.php
bash tests/run-mutations.sh
python3 .github/scripts/security-audit.py
```

When modifying translatable strings in PHP, regenerate and verify `languages/crawler-labyrinth.pot`:

```bash
wp i18n make-pot . languages/crawler-labyrinth.pot --domain=crawler-labyrinth --exclude=tests,vendor,node_modules,dist,build,.git --skip-audit
php tests/test-pot-freshness.php
```
