# Zendesk API notes

Every endpoint the package calls, with the evidence behind its shape. All requests are `GET`, relative to `https://{subdomain}.zendesk.com/api/v2`, authenticated per installation.

## Verification status — please read

During development the endpoint shapes were checked against Zendesk's published API reference (indexed through Context7, 11 October 2026). The package has **not** been run against a live Zendesk account: every test uses simulated HTTP. The table separates what was confirmed in that reference from what is implemented from the documented API design but whose exact response keys were not re-read. Validate the second group on a sandbox before relying on it.

| Area | Status |
|---|---|
| Auth: Basic `{email}/token:{token}`, Bearer, token location in Admin Center | **Confirmed** |
| Cursor pagination: `page[size]` (max 100), `page[after]`, `meta.has_more` / `meta.after_cursor` | **Confirmed** (search export and Help Center introduction; offset pagination used when `page[size]` is absent) |
| `GET /tickets/{id}/comments`: cursor/offset pagination, 100 max, fields `body`, `public`, `author_id`, `created_at`, `attachments` | **Confirmed** |
| Attachment object: `file_name`, `content_type`, `content_url`, `size`, `inline`, `deleted`, `malware_scan_result` (`malware_found`, `malware_not_found`, `failed_to_scan`, `not_scanned`); `content_url` may be hosted externally — do not send credentials | **Confirmed** |
| `GET /search`: `type:ticket status:open` syntax, `sort_by`, `sort_order`, offset pagination only, ≤ 1 000 results, ≤ 100 per page, own rate limit, indexing delay | **Confirmed** |
| Rate limits: `429` + `Retry-After`; incremental exports 10/min (30 with High Volume); cursor-based user export 20/min (60 High Volume) | **Confirmed** |
| Help Center article object: `draft`, `locale`, `user_segment_id`, `permission_group_id`, `author_id`; list/show/search endpoints; Help Center cursor pagination via `page[size]` | **Confirmed** (field list); `html_url`, `section_id`, `label_names`, `edited_at`, `outdated` used as documented but not re-read |
| `GET /incremental/tickets/cursor?start_time=` → `tickets`, `after_cursor`, `end_of_stream`; deleted tickets present with `status: "deleted"` | **Implemented from the documented design; response keys not re-read** |
| `GET /incremental/users/cursor` → `users`, `after_cursor`, `end_of_stream`; deleted users `active: false` | **Implemented from the documented design; not re-read** |
| `GET /incremental/organizations?start_time=` → `organizations`, `end_time`, `end_of_stream` | **Implemented from the documented design; not re-read** |
| `GET /users/me` returns `200` with a null identity for anonymous callers | **Implemented from experience; not re-read** (the check is harmless if wrong: it only adds a stricter test) |
| `GET /users/search?query=`, `/organizations/autocomplete?name=`, `/users/show_many?ids=`, `/organizations/show_many?ids=`, `/users/{id}/tickets/requested`, `/organizations/{id}/tickets` | **Implemented from the documented design; not re-read** |
| `per_page` on the cursor-based user export (the package requests 100); `user_segment_ids` (plural) on Help Center articles | **Implemented from the documented design; not re-read** |
| `include=users,organizations` side-loads on ticket show; `include=users` on comments, list tickets | **Implemented from the documented design; not re-read** |

Each "not re-read" item is isolated in one place (`SyncManager` step methods or `ZendeskTools::request()`), so correcting a key after a live test is a one-line change, and the tests that pin it (`SyncTest`, `ToolsTest`) show exactly what to update.

## Endpoints used by the importer

| Purpose | Request |
|---|---|
| Credential check, health | `GET /users/me` |
| Ticket stream | `GET /incremental/tickets/cursor?start_time={epoch}` then `?cursor={after_cursor}` |
| Ticket detail | `GET /tickets/{id}?include=users,organizations` |
| Comments | `GET /tickets/{id}/comments?include=users&page[size]=100[&page[after]=…]` |
| Attachment bytes | `GET {comment.attachments[].content_url}` (allow-listed, redirect-validated) |
| Customer stream | `GET /incremental/users/cursor?start_time={epoch}` then `?cursor=…` |
| Organization stream | `GET /incremental/organizations?start_time={epoch}` |
| Organization name for a profile | `GET /organizations/{id}` (cached per run) |
| Articles | `GET /help_center/articles` or `GET /help_center/{locale}/articles`, `page[size]=100` |
| Article existence check | `GET /help_center/articles/{id}` |
| History existence checks | `GET /users/show_many?ids=…`, `GET /organizations/show_many?ids=…` |

## Endpoints used by the live tools

See [live-tools.md](live-tools.md). In addition to the above: `/users/{id}/tickets/requested`, `/organizations/{id}/tickets`, `/tickets`, `/search`, `/users/search`, `/organizations/autocomplete`, `/users/{id}`, `/organizations/{id}`, `/help_center/articles/search`.

## Behaviours the design relies on

- **Ticket export scope.** Codex review cited a `support_type_scope` parameter (`agent` by default, `all` to include AI-agent tickets) on the cursor ticket export. It could not be confirmed in the reference pages available while building this package, so it is **opt-in** (`sync.ticket_support_type_scope` / `CONNECTOR_ZENDESK_TICKET_SCOPE`) and unset by default rather than sent blindly to every account. Verify it on a sandbox before enabling. The scope in force is stored in each scan's checkpoint: the first scan after it changes restarts the ticket window (`date_window_days`) instead of resuming near the previous scan, so tickets of the newly included kind inside the window are exported; for older ones run *Fetch all*. Narrowing or replacing the scope does not retire tickets that fall outside it (there is no ticket reconciliation phase); remove those through the KB project if they must disappear.
- **Export freshness.** A `start_time` too close to now is rejected by Zendesk, hence `sync.export_min_age_seconds`.
- **Archived tickets.** Tickets are archived after a retention period and are not enumerable from list or search endpoints (only by id); a ticket `404` is therefore never treated as deletion.
- **Search lag.** New or changed records can take minutes to appear in search results; the live tools declare `search_index_may_lag_by_minutes`.
- **Impersonation.** An API token acts as its agent; what the agent cannot see, the connector cannot import.

## Reference

Official documentation: <https://developer.zendesk.com/api-reference/>.
