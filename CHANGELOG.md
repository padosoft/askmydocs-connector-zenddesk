# Changelog

All notable changes to `padosoft/askmydocs-connector-zendesk` are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `zendesk` connector for AskMyDocs built on `padosoft/askmydocs-connector-base ^1.6`, with the capabilities `SupportsCredentialForm`, `SupportsConnectionSettings`, `DeclaresProvenance` and `SupportsSourceAcl`.
- Two authentication modes: API token (`{email}/token` over HTTP Basic) and a pre-issued OAuth access token (Bearer). Credentials are verified against `GET /users/me` — a real agent or admin identity is required — before they reach the encrypted vault.
- Resumable, checkpointed import of tickets with their comments, attachments (PDF, DOCX, TXT, Markdown; images with host OCR), end-user profiles, organization profiles and published Help Center articles.
- Cursor-based incremental exports for tickets and users, a time-based export for organizations, and cursor pagination for Help Center articles; ordinary (`window`, default 90 days) and historical (`history`) scan modes.
- Content fingerprints, per-installation atomic lock, `Retry-After` handling, cancellation on pause or disconnect, and deletion only on confirmed evidence.
- Installation switches: internal notes, customers, organizations, Help Center (with optional locale), attachment limits and the provenance tier; a change to content settings re-imports known tickets.
- Eleven read-only live tools per installation (`list_tickets`, `search_tickets`, `search_users`, `get_user`, `search_agents`, `search_organizations`, `get_organization`, `get_ticket`, `list_comments`, `search_articles`, `get_article`) with project scoping, strict argument validation, size caps, coverage metadata and structured errors.
- Agent usage profile (`resources/agent-usage.json`, schema version 1) published through `extra.askmydocs.agent_usage`.
- Source ACL reporting (`zendesk:agents`, `anyone`, `zendesk:user_segment:{id}`) stamped on every document and available through `readAcl()`.
- SSRF-hardened HTTP layer: account-subdomain validation, attachment host allow-list, per-hop redirect validation and DNS pinning to public addresses; credentials sent only to the account host.
- Testbench suite (76 tests), PHPStan level 6, Laravel Pint, GitHub Actions matrix (PHP 8.3/8.4 × Laravel 12/13).
- Documentation: README and `docs/` (architecture, credential setup, sync semantics, live tools, data model, security, API notes, troubleshooting, development).

### Known limitations

- Validated with simulated HTTP only; no live-account test has been run. [docs/zendesk-api.md](docs/zendesk-api.md) lists which endpoint shapes were confirmed against the published reference and which still need a sandbox check.
- OAuth access tokens are not refreshed by the package.
- Custom host-mapped domains are not supported; use the `*.zendesk.com` subdomain.
- Zendesk Guide content other than articles (community posts, custom objects, chat transcripts, voice recordings) is not imported.
