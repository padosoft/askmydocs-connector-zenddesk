# Live tools

Eleven read-only tools per active installation, named `zendesk_{installationId}_{action}`. The catalog is `ZendeskTools::catalog(?string $projectKey)`; calls go through `ZendeskTools::execute($name, $arguments, $projectKey, ?Closure $beforeRequest)`.

## Availability rules

- `chat_tools.enabled` must be true.
- The installation must belong to the **current tenant**, have status `active`, and be bound (project column, legacy `config_json.project_key`, or the host default) to the **project passed in**. A `null` or empty project returns an empty catalog.
- `execute()` re-resolves the tool from the catalog on every call and throws `RuntimeException` if it is gone.
- `$beforeRequest` is invoked before every physical request so a host can enforce a request budget; it may throw to interrupt.

## Argument rules

Every tool schema is `additionalProperties: false`. Before any HTTP call the executor rejects: unknown keys, missing required keys, non-integer or `< 1` integer values, empty or over-long strings, and invalid cursors. Tool-specific checks return a structured `invalid_query` error with `physical_request_count: 0` (no request is made):

| Tool | Extra rule |
|---|---|
| `list_tickets` | at most one of `requester_id`, `organization_id`, `email` |
| `search_tickets` | free text plus Zendesk filters; no `type:` operator (it is forced to `ticket`), max 512 chars, balanced quotes, no control chars |
| `search_users`, `search_agents`, `search_organizations`, `search_articles` | `term` non-empty, ≤ 255 chars, no control chars |
| any search | `page` ≤ 10 (Zendesk search returns at most 1 000 results) |
| `cursor` | only the URL-safe alphabet Zendesk issues, ≤ 2 048 chars |

## Tools

### `list_tickets`
`requester_id` → `GET /users/{id}/tickets/requested`; `organization_id` → `GET /organizations/{id}/tickets`; `email` → search `type:ticket requester:"{email}"`; none → `GET /tickets`. Cursor pagination (`page[size]`, `page[after]`) except the email form, which pages through search. Sorted by update time, newest first, all statuses.

### `search_tickets`
`GET /search?query=type:ticket {query}` sorted by `updated_at desc`. Example queries: `status:open priority>normal`, `assignee_id:123 tags:vip`, `created>2026-01-01 printer`.

### `search_users` / `get_user`
`GET /users/search?query={term}` (matches name and email; **only end users are returned**, agents and admins are dropped so an agent can never be mistaken for a requester — use `search_agents` for them) / `GET /users/{id}` (any role, for resolving assignees and requesters by id).

### `search_agents`
`GET /search?query=type:user role:agent {term}`. Use only when the user explicitly asks for tickets *assigned to* an agent; then call `search_tickets` with `assignee_id:{verified id}`.

### `search_organizations` / `get_organization`
`GET /organizations/autocomplete?name={term}` (first matches only) / `GET /organizations/{id}`.

### `get_ticket`
`GET /tickets/{id}?include=users`. A ticket with status `deleted` is not returned.

### `list_comments`
`GET /tickets/{id}/comments?include=users`, cursor-paged, oldest first. Internal notes (`public: false`) are dropped when `include_internal_notes` is off. Attachment entries carry `id`, `file_name`, `content_type`, `size` only.

### `search_articles` / `get_article`
`GET /help_center/articles/search?query={term}` / `GET /help_center/articles/{id}`. Drafts are filtered out.

## Result shape

```json
{
  "data": {
    "records": [{ "id": 4821, "kind": "ticket", "subject": "…", "status": "open", "requester_id": 10,
                  "body": "…", "attachments": [], "url": "https://acme.zendesk.com/agent/tickets/4821" }],
    "page": 1,
    "has_more": true,
    "next_cursor": "opaque-or-null",
    "truncated": false,
    "total": 120,
    "meta": { "ambiguous": false, "term_hash": null, "users": { "10": "Anne Richard" } },
    "coverage": { "scope": "api_enumerable", "complete": false, "sorted_by": "updated_at_desc",
                  "requester_id": 10, "organization_id": null, "email_hash": null, "filter": null,
                  "limitations": ["archived_tickets_may_be_excluded"] }
  },
  "physical_request_count": 1,
  "provenance": { "source": "zendesk", "installation_id": 42 }
}
```

- **Allow-listed fields only.** Each record type exposes a fixed field list; unknown upstream fields (including custom fields, tokens or signed URLs) are never forwarded.
- **`url`** is always built from the account host, except for an article's `html_url`, which is used only when its host is the account's own.
- **`meta.users`** maps ids to display names when Zendesk side-loaded them (never emails).
- **`coverage.email_hash` / `meta.term_hash`** are SHA-256 hashes so a client can correlate requests without storing the address or name.
- **Truncation.** Bodies are cut at 16 000 characters (`body_truncated: true` on the record). Records are added one at a time while the JSON stays under `chat_tools.max_result_bytes`; if the cap stops the list, `truncated` is true and `coverage.complete` false.

## Errors

```json
{ "error": "Zendesk rate limit reached. The import will resume after Retry-After.",
  "error_code": "rate_limited", "http_status": 429, "retryable": true,
  "validation_fields": [], "physical_request_count": 1,
  "provenance": { "source": "zendesk", "installation_id": 42 } }
```

| `error_code` | Typical status | `retryable` |
|---|---|---|
| `invalid_query` | 400, 422, or local validation | no |
| `authentication_failed` | 401 | no |
| `permission_denied` | 403 | no |
| `resource_not_found` | 404 | no |
| `rate_limited` | 429 | yes — wait; the executor never blocks |
| `request_failed` | 5xx and everything else | 5xx yes |

An error is *never* represented as an empty record list.
