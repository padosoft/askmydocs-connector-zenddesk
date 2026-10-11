<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorZendesk\Sync;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;

/**
 * One bounded batch of a scan. Each continuation is a fresh job so a long,
 * healthy import never exhausts the queue's retry budget.
 */
final class ProcessSyncBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(public readonly int $runId, public readonly string $tenantId)
    {
        $this->onQueue((string) config('connector-zendesk.sync.queue', 'zendesk'));
        $this->onConnection(config('connector-zendesk.sync.connection'));
    }

    /** @return list<object|string> */
    public function middleware(): array
    {
        return config('connector-zendesk.sync.middleware', []);
    }

    public function handle(SyncManager $manager, TenantContext $tenants): void
    {
        $prior = $tenants->current();
        $tenants->set($this->tenantId);
        try {
            $run = SyncRun::query()->where('tenant_id', $this->tenantId)->whereKey($this->runId)->first();
            if ($run === null) {
                return;
            }
            $delay = $manager->batch($this->runId);
            if ($delay !== null) {
                self::dispatch($this->runId, $this->tenantId)->delay($delay);
            }
        } finally {
            $tenants->set($prior);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        // A queue timeout can kill the worker before batch() records anything.
        // The exception body is never persisted: transport errors may quote URLs.
        $message = 'Zendesk worker stopped before completing the batch; resume the import.';
        SyncRun::query()->where('tenant_id', $this->tenantId)->whereKey($this->runId)->whereIn('status', ['queued', 'running', 'failed'])
            ->update(['status' => 'failed', 'error' => $message]);
        $run = SyncRun::query()->where('tenant_id', $this->tenantId)->whereKey($this->runId)->where('status', 'failed')->first();
        if ($run === null) {
            return;
        }
        ConnectorInstallation::query()->where('tenant_id', $this->tenantId)->whereKey($run->installation_id)
            ->whereIn('status', ['active', 'errored'])->update(['status' => 'errored', 'error_json' => ['message' => $message]]);
    }
}
