# Contributing to Crawler Labyrinth

Contributions are welcome via GitHub issues and pull requests at [arnoldwender/crawler-labyrinth](https://github.com/arnoldwender/crawler-labyrinth).

## Support & Issue Scope

**Zero technical, configuration, or private support is provided.** See [SUPPORT.md](SUPPORT.md):

- Report reproducible code defects **exclusively** via public [GitHub Issues](https://github.com/arnoldwender/crawler-labyrinth/issues).
- **Do not** contact the author privately via email, `arnoldwender.com`, or direct messages — private inquiries are never answered.
- Issues and pull requests carry no guaranteed response or maintenance SLA, and the software is provided strictly **"AS IS"** without warranty or liability.

---

## Development Standards

1. **Strict PHP 8.2+ Typing:** Every PHP file uses `declare(strict_types=1);` and guards direct access with `defined( 'ABSPATH' ) || exit;`.
2. **WordPress Escaping & Sanitization:** Use `wp_parse_url()`, `wp_unslash()` + `sanitize_*()`, `wpdb->prepare()`, `esc_html*()`, `esc_attr*()`, `esc_url()`, and `wp_kses()`. Avoid inline scripts or external CDN/network assets.
3. **Zero Outbound Proxying:** Decoy responses must remain locally generated; never add runtime HTTP requests to external trap servers.
4. **Constant-Time Verification:** Cryptographic token and hash comparisons must use `hash_equals()`.
5. **CSS Token Hygiene:** Keep all design tokens under `--cl-*` in `assets/css/crawler-labyrinth-admin.css`, preserve `>= 44px` interactive targets and `:focus-visible` outlines, and never write `outline: none`.
6. **Prefix Alignment:** Keep all global constants (`CRAWLER_LABYRINTH_*`), options (`crawler_labyrinth_*`), hooks (`crawler_labyrinth_*`), REST routes (`crawler-labyrinth/v1`), and WP-CLI subcommands (`crawler-labyrinth`) aligned with the plugin slug.

---

## Local Verification Workflow

Run all checks locally before opening a pull request:

```bash
php tests/test-suite.php
php tests/test-css-tokens.php
bash tests/run-mutations.sh
php tests/test-pot-freshness.php
python3 .github/scripts/security-audit.py
```

Every bug fix or security hardening change should include both a regression assertion in `tests/test-suite.php` and a corresponding mutant entry in `tests/mutations.php`.

## Pull Request Process

1. Fork the repository and branch from `main` (e.g. `fix/issue-summary` or `feat/filter-name`).
2. Keep pull requests focused on one change and update `README.md`, `readme.txt`, or `docs/` if public options or hooks change.
3. Ensure GitHub Actions CI passes across all PHP versions.
