# Troubleshooting

## Reading the state

```php
use Padosoft\AskMyDocsConnectorZendesk\Sync\SyncManager;

$run = app(SyncManager::class)->latest($installation);
$run->summary();   // id, mode, status, counts, error, phase
$run->checkpoint;  // phase, cursor, pending, pages, …
```

or directly: `SELECT id, mode, status, error, checkpoint FROM zendesk_sync_runs WHERE installation_id = ? ORDER BY id DESC LIMIT 5;`

## Symptoms

| Symptom | Likely cause | Fix |
|---|---|---|
| *Enter the account subdomain…* | The value is not a single label (`a.b`, a path, a custom domain) | Use `company` / `company.zendesk.com` |
| *Zendesk did not recognise the credentials* | Wrong token or email, **Token access** off, wrong subdomain | Re-create the token; check the admin email |
| *Use an admin account…* | Token paired with a non-admin email (incremental exports are admin-only) | Use an admin |
| Health shows `Zendesk rejected the credentials` | Token deleted, rotated, or OAuth token expired | Re-enter credentials |
| `permission_denied` for users or articles | The agent's role cannot view them | Adjust the role, or disable that content type |
| Scan stuck on `queued` | No worker for queue `zendesk`; or queue driver is `sync` | `php artisan queue:work zendesk --queue=zendesk --timeout=600 --tries=3` |
| *Zendesk imports require an asynchronous queue connection* | `QUEUE_CONNECTION=sync` | Use `database` or `redis` |
| Batches keep being deferred 30 s | Another batch holds the installation lock, or the cache store has no atomic locks | Use Redis; wait for the running batch |
| Frequent `rate_limited` | Account-wide traffic or the incremental export limit | Nothing to do; scans resume after `Retry-After` |
| *export cursor did not advance* | Zendesk repeated a cursor without `end_of_stream` | Start the scan again; if it repeats, report the run's `checkpoint` |
| *organization export cannot advance at the timestamp boundary* | More than a page of organizations share one `end_time` | Report it; the scan stays resumable |
| *attachment host is not allowed* | The file is served from a host not in `attachments.allowed_hosts` | Add the exact CDN host after confirming it is Zendesk's |
| *attachment MIME does not match its file type* | A renamed or corrupt file | Expected protection; the scan stops so you notice. Remove the file in Zendesk or disable attachments |
| No `zendesk_*` tools in the chat | The installation has not enabled *Expose live Zendesk tools to the chat* (off by default), or `chat_tools.enabled` is false, or the installation is bound to another project | Enable the setting after reading the trust note in [live-tools](live-tools.md) |
| `attachments_skipped` is high | Unsupported type, over-size, a malware verdict other than `malware_not_found` (including `not_scanned` / `failed_to_scan`, which are retried when the ticket is next imported), per-ticket limit | Review `attachments.*` (`allow_unscanned` is the fail-open opt-in); images need `kb.ocr.enabled` |
| Old tickets are missing | Outside `date_window_days` | Widen the window, or **Fetch all** |
| A ticket I deleted is still searchable | The deletion appears in the next export after the deletion is committed | Run **Sync now** |
| Internal notes still searchable | Re-import happens on the next scan | **Sync now**; check `internal_notes_included` in the metadata |
| Live search misses a brand-new ticket | Search index lag | Use `get_ticket`, or retry in a few minutes |
| `search_result_cap_1000` | More than 1 000 matches | Narrow the query (status, dates, assignee) |
| `meta.ambiguous = true` | Partial or duplicate name | Ask the user to choose; never guess an id |

## Gathering a report

Include: package version, PHP/Laravel versions, the failing `SyncRun` row (`status`, `error`, `checkpoint` with cursors truncated), and the relevant `laravel.log` lines. **Never** paste tokens, ticket text or signed URLs.
