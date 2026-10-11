<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorZendesk\Sync;

use Carbon\Carbon;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Padosoft\AskMyDocsConnectorBase\Auth\OAuthCredentialVault;
use Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use Padosoft\AskMyDocsConnectorZendesk\Exceptions\ZendeskApiException;
use Padosoft\AskMyDocsConnectorZendesk\Http\ZendeskClient;

/**
 * The resumable scan: a phase machine whose whole state lives in
 * `zendesk_sync_runs.checkpoint`, advanced one small step at a time.
 *
 * Phases, in order (disabled ones are skipped):
 *   refresh        re-import every known ticket, customer and organization when a content setting or the project binding changed
 *   tickets        incremental cursor-based ticket export → one detail+comments import per ticket
 *   users          incremental cursor-based user export, end-users only
 *   organizations  incremental (time-based) organization export
 *   articles       full Help Center article enumeration (cursor pagination)
 *   article_check  articles the scan did not meet: detail 404 confirms deletion
 *   customer_check history mode only: users/organizations the scan did not meet, verified via show_many
 */
final class SyncManager
{
    public const PHASES = ['tickets', 'users', 'organizations', 'articles', 'article_check', 'customer_check'];

    /** @var array<int,string|null> */
    private array $organizationNames = [];

    public function __construct(
        private readonly OAuthCredentialVault $vault,
        private readonly TenantContext $tenants,
        private readonly DocumentImporter $importer,
        private readonly ConnectorIngestionContract $ingestion,
    ) {}

    public function installation(int $id): ConnectorInstallation
    {
        return ConnectorInstallation::query()->where('tenant_id', $this->tenants->current())->where('connector_name', 'zendesk')->whereKey($id)->firstOrFail();
    }

    public function client(ConnectorInstallation $installation, ?Closure $beforeRequest = null): ZendeskClient
    {
        if ($installation->tenant_id !== $this->tenants->current() || $installation->connector_name !== 'zendesk') {
            throw new \RuntimeException('Zendesk installation scope mismatch.');
        }
        $secret = $this->vault->getAccessToken($installation->id);
        if ($secret === null) {
            throw new \RuntimeException('Zendesk credentials are missing.');
        }

        return new ZendeskClient(
            (string) data_get($installation->config_json, 'connection.subdomain'),
            $secret,
            (string) data_get($installation->config_json, 'connection.auth_mode', ZendeskClient::AUTH_API_TOKEN),
            (string) data_get($installation->config_json, 'connection.email', ''),
            $beforeRequest,
        );
    }

