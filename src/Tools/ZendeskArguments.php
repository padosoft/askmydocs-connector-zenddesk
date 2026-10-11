<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorZendesk\Tools;

/** Request identity follows the actual wire defaults, not the planner's spelling. */
final class ZendeskArguments
{
    /** Zendesk's search index never returns more than 1000 results: 10 pages of 100. */
    public const SEARCH_PAGE_LIMIT = 10;

    public const MAX_PER_PAGE = 100;

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    public static function normalize(string $action, array $arguments): array
    {
        if (in_array($action, ['search_tickets', 'search_users', 'search_agents', 'search_articles'], true)) {
            $arguments['page'] = (int) ($arguments['page'] ?? 1);
        }
        if (in_array($action, ['list_tickets', 'list_comments'], true)) {
            $arguments['per_page'] = min(self::MAX_PER_PAGE, (int) ($arguments['per_page'] ?? 30));
        }
        foreach (['query', 'term', 'email'] as $key) {
            if (is_string($arguments[$key] ?? null)) {
                $arguments[$key] = trim((string) preg_replace('/\s+/u', ' ', $arguments[$key]));
            }
        }
        ksort($arguments);

        return $arguments;
    }

    /**
     * A ticket search query is Zendesk search syntax: free text plus
     * `operator:value` / `operator>value` / `operator<value` filters.
     * The connector always forces `type:ticket`, so any other `type:` would
     * silently widen the search to users and organizations and is refused,
     * as are control characters and unbalanced quotes.
     */
    public static function validTicketQuery(string $query): bool
    {
        $query = trim($query);
        if ($query === '' || strlen($query) > 512 || preg_match('/[\x00-\x1f\x7f]/', $query) === 1) {
            return false;
        }
        if (substr_count($query, '"') % 2 !== 0) {
            return false;
        }
        if (preg_match('/(?:^|\s)-?type\s*[:<>=]/i', $query) === 1) {
            return false;
        }

        return true;
    }

    /** A free-text term: non-empty, bounded, no control characters. */
    public static function validTerm(string $term): bool
    {
        $term = trim($term);

        return $term !== '' && strlen($term) <= 255 && preg_match('/[\x00-\x1f\x7f]/', $term) === 0;
    }

    /** Cursor tokens are opaque; accept only the URL-safe alphabet Zendesk issues. */
    public static function validCursor(string $cursor): bool
    {
        return preg_match('/^[A-Za-z0-9_\-=+\/.]{1,2048}$/D', $cursor) === 1;
    }
}
