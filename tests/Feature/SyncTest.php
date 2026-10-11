<?php

namespace Padosoft\AskMyDocsConnectorZendesk\Tests\Feature;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Padosoft\AskMyDocsConnectorBase\Access\SourceAccess;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use Padosoft\AskMyDocsConnectorZendesk\Exceptions\ZendeskApiException;
use Padosoft\AskMyDocsConnectorZendesk\Sync\ProcessSyncBatch;
use Padosoft\AskMyDocsConnectorZendesk\Sync\SourceState;
use Padosoft\AskMyDocsConnectorZendesk\Sync\SyncManager;
use Padosoft\AskMyDocsConnectorZendesk\Tests\TestCase;

final class SyncTest extends TestCase
{
    use FakesZendesk;

    public function test_full_scan_imports_every_kind_once_and_is_idempotent(): void
    {
        $this->zendesk();
        $installation = $this->authorized();
        $run = app(SyncManager::class)->start($installation);
        $this->assertSame(90, $run->checkpoint['window_days']);
        $this->assertNull($installation->fresh()->last_sync_at);
        $this->finish($run);

        $this->assertEqualsCanonicalizing(['ticket', 'user', 'organization', 'article'], $this->ingestion->kinds());
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertNotNull($installation->fresh()->last_sync_at);
        $this->assertSame('support', $this->ingestion->documents[0]['projectKey']);

        $ticket = collect($this->ingestion->documents)->firstWhere('metadata.source_kind', 'ticket');
        $body = Storage::disk('local')->get($ticket['relativePath']);
        $this->assertStringContainsString('Internal note', $body);
        $this->assertStringContainsString('Public reply: unplug it', $body);
        $this->assertStringContainsString('[redacted]', $body);
        $this->assertStringNotContainsString('sensitive@example.com', $body);
        $this->assertStringContainsString('Anne Richard (#10)', $body);
        $this->assertStringContainsString('Acme Corp', $body);
        $this->assertSame($installation->id.':ticket:1', $ticket['metadata']['zendesk_remote_id']);
        $this->assertSame('https://example.zendesk.com/agent/tickets/1', $ticket['metadata']['source_url']);

        $count = count($this->ingestion->documents);
        $this->finish(app(SyncManager::class)->start($installation));
        $this->assertCount($count, $this->ingestion->documents, 'unchanged content must not be delivered again');
    }

    public function test_only_end_users_become_customer_documents_and_drafts_are_never_imported(): void
    {
        $this->zendesk();
        $this->finish(app(SyncManager::class)->start($this->authorized()));
        $titles = array_column($this->ingestion->documents, 'title');
        $this->assertContains('Anne Richard', $titles);
        $this->assertNotContains('Sam Agent', $titles);
        $this->assertNotContains('Unreleased draft', $titles);
    }

    public function test_every_document_carries_a_reported_source_acl(): void
    {
        $this->zendesk();
        $this->finish(app(SyncManager::class)->start($this->authorized()));
        foreach ($this->ingestion->documents as $document) {
            $this->assertNotNull(SourceAccess::fromArray($document['metadata'][SourceAccess::METADATA_KEY] ?? null), $document['title']);
        }
        $article = collect($this->ingestion->documents)->firstWhere('metadata.source_kind', 'article');
        $this->assertTrue(SourceAccess::fromArray($article['metadata'][SourceAccess::METADATA_KEY])->isPublic());
        $ticket = collect($this->ingestion->documents)->firstWhere('metadata.source_kind', 'ticket');
        $this->assertFalse(SourceAccess::fromArray($ticket['metadata'][SourceAccess::METADATA_KEY])->isPublic());
    }

    public function test_internal_notes_can_be_excluded(): void
    {
        $this->zendesk();
        $this->finish(app(SyncManager::class)->start($this->authorized(['include_internal_notes' => false])));
        $ticket = collect($this->ingestion->documents)->firstWhere('metadata.source_kind', 'ticket');
        $body = Storage::disk('local')->get($ticket['relativePath']);
        $this->assertStringNotContainsString('escalate to facilities', $body);
        $this->assertStringContainsString('Public reply', $body);
    }

    public function test_customers_organizations_and_help_center_can_be_switched_off(): void
    {
        $this->zendesk();
        $this->finish(app(SyncManager::class)->start($this->authorized(['customers' => ['enabled' => false], 'organizations' => ['enabled' => false], 'help_center' => ['enabled' => false]])));
        $this->assertSame(['ticket'], $this->ingestion->kinds());
    }

    public function test_changing_a_content_setting_refreshes_already_imported_tickets(): void
    {
        $this->zendesk();
        $installation = $this->authorized();
        $this->finish(app(SyncManager::class)->start($installation));
        $installation->update(['config_json' => ['connection' => $installation->config_json['connection'], 'include_internal_notes' => false]]);
        $this->finish(app(SyncManager::class)->start($installation));
        $path = SourceState::where('kind', 'ticket')->firstOrFail()->relative_path;
        $this->assertStringNotContainsString('escalate to facilities', Storage::disk('local')->get($path));
    }