    public function start(ConnectorInstallation $installation, bool $history = false): SyncRun
    {
        $installation = $this->installation($installation->id);
        if (! in_array($installation->status, ['active', 'errored'], true)) {
            throw new \RuntimeException('Enable the Zendesk installation before importing.');
        }
        // The connection ProcessSyncBatch really uses: the connector-specific one when set, else the default.
        $connection = config('connector-zendesk.sync.connection') ?: config('queue.default');
        if (config('queue.connections.'.$connection.'.driver') === 'sync' || $connection === 'sync') {
            throw new \RuntimeException('Zendesk imports require an asynchronous queue connection.');
        }
        $mode = $history ? 'history' : 'window';
        $dispatch = false;
        $run = DB::transaction(function () use ($installation, $mode, $history, &$dispatch): SyncRun {
            ConnectorInstallation::query()->whereKey($installation->id)->where('tenant_id', $installation->tenant_id)->lockForUpdate()->firstOrFail();
            $query = $this->runs($installation);
            $query->whereIn('status', ['queued', 'running', 'failed']);
            $query->latest('id');
            $existing = $query->get()->first(fn ($candidate) => ! $history || $candidate->mode === 'history');
            if ($existing !== null) {
                if ($existing->status === 'failed') {
                    $existing->forceFill(['status' => 'queued', 'error' => null])->save();
                    $dispatch = true;
                }

                return $existing;
            }
            $window = max(1, min(36500, (int) data_get($installation->config_json, 'date_window_days', 90)));
            $since = $history ? Carbon::create(1970, 1, 1, 0, 0, 0, 'UTC') : Carbon::now('UTC')->subDays($window);
            $windowStart = $since->copy();
            // Ties on the start time (two scans within one second) are broken by id, so "previous" is always the newest.
            $previous = $this->runs($installation)->where('status', 'completed')->latest('scan_started_at')->latest('id')->first();
            $projection = self::projection($installation);
            $refresh = $previous !== null && ($previous->checkpoint['projection_hash'] ?? null) !== $projection;
            if (! $history && $previous !== null && (int) ($previous->checkpoint['window_days'] ?? 0) >= $window) {
                $since = $since->max(Carbon::parse($previous->scan_started_at)->subSeconds((int) config('connector-zendesk.sync.overlap_seconds', 120)));
            }
            // Zendesk rejects an export start_time that is too close to "now".
            $newest = Carbon::now('UTC')->subSeconds(max(60, (int) config('connector-zendesk.sync.export_min_age_seconds', 90)));
            $since = $since->min($newest);
            // A content type that was off during the previous completed scan has never been exported for this
            // window: its export must cover the whole window, not only what changed since that scan.
            $content = [
                'customers' => (bool) data_get($installation->config_json, 'customers.enabled', true),
                'organizations' => (bool) data_get($installation->config_json, 'organizations.enabled', true),
            ];
            $before = (array) ($previous?->checkpoint['content'] ?? []);
            $usersSince = $content['customers'] && ! ($before['customers'] ?? false) ? $windowStart->min($newest) : $since;
            $organizationsSince = $content['organizations'] && ! ($before['organizations'] ?? false) ? $windowStart->min($newest) : $since;
            $dispatch = true;

            return SyncRun::query()->create([
                'tenant_id' => $installation->tenant_id, 'installation_id' => $installation->id, 'mode' => $mode,
                'status' => 'queued', 'scan_started_at' => now(), 'counts' => [],
                'checkpoint' => [
                    'phase' => 'tickets', 'since' => $since->getTimestamp(), 'initial_since' => $since->getTimestamp(),
                    'users_since' => $usersSince->getTimestamp(), 'org_since' => $organizationsSince->getTimestamp(), 'content' => $content, 'cursor' => null, 'pending' => [], 'pages' => 0,
                    'window_days' => $history ? 36500 : $window, 'projection_hash' => $projection, 'refresh' => $refresh, 'refresh_kind' => 'ticket', 'refresh_cursor' => 0,
                ],
            ]);
        });
        if ($dispatch) {
            ProcessSyncBatch::dispatch($run->id, $installation->tenant_id);
        }

        return $run;
    }

    public function latest(ConnectorInstallation $installation): ?SyncRun
    {
        $installation = $this->installation($installation->id);

        return $this->runs($installation)->latest('id')->first();
    }

    public function cancel(ConnectorInstallation $installation): void
    {
        $this->runs($this->installation($installation->id))->whereIn('status', ['queued', 'running', 'failed'])->update(['status' => 'cancelled']);
    }

    /** Returns seconds before the next batch; null means finished. */
    public function batch(int $runId): ?int
    {
        $run = SyncRun::query()->where('tenant_id', $this->tenants->current())->whereKey($runId)->firstOrFail();
        $lock = Cache::lock('zendesk-sync:'.$run->tenant_id.':'.$run->installation_id, 660);
        if (! $lock->get()) {
            return 30;
        }
        try {
            $run->refresh();
            if (in_array($run->status, ['completed', 'cancelled'], true)) {
                return null;
            }
            $installation = $this->installation($run->installation_id);
            if (! in_array($installation->status, ['active', 'errored'], true)) {
                $run->forceFill(['status' => 'cancelled'])->save();

                return null;
            }
            $run->forceFill(['status' => 'running', 'error' => null])->save();
            $client = $this->client($installation);
            $started = microtime(true);
            $items = max(1, min(100, (int) config('connector-zendesk.sync.batch_items', 5)));
            for ($i = 0; $i < $items && microtime(true) - $started < (int) config('connector-zendesk.sync.batch_seconds', 60); $i++) {
                if ($run->fresh()->status === 'cancelled' || ! in_array($installation->fresh()->status, ['active', 'errored'], true)) {
                    $run->forceFill(['status' => 'cancelled'])->save();

                    return null;
                }
                if ($this->step($installation, $run, $client)) {
                    $this->complete($installation, $run);

                    return null;
                }
                $run->save();
            }
            $run->forceFill(['status' => 'queued'])->save();

            return 1;
        } catch (ZendeskApiException $exception) {
            $run->forceFill(['status' => $exception->status === 429 ? 'queued' : 'failed', 'error' => $exception->getMessage()])->save();
            if ($exception->status === 429) {
                return $exception->retryAfter;
            }
            $this->recordFailure($run);
            throw $exception;
        } catch (\Throwable $exception) {
            $run->forceFill(['status' => 'failed', 'error' => $exception->getMessage()])->save();
            $this->recordFailure($run);
            throw $exception;
        } finally {
            $lock->release();
        }
    }

