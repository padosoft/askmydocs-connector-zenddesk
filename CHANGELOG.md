# Changelog

All notable changes to `padosoft/askmydocs-connector-zendesk` are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] — 2026-10-11

### Added

- `zendesk` connector for AskMyDocs built on `padosoft/askmydocs-connector-base ^1.6`, with the capabilities `SupportsCredentialForm`, `SupportsConnectionSettings`, `DeclaresProvenance` and `SupportsSourceAcl`.
- Two authentication modes: API token (`{email}/token` over HTTP Basic) and a pre-issued OAuth access token (Bearer). Credentials are verified against `GET /users/me` — a real admin identity is required (incremental exports are admin-only) — before they reach the encrypted vault.
- Resumable, checkpointed import of tickets with their comments, attachments (PDF, DOCX, TXT, Markdown; images with host OCR), end-user profiles, organization profiles and published Help Center articles.
- Cursor-based incremental exports for tickets and users, a time-based export for organizations, and cursor pagination for Help Center articles; ordinary (`window`, default 90 days) and historical (`history`) scan modes.
- Content fingerprints, per-installation atomic lock, `Retry-After` handling, cancellation on pause or disconnect, and deletion only on confirmed evidence.
- Rebinding an installation to another KB project re-delivers every known ticket, customer and organization there (refresh pass) and retires the copy in the previous project; attachment file names never reach metadata; titles are redacted like bodies; attachments Zendesk later flags as deleted or malware are removed from the index; history reconciliation honours the customers/organizations switches.
- Help Center visibility honours both `user_segment_id` and the plural `user_segment_ids`, and reports *unknown* (never public) when the payload carries neither; the user export is requested in pages of 100; articles outside a newly configured locale are retired; locale-scoped paths such as `/help_center/en-us/articles` are accepted by the client.
- The synchronous-queue guard checks the connection the import jobs really use; a refresh that cannot re-read a known object retires its document instead of keeping stale content; `search_users` returns customers only; a malformed singular `user_segment_id` is reported as unknown.
- Enabling customers or organizations after a completed scan back-fills the whole window instead of only the delta; organization paging follows `end_of_stream`.
- History reconciliation deletes customers that are now inactive; a refresh retires copies of a switched-off content type that are still indexed under a previous project; the side-loaded user-name map of a tool result is dropped (`meta.users_omitted`) rather than exceed `chat_tools.max_result_bytes`.
- With internal notes off, a ticket whose first comment is private no longer reappears through its `description` (indexed document and `get_ticket` alike); a rebinding also retires Help Center article and attachment copies of a switched-off type left in the previous project.
- Installation switches: internal notes, customers, organizations, Help Center (with optional locale), attachment limits and the provenance tier; a change to content settings re-imports known tickets.
- Eleven read-only live tools per installation (opt-in through the `live_tools.enabled` installation setting, off by default, because they run with the admin credential rather than the asking user's Zendesk permissions) (`list_tickets`, `search_tickets`, `search_users`, `get_user`, `search_agents`, `search_organizations`, `get_organization`, `get_ticket`, `list_comments`, `search_articles`, `get_article`) with project scoping, strict argument validation, size caps, coverage metadata and structured errors.
- Agent usage profile (`resources/agent-usage.json`, schema version 1) published through `extra.askmydocs.agent_usage`.
- Source ACL reporting stamped on every document and available through `readAcl()`: tickets, customers and organizations are reported as *unknown* (agent access can be restricted by role, which cannot be represented) with the ticket's support group as a partial `zendesk:group:{id}` hint; articles as `anyone` or `zendesk:user_segment:{id}`; attachments inherit.
- Attachments are downloaded only with an explicit `malware_not_found` verdict (`attachments.allow_unscanned` opts in to unscanned files; `malware_found` is never fetched). An opt-in `sync.ticket_support_type_scope` selects the ticket export scope (e.g. `all` to include AI-agent tickets).
- SSRF-hardened HTTP layer: account-subdomain validation, attachment host allow-list, per-hop redirect validation and DNS pinning to public addresses; credentials sent only to the account host.
- Testbench suite (102 tests), PHPStan level 6, Laravel Pint, GitHub Actions matrix (PHP 8.3/8.4 × Laravel 12/13).
- Documentation: README and `docs/` (architecture, credential setup, sync semantics, live tools, data model, security, API notes, troubleshooting, development).

### Known limitations

- Validated with simulated HTTP only; no live-account test has been run. [docs/zendesk-api.md](docs/zendesk-api.md) lists which endpoint shapes were confirmed against the published reference and which still need a sandbox check.
- OAuth access tokens are not refreshed by the package.
- Custom host-mapped domains are not supported; use the `*.zendesk.com` subdomain.
- Zendesk Guide content other than articles (community posts, custom objects, chat transcripts, voice recordings) is not imported.

[Unreleased]: https://github.com/padosoft/askmydocs-connector-zenddesk/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/padosoft/askmydocs-connector-zenddesk/releases/tag/v1.0.0
