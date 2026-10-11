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
     * Help Center visibility is defined by user segments, as `user_segment_id` (one segment, or null for
     * everyone) and, on plans with multiple segments, `user_segment_ids` (a list). A non-empty list wins
     * even when the singular field is null, otherwise an article restricted to several segments would be
     * reported as public. A payload that carries neither field says nothing about visibility, so the answer
     * is "unknown" rather than "anyone": a host must never read a missing field as a public share.
     *
     * @param  array<string|int,mixed>  $article
     */
    public static function article(array $article): SourceAccess
    {
        if (! array_key_exists('user_segment_id', $article) && ! array_key_exists('user_segment_ids', $article)) {
            return SourceAccess::unknown();
        }
        $plural = $article['user_segment_ids'] ?? null;
        if ($plural !== null && ! is_array($plural)) {
            return SourceAccess::unknown();
        }
        $segments = [];
        foreach ((array) $plural as $segment) {
            if (! is_int($segment) && ! (is_string($segment) && ctype_digit($segment))) {
                return SourceAccess::unknown();
            }
            $segments[(int) $segment] = true;
        }
        $single = $article['user_segment_id'] ?? null;
        if ($single !== null) {
            $segments[(int) $single] = true;
        }
        if ($segments === []) {
            return SourceAccess::of([SourcePrincipal::anyone()]);
        }

        return SourceAccess::of(array_map(
            static fn (int $segment) => SourcePrincipal::group(self::GROUP_USER_SEGMENT_PREFIX.$segment),
            array_keys($segments),
        ));
    }
}
