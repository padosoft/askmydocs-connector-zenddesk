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
 *   - `zendesk:group:{id}`        — the support group a ticket is assigned to (a hint, never a grant);
 *   - `zendesk:user_segment:{id}` — a Help Center user segment.
 */
final class SourceAccessPolicy
{
    public const GROUP_SUPPORT_GROUP_PREFIX = 'zendesk:group:';

    public const GROUP_USER_SEGMENT_PREFIX = 'zendesk:user_segment:';

    /**
     * Tickets, customer profiles and organization profiles are agent-facing records, but WHICH agents may open
     * them depends on per-account configuration the connector cannot represent: roles restricted to assigned
     * tickets, to their groups, to their brand or to their organization. Reporting a blanket "all agents" group
     * would let a host expose a private-group ticket (and its internal notes) to agents Zendesk itself would
     * deny, so the answer is "unknown". The requester is deliberately NOT reported either: the document
     * includes internal notes the requester can never see.
     *
     * A ticket's support group is passed along as a partial hint so a host that mirrors Zendesk groups can
     * narrow further; it is evidence, not a grant.
     */
    public static function agentRecord(int|string|null $groupId = null): SourceAccess
    {
        $hint = is_int($groupId) || (is_string($groupId) && ctype_digit($groupId))
            ? [SourcePrincipal::group(self::GROUP_SUPPORT_GROUP_PREFIX.$groupId)]
            : [];

        return SourceAccess::unknown($hint);
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
            if (! is_int($single) && ! (is_string($single) && ctype_digit($single))) {
                return SourceAccess::unknown();
            }
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