    private function complete(ConnectorInstallation $installation, SyncRun $run): void
    {
        $run->forceFill(['status' => 'completed', 'finished_at' => now(), 'error' => null])->save();
        $lastSync = $installation->fresh()->last_sync_at;
        ConnectorInstallation::query()->where('tenant_id', $installation->tenant_id)->whereKey($installation->id)
            ->whereIn('status', ['active', 'errored'])->update([
                'last_sync_at' => $lastSync === null ? $run->scan_started_at : Carbon::parse($lastSync)->max($run->scan_started_at),
                'error_json' => null, 'status' => 'active',
            ]);
        $this->ingestion->emitAudit('zendesk', 'sync_completed', $installation->id, $run->summary());
    }

    private function recordFailure(SyncRun $run): void
    {
        $installation = $this->installation($run->installation_id);
        if (in_array($installation->status, ['active', 'errored'], true)) {
            $installation->forceFill(['status' => 'errored', 'error_json' => ['message' => $run->error, 'recorded_at' => now()->toIso8601String()]])->save();
        }
    }

    /** One small unit of work. Returns true when the scan is complete. */
    private function step(ConnectorInstallation $installation, SyncRun $run, ZendeskClient $client): bool
    {
        $cp = $run->checkpoint;
        if ($cp['refresh'] ?? false) {
            $run->checkpoint = $this->refreshStep($installation, $run, $client, $cp);

            return false;
        }
        $phase = (string) ($cp['phase'] ?? 'tickets');
        $cp = match ($phase) {
            'tickets' => $this->ticketsStep($installation, $run, $client, $cp),
            'users' => $this->usersStep($installation, $run, $client, $cp),
            'organizations' => $this->organizationsStep($installation, $run, $client, $cp),
            'articles' => $this->articlesStep($installation, $run, $client, $cp),
            'article_check' => $this->articleCheckStep($installation, $run, $client, $cp),
            'customer_check' => $this->customerCheckStep($installation, $run, $client, $cp),
            'done' => $cp,
            default => throw new \RuntimeException('Unknown Zendesk sync phase.'),
        };
        $run->checkpoint = $cp;

        return ($cp['phase'] ?? null) === 'done';
    }

    /**
     * Re-import what is already known, one object per step: tickets, then customers, then organizations.
     * Incremental exports resume near the previous watermark, so an object that did not change in Zendesk
     * would otherwise never be seen again after a setting or the project binding changed.
     *
     * @param  array<string,mixed>  $cp
     * @return array<string,mixed>
     */
    private function refreshStep(ConnectorInstallation $installation, SyncRun $run, ZendeskClient $client, array $cp): array
    {
        $kind = (string) ($cp['refresh_kind'] ?? 'ticket');
        $enabled = match ($kind) {
            'user' => (bool) data_get($installation->config_json, 'customers.enabled', true),
            'organization' => (bool) data_get($installation->config_json, 'organizations.enabled', true),
            default => true,
        };
        $state = $enabled ? $this->states($installation)->where('kind', $kind)->where('id', '>', (int) ($cp['refresh_cursor'] ?? 0))->orderBy('id')->first() : null;
        if ($state === null) {
            $next = ['ticket' => 'user', 'user' => 'organization'][$kind] ?? null;
            if ($next === null) {
                $cp['refresh'] = false;

                return $cp;
            }
            $cp['refresh_kind'] = $next;
            $cp['refresh_cursor'] = 0;

            return $cp;
        }
        try {
            match ($kind) {
                'ticket' => $this->importer->ticket($installation, $run, $client, (int) $state->remote_id),
                'user' => $this->refreshUser($installation, $run, $client, (int) $state->remote_id),
                default => $this->refreshOrganization($installation, $run, $client, (int) $state->remote_id),
            };
        } catch (ZendeskApiException $exception) {
            if ($exception->status !== 404) {
                throw $exception;
            }
            // The refresh exists because what was indexed no longer matches the settings (private notes now
            // excluded, another project). An object that cannot be read again cannot be regenerated, and the
            // incremental exports will not revisit it, so keeping the old copy would keep stale private text or a
            // copy in the previous project indefinitely: retire it.
            $this->importer->delete($installation, $run, $kind, $state->remote_id);
        }
        $cp['refresh_cursor'] = $state->id;

        return $cp;
    }

