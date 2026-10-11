<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorZendesk\Sync;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One scan of one installation: its mode, its durable checkpoint and counts.
 *
 * @property int $id
 * @property string $tenant_id
 * @property int $installation_id
 * @property string $mode
 * @property string $status
 * @property array<string,mixed> $checkpoint
 * @property array<string,int> $counts
 * @property ?string $error
 * @property Carbon $scan_started_at
 */
final class SyncRun extends Model
{
    protected $table = 'zendesk_sync_runs';

    protected $guarded = [];

    /**
     * @return array<string|int,mixed>
     */
    protected function casts(): array
    {
        return ['checkpoint' => 'array', 'counts' => 'array', 'scan_started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    /**
     * @return array<string|int,mixed>
     */
    public function summary(): array
    {
        return ['id' => $this->id, 'mode' => $this->mode, 'status' => $this->status, 'counts' => $this->counts, 'error' => $this->error, 'phase' => $this->checkpoint['phase'] ?? null];
    }
}
