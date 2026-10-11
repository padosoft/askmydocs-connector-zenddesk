<?php

namespace Padosoft\AskMyDocsConnectorZendesk\Tests\Feature;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Padosoft\AskMyDocsConnectorBase\Auth\OAuthCredentialVault;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorZendesk\Sync\SyncManager;
use Padosoft\AskMyDocsConnectorZendesk\Sync\SyncRun;

/** A small in-memory Zendesk: just enough routes for a full scan. */
trait FakesZendesk
{
    private function authorized(array $config = []): ConnectorInstallation
    {
        $installation = $this->installation($config);
        app(OAuthCredentialVault::class)->setCredentials($installation->id, accessToken: 'secret-token');

        return $installation;
    }

    /**
     * @param  array<string,mixed>  $overrides  path => response body (array) or a status code (int)
     */
    private function zendesk(array $overrides = [], ?\Closure $extra = null): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($overrides, $extra) {
            if ($extra !== null && ($response = $extra($request)) !== null) {
                return $response;
            }
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $path = str_starts_with($path, '/api/v2') ? substr($path, 7) : $path;
            if (array_key_exists($path, $overrides)) {
                $override = $overrides[$path];

                return is_int($override) ? Http::response([], $override) : Http::response($override);
            }
            $ticket = ['id' => 1, 'subject' => 'Printer on fire', 'status' => 'open', 'priority' => 'high', 'type' => 'incident', 'requester_id' => 10, 'assignee_id' => 11,
                'organization_id' => 5, 'tags' => ['urgent'], 'via' => ['channel' => 'email'], 'created_at' => '2026-10-01T10:00:00Z', 'updated_at' => '2026-10-02T10:00:00Z'];
            $users = [['id' => 10, 'name' => 'Anne Richard', 'email' => 'anne@example.test', 'role' => 'end-user'], ['id' => 11, 'name' => 'Sam Agent', 'email' => 'sam@example.com', 'role' => 'agent']];

            return Http::response(match ($path) {
                '/incremental/tickets/cursor' => ['tickets' => [['id' => 1, 'status' => 'open']], 'after_cursor' => 'cur-1', 'end_of_stream' => true],
                '/tickets/1' => ['ticket' => $ticket, 'users' => $users, 'organizations' => [['id' => 5, 'name' => 'Acme Corp']]],
                '/tickets/1/comments' => ['comments' => [
                    ['id' => 100, 'author_id' => 10, 'public' => true, 'created_at' => '2026-10-01T10:00:00Z', 'plain_body' => 'My printer, sensitive@example.com, is on fire', 'attachments' => []],
                    ['id' => 101, 'author_id' => 11, 'public' => false, 'created_at' => '2026-10-01T11:00:00Z', 'plain_body' => 'Internal: escalate to facilities', 'attachments' => []],
                    ['id' => 102, 'author_id' => 11, 'public' => true, 'created_at' => '2026-10-01T12:00:00Z', 'plain_body' => 'Public reply: unplug it', 'attachments' => []],
                ], 'users' => $users, 'meta' => ['has_more' => false, 'after_cursor' => null]],
                '/incremental/users/cursor' => ['users' => [
                    ['id' => 10, 'name' => 'Anne Richard', 'email' => 'anne@example.test', 'role' => 'end-user', 'organization_id' => 5, 'notes' => 'VIP customer'],
                    ['id' => 11, 'name' => 'Sam Agent', 'email' => 'sam@example.com', 'role' => 'agent'],
                ], 'after_cursor' => 'ucur-1', 'end_of_stream' => true],
                '/organizations/5' => ['organization' => ['id' => 5, 'name' => 'Acme Corp']],
                '/incremental/organizations' => ['organizations' => [['id' => 5, 'name' => 'Acme Corp', 'domain_names' => ['acme.test'], 'notes' => 'Key account']], 'end_time' => 1760000000, 'end_of_stream' => true],
                '/help_center/articles' => ['articles' => [
                    ['id' => 7, 'title' => 'Reset your password', 'body' => '<p>Click <b>Forgot password</b></p>', 'draft' => false, 'locale' => 'en-us', 'section_id' => 3, 'user_segment_id' => null, 'label_names' => ['account'], 'html_url' => 'https://example.zendesk.com/hc/en-us/articles/7', 'updated_at' => '2026-10-02T10:00:00Z'],
                    ['id' => 8, 'title' => 'Unreleased draft', 'body' => '<p>secret plan</p>', 'draft' => true, 'locale' => 'en-us', 'updated_at' => '2026-10-02T10:00:00Z'],
                ], 'meta' => ['has_more' => false, 'after_cursor' => null]],
                '/help_center/articles/7' => ['article' => ['id' => 7, 'title' => 'Reset your password', 'body' => '<p>Click</p>', 'draft' => false, 'user_segment_id' => null]],
                '/users/me' => ['user' => ['id' => 11, 'role' => 'agent', 'name' => 'Sam Agent']],
                default => throw new \RuntimeException('Unexpected fixture path: '.$path),
            });
        });
    }

    private function finish(SyncRun $run): void
    {
        for ($batch = 0; $batch < 60; $batch++) {
            if (app(SyncManager::class)->batch($run->id) === null) {
                return;
            }
        }
        $this->fail('Sync did not complete.');
    }
}