    private function refreshUser(ConnectorInstallation $installation, SyncRun $run, ZendeskClient $client, int $id): void
    {
        $user = $client->get('/users/'.$id)['user'] ?? null;
        if (! is_array($user) || ! isset($user['id'])) {
            throw new \RuntimeException('Zendesk user detail is missing its identity.');
        }
        if (($user['role'] ?? '') !== 'end-user' || ($user['active'] ?? true) === false) {
            $this->importer->delete($installation, $run, 'user', (string) $user['id']);

            return;
        }
        $this->importer->user($installation, $run, $user, $this->organizationName($client, $user['organization_id'] ?? null));
    }

    private function refreshOrganization(ConnectorInstallation $installation, SyncRun $run, ZendeskClient $client, int $id): void
    {
        $organization = $client->get('/organizations/'.$id)['organization'] ?? null;
        if (! is_array($organization) || ! isset($organization['id'])) {
            throw new \RuntimeException('Zendesk organization detail is missing its identity.');
        }
        $this->importer->organization($installation, $run, $organization);
    }

    /**
     * @param  array<string,mixed>  $cp
     * @return array<string,mixed>
     */
    private function ticketsStep(ConnectorInstallation $installation, SyncRun $run, ZendeskClient $client, array $cp): array
    {
        if (($cp['pending'] ?? []) !== []) {
            $item = $cp['pending'][0];
            if (($item['status'] ?? null) === 'deleted') {
                $this->importer->delete($installation, $run, 'ticket', (string) $item['id']);
            } else {
                try {
                    $this->importer->ticket($installation, $run, $client, (int) $item['id']);
                } catch (ZendeskApiException $exception) {
                    // Deleted between export and detail: the stream will report it with status "deleted".
                    if ($exception->status !== 404) {
                        throw $exception;
                    }
                }
            }
            array_shift($cp['pending']);

            return $cp;
        }
        if ($cp['stream_done'] ?? false) {
            return $this->transition($installation, $run, $cp, 'tickets');
        }
        $query = ($cp['cursor'] ?? null) !== null ? ['cursor' => $cp['cursor']] : ['start_time' => (int) $cp['since']];
        $scope = config('connector-zendesk.sync.ticket_support_type_scope');
        if (in_array($scope, ['agent', 'ai_agent', 'all'], true)) {
            $query['support_type_scope'] = $scope;
        }
        $data = $client->get('/incremental/tickets/cursor', $query);
        $pending = [];
        foreach ((array) ($data['tickets'] ?? []) as $row) {
            if (! is_array($row) || ! isset($row['id'])) {
                throw new \RuntimeException('Zendesk ticket export is missing a ticket identity.');
            }
            $pending[] = ['id' => (int) $row['id'], 'status' => (string) ($row['status'] ?? '')];
        }
        $cp['pending'] = $pending;
        $after = $data['after_cursor'] ?? null;
        $cp['stream_done'] = (bool) ($data['end_of_stream'] ?? false) || ! is_string($after) || $after === '';
        if (! $cp['stream_done'] && $after === ($cp['cursor'] ?? null)) {
            throw new \RuntimeException('Zendesk ticket export cursor did not advance; import remains incomplete.');
        }
        $cp['cursor'] = is_string($after) && $after !== '' ? $after : $cp['cursor'];
        $cp['pages'] = (int) ($cp['pages'] ?? 0) + 1;
        if ($cp['pages'] > 100000) {
            throw new \RuntimeException('Zendesk ticket export exceeded the page limit.');
        }

        return $cp;
    }