    public function test_a_deleted_ticket_in_the_stream_removes_its_documents_and_attachments(): void
    {
        $this->zendesk();
        $installation = $this->authorized();
        $this->finish(app(SyncManager::class)->start($installation));
        $this->zendesk(['/incremental/tickets/cursor' => ['tickets' => [['id' => 1, 'status' => 'deleted']], 'after_cursor' => 'cur-2', 'end_of_stream' => true]]);
        $this->finish(app(SyncManager::class)->start($installation));
        $this->assertContains(['tenant-a', $installation->id.':ticket:1'], $this->ingestion->deleted);
        $this->assertSame(0, SourceState::where('kind', 'ticket')->count());
    }

    public function test_a_ticket_404_is_not_deletion_evidence(): void
    {
        $this->zendesk();
        $installation = $this->authorized();
        $this->finish(app(SyncManager::class)->start($installation));
        $this->zendesk(['/tickets/1' => 404]);
        $this->finish(app(SyncManager::class)->start($installation));
        $this->assertSame([], array_filter($this->ingestion->deleted, fn ($row) => str_contains($row[1], ':ticket:')));
        $this->assertSame(1, SourceState::where('kind', 'ticket')->count());
    }

    public function test_article_detail_404_confirms_deletion_and_a_draft_removes_the_document(): void
    {
        $this->zendesk();
        $installation = $this->authorized();
        $this->finish(app(SyncManager::class)->start($installation));
        // Article 7 vanishes from the list; its detail answers 404.
        $this->zendesk(['/help_center/articles' => ['articles' => [], 'meta' => ['has_more' => false]], '/help_center/articles/7' => 404]);
        $this->finish(app(SyncManager::class)->start($installation));
        $this->assertContains(['tenant-a', $installation->id.':article:7'], $this->ingestion->deleted);
        $this->assertSame(0, SourceState::where('kind', 'article')->count());
    }

    public function test_absence_from_a_list_alone_does_not_delete_an_article(): void
    {
        $this->zendesk();
        $installation = $this->authorized();
        $this->finish(app(SyncManager::class)->start($installation));
        $this->zendesk(['/help_center/articles' => ['articles' => [], 'meta' => ['has_more' => false]]]);
        $this->finish(app(SyncManager::class)->start($installation));
        $this->assertSame([], array_filter($this->ingestion->deleted, fn ($row) => str_contains($row[1], ':article:')));
    }

    public function test_history_mode_deletes_customers_the_full_export_no_longer_returns(): void
    {
        $this->zendesk();
        $installation = $this->authorized();
        $this->finish(app(SyncManager::class)->start($installation, true));
        $this->assertSame(1, SourceState::where('kind', 'user')->count());
        $this->zendesk(['/incremental/users/cursor' => ['users' => [], 'after_cursor' => 'u2', 'end_of_stream' => true], '/users/show_many' => ['users' => []],
            '/incremental/organizations' => ['organizations' => [], 'end_time' => 1760000000, 'end_of_stream' => true], '/organizations/show_many' => ['organizations' => []]]);
        $this->finish(app(SyncManager::class)->start($installation, true));
        $this->assertContains(['tenant-a', $installation->id.':user:10'], $this->ingestion->deleted);
        $this->assertContains(['tenant-a', $installation->id.':organization:5'], $this->ingestion->deleted);
    }

    public function test_a_customer_promoted_to_agent_loses_its_profile_document(): void
    {
        $this->zendesk();
        $installation = $this->authorized();
        $this->finish(app(SyncManager::class)->start($installation));
        $this->zendesk(['/incremental/users/cursor' => ['users' => [['id' => 10, 'name' => 'Anne Richard', 'role' => 'agent']], 'after_cursor' => 'u2', 'end_of_stream' => true]]);
        $this->finish(app(SyncManager::class)->start($installation));
        $this->assertContains(['tenant-a', $installation->id.':user:10'], $this->ingestion->deleted);
    }

    public function test_a_deleted_inactive_user_loses_its_profile_document(): void
    {
        $this->zendesk();
        $installation = $this->authorized();
        $this->finish(app(SyncManager::class)->start($installation));
        $this->zendesk(['/incremental/users/cursor' => ['users' => [['id' => 10, 'name' => 'Anne Richard', 'role' => 'end-user', 'active' => false]], 'after_cursor' => 'u2', 'end_of_stream' => true]]);
        $this->finish(app(SyncManager::class)->start($installation));
        $this->assertContains(['tenant-a', $installation->id.':user:10'], $this->ingestion->deleted);
        $this->assertSame(0, SourceState::where('kind', 'user')->count());
    }

    private function withAttachment(string $url, string $name = 'info.txt', string $type = 'text/plain'): array
    {
        return ['/tickets/1/comments' => ['comments' => [['id' => 100, 'author_id' => 10, 'public' => true, 'created_at' => '2026-10-01T10:00:00Z', 'plain_body' => 'See file',
            'attachments' => [['id' => 55, 'file_name' => $name, 'content_type' => $type, 'size' => 11, 'malware_scan_result' => 'malware_not_found', 'content_url' => $url]]]], 'meta' => ['has_more' => false]]];
    }

