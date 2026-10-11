# Data model

## Tables

Both tables are created by `database/migrations/2026_10_11_000001_create_zendesk_sync_tables.php` and loaded automatically. Deleting an installation cascades to them.

### `zendesk_sync_runs`

| Column | Type | Meaning |
|---|---|---|
| `id` | bigint | Scan id |
| `tenant_id` | string, indexed | Tenant that owns the installation (R30) |
| `installation_id` | FK → `connector_installations`, cascade | Installation scanned |
| `mode` | string | `window` or `history` |
| `status` | string | `queued`, `running`, `failed`, `completed`, `cancelled` |
| `checkpoint` | json | Phase machine state — see [sync-semantics](sync-semantics.md#checkpoint-anatomy) |
| `counts` | json | `documents_added`, `documents_updated`, `documents_removed`, per-kind counters (`tickets`, `users`, …), `attachments_skipped` |
| `error` | text, null | Sanitised failure message, never an upstream body |
| `scan_started_at` | timestamp | Becomes the installation's `last_sync_at` on completion |
| `finished_at` | timestamp, null | Completion time |

Index: `(tenant_id, installation_id, status)`.

### `zendesk_source_states`

| Column | Type | Meaning |
|---|---|---|
| `tenant_id`, `installation_id` | — | Scope |
| `kind` | string(20) | `ticket`, `attachment`, `user`, `organization`, `article` |
| `remote_id` | string(100) | Zendesk numeric id (digits only, validated) |
| `parent_id` | string(100), null | For attachments, the ticket id |
| `fingerprint` | string(64) | SHA-256 of body, fields and reported ACL |
| `relative_path` | text | Where the document was written |
| `last_seen_run_id` | bigint | Last scan that met this object — drives the existence checks |

Unique: `(tenant_id, installation_id, kind, remote_id)` named `zendesk_source_identity`. Index `(tenant_id, installation_id, kind, parent_id)` serves attachment cleanup.

## Document layout

```
{project}/connectors/zendesk/tenant-{sha256(tenant_id)}/installation-{installation_id}/{kind}/{remote_id}.{ext}
```

`ext` is `md` for generated documents and the real extension (`pdf`, `docx`, `txt`, `md`, `markdown`, or an image type with OCR) for attachments. `project` is the installation's `project_key`, or the legacy `config_json.project_key`, or `kb.ingest.default_project`, or `default`; it must match `^[a-zA-Z0-9_-]+$`.

## Rendered documents

A ticket:

```markdown
# Printer on fire

- **Ticket:** #4821
- **Status:** open
- **Priority:** high
- **Requester:** Anne Richard (#10)
- **Assignee:** Sam Agent (#11)
- **Organization:** Acme Corp
- **Channel:** email
- **Tags:** urgent
- **Created:** 2026-10-01T10:00:00Z
- **Updated:** 2026-10-02T10:00:00Z

## Comment · 2026-10-01T10:00:00Z · Anne Richard (#10)

My printer is on fire …

## Internal note · 2026-10-01T11:00:00Z · Sam Agent (#11)

Escalate to facilities …
```

The first comment of a Zendesk ticket *is* its description, so the description is only rendered separately when a ticket has no comments. Customers, organizations and articles follow the same `# Title` + facts + sections pattern.

## Metadata

Every dispatched document carries:

| Key | Content |
|---|---|
| `connector` | `zendesk` |
| `installation_id` | Installation id |
| `zendesk_remote_id` | `{installation}:{kind}:{id}` — the deletion key and the `readAcl()` input |
| `remote_id`, `source_kind` | Raw id and kind |
| `source_url` | Agent-interface URL (`/agent/tickets/{id}`, `/agent/users/{id}`, `/agent/organizations/{id}`) or `/hc/articles/{id}` for an article (never the article's `html_url`, whose title-derived slug can carry personal data the metadata envelope would keep) |
| `converter_hints.zendesk` | Vendor fields: status, priority, type, requester/assignee/organization/group ids, channel, tags, timestamps, `internal_notes_included`, article locale/section/segment/permission group, … |
| `converter_hints._derived` | `search_tags`, `status_active` (open ticket / non-suspended customer / non-outdated article), `recency_bucket`, `owner` (always null — personal emails are not copied to metadata) |
| `_source_access` | The reported ACL — see [security](security.md#source-access-reporting) |

Names and emails of people, and attachment file names, appear only in document bodies and titles (subject to the host's redaction), never in the metadata.

## Remote identifiers

`Support\RemoteId` is the single place the identifier format lives. `RemoteId::parse()` accepts only `^[1-9][0-9]*:(ticket|attachment|article|user|organization):[1-9][0-9]*$` and throws on anything else, so a hostile or malformed string can never reach a URL path.