    /**
     * @param  array<string,mixed>  $cp
     * @return array<string,mixed>
     */
    private function usersStep(ConnectorInstallation $installation, SyncRun $run, ZendeskClient $client, array $cp): array
    {
        if ($cp['stream_done'] ?? false) {
            return $this->transition($installation, $run, $cp, 'users');
        }
        // A page is imported in one step, with an organization lookup per distinct organization: keep it small
        // so a timeout or a 429 repeats little work instead of up to 1 000 users.
        $query = (($cp['cursor'] ?? null) !== null ? ['cursor' => $cp['cursor']] : ['start_time' => (int) ($cp['users_since'] ?? $cp['since'])]) + ['per_page' => 100];
        $data = $client->get('/incremental/users/cursor', $query);
        foreach ((array) ($data['users'] ?? []) as $user) {
            if (! is_array($user) || ! isset($user['id'])) {
                throw new \RuntimeException('Zendesk user export is missing a user identity.');
            }
            if (($user['role'] ?? '') !== 'end-user' || ($user['active'] ?? true) === false) {
                // Staff accounts are not customers, and Zendesk reports a deleted user as inactive: in both cases
                // a previously imported profile document is removed.
                $this->importer->delete($installation, $run, 'user', (string) $user['id']);

                continue;
            }
            $this->importer->user($installation, $run, $user, $this->organizationName($client, $user['organization_id'] ?? null));
        }
        $after = $data['after_cursor'] ?? null;
        $cp['stream_done'] = (bool) ($data['end_of_stream'] ?? false) || ! is_string($after) || $after === '';
        if (! $cp['stream_done'] && $after === ($cp['cursor'] ?? null)) {
            throw new \RuntimeException('Zendesk user export cursor did not advance; import remains incomplete.');
        }
        $cp['cursor'] = is_string($after) && $after !== '' ? $after : $cp['cursor'];
        $cp['pages'] = (int) ($cp['pages'] ?? 0) + 1;
        if ($cp['pages'] > 100000) {
            throw new \RuntimeException('Zendesk user export exceeded the page limit.');
        }

        return $cp;
    }

    /**
     * @param  array<string,mixed>  $cp
     * @return array<string,mixed>
     */
    private function organizationsStep(ConnectorInstallation $installation, SyncRun $run, ZendeskClient $client, array $cp): array
    {
        if ($cp['stream_done'] ?? false) {
            return $this->transition($installation, $run, $cp, 'organizations');
        }
        $start = (int) ($cp['org_since'] ?? $cp['since']);
        $data = $client->get('/incremental/organizations', ['start_time' => $start]);
        $rows = (array) ($data['organizations'] ?? []);
        foreach ($rows as $organization) {
            if (! is_array($organization) || ! isset($organization['id'])) {
                throw new \RuntimeException('Zendesk organization export is missing an organization identity.');
            }
            $this->importer->organization($installation, $run, $organization);
            if (is_string($organization['name'] ?? null)) {
                $this->organizationNames[(int) $organization['id']] = $organization['name'];
            }
        }
        $endTime = (int) ($data['end_time'] ?? 0);
        // Zendesk documents that a short or empty page does not mean the export is complete: only end_of_stream
        // does. The row count is a fallback for a response that carries no such flag at all.
        $flag = $data['end_of_stream'] ?? null;
        $cp['stream_done'] = $flag === true || ($flag === null && ($rows === [] || count($rows) < 1000)) || $endTime === 0;
        if (! $cp['stream_done'] && $endTime <= $start) {
            throw new \RuntimeException('Zendesk organization export cannot advance at the timestamp boundary; import remains incomplete.');
        }
        $cp['org_since'] = max($endTime, $start);
        $cp['pages'] = (int) ($cp['pages'] ?? 0) + 1;
        if ($cp['pages'] > 100000) {
            throw new \RuntimeException('Zendesk organization export exceeded the page limit.');
        }

        return $cp;
    }

