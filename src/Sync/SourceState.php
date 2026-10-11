<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorZendesk\Sync;

use Illuminate\Database\Eloquent\Model;

/**
 * What this installation last delivered for one remote object, keyed by
 * (tenant, installation, kind, remote id). The fingerprint makes re-delivery
 * of unchanged content a no-op; `last_seen_run_id` is what reconciliation
 * phases use to find objects a scan did not meet.
 *
 * @property int $id
 * @property string $kind
 * @property string $remote_id
 * @property ?string $parent_id
 * @property string $fingerprint
 * @property string $relative_path
 * @property int $last_seen_run_id
 */
final class SourceState extends Model
{
    protected $table = 'zendesk_source_states';

    protected $guarded = [];
}
