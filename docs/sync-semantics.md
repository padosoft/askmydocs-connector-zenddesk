# Sync semantics

## Vocabulary

- **Scan** — one `SyncRun` row: mode, status (`queued`, `running`, `failed`, `completed`, `cancelled`), checkpoint, counters.
- **Batch** — one `ProcessSyncBatch` job: up to `sync.batch_items` steps or `sync.batch_seconds` seconds.
- **Step** — one unit of work inside a phase.
- **Source state** — a `zendesk_source_states` row remembering the fingerprint last delivered for one remote object.

## Starting a scan

`SyncManager::start($installation, history: false)`:

1. refuses an installation that is not `active` or `errored`, and refuses the `sync` queue driver;
2. inside a transaction that locks the installation row, **reuses** any scan that is queued, running or failed (a failed one is re-queued) — so two operators clicking *Sync now* never create two scans, and *Fetch all* only reuses a *history* scan;
3. otherwise creates a scan whose export start is
   - ordinary: `now - date_window_days`, moved forward to the previous completed scan's start minus `sync.overlap_seconds` when the window has not grown;
   - history: the Unix epoch;
   and never later than `now - max(60, sync.export_min_age_seconds)` because Zendesk rejects an export `start_time` that is too recent;
4. dispatches the first `ProcessSyncBatch`.

The installation's `last_sync_at` advances **only when a scan completes**, to the scan's *start* time. A failed or cancelled scan never moves the watermark, so nothing is skipped on the next run.

## Phases

### `tickets`

Reads `GET /incremental/tickets/cursor` — first with `start_time`, then with the returned `after_cursor` — until `end_of_stream`. Each exported ticket becomes a pending item; items are then processed one per step:

- status `deleted` → remove the ticket document and its attachments;
- otherwise `GET /tickets/{id}?include=users,organizations` and every page of `GET /tickets/{id}/comments?include=users` (cursor pagination, 100 per page), then render and deliver. A `404` between export and detail is ignored: if the ticket was really deleted the stream reports it.

### `users` (if `customers.enabled`)

Cursor-based incremental user export. **End users** become profile documents. Anyone else, or a user reported inactive (Zendesk's representation of a deleted user), has their existing profile document removed.

### `organizations` (if `organizations.enabled`)

Time-based incremental organization export, following `end_time` until `end_of_stream` or a short page. Each organization becomes a profile document. A boundary that cannot advance raises instead of looping.

### `articles` and `article_check` (if `help_center.enabled`)

`articles` walks `GET /help_center/articles` (or the locale-scoped path when `help_center.locale` is set) with cursor pagination. Drafts delete their existing document. `article_check` then visits every known article the walk did **not** meet in this scan and asks Zendesk for its detail: success re-imports it, `404` confirms deletion, anything else is an error.

### `customer_check` (history scans only)

Known customers and organizations not seen by the full export are looked up with `GET /users/show_many` and `GET /organizations/show_many` in groups of 100. Zendesk omits unknown ids, which is the deletion evidence. A user who is now an agent is removed too.

## Checkpoint anatomy

```json
{
  "phase": "tickets",
  "since": 1760000000,
  "initial_since": 1760000000,
  "cursor": "…opaque…",
  "pending": [{"id": 4821, "status": "open"}],
  "stream_done": false,
  "pages": 3,
  "window_days": 90,
  "projection_hash": "…sha256…",
  "refresh_tickets": false,
  "refresh_cursor": 0
}
```

Never edit a checkpoint by hand. To start over, cancel the scan (disconnect/pause) and start a new one.

## Idempotency and change detection

`fingerprint = sha256(body ⏐ fields ⏐ reported ACL)`. Equal fingerprint → only `last_seen_run_id` is touched and nothing is delivered. The host also upserts by path, so a retry between "delivered" and "checkpoint saved" cannot create a duplicate.

## Settings that change what a ticket contains

`include_internal_notes`, the `attachments` options and the host's `kb.ocr.enabled` are hashed into `projection_hash`. When the hash differs from the previous completed scan, the next scan begins with a `refresh` pass that re-imports every known ticket (one per step) before the normal phases. This is how switching internal notes off removes already-indexed private text, and its attachments, from the KB.

## Failure handling

| Event | Behaviour |
|---|---|
| `429` | Status stays `queued`; the batch is re-queued after `Retry-After` seconds (60 when absent); checkpoint untouched |
| `5xx`, connection error | Retried up to `http.attempts`; then the scan becomes `failed`, the installation `errored`, the exception rethrown |
| `401`/`403` | Not retried; scan `failed`, installation `errored`; fix the credential/role and start again to resume |
| Worker killed by timeout | `ProcessSyncBatch::failed()` marks the scan `failed` with a fixed message — no exception text is stored |
| Installation paused/disabled mid-batch | Scan becomes `cancelled` at the next step; watermark unchanged |
| Second batch for one installation | Deferred 30 s by an atomic cache lock |
| Export cursor repeats | Scan fails loudly: *cursor did not advance* |

Restarting after any failure **resumes** the same scan from its checkpoint.

## What is never deleted

- Tickets that merely fall outside the ordinary window.
- Anything on a `404` of a ticket (archived tickets answer 404).
- Articles absent from a list but not confirmed gone.
- Documents of a content type the operator later disabled.