    /**
     * @param  array<string,mixed>  $cp
     * @return array<string,mixed>
     */
    private function articlesStep(ConnectorInstallation $installation, SyncRun $run, ZendeskClient $client, array $cp): array
    {
        if ($cp['stream_done'] ?? false) {
            return $this->transition($installation, $run, $cp, 'articles');
        }
        $locale = self::locale($installation);
        $path = $locale === null ? '/help_center/articles' : '/help_center/'.$locale.'/articles';
        $data = $client->get($path, ['page' => array_filter(['size' => 100, 'after' => $cp['cursor'] ?? null])]);
        foreach ((array) ($data['articles'] ?? []) as $article) {
            if (! is_array($article) || ! isset($article['id'])) {
                throw new \RuntimeException('Zendesk article list is missing an article identity.');
            }
            $this->importer->article($installation, $run, $article);
        }
        $after = $data['meta']['after_cursor'] ?? null;
        $cp['stream_done'] = ! ($data['meta']['has_more'] ?? false) || ! is_string($after) || $after === '';
        if (! $cp['stream_done'] && $after === ($cp['cursor'] ?? null)) {
            throw new \RuntimeException('Zendesk article cursor did not advance; import remains incomplete.');
        }
        $cp['cursor'] = is_string($after) && $after !== '' ? $after : $cp['cursor'];
        $cp['pages'] = (int) ($cp['pages'] ?? 0) + 1;
        if ($cp['pages'] > 10000) {
            throw new \RuntimeException('Zendesk article pagination limit exceeded.');
        }

        return $cp;
    }

    /**
     * @param  array<string,mixed>  $cp
     * @return array<string,mixed>
     */
    private function articleCheckStep(ConnectorInstallation $installation, SyncRun $run, ZendeskClient $client, array $cp): array
    {
        $state = $this->states($installation)->where('kind', 'article')->where('id', '>', (int) ($cp['check_cursor'] ?? 0))
            ->where('last_seen_run_id', '!=', $run->id)->orderBy('id')->first();
        if ($state === null) {
            return $this->transition($installation, $run, $cp, 'article_check');
        }
        try {
            $data = $client->get('/help_center/articles/'.(int) $state->remote_id);
            $article = $data['article'] ?? null;
            if (! is_array($article) || ! isset($article['id'])) {
                throw new \RuntimeException('Zendesk article detail is missing its identity.');
            }
            $configured = self::locale($installation);
            $actual = is_string($article['locale'] ?? null) ? str_replace('_', '-', strtolower($article['locale'])) : null;
            if ($configured !== null && $actual !== null && $actual !== $configured) {
                // The unscoped detail endpoint still finds it, but it is outside the configured locale: retire it.
                $this->importer->delete($installation, $run, 'article', $state->remote_id);
            } else {
                $this->importer->article($installation, $run, $article);
            }
        } catch (ZendeskApiException $exception) {
            if ($exception->status !== 404) {
                throw $exception;
            }
            // Only a detail 404 confirms deletion; absence from a list does not.
            $this->importer->delete($installation, $run, 'article', $state->remote_id);
        }
        $cp['check_cursor'] = $state->id;

        return $cp;
    }

    /**
     * History mode only: objects the full export did not return are looked up
     * in batches of 100; Zendesk omits unknown ids from show_many, which is the
     * deletion evidence.
     *
     * @param  array<string,mixed>  $cp
     * @return array<string,mixed>
     */
    private function customerCheckStep(ConnectorInstallation $installation, SyncRun $run, ZendeskClient $client, array $cp): array
    {
        $kind = (string) ($cp['check_kind'] ?? 'user');
        $switch = $kind === 'user' ? 'customers.enabled' : 'organizations.enabled';
        if (! (bool) data_get($installation->config_json, $switch, true)) {
            // A disabled content type is never reconciled: its existing documents are left untouched.
            if ($kind === 'user') {
                $cp['check_kind'] = 'organization';
                $cp['check_cursor'] = 0;

                return $cp;
            }

            return $this->transition($installation, $run, $cp, 'customer_check');
        }
        $states = $this->states($installation)->where('kind', $kind)->where('id', '>', (int) ($cp['check_cursor'] ?? 0))
            ->where('last_seen_run_id', '!=', $run->id)->orderBy('id')->limit(100)->get();
        if ($states->isEmpty()) {
            if ($kind === 'user') {
                $cp['check_kind'] = 'organization';
                $cp['check_cursor'] = 0;

                return $cp;
            }

            return $this->transition($installation, $run, $cp, 'customer_check');
        }
        $ids = $states->pluck('remote_id')->map(fn ($id) => (int) $id)->all();
        $collection = $kind === 'user' ? 'users' : 'organizations';
        $data = $client->get('/'.$collection.'/show_many', ['ids' => implode(',', $ids)]);
        $present = [];
        foreach ((array) ($data[$collection] ?? []) as $row) {
            if (is_array($row) && isset($row['id'])) {
                $present[(int) $row['id']] = $row;
            }
        }
        foreach ($states as $state) {
            $row = $present[(int) $state->remote_id] ?? null;
            if ($row === null || ($kind === 'user' && ($row['role'] ?? '') !== 'end-user')) {
                $this->importer->delete($installation, $run, $kind, $state->remote_id);
            } elseif ($kind === 'user') {
                $this->importer->user($installation, $run, $row, $this->organizationName($client, $row['organization_id'] ?? null));
            } else {
                $this->importer->organization($installation, $run, $row);
            }
            $cp['check_cursor'] = max((int) ($cp['check_cursor'] ?? 0), $state->id);
        }

        return $cp;
    }