    public function test_attachment_download_sends_credentials_only_to_the_account_host(): void
    {
        $this->zendesk($this->withAttachment('https://example.zendesk.com/attachments/token/abc/?name=info.txt'), function ($request) {
            return match (parse_url($request->url(), PHP_URL_HOST)) {
                'example.zendesk.com' => str_contains($request->url(), '/attachments/') ? Http::response('', 302, ['Location' => 'https://p1.zdusercontent.com/files/info.txt?signature=SECRET']) : null,
                'p1.zdusercontent.com' => Http::response('hello world', 200, ['Content-Type' => 'text/plain']),
                default => null,
            };
        });
        $this->finish(app(SyncManager::class)->start($this->authorized()));

        Http::assertSent(fn ($r) => str_contains($r->url(), '/attachments/token/abc') && $r->hasHeader('Authorization'));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'zdusercontent.com') && ! $r->hasHeader('Authorization'));
        $attachment = collect($this->ingestion->documents)->firstWhere('metadata.source_kind', 'attachment');
        $this->assertSame('hello world', Storage::disk('local')->get($attachment['relativePath']));
        $this->assertStringNotContainsString('SECRET', json_encode($this->ingestion->documents));
        $this->assertSame('inherited', $attachment['metadata']['_source_access']['inherits_from_parent'] ? 'inherited' : 'own');
    }

    public function test_attachment_with_a_disallowed_host_or_a_mismatched_type_is_refused(): void
    {
        $this->zendesk($this->withAttachment('https://evil.example/file.txt'));
        $run = app(SyncManager::class)->start($this->authorized());
        try {
            $this->finish($run);
            $this->fail('A foreign attachment host must stop the scan.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('attachment host is not allowed', $exception->getMessage());
        }
        $this->assertSame('failed', $run->fresh()->status);
    }

    public function test_attachment_bytes_must_match_the_declared_type(): void
    {
        $this->zendesk($this->withAttachment('https://example.zendesk.com/a/fake.pdf', 'fake.pdf', 'application/pdf'), fn ($r) => str_contains($r->url(), '/a/fake.pdf') ? Http::response('<html>not a pdf</html>') : null);
        $run = app(SyncManager::class)->start($this->authorized());
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MIME does not match');
        $this->finish($run);
    }

    public function test_flagged_deleted_or_oversized_attachments_are_skipped_not_downloaded(): void
    {
        $comments = ['/tickets/1/comments' => ['comments' => [['id' => 100, 'author_id' => 10, 'public' => true, 'created_at' => '2026-10-01T10:00:00Z', 'plain_body' => 'files', 'attachments' => [
            ['id' => 1, 'file_name' => 'a.txt', 'content_type' => 'text/plain', 'size' => 5, 'malware_scan_result' => 'malware_found', 'content_url' => 'https://example.zendesk.com/a1'],
            ['id' => 2, 'file_name' => 'b.exe', 'content_type' => 'application/octet-stream', 'size' => 5, 'content_url' => 'https://example.zendesk.com/a2'],
            ['id' => 3, 'file_name' => 'c.txt', 'content_type' => 'text/plain', 'size' => 999999999, 'content_url' => 'https://example.zendesk.com/a3'],
        ]]], 'meta' => ['has_more' => false]]];
        $this->zendesk($comments);
        $run = app(SyncManager::class)->start($this->authorized());
        $this->finish($run);
        Http::assertNotSent(fn ($r) => in_array(parse_url($r->url(), PHP_URL_PATH), ['/a1', '/a2', '/a3'], true));
        $this->assertSame(3, $run->fresh()->counts['attachments_skipped']);
    }

    public function test_rate_limit_defers_the_batch_by_retry_after_and_keeps_the_checkpoint(): void
    {
        $installation = $this->authorized();
        $run = app(SyncManager::class)->start($installation);
        Http::swap(new Factory);
        Http::fake(['*' => Http::response([], 429, ['Retry-After' => '34'])]);
        $this->assertSame(34, app(SyncManager::class)->batch($run->id));
        $this->assertSame('queued', $run->fresh()->status);
        $this->assertSame('tickets', $run->fresh()->checkpoint['phase']);
        $this->assertNull($run->fresh()->checkpoint['cursor']);
        Http::assertSentCount(1);
    }

    public function test_a_failed_scan_resumes_from_its_checkpoint_without_an_early_watermark(): void
    {
        $installation = $this->authorized();
        $run = app(SyncManager::class)->start($installation);
        $this->zendesk(['/incremental/tickets/cursor' => 503]);
        try {
            app(SyncManager::class)->batch($run->id);
            $this->fail('503 must surface.');
        } catch (ZendeskApiException $exception) {
            $this->assertSame(503, $exception->status);
        }
        $this->assertNull($installation->fresh()->last_sync_at);
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame('errored', $installation->fresh()->status);
        $this->zendesk();
        $resumed = app(SyncManager::class)->start($installation);
        $this->assertSame($run->id, $resumed->id);
        $this->finish($resumed);
        $this->assertSame('active', $installation->fresh()->status);
    }

    public function test_an_export_cursor_that_does_not_advance_fails_loudly(): void
    {
        $installation = $this->authorized();
        $run = app(SyncManager::class)->start($installation);
        $cp = $run->checkpoint;
        $cp['cursor'] = 'same';
        $run->checkpoint = $cp;
        $run->save();
        $this->zendesk(['/incremental/tickets/cursor' => ['tickets' => [], 'after_cursor' => 'same', 'end_of_stream' => false]]);
        $this->expectExceptionMessage('did not advance');
        app(SyncManager::class)->batch($run->id);
    }

    public function test_the_export_start_time_is_never_too_recent_and_history_starts_at_the_epoch(): void
    {
        $installation = $this->authorized(['date_window_days' => 45]);
        $manager = app(SyncManager::class);
        $history = $manager->start($installation, true);
        $this->assertSame(0, $history->checkpoint['since']);
        $this->assertSame($history->id, $manager->start($installation, true)->id);
        $this->assertSame(45, $installation->fresh()->config_json['date_window_days']);
        $this->assertLessThanOrEqual(now()->subSeconds(60)->getTimestamp(), $history->checkpoint['initial_since']);
    }

    public function test_a_second_batch_for_the_same_installation_is_deferred_by_the_lock(): void
    {
        $installation = $this->authorized();
        $run = app(SyncManager::class)->start($installation);
        $lock = Cache::lock('zendesk-sync:tenant-a:'.$installation->id, 660);
        $lock->get();
        $this->assertSame(30, app(SyncManager::class)->batch($run->id));
        $lock->release();
    }

    public function test_pausing_the_installation_mid_batch_cancels_the_continuation(): void
    {
        $installation = $this->authorized();
        $run = app(SyncManager::class)->start($installation);
        Http::swap(new Factory);
        Http::fake(function () use ($installation) {
            $installation->update(['status' => 'paused']);

            return Http::response(['tickets' => [], 'after_cursor' => 'x', 'end_of_stream' => false]);
        });
        $this->assertNull(app(SyncManager::class)->batch($run->id));
        $this->assertSame('cancelled', $run->fresh()->status);
        $this->assertNull($installation->fresh()->last_sync_at);
    }

    public function test_a_worker_timeout_keeps_the_checkpoint_and_never_persists_the_exception_body(): void
    {
        $installation = $this->authorized();
        $run = app(SyncManager::class)->start($installation, true);
        $checkpoint = $run->checkpoint;
        (new ProcessSyncBatch($run->id, 'tenant-a'))->failed(new \RuntimeException('https://x.zdusercontent.com/?signature=secret-token'));
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame($checkpoint, $run->fresh()->checkpoint);
        $this->assertStringNotContainsString('secret-token', (string) $run->fresh()->error);
        $this->assertSame('errored', $installation->fresh()->status);
        $this->assertSame($run->id, app(SyncManager::class)->start($installation, true)->id);
    }

    public function test_rebinding_the_installation_to_another_project_moves_every_known_object_even_when_exports_are_empty(): void
    {
        $this->zendesk();
        $installation = $this->authorized();
        $this->finish(app(SyncManager::class)->start($installation));
        $before = count($this->ingestion->documents);
        $installation->forceFill(['project_key' => 'other'])->save();

        // Real incremental exports resume near the watermark: nothing changed in Zendesk, so they return nothing.
        $empty = ['after_cursor' => 'x', 'end_of_stream' => true];
        $this->zendesk([
            '/incremental/tickets/cursor' => ['tickets' => []] + $empty,
            '/incremental/users/cursor' => ['users' => []] + $empty,
            '/incremental/organizations' => ['organizations' => [], 'end_time' => 1760000000, 'end_of_stream' => true],
        ]);
        $this->finish(app(SyncManager::class)->start($installation));

        $this->assertGreaterThan($before, count($this->ingestion->documents), 'unchanged objects must be delivered to the new project');
        foreach (['ticket', 'user', 'organization', 'article'] as $kind) {
            $document = collect($this->ingestion->documents)->where('metadata.source_kind', $kind)->last();
            $this->assertSame('other', $document['projectKey'], $kind);
            $this->assertStringStartsWith('other/connectors/zendesk/', $document['relativePath'], $kind);
            $this->assertContains(['tenant-a', $installation->id.':'.$kind.':'.$document['metadata']['remote_id']], $this->ingestion->deleted, $kind.': the previous copy is retired');
        }
        $this->assertSame(1, SourceState::where('kind', 'ticket')->count());
    }

    public function test_a_text_attachment_name_with_pii_reaches_neither_the_title_nor_the_metadata(): void
    {
        $url = 'https://example.zendesk.com/files/leak.txt';
        $this->zendesk($this->withAttachment($url, 'sensitive@example.com.txt'), fn ($request) => str_contains($request->url(), '/files/leak.txt') ? Http::response('hello', 200, ['Content-Type' => 'text/plain']) : null);
        $this->finish(app(SyncManager::class)->start($this->authorized()));
        $attachment = collect($this->ingestion->documents)->firstWhere('metadata.source_kind', 'attachment');
        $this->assertNotNull($attachment);
        $this->assertSame('[redacted].txt', $attachment['title']);
        $this->assertStringNotContainsString('sensitive@example.com', json_encode($attachment['metadata']));
    }

    public function test_the_user_export_is_requested_in_small_pages(): void
    {
        $this->zendesk();
        $this->finish(app(SyncManager::class)->start($this->authorized(), true));
        Http::assertSent(fn ($r) => str_contains($r->url(), '/incremental/users/cursor') && ($r['per_page'] ?? null) === 100);
    }

    public function test_an_article_outside_the_configured_locale_is_retired_not_reimported(): void
    {
        $this->zendesk();
        $installation = $this->authorized();
        $this->finish(app(SyncManager::class)->start($installation));
        $this->assertSame(1, SourceState::where('kind', 'article')->count());

        $installation->update(['config_json' => ['connection' => $installation->config_json['connection'], 'help_center' => ['locale' => 'it']]]);
        // The locale-scoped list is empty; the unscoped detail still finds the English article.
        $this->zendesk([
            '/help_center/it/articles' => ['articles' => [], 'meta' => ['has_more' => false]],
            '/help_center/articles/7' => ['article' => ['id' => 7, 'title' => 'Reset your password', 'body' => '<p>Click</p>', 'draft' => false, 'locale' => 'en_us', 'user_segment_id' => null]],
        ]);
        $this->finish(app(SyncManager::class)->start($installation));

        $this->assertContains(['tenant-a', $installation->id.':article:7'], $this->ingestion->deleted);
        $this->assertSame(0, SourceState::where('kind', 'article')->count());
    }

    public function test_an_article_in_the_configured_locale_is_still_reimported_by_the_check(): void
    {
        $this->zendesk();
        $installation = $this->authorized();
        $this->finish(app(SyncManager::class)->start($installation));
        $installation->update(['config_json' => ['connection' => $installation->config_json['connection'], 'help_center' => ['locale' => 'en-us']]]);
        $this->zendesk([
            '/help_center/en-us/articles' => ['articles' => [], 'meta' => ['has_more' => false]],
            '/help_center/articles/7' => ['article' => ['id' => 7, 'title' => 'Reset your password', 'body' => '<p>Click</p>', 'draft' => false, 'locale' => 'en_US', 'user_segment_id' => null]],
        ]);
        $this->finish(app(SyncManager::class)->start($installation));
        $this->assertSame([], array_filter($this->ingestion->deleted, fn ($row) => str_contains($row[1], ':article:')));
        $this->assertSame(1, SourceState::where('kind', 'article')->count());
    }

    public function test_the_connector_specific_queue_connection_must_be_asynchronous_too(): void
    {
        $installation = $this->authorized();
        config(['queue.default' => 'database', 'connector-zendesk.sync.connection' => 'sync']);
        try {
            app(SyncManager::class)->start($installation);
            $this->fail('A synchronous connector connection would run the whole import inline.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('asynchronous queue', $exception->getMessage());
        }
        config(['connector-zendesk.sync.connection' => null]);
        $this->assertSame('queued', app(SyncManager::class)->start($installation)->status);
    }

    public function test_a_refresh_that_cannot_re_read_a_known_ticket_retires_the_stale_copy(): void
    {
        $this->zendesk();
        $installation = $this->authorized();
        $this->finish(app(SyncManager::class)->start($installation));
        $installation->update(['config_json' => ['connection' => $installation->config_json['connection'], 'include_internal_notes' => false]]);
        // The ticket can no longer be read; the incremental export does not return it either.
        $this->zendesk(['/tickets/1' => 404, '/incremental/tickets/cursor' => ['tickets' => [], 'after_cursor' => 'x', 'end_of_stream' => true]]);
        $this->finish(app(SyncManager::class)->start($installation));
        $this->assertContains(['tenant-a', $installation->id.':ticket:1'], $this->ingestion->deleted, 'private notes must not stay indexed');
        $this->assertSame(0, SourceState::where('kind', 'ticket')->count());
    }

    public function test_organization_paging_follows_end_of_stream_not_the_row_count(): void
    {
        $middle = now()->subDay()->getTimestamp();
        $this->zendesk([], function ($request) use ($middle) {
            if (! str_contains($request->url(), '/incremental/organizations')) {
                return null;
            }

            // A short page with end_of_stream=false must not end the export; the next request resumes at end_time.
            return ((int) $request['start_time']) < $middle
                ? Http::response(['organizations' => [['id' => 5, 'name' => 'Acme Corp']], 'end_time' => $middle, 'end_of_stream' => false])
                : Http::response(['organizations' => [['id' => 6, 'name' => 'Globex']], 'end_time' => $middle + 100, 'end_of_stream' => true]);
        });
        $this->finish(app(SyncManager::class)->start($this->authorized()));
        $titles = array_column(array_filter($this->ingestion->documents, fn ($d) => $d['metadata']['source_kind'] === 'organization'), 'title');
        $this->assertEqualsCanonicalizing(['Acme Corp', 'Globex'], $titles);
    }

    public function test_enabling_customers_after_a_completed_scan_backfills_the_whole_window(): void
    {
        $this->zendesk();
        $installation = $this->authorized(['customers' => ['enabled' => false], 'organizations' => ['enabled' => false]]);
        $this->finish(app(SyncManager::class)->start($installation));
        $this->assertSame(0, SourceState::where('kind', 'user')->count());

        $installation->update(['config_json' => ['connection' => $installation->config_json['connection'], 'customers' => ['enabled' => true], 'organizations' => ['enabled' => true]]]);
        $this->zendesk();
        $run = app(SyncManager::class)->start($installation);
        $windowStart = now()->subDays(90)->getTimestamp();
        $this->assertLessThanOrEqual($windowStart + 5, $run->checkpoint['users_since'], 'newly enabled customers must cover the full window');
        $this->assertLessThanOrEqual($windowStart + 5, $run->checkpoint['org_since'], 'newly enabled organizations must cover the full window');
        $this->assertGreaterThan($windowStart + 86400, $run->checkpoint['since'], 'tickets still continue from the previous scan');
        $this->finish($run);
        $this->assertSame(1, SourceState::where('kind', 'user')->count());

        // Once covered, the next scan goes back to resuming from the watermark.
        $this->zendesk();
        $next = app(SyncManager::class)->start($installation);
        $this->assertGreaterThan($windowStart + 86400, $next->checkpoint['users_since']);
        $this->assertGreaterThan($windowStart + 86400, $next->checkpoint['org_since']);
    }

    public function test_titles_are_redacted_like_bodies(): void
    {
        $ticket = ['id' => 1, 'subject' => 'Refund for sensitive@example.com', 'status' => 'open', 'requester_id' => 10, 'created_at' => '2026-10-01T10:00:00Z', 'updated_at' => '2026-10-02T10:00:00Z'];
        $this->zendesk(['/tickets/1' => ['ticket' => $ticket, 'users' => [], 'organizations' => []]]);
        $this->finish(app(SyncManager::class)->start($this->authorized()));
        $document = collect($this->ingestion->documents)->firstWhere('metadata.source_kind', 'ticket');
        $this->assertSame('Refund for [redacted]', $document['title']);
        $this->assertStringNotContainsString('sensitive@example.com', json_encode($this->ingestion->documents));
    }

    public function test_an_attachment_later_flagged_deleted_or_malware_is_removed_from_the_index(): void
    {
        foreach (['deleted' => true, 'malware_scan_result' => 'malware_found'] as $flag => $value) {
            $this->ingestion->documents = [];
            $this->ingestion->deleted = [];
            SourceState::query()->delete();
            $download = fn ($request) => str_contains($request->url(), '/files/info.txt') ? Http::response('hello world', 200, ['Content-Type' => 'text/plain']) : null;
            $url = 'https://example.zendesk.com/files/info.txt';
            $this->zendesk($this->withAttachment($url), $download);
            $installation = $this->authorized();
            $this->finish(app(SyncManager::class)->start($installation));
            $this->assertSame(1, SourceState::where('kind', 'attachment')->count(), $flag);

            $flagged = $this->withAttachment($url);
            $flagged['/tickets/1/comments']['comments'][0]['attachments'][0][$flag] = $value;
            $this->zendesk($flagged, $download);
            $this->finish(app(SyncManager::class)->start($installation));

            $this->assertContains(['tenant-a', $installation->id.':attachment:55'], $this->ingestion->deleted, $flag);
            $this->assertSame(0, SourceState::where('kind', 'attachment')->count(), $flag);
            $installation->delete();
        }
    }

    public function test_attachments_without_a_clean_malware_verdict_are_not_downloaded_unless_opted_in(): void
    {
        $url = 'https://example.zendesk.com/files/info.txt';
        $download = fn ($request) => str_contains($request->url(), '/files/info.txt') ? Http::response('hello world', 200, ['Content-Type' => 'text/plain']) : null;
        foreach (['not_scanned', 'failed_to_scan', null] as $verdict) {
            $routes = $this->withAttachment($url);
            if ($verdict === null) {
                unset($routes['/tickets/1/comments']['comments'][0]['attachments'][0]['malware_scan_result']);
            } else {
                $routes['/tickets/1/comments']['comments'][0]['attachments'][0]['malware_scan_result'] = $verdict;
            }
            config()->set('connector-zendesk.attachments.allow_unscanned', false);
            $this->ingestion->documents = [];
            SourceState::query()->delete();
            $this->zendesk($routes, $download);
            $installation = $this->authorized();
            $this->finish(app(SyncManager::class)->start($installation));
            Http::assertNotSent(fn ($r) => str_contains($r->url(), '/files/info.txt'));
            $this->assertSame(0, SourceState::where('kind', 'attachment')->count(), (string) $verdict);

            config()->set('connector-zendesk.attachments.allow_unscanned', true);
            $this->zendesk($routes, $download);
            $this->finish(app(SyncManager::class)->start($installation));
            $this->assertSame(1, SourceState::where('kind', 'attachment')->count(), (string) $verdict);

            // Even when opted in, a file Zendesk flags as malware is never fetched.
            $routes['/tickets/1/comments']['comments'][0]['attachments'][0]['malware_scan_result'] = 'malware_found';
            $this->zendesk($routes, $download);
            $this->finish(app(SyncManager::class)->start($installation));
            $this->assertSame(0, SourceState::where('kind', 'attachment')->count(), (string) $verdict);
            $installation->delete();
        }
    }

    public function test_the_ticket_export_scope_is_sent_only_when_configured_and_valid(): void
    {
        foreach ([[null, null], ['all', 'all'], ['ai_agent', 'ai_agent'], ['bogus', null]] as [$configured, $expected]) {
            config()->set('connector-zendesk.sync.ticket_support_type_scope', $configured);
            $this->zendesk();
            SourceState::query()->delete();
            $installation = $this->authorized();
            $this->finish(app(SyncManager::class)->start($installation));
            Http::assertSent(function ($r) use ($expected) {
                if (! str_contains($r->url(), '/incremental/tickets/cursor')) {
                    return false;
                }
                parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $query);

                return ($query['support_type_scope'] ?? null) === $expected;
            });
            $installation->delete();
        }
    }

    public function test_history_reconciliation_skips_the_content_type_that_is_switched_off(): void
    {
        $this->zendesk();
        $installation = $this->authorized();
        $this->finish(app(SyncManager::class)->start($installation, true));
        $this->assertSame(1, SourceState::where('kind', 'user')->count());
        $this->assertSame(1, SourceState::where('kind', 'organization')->count());

        $installation->update(['config_json' => ['connection' => $installation->config_json['connection'], 'customers' => ['enabled' => false]]]);
        // No /users/show_many route: asking for it would hit the fixture's "unexpected path" failure.
        $this->zendesk(['/incremental/organizations' => ['organizations' => [], 'end_time' => 1760000000, 'end_of_stream' => true], '/organizations/show_many' => ['organizations' => []]]);
        $this->finish(app(SyncManager::class)->start($installation, true));

        $this->assertContains(['tenant-a', $installation->id.':organization:5'], $this->ingestion->deleted, 'the enabled type is still reconciled');
        $this->assertNotContains(['tenant-a', $installation->id.':user:10'], $this->ingestion->deleted, 'the disabled type is left untouched');
        $this->assertSame(1, SourceState::where('kind', 'user')->count());
    }

    public function test_a_tenant_mismatch_is_rejected_before_any_request(): void
    {
        $installation = $this->authorized();
        app(TenantContext::class)->set('tenant-b');
        $this->expectException(\RuntimeException::class);
        app(SyncManager::class)->client($installation);
    }

    public function test_history_reconciliation_deletes_a_customer_that_is_now_inactive(): void
    {
        $this->zendesk();
        $installation = $this->authorized();
        $this->finish(app(SyncManager::class)->start($installation, true));
        $this->assertSame(1, SourceState::where('kind', 'user')->count());

        $this->zendesk([
            '/incremental/users/cursor' => ['users' => [], 'after_cursor' => 'u2', 'end_of_stream' => true],
            '/users/show_many' => ['users' => [['id' => 10, 'name' => 'Anne', 'role' => 'end-user', 'active' => false]]],
        ]);
        $this->finish(app(SyncManager::class)->start($installation, true));

        $this->assertContains(['tenant-a', $installation->id.':user:10'], $this->ingestion->deleted);
        $this->assertSame(0, SourceState::where('kind', 'user')->count());
    }

    public function test_rebinding_retires_copies_of_a_content_type_that_is_switched_off(): void
    {
        $this->zendesk();
        $installation = $this->authorized();
        $this->finish(app(SyncManager::class)->start($installation));
        $this->assertSame(1, SourceState::where('kind', 'user')->count());
        $before = count($this->ingestion->documents);

        $installation->update(['project_key' => 'other', 'config_json' => ['connection' => $installation->config_json['connection'], 'customers' => ['enabled' => false]]]);
        $empty = ['after_cursor' => 'x', 'end_of_stream' => true];
        $this->zendesk([
            '/incremental/tickets/cursor' => ['tickets' => []] + $empty,
            '/incremental/organizations' => ['organizations' => [], 'end_time' => 1760000000, 'end_of_stream' => true],
        ]);
        $this->finish(app(SyncManager::class)->start($installation));

        $this->assertContains(['tenant-a', $installation->id.':user:10'], $this->ingestion->deleted, 'the copy in the previous project is retired');
        $this->assertSame(0, SourceState::where('kind', 'user')->count());
        $this->assertSame([], collect(array_slice($this->ingestion->documents, $before))->where('metadata.source_kind', 'user')->all(), 'a switched-off type is not re-imported into the new project');
    }

    public function test_a_private_first_comment_is_not_rendered_as_the_description_when_internal_notes_are_off(): void
    {
        $ticket = ['id' => 1, 'subject' => 'Printer on fire', 'status' => 'open', 'description' => 'PRIVATE-FIRST-NOTE', 'requester_id' => 10, 'tags' => []];
        $routes = [
            '/tickets/1' => ['ticket' => $ticket, 'users' => []],
            '/tickets/1/comments' => ['comments' => [['id' => 100, 'author_id' => 11, 'public' => false, 'created_at' => '2026-10-01T10:00:00Z', 'plain_body' => 'PRIVATE-FIRST-NOTE', 'attachments' => []]], 'meta' => ['has_more' => false]],
        ];
        $this->zendesk($routes);
        $this->finish(app(SyncManager::class)->start($this->authorized(['include_internal_notes' => false])));
        $document = collect($this->ingestion->documents)->firstWhere('metadata.source_kind', 'ticket');
        $this->assertStringNotContainsString('PRIVATE-FIRST-NOTE', Storage::disk('local')->get($document['relativePath']));

        // With internal notes enabled the same ticket is indexed in full.
        $this->ingestion->documents = [];
        SourceState::query()->delete();
        $this->zendesk($routes);
        $this->finish(app(SyncManager::class)->start($this->authorized()));
        $document = collect($this->ingestion->documents)->firstWhere('metadata.source_kind', 'ticket');
        $this->assertStringContainsString('PRIVATE-FIRST-NOTE', Storage::disk('local')->get($document['relativePath']));
    }

    public function test_rebinding_retires_help_center_articles_and_attachments_that_are_switched_off(): void
    {
        $url = 'https://example.zendesk.com/files/info.txt';
        $download = fn ($request) => str_contains($request->url(), '/files/info.txt') ? Http::response('hello world', 200, ['Content-Type' => 'text/plain']) : null;
        $this->zendesk($this->withAttachment($url), $download);
        $installation = $this->authorized();
        $this->finish(app(SyncManager::class)->start($installation));
        $this->assertSame(1, SourceState::where('kind', 'article')->count());
        $this->assertSame(1, SourceState::where('kind', 'attachment')->count());
        $before = count($this->ingestion->documents);

        $installation->update(['project_key' => 'other', 'config_json' => ['connection' => $installation->config_json['connection'], 'help_center' => ['enabled' => false], 'attachments' => ['enabled' => false]]]);
        $empty = ['after_cursor' => 'x', 'end_of_stream' => true];
        $this->zendesk([
            '/incremental/tickets/cursor' => ['tickets' => []] + $empty,
            '/incremental/users/cursor' => ['users' => []] + $empty,
            '/incremental/organizations' => ['organizations' => [], 'end_time' => 1760000000, 'end_of_stream' => true],
        ]);
        $this->finish(app(SyncManager::class)->start($installation));

        $this->assertSame(0, SourceState::where('kind', 'article')->count());
        $this->assertSame(0, SourceState::where('kind', 'attachment')->count());
        $this->assertNotEmpty(array_filter($this->ingestion->deleted, fn ($d) => str_contains($d[1], ':article:')));
        $this->assertNotEmpty(array_filter($this->ingestion->deleted, fn ($d) => str_contains($d[1], ':attachment:')));
        $after = collect(array_slice($this->ingestion->documents, $before));
        $this->assertSame([], $after->whereIn('metadata.source_kind', ['article', 'attachment'])->all(), 'switched-off types are not re-imported');
    }

    public function test_binary_attachment_names_and_article_slugs_with_pii_never_reach_titles_or_metadata(): void
    {
        $url = 'https://example.zendesk.com/files/leak.pdf';
        $routes = $this->withAttachment($url, 'sensitive@example.com.pdf', 'application/pdf');
        $routes['/help_center/articles'] = ['articles' => [['id' => 7, 'title' => 'Guide', 'body' => '<p>x</p>', 'draft' => false, 'locale' => 'en-us', 'user_segment_id' => null,
            'html_url' => 'https://example.zendesk.com/hc/en-us/articles/7-sensitive-person-at-example', 'updated_at' => '2026-10-02T10:00:00Z']], 'meta' => ['has_more' => false, 'after_cursor' => null]];
        $this->zendesk($routes, fn ($request) => str_contains($request->url(), '/files/leak.pdf') ? Http::response("%PDF-1.4\n%test\n", 200, ['Content-Type' => 'application/pdf']) : null);
        $this->finish(app(SyncManager::class)->start($this->authorized()));

        $attachment = collect($this->ingestion->documents)->firstWhere('metadata.source_kind', 'attachment');
        $this->assertNotNull($attachment);
        $this->assertStringNotContainsString('sensitive@example.com', $attachment['title']);
        $this->assertStringNotContainsString('sensitive@example.com', json_encode($attachment['metadata']));

        $article = collect($this->ingestion->documents)->firstWhere('metadata.source_kind', 'article');
        $this->assertNotNull($article);
        $this->assertSame('https://example.zendesk.com/hc/articles/7', $article['metadata']['source_url']);
        $this->assertStringNotContainsString('sensitive-person', json_encode($article['metadata']));
    }

    public function test_vendor_tags_and_domain_names_are_redacted_in_the_metadata(): void
    {
        $this->zendesk([
            '/tickets/1' => ['ticket' => ['id' => 1, 'subject' => 's', 'status' => 'open', 'tags' => ['sensitive@example.com', 'urgent'], 'requester_id' => 10], 'users' => []],
            '/incremental/organizations' => ['organizations' => [['id' => 5, 'name' => 'Acme', 'domain_names' => ['sensitive@example.com'], 'tags' => ['sensitive@example.com']]], 'end_time' => 1760000000, 'end_of_stream' => true],
            '/help_center/articles' => ['articles' => [['id' => 7, 'title' => 'Guide', 'body' => '<p>x</p>', 'draft' => false, 'locale' => 'en-us', 'user_segment_id' => null, 'label_names' => ['sensitive@example.com'], 'updated_at' => '2026-10-02T10:00:00Z']], 'meta' => ['has_more' => false, 'after_cursor' => null]],
        ]);
        $this->finish(app(SyncManager::class)->start($this->authorized()));
        foreach (['ticket', 'organization', 'article'] as $kind) {
            $document = collect($this->ingestion->documents)->firstWhere('metadata.source_kind', $kind);
            $this->assertNotNull($document, $kind);
            $this->assertStringNotContainsString('sensitive@example.com', json_encode($document['metadata']), $kind);
        }
    }

    public function test_a_phase_switched_off_mid_scan_is_skipped_instead_of_finished(): void
    {
        $installation = $this->authorized();
        $run = app(SyncManager::class)->start($installation);
        $cp = $run->checkpoint;
        $cp['phase'] = 'users';
        $run->checkpoint = $cp;
        $run->save();
        $installation->update(['config_json' => ['connection' => $installation->config_json['connection'], 'customers' => ['enabled' => false]]]);
        // No /incremental/users route is faked: asking for it would hit the fixture's "unexpected path" failure.
        $this->zendesk();
        $this->finish($run);

        $this->assertSame(0, SourceState::where('kind', 'user')->count());
        $this->assertSame('done', $run->fresh()->checkpoint['phase']);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/incremental/users'));
    }

    public function test_the_unscanned_attachment_policy_is_part_of_the_projection(): void
    {
        $installation = $this->installation();
        config()->set('connector-zendesk.attachments.allow_unscanned', false);
        $off = SyncManager::projection($installation);
        config()->set('connector-zendesk.attachments.allow_unscanned', true);
        $this->assertNotSame($off, SyncManager::projection($installation));
    }
}
