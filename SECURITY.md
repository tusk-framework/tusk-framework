# Security Policy

## Supported versions

Security fixes are applied to the current `main` branch and the latest
published Framework release. Older releases may not receive fixes; upgrade
before reporting a vulnerability when possible.

## Reporting a vulnerability

Do not disclose vulnerabilities, exposed credentials, or release-signing
concerns in a public issue or pull request. Use GitHub's private security
reporting flow for `tusk-framework/tusk-framework`, or contact the project
maintainers privately through the Tusk organization.

Please include the affected package and version or commit, the PHP version and
runtime, a minimal reproduction, the security impact, and any suggested
mitigation. Do not include real private keys or production credentials in the
report.

Reports are reviewed privately and contributors will be kept informed when it
is safe to share progress. Public disclosure should be coordinated with the
maintainers after a fix or mitigation is available.

## Release security

Framework releases are built from reviewed source tags and should publish
checksums and provenance for generated release artifacts. Private signing
material belongs only in protected GitHub Actions secrets and must never be
committed to this repository.

If a signing key, token, or credential is exposed, report it privately
immediately so it can be revoked and the release trust chain rotated.