    /**
     * Move to the next enabled phase after `$current`, resetting per-phase cursors.
     *
     * @param  array<string,mixed>  $cp
     * @return array<string,mixed>
     */
    private function transition(ConnectorInstallation $installation, SyncRun $run, array $cp, string $current): array
    {
        $index = array_search($current, self::PHASES, true);
        $next = 'done';
        for ($i = ($index === false ? 0 : $index + 1); $i < count(self::PHASES); $i++) {
            if ($this->enabled($installation, $run, self::PHASES[$i])) {
                $next = self::PHASES[$i];
                break;
            }
        }
        $cp['phase'] = $next;
        $cp['cursor'] = null;
        $cp['pending'] = [];
        $cp['pages'] = 0;
        $cp['stream_done'] = false;
        $cp['check_cursor'] = 0;
        $cp['check_kind'] = 'user';

        return $cp;
    }

    private function enabled(ConnectorInstallation $installation, SyncRun $run, string $phase): bool
    {
        $config = $installation->config_json;

        return match ($phase) {
            'tickets' => true,
            'users' => (bool) data_get($config, 'customers.enabled', true),
            'organizations' => (bool) data_get($config, 'organizations.enabled', true),
            'articles', 'article_check' => (bool) data_get($config, 'help_center.enabled', true),
            'customer_check' => $run->mode === 'history' && ((bool) data_get($config, 'customers.enabled', true) || (bool) data_get($config, 'organizations.enabled', true)),
            default => false,
        };
    }

    private function organizationName(ZendeskClient $client, mixed $organizationId): ?string
    {
        if ($organizationId === null || (int) $organizationId < 1) {
            return null;
        }
        $id = (int) $organizationId;
        if (array_key_exists($id, $this->organizationNames)) {
            return $this->organizationNames[$id];
        }
        try {
            $name = $client->get('/organizations/'.$id)['organization']['name'] ?? null;
        } catch (ZendeskApiException $exception) {
            if (! in_array($exception->status, [403, 404], true)) {
                throw $exception;
            }
            $name = null;
        }

        return $this->organizationNames[$id] = is_string($name) ? $name : null;
    }

    public static function locale(ConnectorInstallation $installation): ?string
    {
        $locale = strtolower(trim((string) data_get($installation->config_json, 'help_center.locale', '')));
        if ($locale === '') {
            return null;
        }
        if (preg_match('/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/D', $locale) !== 1) {
            throw new \RuntimeException('Zendesk Help Center locale is invalid.');
        }

        return $locale;
    }

    /** Hash of everything that changes what an already-imported object would contain or where it lives. */
    public static function projection(ConnectorInstallation $installation): string
    {
        $project = $installation->project_key ?: data_get($installation->config_json, 'project_key', config('kb.ingest.default_project', 'default'));

        return hash('sha256', json_encode([
            'project' => is_string($project) && $project !== '' ? $project : 'default',
            'internal' => data_get($installation->config_json, 'include_internal_notes', true),
            'attachments' => data_get($installation->config_json, 'attachments', []),
            'ocr' => config('kb.ocr.enabled', false),
        ], JSON_THROW_ON_ERROR));
    }

    /** @return Builder<SyncRun> */
    private function runs(ConnectorInstallation $installation): Builder
    {
        return SyncRun::query()->where('tenant_id', $installation->tenant_id)->where('installation_id', $installation->id);
    }

    /** @return Builder<SourceState> */
    private function states(ConnectorInstallation $installation): Builder
    {
        return SourceState::query()->where('tenant_id', $installation->tenant_id)->where('installation_id', $installation->id);
    }
}
