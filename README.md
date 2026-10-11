<h1 align="center">askmydocs-connector-zendesk</h1>

<p align="center">
  <strong>Zendesk connector for AskMyDocs — tickets, comments, attachments, customers, organizations and Help Center articles, plus read-only live tools for the chat.</strong><br/>
  Drop-in Laravel package. Install it next to <code>askmydocs-connector-base</code> and the Zendesk card appears in the admin UI.
</p>

<p align="center">
  <a href="https://github.com/padosoft/askmydocs-connector-zenddesk/actions/workflows/tests.yml"><img alt="CI status" src="https://img.shields.io/github/actions/workflow/status/padosoft/askmydocs-connector-zenddesk/tests.yml?branch=main&label=tests"></a>
  <a href="LICENSE"><img alt="License" src="https://img.shields.io/badge/license-Apache--2.0-blue.svg"></a>
  <img alt="PHP version" src="https://img.shields.io/badge/php-8.3%20%7C%208.4-777BB4">
  <img alt="Laravel version" src="https://img.shields.io/badge/laravel-12%20%7C%2013-FF2D20">
  <img alt="Connector base" src="https://img.shields.io/badge/connector--base-%5E1.6-informational">
</p>

---

## Table of contents

1. [Why this package](#why-this-package)
2. [Features](#features)
3. [Architecture at a glance](#architecture-at-a-glance)
4. [Installation](#installation)
5. [Credential setup (step by step)](#credential-setup-step-by-step)
6. [Activation inside AskMyDocs](#activation-inside-askmydocs)
7. [What gets ingested](#what-gets-ingested)
8. [Sync semantics](#sync-semantics)
9. [Live tools for the chat](#live-tools-for-the-chat)
10. [Agent usage profile](#agent-usage-profile)
11. [Trust, privacy and access control](#trust-privacy-and-access-control)
12. [Configuration reference](#configuration-reference)
13. [Queues and operations](#queues-and-operations)
14. [Testing and development](#testing-and-development)
15. [Troubleshooting](#troubleshooting)
16. [Documentation index](#documentation-index)
17. [License](#license)

---

## Why this package

A customer-service desk is one of the richest sources of company knowledge: every resolved ticket is a worked example, every Help Center article is an approved answer, and every customer and organization profile is context an agent would otherwise have to look up by hand. [AskMyDocs](https://github.com/lopadova/AskMyDocs) is an enterprise RAG and canonical-knowledge platform; this package pipes a Zendesk Support account into it.

It does two complementary things:

- **Indexed knowledge** — a resumable, checkpointed import turns tickets (with their comments), attachments, customers, organizations and published Help Center articles into KB documents, so the chat can find *"how did we resolve this before?"* semantically.
- **Live answers** — eleven read-only tools let the chat ask Zendesk *right now* about a specific ticket, customer, organization or article, so *"what is the status of ticket #4821?"* is never answered from a stale snapshot.

It follows the same contract as the sibling connectors (`askmydocs-connector-freshdesk`, `-jira`, `-confluence`, `-google-drive`, …): it extends `Padosoft\AskMyDocsConnectorBase\BaseConnector`, is discovered through `composer.json` `extra.askmydocs.connectors`, and never touches host classes — everything host-side goes through `ConnectorIngestionContract`.

## Features

- 🔌 **Zero-config discovery** — Laravel auto-discovery registers the service provider; the connector is registered under the key `zendesk` and also added to `connectors.built_in`, so it works with a Composer `path` repository during development.
- 🔐 **Two authentication modes** — API token (`{agent email}/token` + token, HTTP Basic) or an OAuth access token (Bearer). Secrets live only in the encrypted credential vault, never in `config_json`.
- 🎫 **Tickets with their conversation** — subject, status, priority, type, requester, assignee, organization, channel, tags and every comment, with author names resolved.
- 🔒 **Internal notes are a switch** — private agent comments are included by default and can be excluded; changing the setting re-imports already imported tickets.
- 📎 **Attachments as documents** — PDF, DOCX, TXT and Markdown (images too when the host enables OCR). Size, count, malware-scan result, real MIME and DOCX structure are checked; credentials are sent only to the account host.
- 👤 **Customers and 🏢 organizations** — one profile document each (end users only; agents are never imported as customers).
- 📚 **Help Center articles** — published articles only; drafts are never imported and a draft removes the existing document. Optional locale filter.
- ♻️ **Resumable, idempotent scans** — durable checkpoints, per-installation lock, content fingerprints, `Retry-After` honoured, deletions only on confirmed evidence.
- 🧭 **Cursor-based incremental exports** — tickets and users use Zendesk's cursor-based incremental exports, the documented way to walk large accounts without skipping records.
- 🛠️ **Eleven read-only live tools** — per installation, project-scoped, size-capped, with explicit coverage and pagination metadata.
- 🧠 **Agent usage profile** — `resources/agent-usage.json` tells the orchestrator when to go live, when to use the index and when to combine both.
- 🏷️ **Provenance and ACL reporting** — implements `DeclaresProvenance` (tickets are customer-authored, so the default is `untrusted-external`) and `SupportsSourceAcl` (agents-only records, public or segment-restricted articles).
- 🏢 **Multi-account, multi-tenant** — several Zendesk accounts per tenant, each bound to its own KB project; every query is tenant-scoped.

## Architecture at a glance

```
                ┌──────────────────────────────┐
Composer        │ padosoft/askmydocs-connector-│
require ───────▶│ zendesk (this package)       │
                └──────────────┬───────────────┘
                               │ extra.askmydocs.connectors
                               ▼
                ┌──────────────────────────────┐
                │ askmydocs-connector-base ^1.6│
                │ ConnectorRegistry · Vault ·  │
                │ SyncScheduler · TenantContext│
                └──────────────┬───────────────┘
                               │ resolves ZendeskConnector
                               ▼
   syncFull / syncIncremental ─▶ SyncManager::start()   (queues a scan, returns at once)
                                      │
                                      ▼  ProcessSyncBatch (queue "zendesk", one bounded batch per job)
        ┌──────────── phase machine, state in zendesk_sync_runs.checkpoint ────────────┐
        │ tickets → users → organizations → articles → article_check → customer_check  │
        └───────────────┬──────────────────────────────────────────────┬───────────────┘
                        │ ZendeskClient (read-only, capped, retried)    │ DocumentImporter
                        ▼                                               ▼
              https://{subdomain}.zendesk.com/api/v2        ConnectorIngestionContract (host)
                                                            ├─ redactContent()   PII at the boundary
                                                            ├─ dispatchIngestion()
                                                            └─ softDeleteByRemoteId()

   chat ──▶ ZendeskTools::catalog() / execute()  ──▶ ZendeskClient (live, read-only)
```

Key design decisions (full rationale in [docs/architecture.md](docs/architecture.md)):

1. **Scheduling is separate from importing.** `syncFull()` and `syncIncremental()` only queue or resume a scan; the work happens in `ProcessSyncBatch` jobs. The `$since` argument of `syncIncremental()` is deliberately ignored — the package's own durable checkpoints are the watermark.
2. **One write path.** Every document kind goes through `DocumentImporter::write()`: redaction, fingerprint, stable path, source-aware metadata, reported ACL, counters.
3. **The package never decides who may read a document.** It reports what Zendesk says (`SupportsSourceAcl`); the host maps those identifiers to its own subjects.

## Installation

Requirements: PHP **8.3+**, Laravel **12 or 13**, `ext-curl`, `ext-fileinfo`, `ext-zip`, and `padosoft/askmydocs-connector-base ^1.6`. The host must bind `ConnectorIngestionContract` (AskMyDocs ships the reference bridge) and run an **asynchronous queue**.

```bash
composer config repositories.askmydocs-connector-base vcs https://github.com/padosoft/askmydocs-connector-base
composer config repositories.askmydocs-connector-zendesk vcs https://github.com/padosoft/askmydocs-connector-zenddesk
composer require 'padosoft/askmydocs-connector-zendesk:^1.0'
php artisan migrate
```

> The Composer package is `padosoft/askmydocs-connector-zendesk`; the GitHub repository slug is `askmydocs-connector-zenddesk` (historical spelling). The current release is `v1.0.0`; it has been validated with simulated HTTP only, so run a first scan against a Zendesk sandbox before pointing it at production (see [docs/zendesk-api.md](docs/zendesk-api.md)).

Optional publishes:

```bash
php artisan vendor:publish --tag=connector-zendesk-config   # config/connector-zendesk.php
php artisan vendor:publish --tag=connector-zendesk-assets   # public/connectors/zendesk.svg
```

The package ships one migration that creates two tables, loaded automatically: `zendesk_sync_runs` (one row per scan, with its checkpoint) and `zendesk_source_states` (what was last delivered for each remote object). The `connector_installations` / `connector_credentials` tables belong to `connector-base`.

### Developing against a local checkout

```bash
# composer.local.json is git-ignored: a copy of composer.json whose `repositories`
# entry is a `path` repository pointing at your connector-base checkout.
COMPOSER=composer.local.json composer install
```

See [docs/development.md](docs/development.md).

## Credential setup (step by step)

Full walkthrough with troubleshooting: [docs/credential-setup.md](docs/credential-setup.md). The short version:

### Option A — API token (simplest)

1. In Zendesk open **Admin Center → Apps and integrations → APIs → Zendesk API**.
2. On the **Settings** tab make sure **Token access** is enabled.
3. Click **Add API token**, give it a description such as `AskMyDocs`, copy the token **once** (Zendesk will not show it again) and save.
4. Decide which agent the token acts as. **An API token impersonates the agent whose email you pair it with**, so use a dedicated agent (for example `askmydocs@your-company.com`) with the narrowest role that can read what you want indexed — tickets, users, organizations and Help Center articles. A read-only custom role is ideal.
5. In AskMyDocs choose **Connectors → Zendesk → Install** and enter:
   - **Zendesk subdomain** — `company` for `company.zendesk.com` (the full host or `https://company.zendesk.com` is accepted too);
   - **Authentication** — *API token*;
   - **Agent email** — the dedicated agent's email;
   - **API token** — the token from step 3.

Zendesk's credential format is `{email_address}/token:{api_token}` over HTTP Basic; the package builds it for you.

### Option B — OAuth access token

Zendesk recommends OAuth over API tokens because a token can be scoped. Create an OAuth client in **Admin Center → Apps and integrations → APIs → OAuth Clients**, obtain an access token that carries the **`read`** scope, then choose *OAuth access token* in the form and paste it. The package sends it as `Authorization: Bearer …`.

> **Limitation (v1):** the package consumes an access token you provide; it does not run the OAuth authorization-code dance or refresh expiring tokens. Use a non-expiring token, or re-enter the token when it expires (the installation shows an *errored* state and a 401 in its health check).

### What the form checks

Saving the form calls `GET /api/v2/users/me` **before** anything reaches the vault. It must return a real user with the role `agent` or `admin`: Zendesk answers `200` with a null identity to anonymous callers, and an end-user token cannot read tickets, so both are rejected. A failed re-configuration leaves the previous secret untouched.

## Activation inside AskMyDocs

1. Open **Settings → Connectors** in the AskMyDocs admin UI.
2. The **Zendesk** card appears; click **Install**, fill in the credential form, choose a label (for example `support-eu`) and the **KB project** the documents should land in.
3. Open the installation's **settings** to review the sync window, internal notes, customers, organizations, Help Center, attachments and trust level (see [Configuration reference](#configuration-reference)).
4. Click **Sync now**, or wait for the scheduler (default cadence is set by `connector-base`). **Fetch all** starts the one-time historical import.

You can connect several Zendesk accounts (labels) to one tenant, each with its own project binding.

## What gets ingested

| Kind | Source | Document | Notes |
|---|---|---|---|
| `ticket` | Incremental ticket export + `GET /tickets/{id}` + `GET /tickets/{id}/comments` | Markdown: header facts, then every comment oldest-first with author and timestamp | Requester, assignee and organization names are resolved; internal notes are labelled and optional |
| `attachment` | `content_url` of comment attachments | The file itself (`pdf`, `docx`, `txt`, `md`; images when `kb.ocr.enabled`) | Child of its ticket; inline images are skipped |
| `user` | Incremental user export | Markdown profile: email, role, organization, phone, tags, details, notes | **End users only**; agents are never customers |
| `organization` | Incremental organization export | Markdown profile: domains, tags, details, notes | |
| `article` | `GET /help_center/articles` | Markdown converted from the article HTML | **Published only**; drafts are never imported |

Documents are written to `{project}/connectors/zendesk/tenant-{sha256(tenant)}/installation-{id}/{kind}/{remote id}.{ext}` and carry, under `metadata.converter_hints.zendesk`, the vendor fields (status, priority, ids, tags, timestamps, …) plus the standard `_derived` block (`search_tags`, `status_active`, `recency_bucket`). The remote identifier is stored as `metadata.zendesk_remote_id = "{installation}:{kind}:{id}"` — the key used for deletions and for `readAcl()`.

Text bodies and titles pass through `ConnectorIngestionContract::redactContent()` **before** they are written or dispatched, so the host's PII policy applies to tickets, comments, profiles and articles. Binary attachments are not redacted (regex redaction cannot inspect PDFs or images); see [docs/security.md](docs/security.md).

## Sync semantics

Two scan modes share one pipeline:

| Mode | Start | Ticket / user / organization period |
|---|---|---|
| Ordinary (`window`) | `SyncManager::start($installation)` — also what `syncFull()` and `syncIncremental()` call | First scan: the last `date_window_days` (default **90**). Later scans continue from the previous completed scan, minus a 120-second overlap, as long as the window did not grow |
| Historical (`history`, **Fetch all**) | `SyncManager::start($installation, history: true)` | From `1970-01-01` up to the scan start, regardless of the ordinary window |

Help Center articles are enumerated in full in both modes (they are not windowed).

Phases run in a fixed order and skip what you disabled: `tickets → users → organizations → articles → article_check`, plus `customer_check` in history mode. State lives in `zendesk_sync_runs.checkpoint`; a failed run resumes where it stopped.

- **Cursor-based exports.** Tickets and users are read from Zendesk's cursor-based incremental exports (`start_time`, then the returned `after_cursor` until `end_of_stream`). A cursor that does not advance fails the scan loudly instead of looping.
- **Idempotency.** Each document has a SHA-256 fingerprint of its body, fields and reported ACL. Unchanged content is not delivered again; a changed ACL is.
- **Rate limits.** A `429` keeps the checkpoint and re-queues the batch after `Retry-After` seconds (60 if absent). Incremental exports are limited by Zendesk to 10 requests per minute (30 with the High Volume add-on) — the batch loop is small by design.
- **Deletions need evidence.**
  - A ticket is removed (with its attachments) when the export reports it with status `deleted`. A `404` on a ticket is *not* evidence (archived or inaccessible tickets answer 404).
  - A customer is removed when the export reports them as non-end-user or inactive; in history mode, customers and organizations the full export did not return are verified with `show_many` and removed if Zendesk omits them.
  - An article is removed when it becomes a draft, or when the article detail answers `404` after it vanished from the list. Absence from a list alone never deletes.
  - An attachment Zendesk later marks as deleted or malware is removed; one that is merely over a size or count limit is not.
  - Tickets that fall out of the ordinary window are **kept**.
- **Settings that change content or destination** (internal notes, attachment options, OCR, the KB project binding) are hashed; on change the next scan first re-imports every known ticket, customer and organization.
- **Disabling a content type** (customers, organizations, Help Center) stops importing it and its history reconciliation, but does not delete what was already imported.
- **Rebinding to another project** re-delivers every known object to the new project through that refresh pass and retires the copy in the previous one.

Details and failure semantics: [docs/sync-semantics.md](docs/sync-semantics.md).

## Live tools for the chat

`Tools\ZendeskTools` exposes a catalog and an executor, independent of the importer. For every **active** installation bound to the requested project there are **eleven read-only tools**, named `zendesk_{installationId}_{action}` (for example `zendesk_42_get_ticket`):

| Action | Arguments | Purpose |
|---|---|---|
| `list_tickets` | one of `requester_id`, `organization_id`, `email` — plus `cursor?`, `page?`, `per_page?` | Tickets of a verified requester or organization, newest first, all statuses; without a selector, the most recently updated tickets |
| `search_tickets` | `query`, `page?` | Zendesk search syntax (free text + filters such as `status:open`, `priority>normal`, `assignee_id:123`); `type:ticket` is forced; max 1000 results |
| `search_users` | `term`, `page?` | Resolve a customer (end user) by name or email; agents are not returned |
| `get_user` | `id` | A user (customer or agent) by ID |
| `search_agents` | `term`, `page?` | Resolve a support agent (`role:agent`) |
| `search_organizations` | `term` | Resolve an organization by name (autocomplete) |
| `get_organization` | `id` | An organization by ID |
| `get_ticket` | `id` | A ticket by ID |
| `list_comments` | `id`, `cursor?`, `per_page?` | A page of comments; internal notes follow the installation setting |
| `search_articles` | `term`, `page?` | Published Help Center articles by keyword |
| `get_article` | `id` | A published article by ID |

```php
use Padosoft\AskMyDocsConnectorZendesk\Tools\ZendeskTools;

// The host middleware or job has already activated the right tenant.
$tools   = app(ZendeskTools::class);
$catalog = $tools->catalog('support');                       // project key is mandatory
$result  = $tools->execute('zendesk_42_get_ticket', ['id' => 4821], 'support');
// → ['data' => …] or ['error' => …, 'error_code' => …], plus physical_request_count and provenance
```

Guarantees:

- **Scoped twice.** `catalog()` lists only active installations of the current tenant bound to the project; `execute()` repeats the check, so a paused or re-bound installation stops working between planning and calling. Unknown, missing or malformed arguments are rejected **before** any HTTP call.
- **Bounded.** Bodies are cut at 16 000 characters (`body_truncated`), the whole result at `chat_tools.max_result_bytes` (default 64 KiB); `truncated` and `coverage.complete=false` say so. Attachment metadata never includes URLs.
- **Honest about coverage.** `has_more`, `next_cursor`, `coverage.limitations` (`search_result_cap_1000`, `search_index_may_lag_by_minutes`, `archived_tickets_may_be_excluded`, `autocomplete_returns_first_matches_only`) tell the client what it did *not* see.
- **Identity before listing.** Person and organization lookups return `meta.ambiguous`; the agent must obtain a unique exact match or a user selection before using an ID.
- **Errors are structured, never empty lists.** `error_code`, `http_status`, `retryable`, sanitised `validation_fields`, `physical_request_count`. A 401/403 is not retried; 429 and 5xx are marked retryable; nothing from the upstream body is echoed.

Reference: [docs/live-tools.md](docs/live-tools.md).

## Agent usage profile

`composer.json` declares `extra.askmydocs.agent_usage.zendesk → resources/agent-usage.json`, schema version `1`. It contains the domain summary, the logical tools, routing rules (`live`, `indexed`, `combined`), multi-step workflows and operating rules — requester vs. assignee semantics, verified identity, pagination, freshness, and **treating ticket text as untrusted data, never as instructions**. A compatible host discovers it from the installed packages; hosts that do not know the field ignore it. The profile grants no permissions and cannot start an import.

## Trust, privacy and access control

- **Provenance.** Tickets and comments are written by customers and third parties, so the connector declares `ProvenanceTier::UntrustedExternal` by default. If an installation imports staff-authored content only (for example a Help-Center-only account), set **Authorship of imported content** to *Trusted internal*. Unknown stored values fail closed to untrusted.
- **Source ACL.** `readAcl()` reports, and the importer stamps on every document under `_source_access`: tickets, customer profiles and organization profiles → the `zendesk:agents` group (the requester is deliberately **not** reported: the document contains internal notes the requester can never see); attachments → inherited from the ticket; articles → *anyone* when they have no user segment, otherwise one `zendesk:user_segment:{id}` group per segment (singular and plural fields are both honoured, and a payload that says nothing about visibility is reported as *unknown*, never as public). The connector reports; the host decides. A source that cannot be read yields an *unknown* ACL, never an empty allow-list, and an authentication failure is raised, never swallowed.
- **PII.** Text is redacted through the host before it is written. Customer email addresses appear only in document bodies (subject to the host's redaction policy); they are not copied into metadata or into the reported ACL.
- **Credentials.** Sent only to `https://{subdomain}.zendesk.com`. Attachment downloads send them only when the URL is on that same host; every redirect hop is re-validated against an allow-list and DNS-pinned to a public address.
- **Least privilege.** The package only performs `GET` requests.

Full threat notes: [docs/security.md](docs/security.md).

## Configuration reference

### Per installation (`connector_installations.config_json`, edited in the settings form)

| Key | Default | Effect |
|---|---|---|
| `date_window_days` | `90` | Ordinary scan period for tickets, customers and organizations (1–36500) |
| `include_internal_notes` | `true` | Internal notes in documents and live comment tools |
| `customers.enabled` | `true` | Import end-user profiles |
| `organizations.enabled` | `true` | Import organization profiles |
| `help_center.enabled` | `true` | Import published articles |
| `help_center.locale` | *(empty)* | Restrict articles to one locale such as `en-us`; empty = every locale the agent can read |
| `attachments.enabled` | `true` | Download compatible attachments |
| `attachments.max_size_mb` | `25` | Per-file limit (1–100 MiB) |
| `attachments.max_per_ticket` | `20` | Compatible files per ticket (1–100) |
| `provenance_tier` | `untrusted-external` | `trusted-internal` or `untrusted-external` |
| `connection.subdomain` / `auth_mode` / `email` | — | Set by the credential form; the secret is in the vault |

### Package (`config/connector-zendesk.php`)

| Key | Default | Effect |
|---|---|---|
| `http.timeout` / `connect_timeout` | `30` / `5` s | API timeouts |
| `http.attempts` | `3` | Tries for connection failures and 5xx |
| `http.max_json_bytes` | `16 000 000` | Cap on one API response |
| `http.resolve_dns` | `true` | DNS-pin public addresses; `false` is for simulated HTTP in tests only |
| `attachments.allowed_hosts` | `*.zendesk.com`, `*.zdusercontent.com`, `*.zdassets.com`, S3 hosts | Where attachments (and their redirects) may come from |
| `sync.batch_items` / `batch_seconds` | `5` / `60` | Steps and indicative duration of one batch |
| `sync.overlap_seconds` | `120` | Re-scan overlap before the previous watermark |
| `sync.export_min_age_seconds` | `90` | Keeps the export `start_time` safely in the past |
| `sync.queue` / `sync.connection` | `zendesk` / *(default)* | `CONNECTOR_ZENDESK_QUEUE`, `CONNECTOR_ZENDESK_CONNECTION` |
| `sync.middleware` | `[]` | Job middleware for `ProcessSyncBatch` and `StartSync` |
| `chat_tools.enabled` | `true` | Disable to remove every live tool |
| `chat_tools.max_result_bytes` | `65536` | Cap on one tool result |

## Queues and operations

```bash
php artisan queue:work zendesk --queue=zendesk --timeout=600 --tries=3
```

- Use an **asynchronous** queue (a synchronous `queue.default` or `CONNECTOR_ZENDESK_CONNECTION` is refused) and a cache store with **atomic locks** (Redis): one batch per installation runs at a time, others are deferred by 30 seconds.
- The queue connection's `retry_after` must be greater than 600 seconds.
- `StartSync($installationId, $tenantId)` is the entry point for your own scheduler; it queues or resumes a scan without marking anything complete.
- A worker timeout leaves a *resumable* failure (the installation shows `errored`) and keeps the checkpoint; starting a scan again resumes it. No exception body is ever persisted.
- Pausing or disconnecting an installation cancels its scan at the next step.

## Testing and development

```bash
composer install            # or: COMPOSER=composer.local.json composer install
composer test               # PHPUnit + Testbench, SQLite, simulated HTTP
composer analyse            # PHPStan level 6
vendor/bin/pint --test      # code style
```

The suite covers credential verification and state tokens, both auth modes, full scans and idempotency, internal-note and content-type switches, deletions on evidence only, attachments (credentials scoping, redirects, MIME, size, malware flag), rate limits, resume after failure, locks, cancellation, tenant isolation, every live tool, the agent profile, provenance and ACL. CI runs PHP 8.3/8.4 × Laravel 12/13. **No test talks to a real Zendesk account**; see [Troubleshooting](#troubleshooting) and [docs/zendesk-api.md](docs/zendesk-api.md) for what has and has not been verified against a live instance.

## Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| Install says *Zendesk did not recognise the credentials* | `users/me` returned an anonymous identity: wrong token, wrong subdomain, or token access disabled | Re-check the token, the agent email and **Token access** in Admin Center |
| *Use an agent or admin account* | The email belongs to an end user | Pair the token with an agent email |
| `Zendesk rejected the credentials` (401) in health | Token deleted/rotated, or OAuth token expired | Create a new token and re-enter credentials |
| `The Zendesk account does not permit this operation` (403) | The agent's role cannot read that resource (for example users or Help Center) | Grant the role access, or switch the content type off |
| Scan stays *queued* | No worker on the `zendesk` queue, or a `sync` queue | Run the worker command above; use an async queue |
| `Zendesk rate limit reached` | Incremental exports are limited per minute | Nothing to do: the batch resumes after `Retry-After` |
| *export cursor did not advance* | Zendesk returned the same cursor without ending the stream | Re-run; if persistent, open an issue with the run's `checkpoint` |
| Ticket appears in search but not in the index | It is outside `date_window_days` | Widen the window or use **Fetch all** |
| New ticket missing from live search | Zendesk search is indexed with a delay of minutes | Use `get_ticket`, or retry shortly |
| Internal notes still visible after switching off | The re-import runs on the next scan | Trigger **Sync now** |
| Deleted customer still in the KB | Ordinary scans only see exports inside the window | Run **Fetch all** once; it verifies absent customers |

More: [docs/troubleshooting.md](docs/troubleshooting.md).

## Documentation index

| Page | Contents |
|---|---|
| [docs/architecture.md](docs/architecture.md) | Components, data flow, design decisions and trade-offs |
| [docs/credential-setup.md](docs/credential-setup.md) | Creating tokens/OAuth credentials, roles, rotation |
| [docs/sync-semantics.md](docs/sync-semantics.md) | Phases, checkpoints, deletions, failure and resume behaviour |
| [docs/live-tools.md](docs/live-tools.md) | Tool reference, argument rules, result and error shapes |
| [docs/data-model.md](docs/data-model.md) | Tables, document layout, metadata, remote identifiers |
| [docs/security.md](docs/security.md) | Threat model, SSRF/credential handling, PII, ACL, provenance |
| [docs/zendesk-api.md](docs/zendesk-api.md) | Every endpoint used, with its verification status |
| [docs/troubleshooting.md](docs/troubleshooting.md) | Symptom → cause → fix |
| [docs/development.md](docs/development.md) | Local setup, tests, extending the package |
| [CHANGELOG.md](CHANGELOG.md) | Release notes |

## License

Apache-2.0 — see [LICENSE](LICENSE).

Zendesk is a trademark of Zendesk, Inc. This project is independent and is not endorsed by or affiliated with Zendesk. See [public/icons/README.md](public/icons/README.md) for the icon's provenance.

Built and maintained by [Padosoft](https://padosoft.com/). Part of the AskMyDocs connector ecosystem.
