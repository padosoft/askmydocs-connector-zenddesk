# Security

This page lists what the package defends against, how, and what it deliberately leaves to the host.

## Threat model in one paragraph

The connector reads data written by **customers** (ticket text, attachment contents, profile fields) and hands it to a platform whose agents also have tools. Content is therefore untrusted input. Credentials give broad read access to a customer-service desk. URLs inside API responses (attachment links) are attacker-influenced. The package's job is to keep secrets confined, keep outbound requests on a short leash, keep untrusted text labelled as such, and never claim more certainty than Zendesk gave it.

## Credentials

| Control | Where |
|---|---|
| Secret stored only in the encrypted `connector-base` vault; never in `config_json`, logs, exception messages or checkpoints | `ZendeskConnector::handleOAuthCallback`, `SyncManager::client` |
| Verified (`GET /users/me`, real agent/admin identity) **before** it replaces the stored secret | `ZendeskConnector::verify` |
| State token is single-use, tenant- and installation-bound | `BaseConnector::issueOAuthState` / `consumeOAuthState` |
| Sent only to `https://{subdomain}.zendesk.com`; on attachment downloads only when the URL host equals that host | `ZendeskClient::download` |
| Upstream error bodies are never echoed; only status, `Retry-After` and allow-listed field names survive | `ZendeskApiException`, `ZendeskClient::validationFields` |
| Use a dedicated least-privilege agent | [credential-setup](credential-setup.md) |

## Outbound requests (SSRF)

`UrlPolicy` runs before any socket is opened:

1. **API calls** — scheme `https`, port 443, no userinfo or fragment, host exactly `{label}.zendesk.com`, path restricted to `^/[a-z0-9_/]+$` (no dots, queries or traversal).
2. **Attachments** — the host must match `attachments.allowed_hosts`; **every redirect hop** (max 3, `https` only) is re-validated; redirects are never followed automatically.
3. **DNS pinning** — the host is resolved, every A/AAAA record must be public (private and reserved ranges are refused), and the connection is pinned to those addresses with `CURLOPT_RESOLVE`, removing the time-of-check/time-of-use gap. `http.resolve_dns=false` exists for faked HTTP in tests; never set it in production.
4. **Bounded responses** — JSON capped at `http.max_json_bytes`, attachments at the configured size, both streamed and checked incrementally, so a hostile `Content-Length` cannot exhaust memory.

Only `GET` is ever issued.

## Attachments

Attachments are customer-supplied files, the highest-risk input the package touches.

- Only `pdf`, `docx`, `txt`, `md`, `markdown` (and image types when `kb.ocr.enabled`) are downloaded; everything else is skipped and counted in `attachments_skipped`.
- Skipped without downloading: `malware_scan_result = malware_found`, `deleted = true`, over-size, over the per-ticket limit, inline images.
- After download the **real bytes** are checked with `finfo`: the detected MIME must match the extension, and the declared `content_type` must agree. A `.docx` must be a ZIP with `[Content_Types].xml` and `word/document.xml`. A mismatch aborts the scan with a clear message instead of ingesting a disguised file.
- The file name is reduced to its basename with control characters removed; it is never used in a path (the stored path uses the numeric attachment id).
- Signed or tokenised URLs are never persisted in metadata, documents, checkpoints or tool results.
- Binary attachments are **not** PII-redacted (a regex cannot inspect a PDF or an image). If that matters to you, disable `attachments.enabled`.

## PII

- Every `text/markdown` and `text/plain` body is passed through `ConnectorIngestionContract::redactContent()` **before** it is written to the KB disk.
- Emails and names are kept out of document metadata and out of the reported ACL.
- Live tool results contain what the Zendesk record contains and are **not** redacted by this package; apply your host's output policy. Lookup hashes (`term_hash`, `email_hash`) are used instead of echoing search terms.
- Internal notes can be excluded (`include_internal_notes`). Turning it off triggers a re-import so previously indexed private text is replaced.

## Provenance

`provenanceTier()` returns, per installation, the stored `provenance_tier` setting: `untrusted-external` by default (customers write tickets), `trusted-internal` if the operator says the installation carries staff-authored content only. Unknown or malformed stored values fail **closed** to `untrusted-external`. The method performs no network call and is safe on the ingestion path. The agent usage profile additionally instructs the orchestrator to treat ticket and comment text as data, never as instructions.

## Source access reporting

`readAcl($remoteId)` and the `_source_access` metadata key report, never decide:

| Object | Reported |
|---|---|
| Ticket, customer, organization | group `zendesk:agents` |
| Attachment | inherits from its ticket |
| Article, no user segment | `anyone` |
| Article with segment | group `zendesk:user_segment:{id}` |

The requester is **not** reported as a reader of their ticket: the indexed document contains internal notes the requester can never see. If Zendesk cannot answer (5xx, malformed), `SourceAccess::unknown()` is returned — never an empty allow-list, because "nobody" and "we could not find out" must stay distinguishable. 401/403 are raised as `ConnectorAuthException`, never swallowed.

## Tenant isolation

Every installation lookup filters on `tenant_id` and connector name; `SyncManager::client()` refuses a mismatch; the live-tool catalog is built from the current tenant only; the sync lock and document paths are tenant-scoped; `ProcessSyncBatch` restores the previous tenant context in a `finally`. Tests cover cross-tenant execution, listing and ACL reads.

## Reporting a vulnerability

Please do not open a public issue for security problems. Contact Padosoft through <https://padosoft.com/> or the repository's private security advisory form.
