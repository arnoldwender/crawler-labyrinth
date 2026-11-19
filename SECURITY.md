# Security Policy

## Supported Versions

| Version | Supported |
| --- | --- |
| `1.0.x` | Supported |

---

## Reporting a Vulnerability

Please **do not** open a public GitHub issue for security vulnerabilities.

Submit security reports privately via GitHub Private Vulnerability Reporting:

- **Security Advisories:** [github.com/arnoldwender/crawler-labyrinth/security/advisories/new](https://github.com/arnoldwender/crawler-labyrinth/security/advisories/new)
- **Maintainer:** [Arnold Wender](https://arnoldwender.com)

### What to Include

1. Minimal reproduction steps or HTTP/WP-CLI payload.
2. Affected component (`TrapRouter`, `TokenSigner`, `HitLogger`, `RestController`, `AdminDashboard`, or `LabyrinthCommand`).
3. Assessment of impact (authentication bypass, privilege escalation, chain tampering, caching leak, or escaping flaw).
