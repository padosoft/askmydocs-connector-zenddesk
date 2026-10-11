<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorZendesk\Support;

use Padosoft\AskMyDocsConnectorBase\Access\SourceAccess;
use Padosoft\AskMyDocsConnectorBase\Access\SourcePrincipal;

/**
 * What Zendesk says about who may read an item, in the vocabulary of
 * `SupportsSourceAcl`. The connector reports; the host maps these external
 * identifiers to its own subjects.
 *
 * Group identifiers are namespaced so a host can tell them apart:
 *   - `zendesk:agents`            — every agent and admin of the account;
 *   - `zendesk:user_segment:{id}` — a Help Center user segment.
 */
final class SourceAccessPolicy
{
    public const GROUP_AGENTS = 'zendesk:agents';

    public const GROUP_USER_SEGMENT_PREFIX = 'zendesk:user_segment:';

    /**
     * Tickets, customer profiles and organization profiles are agent-facing
     * records. The requester is deliberately NOT reported as a principal: the
     * ingested document includes internal notes the requester can never see,
     * so a host that mapped the requester's email to a user would leak them.
     */
    public static function agentsOnly(): SourceAccess
    {
        return SourceAccess::of([SourcePrincipal::group(self::GROUP_AGENTS)]);
    }

    /**
     * A Help Center article with no user segment is visible to everyone,
     * including anonymous visitors; with a segment it is restricted to that
     * segment's members (signed-in users or agents, as configured in Guide).
     *
     * @param  array<string|int,mixed>  $article
     */
    public static function article(array $article): SourceAccess
    {
        $segment = $article['user_segment_id'] ?? null;
        if ($segment === null) {
            return SourceAccess::of([SourcePrincipal::anyone()]);
        }

        return SourceAccess::of([SourcePrincipal::group(self::GROUP_USER_SEGMENT_PREFIX.(int) $segment)]);
    }
}
