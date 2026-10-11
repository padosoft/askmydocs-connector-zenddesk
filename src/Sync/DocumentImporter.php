<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorZendesk\Sync;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Padosoft\AskMyDocsConnectorBase\Access\SourceAccess;
use Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorBase\Support\Metadata\SourceAwareMetadataBuilder;
use Padosoft\AskMyDocsConnectorZendesk\Http\UrlPolicy;
use Padosoft\AskMyDocsConnectorZendesk\Http\ZendeskClient;
use Padosoft\AskMyDocsConnectorZendesk\Support\Markdown;
use Padosoft\AskMyDocsConnectorZendesk\Support\RemoteId;
use Padosoft\AskMyDocsConnectorZendesk\Support\SourceAccessPolicy;

/**
 * Turns Zendesk objects into KB documents and hands them to the host.
 *
 * One `write()` path for every kind: redaction of text bodies, a content
 * fingerprint that suppresses unchanged deliveries, a stable relative path,
 * source-aware metadata with the reported ACL, and the per-run counters.
 */
final readonly class DocumentImporter
{
    private const MIMES = [
        'pdf' => 'application/pdf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'txt' => 'text/plain',
        'md' => 'text/markdown',
        'markdown' => 'text/markdown',
    ];

    private const IMAGE_MIMES = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'tif' => 'image/tiff', 'tiff' => 'image/tiff', 'webp' => 'image/webp'];

    public function __construct(private ConnectorIngestionContract $ingestion) {}

    /**
     * Import one ticket: detail (with requester/assignee/organization
     * side-loads), every comment page (with author side-loads), then the
     * attachments of the visible comments.
     */
    public function ticket(ConnectorInstallation $installation, SyncRun $run, ZendeskClient $client, int $ticketId): void
    {
        $detail = $client->get('/tickets/'.$ticketId, ['include' => 'users,organizations']);
        $ticket = $detail['ticket'] ?? null;
        if (! is_array($ticket) || ! isset($ticket['id'])) {
            throw new \RuntimeException('Zendesk ticket detail is missing its identity.');
        }
        if (($ticket['status'] ?? null) === 'deleted') {
            $this->delete($installation, $run, 'ticket', (string) $ticket['id']);

            return;
        }
        $names = self::names($detail);
        $internal = (bool) data_get($installation->config_json, 'include_internal_notes', true);
        $comments = [];
        $cursor = null;
        for ($page = 1; $page <= 300; $page++) {
            $query = ['include' => 'users', 'page' => array_filter(['size' => 100, 'after' => $cursor])];
            $data = $client->get('/tickets/'.$ticketId.'/comments', $query);
            $names += self::names($data);
            foreach ((array) ($data['comments'] ?? []) as $comment) {
                if (! is_array($comment)) {
                    continue;
                }
                if (! $internal && ($comment['public'] ?? true) === false) {
                    continue;
                }
                $comments[] = $comment;
            }
            if (! ($data['meta']['has_more'] ?? false)) {
                break;
            }
            $cursor = (string) ($data['meta']['after_cursor'] ?? '');
            if ($cursor === '') {
                break;
            }
            if ($page === 300) {
                throw new \RuntimeException('Zendesk comments exceeded the pagination limit.');
            }
        }
        $organization = self::organizationName($detail, $ticket['organization_id'] ?? null);
        $body = Markdown::ticket($ticket, $comments, $names, $organization);
        $fields = [
            'status' => $ticket['status'] ?? null, 'priority' => $ticket['priority'] ?? null, 'type' => $ticket['type'] ?? null,
            'requester_id' => $ticket['requester_id'] ?? null, 'assignee_id' => $ticket['assignee_id'] ?? null,
            'organization_id' => $ticket['organization_id'] ?? null, 'group_id' => $ticket['group_id'] ?? null,
            'channel' => $ticket['via']['channel'] ?? null, 'tags' => array_values(array_map('strval', (array) ($ticket['tags'] ?? []))),
            'created_at' => $ticket['created_at'] ?? null, 'updated_at' => $ticket['updated_at'] ?? null, 'internal_notes_included' => $internal,
        ];
        $this->write($installation, $run, 'ticket', (string) $ticket['id'], (string) ($ticket['subject'] ?? 'Ticket #'.$ticket['id']), $body, 'text/markdown', null, $fields,
            SourceAccessPolicy::agentsOnly(), ! in_array($ticket['status'] ?? '', ['closed', 'solved'], true));
        $this->attachments($installation, $run, $client, (string) $ticket['id'], $comments);
    }

    /**
     * @param  array<string|int,mixed>  $user
     */
    public function user(ConnectorInstallation $installation, SyncRun $run, array $user, ?string $organization): void
    {
        $body = Markdown::user($user, $organization);
        // Identity fields stay in the redacted body only: the metadata envelope is not redacted by the host.
        $fields = [
            'role' => $user['role'] ?? null, 'organization_id' => $user['organization_id'] ?? null, 'suspended' => (bool) ($user['suspended'] ?? false),
            'tags' => array_values(array_map('strval', (array) ($user['tags'] ?? []))), 'created_at' => $user['created_at'] ?? null, 'updated_at' => $user['updated_at'] ?? null,
        ];
        $this->write($installation, $run, 'user', (string) $user['id'], (string) ($user['name'] ?? 'Customer #'.$user['id']), $body, 'text/markdown', null, $fields,
            SourceAccessPolicy::agentsOnly(), ! ($user['suspended'] ?? false));
    }

    /**
     * @param  array<string|int,mixed>  $organization
     */
    public function organization(ConnectorInstallation $installation, SyncRun $run, array $organization): void
    {
        $fields = [
            'domain_names' => array_values(array_map('strval', (array) ($organization['domain_names'] ?? []))),
            'tags' => array_values(array_map('strval', (array) ($organization['tags'] ?? []))),
            'created_at' => $organization['created_at'] ?? null, 'updated_at' => $organization['updated_at'] ?? null,
        ];
        $this->write($installation, $run, 'organization', (string) $organization['id'], (string) ($organization['name'] ?? 'Organization #'.$organization['id']),
            Markdown::organization($organization), 'text/markdown', null, $fields, SourceAccessPolicy::agentsOnly(), true);
    }

    /**
     * @param  array<string|int,mixed>  $article
     */
    public function article(ConnectorInstallation $installation, SyncRun $run, array $article): void
    {
        $id = (string) $article['id'];
        if ($article['draft'] ?? false) {
            $this->delete($installation, $run, 'article', $id);

            return;
        }
        $fields = [
            'section_id' => $article['section_id'] ?? null, 'locale' => $article['locale'] ?? null, 'author_id' => $article['author_id'] ?? null,
            'user_segment_id' => $article['user_segment_id'] ?? null, 'permission_group_id' => $article['permission_group_id'] ?? null,
            'tags' => array_values(array_map('strval', (array) ($article['label_names'] ?? []))), 'outdated' => (bool) ($article['outdated'] ?? false),
            'created_at' => $article['created_at'] ?? null, 'updated_at' => $article['updated_at'] ?? null, 'edited_at' => $article['edited_at'] ?? null,
            'html_url' => is_string($article['html_url'] ?? null) ? $article['html_url'] : null,
        ];
        $this->write($installation, $run, 'article', $id, (string) ($article['title'] ?? 'Article'), Markdown::article($article), 'text/markdown', null, $fields,
            SourceAccessPolicy::article($article), ! ($article['outdated'] ?? false));
    }

    /**
     * @param  array<int,array<string|int,mixed>>  $comments
     */
    private function attachments(ConnectorInstallation $installation, SyncRun $run, ZendeskClient $client, string $ticketId, array $comments): void
    {
        $attachments = [];
        foreach ($comments as $comment) {
            foreach ((array) ($comment['attachments'] ?? []) as $attachment) {
                if (is_array($attachment) && ! ($attachment['inline'] ?? false)) {
                    $attachments[] = $attachment;
                }
            }
        }
        $accepted = [];
        if (data_get($installation->config_json, 'attachments.enabled', true)) {
            $limit = max(1, min(100, (int) data_get($installation->config_json, 'attachments.max_per_ticket', 20)));
            foreach ($attachments as $attachment) {
                if (count($accepted) >= $limit) {
                    $this->count($run, 'attachments_skipped');

                    continue;
                }
                if ($this->attachment($installation, $run, $client, $attachment, $ticketId)) {
                    $accepted[] = (string) $attachment['id'];
                }
            }
        }
        // Hiding internal notes also removes the attachments they carried.
        // Deleted or malware-flagged files are positive evidence that the file must not stay indexed.
        $remoteVisible = [];
        foreach ($attachments as $attachment) {
            if (! ($attachment['deleted'] ?? false) && ($attachment['malware_scan_result'] ?? '') !== 'malware_found') {
                $remoteVisible[] = (int) ($attachment['id'] ?? 0);
            }
        }
        foreach ($this->states($installation)->where('kind', 'attachment')->where('parent_id', $ticketId)->get() as $state) {
            if (in_array((string) $state->remote_id, $accepted, true)) {
                continue;
            }
            // A disabled import or a size limit is not evidence of remote deletion; a flagged or deleted file is.
            if (! in_array((int) $state->remote_id, $remoteVisible, true)) {
                $this->remove($installation, $run, $state);
            }
        }
    }

    /**
     * @param  array<string|int,mixed>  $attachment
     */
    private function attachment(ConnectorInstallation $installation, SyncRun $run, ZendeskClient $client, array $attachment, string $ticketId): bool
    {
        $name = basename(str_replace('\\', '/', (string) ($attachment['file_name'] ?? '')));
        $name = preg_replace('/[\x00-\x1f\x7f]/', '', $name) ?? '';
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $mimes = self::MIMES;
        if (config('kb.ocr.enabled', false)) {
            $mimes += self::IMAGE_MIMES;
        }
        $maxBytes = max(1, min(100, (int) data_get($installation->config_json, 'attachments.max_size_mb', 25))) * 1024 * 1024;
        $malware = ($attachment['malware_scan_result'] ?? 'malware_not_found') === 'malware_found';
        if ($malware || ($attachment['deleted'] ?? false) || ! isset($mimes[$extension]) || (int) ($attachment['size'] ?? 0) > $maxBytes || ! isset($attachment['id'], $attachment['content_url'])) {
            $this->count($run, 'attachments_skipped');

            return false;
        }
        $mime = $mimes[$extension];
        $bytes = $client->download((string) $attachment['content_url'], $maxBytes);
        $detected = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $compatible = $detected === $mime
            || ($mime === 'text/markdown' && $detected === 'text/plain')
            || ($extension === 'docx' && in_array($detected, ['application/zip', 'application/octet-stream'], true) && $this->isDocx($bytes));
        $declared = isset($attachment['content_type']) ? strtolower(trim(explode(';', (string) $attachment['content_type'])[0])) : $mime;
        $declaredOk = $declared === $mime || (in_array($extension, ['md', 'markdown'], true) && $declared === 'text/plain') || $declared === 'application/octet-stream';
        if (! $compatible || ! $declaredOk) {
            throw new \RuntimeException('Zendesk attachment MIME does not match its file type.');
        }
        $this->write($installation, $run, 'attachment', (string) $attachment['id'], $name, $bytes, $mime, $ticketId, ['size' => strlen($bytes)], SourceAccess::inherited(), true, $extension);

        return true;
    }

    private function isDocx(string $bytes): bool
    {
        $path = tempnam(sys_get_temp_dir(), 'zendesk-docx-');
        if ($path === false) {
            throw new \RuntimeException('Unable to validate Zendesk DOCX attachment.');
        }
        try {
            file_put_contents($path, $bytes);
            $zip = new \ZipArchive;
            if ($zip->open($path) !== true) {
                return false;
            }
            try {
                return $zip->locateName('[Content_Types].xml') !== false && $zip->locateName('word/document.xml') !== false;
            } finally {
                $zip->close();
            }
        } finally {
            unlink($path);
        }
    }

    /**
     * @param  array<string|int,mixed>  $fields
     */
    private function write(ConnectorInstallation $installation, SyncRun $run, string $kind, string $id, string $title, string $body, string $mime, ?string $parent, array $fields, SourceAccess $access, bool $active, string $extension = 'md'): void
    {
        if (preg_match('/^[1-9][0-9]*$/D', $id) !== 1) {
            throw new \RuntimeException('Zendesk returned an invalid remote identifier.');
        }
        if (in_array($mime, ['text/markdown', 'text/plain'], true)) {
            // The title is indexed text too: a subject, a customer name or an article title can carry PII.
            $body = $this->ingestion->redactContent($body);
            $title = $this->ingestion->redactContent($title);
        }
        $identity = ['tenant_id' => $installation->tenant_id, 'installation_id' => $installation->id, 'kind' => $kind, 'remote_id' => $id];
        $state = SourceState::query()->where($identity)->first();
        $project = $installation->project_key ?: data_get($installation->config_json, 'project_key', config('kb.ingest.default_project', 'default'));
        $project = is_string($project) && $project !== '' ? $project : 'default';
        if (preg_match('/^[a-zA-Z0-9_-]+$/D', $project) !== 1) {
            throw new \RuntimeException('Zendesk project key is not a safe directory name.');
        }
        $relative = $project.'/connectors/zendesk/tenant-'.hash('sha256', $installation->tenant_id).'/installation-'.$installation->id.'/'.$kind.'/'.$id.'.'.$extension;
        // The destination is part of the identity of a delivery: rebinding the installation to another project
        // must re-deliver unchanged content there instead of leaving it only in the previous project.
        $fingerprint = hash('sha256', $body."\0".$title."\0".json_encode($fields)."\0".json_encode($access->toArray())."\0".$relative);
        if ($state !== null && $state->fingerprint === $fingerprint) {
            $state->forceFill(['last_seen_run_id' => $run->id])->save();

            return;
        }
        if ($state !== null && $state->relative_path !== $relative) {
            // Retire the copy indexed under the previous project before the new one is dispatched, so the old
            // project's readers stop seeing it. Both copies share one remote id, hence this order.
            $this->ingestion->softDeleteByRemoteId($installation, 'zendesk_remote_id', RemoteId::format((int) $installation->id, $kind, $id));
        }
        $paths = $this->ingestion->resolveKbSourcePath($relative);
        if (! Storage::disk($paths['disk'])->put($paths['absolute'], $body)) {
            throw new \RuntimeException('Unable to write the Zendesk source file.');
        }
        $remoteId = RemoteId::format((int) $installation->id, $kind, $id);
        $metadata = (new SourceAwareMetadataBuilder)->build([
            'connector' => 'zendesk', 'installation_id' => $installation->id, 'zendesk_remote_id' => $remoteId,
            'remote_id' => $id, 'source_url' => $this->sourceUrl($installation, $kind, $id, $parent, $fields), 'source_kind' => $kind,
        ], 'zendesk', $fields + ['id' => $id, 'kind' => $kind, 'ticket_id' => $parent], (array) ($fields['tags'] ?? []), $active, $fields['updated_at'] ?? null);
        $metadata[SourceAccess::METADATA_KEY] = $access->toArray();
        $this->ingestion->dispatchIngestion($project, $paths['relative'], $paths['disk'], $title, $metadata, $mime, $installation->tenant_id);
        SourceState::query()->updateOrCreate($identity, ['parent_id' => $parent, 'fingerprint' => $fingerprint, 'relative_path' => $relative, 'last_seen_run_id' => $run->id]);
        $this->count($run, $state === null ? 'documents_added' : 'documents_updated');
        $this->count($run, $kind.'s');
    }

    /**
     * @param  array<string|int,mixed>  $fields
     */
    private function sourceUrl(ConnectorInstallation $installation, string $kind, string $id, ?string $parent, array $fields): string
    {
        $host = UrlPolicy::host((string) data_get($installation->config_json, 'connection.subdomain'));
        if ($kind === 'article') {
            $html = $fields['html_url'] ?? null;
            if (is_string($html) && strtolower((string) parse_url($html, PHP_URL_HOST)) === $host && (parse_url($html, PHP_URL_SCHEME) === 'https')) {
                return $html;
            }

            return 'https://'.$host.'/hc/articles/'.$id;
        }

        return 'https://'.$host.'/agent/'.match ($kind) {
            'user' => 'users/'.$id,
            'organization' => 'organizations/'.$id,
            default => 'tickets/'.($parent ?? $id),
        };
    }

    public function delete(ConnectorInstallation $installation, SyncRun $run, string $kind, string $id): void
    {
        $states = $this->states($installation)->where(function ($query) use ($kind, $id): void {
            $query->where(fn ($q) => $q->where('kind', $kind)->where('remote_id', $id));
            if ($kind === 'ticket') {
                $query->orWhere(fn ($q) => $q->where('kind', 'attachment')->where('parent_id', $id));
            }
        })->get();
        foreach ($states as $state) {
            $this->remove($installation, $run, $state);
        }
    }

    private function remove(ConnectorInstallation $installation, SyncRun $run, SourceState $state): void
    {
        $this->ingestion->softDeleteByRemoteId($installation, 'zendesk_remote_id', RemoteId::format((int) $installation->id, $state->kind, $state->remote_id));
        $state->delete();
        $this->count($run, 'documents_removed');
    }

    private function count(SyncRun $run, string $counter): void
    {
        $counts = $run->counts;
        $counts[$counter] = ($counts[$counter] ?? 0) + 1;
        $run->counts = $counts;
    }

    /** @return Builder<SourceState> */
    private function states(ConnectorInstallation $installation): Builder
    {
        return SourceState::query()->where('tenant_id', $installation->tenant_id)->where('installation_id', $installation->id);
    }

    /**
     * Side-loaded `users` → id → name.
     *
     * @param  array<string|int,mixed>  $payload
     * @return array<int,string>
     */
    private static function names(array $payload): array
    {
        $names = [];
        foreach ((array) ($payload['users'] ?? []) as $user) {
            if (is_array($user) && isset($user['id']) && is_string($user['name'] ?? null)) {
                $names[(int) $user['id']] = $user['name'];
            }
        }

        return $names;
    }

    /**
     * @param  array<string|int,mixed>  $payload
     */
    private static function organizationName(array $payload, mixed $organizationId): ?string
    {
        if ($organizationId === null) {
            return null;
        }
        foreach ((array) ($payload['organizations'] ?? []) as $organization) {
            if (is_array($organization) && (int) ($organization['id'] ?? 0) === (int) $organizationId && is_string($organization['name'] ?? null)) {
                return $organization['name'];
            }
        }

        return null;
    }
}
