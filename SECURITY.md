# Security Policy

## Supported Versions

| Version | Supported |
| --- | --- |
| `1.0.x` | Supported |

---

## Reporting a Vulnerability

Please **do not** open a public GitHub issue for security vulnerabilities, and **do not** send private vulnerability inquiries via email, `arnoldwender.com`, or direct messages (private channels are unmonitored for support).

Submit security reports **exclusively** via GitHub Private Vulnerability Reporting:

- **Security Advisories:** [github.com/arnoldwender/crawler-labyrinth/security/advisories/new](https://github.com/arnoldwender/crawler-labyrinth/security/advisories/new)

> **Best-Effort Only / No Warranty:** This project carries zero technical or user support and zero SLA. Reports submitted via GitHub Security Advisories may be reviewed at the author's sole discretion, without obligation, response timeline, or liability ("AS IS" under [LICENSE](LICENSE)).

### What to Include

1. Minimal reproduction steps or HTTP/WP-CLI payload.
2. Affected component (`TrapRouter`, `TokenSigner`, `HitLogger`, `RestController`, `AdminDashboard`, or `LabyrinthCommand`).
3. Assessment of impact (authentication bypass, privilege escalation, chain tampering, caching leak, or escaping flaw).
