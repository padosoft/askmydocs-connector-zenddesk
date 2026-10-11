<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorZendesk\Support;

/**
 * The one identifier shape this package stores on a document and accepts back.
 *
 * `{installation_id}:{kind}:{remote_id}` is written to
 * `metadata.zendesk_remote_id` at ingest time, is the key the host uses for
 * `softDeleteByRemoteId()`, and is what `SupportsSourceAcl::readAcl()`
 * receives — the registry keeps one connector instance per key, so the
 * installation has to travel inside the identifier.
 */
final class RemoteId
{
    public const KINDS = ['ticket', 'attachment', 'article', 'user', 'organization'];

    public static function format(int $installationId, string $kind, string $remoteId): string
    {
        return $installationId.':'.$kind.':'.$remoteId;
    }

    /**
     * @return array{installation_id:int,kind:string,remote_id:string}
     */
    public static function parse(string $value): array
    {
        if (preg_match('/^([1-9][0-9]*):([a-z]+):([1-9][0-9]*)$/D', $value, $match) !== 1 || ! in_array($match[2], self::KINDS, true)) {
            throw new \InvalidArgumentException('Invalid Zendesk remote identifier.');
        }

        return ['installation_id' => (int) $match[1], 'kind' => $match[2], 'remote_id' => $match[3]];
    }
}
