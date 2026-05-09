# Security Policy

Thank you for helping keep InterMix and its users safe.

## Supported Versions

Security fixes are provided for the current major release line.

| Version | Supported |
|---------| --- |
| `7.x`   | ✅ |
| `< 7.0` | ❌ |

## Reporting a Vulnerability

Please report vulnerabilities privately. Do not open a public issue.

Preferred channels:

1. GitHub private vulnerability reporting:
   `Security` -> `Advisories` -> `Report a vulnerability`
2. Email fallback: `infocyph@gmail.com`

Please include:

- Affected package version(s)
- PHP version and runtime environment
- Reproduction steps or proof-of-concept
- Impact assessment (confidentiality, integrity, availability)
- Known mitigations or workarounds

## Response Targets

- Initial acknowledgment: within 3 business days
- Triage decision: within 7 business days
- Remediation timeline: severity and exploitability dependent

If a report is accepted, we will prepare a patched release and publish an advisory. Reporter credit will be included unless you request to remain anonymous.

## Coordinated Disclosure

Please keep details private until a fix is released. After remediation, advisory details and release notes will be published. CVE assignment may be requested when appropriate.

## Scope

In scope:

- Vulnerabilities in maintained code under `src/`
- Security-impacting dependency risks in direct dependencies

Out of scope:

- Issues affecting unsupported versions only
- Local-only misconfiguration without a library defect
- Non-security bugs or feature requests
