<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorZendesk\Tools;

use Closure;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use Padosoft\AskMyDocsConnectorZendesk\Exceptions\ZendeskApiException;
use Padosoft\AskMyDocsConnectorZendesk\Support\Markdown;
use Padosoft\AskMyDocsConnectorZendesk\Sync\SyncManager;

/**
 * Read-only live tools: eleven per active installation, named
 * `zendesk_{installationId}_{action}`.
 *
 * `catalog()` lists only the active installations of the current tenant that
 * are bound to the requested project; `execute()` repeats those checks, so a
 * revoked or re-scoped installation stops working between planning and
 * calling. Results are bounded, link-free of signed URLs, and never turn a
 * failure into an empty list.
 */
final readonly class ZendeskTools
{
    private const TICKET_FIELDS = ['id', 'subject', 'status', 'priority', 'type', 'requester_id', 'assignee_id', 'submitter_id', 'organization_id', 'group_id', 'tags', 'created_at', 'updated_at'];

    private const USER_FIELDS = ['id', 'name', 'email', 'role', 'organization_id', 'phone', 'tags', 'time_zone', 'locale', 'suspended', 'created_at', 'updated_at'];

    private const ORGANIZATION_FIELDS = ['id', 'name', 'domain_names', 'tags', 'group_id', 'created_at', 'updated_at'];

    private const ARTICLE_FIELDS = ['id', 'title', 'locale', 'section_id', 'author_id', 'label_names', 'html_url', 'created_at', 'updated_at', 'edited_at'];

    private const COMMENT_FIELDS = ['id', 'author_id', 'public', 'created_at'];

    public function __construct(private TenantContext $tenants, private SyncManager $sync) {}

    /**
     * @return list<array<string,mixed>>
     */
    public function catalog(?string $projectKey = null): array
    {
        if (! config('connector-zendesk.chat_tools.enabled', true)) {
            return [];
        }
        $tools = [];
        $installations = ConnectorInstallation::query()->where('tenant_id', $this->tenants->current())->where('connector_name', 'zendesk')->where('status', 'active')->get();
        foreach ($installations as $installation) {
            if (! $this->matchesProject($installation, $projectKey)) {
                continue;
            }
            foreach (self::definitions() as $action => $definition) {
                $tools[] = [
                    'name' => 'zendesk_'.$installation->id.'_'.$action,
                    'description' => $definition['description'].' Account: '.$installation->label.'.',
                    'inputSchema' => $definition['schema'],
                    'outputSchema' => self::outputSchema(),
                    'capability' => self::capability($action, (int) $installation->id),
                    'installation_id' => $installation->id, 'action' => $action,
                    'provenance' => ['source' => 'zendesk', 'installation_id' => $installation->id],
                    'account_label' => $installation->label,
                ];
            }
        }

        return $tools;
    }

    /**
     * @param  array<string|int,mixed>  $arguments
     * @return array<string|int,mixed>
     */
    public function execute(string $name, array $arguments, ?string $projectKey = null, ?Closure $beforeRequest = null): array
    {
        $tool = collect($this->catalog($projectKey))->firstWhere('name', $name);
        if ($tool === null) {
            throw new \RuntimeException('Zendesk tool is no longer available in this project.');
        }
        $action = (string) $tool['action'];
        $schema = self::definitions()[$action]['schema'];
        self::assertArguments($schema, $arguments);
        $arguments = ZendeskArguments::normalize($action, $arguments);
        $installation = $this->sync->installation((int) $tool['installation_id']);
        $client = $this->sync->client($installation, $beforeRequest);
        $failure = fn (string $message, string $code) => ['error' => $message, 'error_code' => $code, 'http_status' => null, 'retryable' => false,
            'validation_fields' => [], 'physical_request_count' => $client->physicalRequests, 'provenance' => $tool['provenance']];
        try {
            $invalid = $this->invalidReason($action, $arguments);
            if ($invalid !== null) {
                return $failure($invalid, 'invalid_query');
            }
            [$path, $query, $key] = $this->request($action, $arguments);
            $data = $client->get($path, $query);
            $rows = $key === null ? [$data[$action === 'get_ticket' ? 'ticket' : (str_contains($action, 'user') ? 'user' : (str_contains($action, 'organization') ? 'organization' : 'article'))] ?? []] : (array) ($data[$key] ?? []);
            $names = [];
            foreach ((array) ($data['users'] ?? []) as $user) {
                if (is_array($user) && isset($user['id'], $user['name'])) {
                    $names[(int) $user['id']] = (string) $user['name'];
                }
            }
            $records = [];
            foreach ($rows as $row) {
                if (is_array($row) && $this->visible($row, $action, $installation)) {
                    $records[] = $this->record($row, $action, $client->host());
                }
            }
            $result = $this->envelope($action, $arguments, $data, $records);
            $result = $this->fit($result, $records);
            if ($names !== [] && $result['records'] !== []) {
                $result['meta']['users'] = $names;
            }

            return ['data' => $result, 'physical_request_count' => $client->physicalRequests, 'provenance' => $tool['provenance']];
        } catch (\Throwable $exception) {
            return ['error' => $exception->getMessage(),
                ...($exception instanceof ZendeskApiException ? $exception->diagnostics() : ['error_code' => 'request_failed', 'http_status' => null, 'retryable' => ! ($exception instanceof \InvalidArgumentException), 'validation_fields' => []]),
                'physical_request_count' => $client->physicalRequests, 'provenance' => $tool['provenance']];
        }
    }

    /**
     * @param  array<string,mixed>  $arguments
     */
    private function invalidReason(string $action, array $arguments): ?string
    {
        if ($action === 'search_tickets' && ! ZendeskArguments::validTicketQuery((string) $arguments['query'])) {
            return 'Use Zendesk search syntax for tickets (free text and filters such as status:open priority>normal); do not set type: yourself.';
        }
        if (in_array($action, ['search_users', 'search_agents', 'search_organizations', 'search_articles'], true) && ! ZendeskArguments::validTerm((string) $arguments['term'])) {
            return 'The search term is empty, too long or contains control characters.';
        }
        if ($action === 'list_tickets' && count(array_intersect_key($arguments, array_flip(['requester_id', 'organization_id', 'email']))) > 1) {
            return 'Pass at most one of requester_id, organization_id or email to list_tickets.';
        }
        if (isset($arguments['cursor']) && ! ZendeskArguments::validCursor((string) $arguments['cursor'])) {
            return 'The cursor is not a token returned by a previous call.';
        }
        if (($arguments['page'] ?? 1) > ZendeskArguments::SEARCH_PAGE_LIMIT) {
            return 'Zendesk search returns at most 1000 results (10 pages of 100); narrow the query instead of paging further.';
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array{0:string,1:array<string,mixed>,2:?string}
     */
    private function request(string $action, array $arguments): array
    {
        $id = (int) ($arguments['id'] ?? 0);
        $page = ['size' => $arguments['per_page'] ?? 30, ...(isset($arguments['cursor']) ? ['after' => $arguments['cursor']] : [])];

        return match ($action) {
            'list_tickets' => match (true) {
                isset($arguments['requester_id']) => ['/users/'.(int) $arguments['requester_id'].'/tickets/requested', ['page' => $page, 'sort' => '-updated_at', 'include' => 'users'], 'tickets'],
                isset($arguments['organization_id']) => ['/organizations/'.(int) $arguments['organization_id'].'/tickets', ['page' => $page, 'sort' => '-updated_at', 'include' => 'users'], 'tickets'],
                isset($arguments['email']) => ['/search', ['query' => 'type:ticket requester:'.self::quote((string) $arguments['email']), 'sort_by' => 'updated_at', 'sort_order' => 'desc', 'page' => (int) ($arguments['page'] ?? 1), 'per_page' => 100, 'include' => 'users'], 'results'],
                default => ['/tickets', ['page' => $page, 'sort' => '-updated_at', 'include' => 'users'], 'tickets'],
            },
            'search_tickets' => ['/search', ['query' => 'type:ticket '.$arguments['query'], 'sort_by' => 'updated_at', 'sort_order' => 'desc', 'page' => $arguments['page'], 'per_page' => 100, 'include' => 'users'], 'results'],
            'search_users' => ['/users/search', ['query' => $arguments['term'], 'page' => $arguments['page'], 'per_page' => 25], 'users'],
            'search_agents' => ['/search', ['query' => 'type:user role:agent '.$arguments['term'], 'page' => $arguments['page'], 'per_page' => 25], 'results'],
            'search_organizations' => ['/organizations/autocomplete', ['name' => $arguments['term']], 'organizations'],
            'get_user' => ['/users/'.$id, [], null],
            'get_organization' => ['/organizations/'.$id, [], null],
            'get_ticket' => ['/tickets/'.$id, ['include' => 'users'], null],
            'list_comments' => ['/tickets/'.$id.'/comments', ['page' => $page, 'include' => 'users'], 'comments'],
            'search_articles' => ['/help_center/articles/search', ['query' => $arguments['term'], 'page' => $arguments['page'], 'per_page' => 25], 'results'],
            'get_article' => ['/help_center/articles/'.$id, [], null],
            default => throw new \LogicException('Unknown Zendesk tool action.'),
        };
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @param  array<string|int,mixed>  $data
     * @param  list<array<string,mixed>>  $records
     * @return array<string,mixed>
     */
    private function envelope(string $action, array $arguments, array $data, array $records): array
    {
        $cursorPaged = isset($data['meta']['has_more']);
        $searchPaged = isset($data['next_page']) || (in_array($action, ['search_tickets', 'search_users', 'search_agents', 'search_articles'], true) || isset($arguments['email']));
        $page = (int) ($arguments['page'] ?? 1);
        $hasMore = $cursorPaged ? (bool) $data['meta']['has_more'] : (is_string($data['next_page'] ?? null) && $data['next_page'] !== '');
        $capped = $searchPaged && ! $cursorPaged && $hasMore && $page >= ZendeskArguments::SEARCH_PAGE_LIMIT;
        $personLookup = in_array($action, ['search_users', 'search_agents', 'search_organizations'], true);
        $normalise = static fn (string $name): string => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
        $term = (string) ($arguments['term'] ?? '');
        $exact = count($records) === 1 && ($normalise((string) ($records[0]['name'] ?? '')) === $normalise($term) || mb_strtolower((string) ($records[0]['email'] ?? '')) === mb_strtolower($term));
        $paginated = in_array($action, ['list_tickets', 'search_tickets', 'list_comments'], true);
        $result = [
            'records' => [], 'page' => $page, 'truncated' => false, 'has_more' => $hasMore,
            'next_cursor' => $cursorPaged && $hasMore ? (string) ($data['meta']['after_cursor'] ?? '') : null,
            'meta' => ['ambiguous' => $personLookup && $records !== [] && ! $exact, 'term_hash' => $personLookup ? hash('sha256', $normalise($term)) : null],
            'coverage' => [
                'requester_id' => $arguments['requester_id'] ?? null, 'organization_id' => $arguments['organization_id'] ?? null,
                'email_hash' => isset($arguments['email']) ? hash('sha256', mb_strtolower((string) $arguments['email'])) : null,
                'filter' => $arguments['query'] ?? null, 'sorted_by' => $paginated ? 'updated_at_desc' : null,
                'scope' => $paginated ? 'api_enumerable' : 'lookup', 'complete' => ! $hasMore,
                'limitations' => array_values(array_filter([
                    str_contains($action, 'tickets') || isset($arguments['email']) ? 'archived_tickets_may_be_excluded' : null,
                    $searchPaged && ! $cursorPaged ? 'search_index_may_lag_by_minutes' : null,
                    $capped ? 'search_result_cap_1000' : null,
                    $action === 'search_organizations' ? 'autocomplete_returns_first_matches_only' : null,
                ])),
            ],
        ];
        if (isset($data['count']) && is_int($data['count'])) {
            $result['total'] = $data['count'];
        }

        return $result;
    }

    /**
     * Add records one by one while the serialized result stays under the cap.
     *
     * @param  array<string,mixed>  $result
     * @param  list<array<string,mixed>>  $records
     * @return array<string,mixed>
     */
    private function fit(array $result, array $records): array
    {
        $cap = (int) config('connector-zendesk.chat_tools.max_result_bytes', 65536);
        foreach ($records as $record) {
            if ($record['body_truncated'] ?? false) {
                $result['truncated'] = true;
                $result['coverage']['complete'] = false;
            }
            $next = $result;
            $next['records'][] = $record;
            if (strlen(json_encode($next, JSON_THROW_ON_ERROR)) > $cap) {
                $result['truncated'] = true;
                $result['coverage']['complete'] = false;
                break;
            }
            $result = $next;
        }

        return $result;
    }

    private function matchesProject(ConnectorInstallation $installation, ?string $project): bool
    {
        $scope = $installation->project_key ?: data_get($installation->config_json, 'project_key', config('kb.ingest.default_project', 'default'));

        return $project !== null && $project !== '' && $scope === $project;
    }

    /**
     * @param  array<string|int,mixed>  $row
     */
    private function visible(array $row, string $action, ConnectorInstallation $installation): bool
    {
        if (str_contains($action, 'article')) {
            return ! ($row['draft'] ?? false);
        }
        if ($action === 'list_comments') {
            return (bool) data_get($installation->config_json, 'include_internal_notes', true) || ($row['public'] ?? true) !== false;
        }

        if ($action === 'search_users') {
            // The user search also matches agents and admins; this tool resolves customers (requesters).
            return ($row['role'] ?? null) === 'end-user';
        }

        // A deleted ticket still answers show with status "deleted" until it is purged; it is not readable content.
        return $action !== 'get_ticket' || ($row['status'] ?? null) !== 'deleted';
    }

    /**
     * @param  array<string|int,mixed>  $row
     * @return array<string,mixed>
     */
    private function record(array $row, string $action, string $host): array
    {
        $type = $row['result_type'] ?? match (true) {
            str_contains($action, 'comment') => 'comment',
            str_contains($action, 'article') => 'article',
            str_contains($action, 'organization') => 'organization',
            str_contains($action, 'user'), str_contains($action, 'agent') => 'user',
            default => 'ticket',
        };
        $allowed = match ($type) {
            'comment' => self::COMMENT_FIELDS, 'article' => self::ARTICLE_FIELDS, 'organization' => self::ORGANIZATION_FIELDS, 'user' => self::USER_FIELDS, default => self::TICKET_FIELDS,
        };
        $fields = array_intersect_key($row, array_flip($allowed));
        $body = Markdown::body($row + ['body' => $row['body'] ?? $row['description'] ?? '']);
        if ($type === 'user' || $type === 'organization') {
            $body = trim((string) ($row['details'] ?? '')."\n".(string) ($row['notes'] ?? ''));
        }
        if ($body !== '') {
            $fields['body'] = mb_substr($body, 0, 16000);
            if (mb_strlen($body) > 16000) {
                $fields['body_truncated'] = true;
            }
        }
        $fields['attachments'] = array_map(
            static fn ($file) => array_intersect_key((array) $file, array_flip(['id', 'file_name', 'content_type', 'size'])),
            array_values(array_filter((array) ($row['attachments'] ?? []), 'is_array')),
        );
        if ($type !== 'comment' && isset($row['id'])) {
            $fields['url'] = $type === 'article' && is_string($row['html_url'] ?? null) && strtolower((string) parse_url($row['html_url'], PHP_URL_HOST)) === $host
                ? $row['html_url']
                : 'https://'.$host.match ($type) {
                    'article' => '/hc/articles/', 'organization' => '/agent/organizations/', 'user' => '/agent/users/', default => '/agent/tickets/',
                }.(int) $row['id'];
        }
        $fields['kind'] = $type;

        return $fields;
    }

    /**
     * @param  array<string,mixed>  $schema
     * @param  array<string|int,mixed>  $arguments
     */
    private static function assertArguments(array $schema, array $arguments): void
    {
        foreach (array_keys($arguments) as $key) {
            if (! isset($schema['properties'][$key])) {
                throw new \InvalidArgumentException('Unknown Zendesk tool argument.');
            }
        }
        foreach ($schema['required'] ?? [] as $required) {
            if (! isset($arguments[$required])) {
                throw new \InvalidArgumentException('Missing Zendesk tool argument: '.$required);
            }
        }
        foreach ($arguments as $key => $value) {
            $type = $schema['properties'][$key]['type'];
            if (($type === 'integer' && (! is_int($value) || $value < 1)) || ($type === 'string' && (! is_string($value) || trim($value) === '' || strlen($value) > 2048))) {
                throw new \InvalidArgumentException('Invalid Zendesk tool argument: '.$key);
            }
        }
    }

    private static function quote(string $value): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }

    /** @return array<string,mixed> */
    private static function capability(string $action, int $installationId): array
    {
        $entity = match (true) {
            str_contains($action, 'organization') => 'organization',
            str_contains($action, 'user'), str_contains($action, 'agent') => 'user',
            str_contains($action, 'article') => 'article',
            str_contains($action, 'comment') => 'comment',
            default => 'ticket',
        };
        $operation = str_starts_with($action, 'search') ? 'search' : (str_starts_with($action, 'list') ? 'list' : 'get');
        $next = match ($action) {
            'search_users' => ['get_user', 'list_tickets'],
            'get_user' => ['list_tickets'],
            'search_organizations' => ['get_organization', 'list_tickets'],
            'get_organization' => ['list_tickets'],
            'search_agents' => ['search_tickets'],
            'list_tickets', 'search_tickets' => ['get_ticket'],
            'get_ticket' => ['list_comments'],
            'search_articles' => ['get_article'],
            default => [],
        };

        return ['entity' => $entity, 'operation' => $operation, 'collection_path' => 'records', 'identity_fields' => ['id'],
            'next_tools' => array_map(static fn ($name) => 'zendesk_'.$installationId.'_'.$name, $next),
            'query_semantics' => $action === 'search_tickets' ? 'zendesk_search_syntax' : 'text'];
    }

    /** @return array<string,mixed> */
    private static function outputSchema(): array
    {
        $properties = ['id' => ['type' => 'integer'], 'kind' => ['type' => 'string']];
        foreach (['name', 'email', 'subject', 'title', 'body', 'url', 'status', 'priority'] as $field) {
            $properties[$field] = ['type' => ['string', 'null']];
        }
        foreach (['requester_id', 'assignee_id', 'organization_id', 'author_id'] as $field) {
            $properties[$field] = ['type' => ['integer', 'null']];
        }

        return ['type' => 'object', 'properties' => [
            'records' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => $properties]],
            'has_more' => ['type' => 'boolean'], 'next_cursor' => ['type' => ['string', 'null']], 'truncated' => ['type' => 'boolean'],
            'coverage' => ['type' => 'object'], 'meta' => ['type' => 'object'],
        ]];
    }

    /** @return array<string,array{description:string,schema:array<string,mixed>}> */
    private static function definitions(): array
    {
        $integer = ['type' => 'integer', 'minimum' => 1];
        $string = ['type' => 'string', 'maxLength' => 512];
        $make = static fn (string $description, array $properties, array $required = []) => ['description' => $description, 'schema' => ['type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false]];

        return [
            'list_tickets' => $make('List tickets requested by a verified requester_id, belonging to an organization_id, or requested by a literal email (pass at most one); with none, lists the most recently updated tickets. All statuses, newest first. Continue with next_cursor (or page for the email form) while has_more=true.', ['requester_id' => $integer, 'organization_id' => $integer, 'email' => $string, 'cursor' => $string, 'page' => $integer, 'per_page' => $integer]),
            'search_tickets' => $make('Search tickets with Zendesk search syntax: free text plus filters such as status:open, priority>normal, assignee_id:123, organization_id:45, tags:vip, created>2026-01-01. The tool forces type:ticket; at most 1000 results.', ['query' => $string, 'page' => $integer], ['query']),
            'search_users' => $make('Resolve a customer (end user) by name or email before listing their tickets; agents are not returned (use search_agents). Proceed only for a unique exact match or a user selection; meta.ambiguous=true requires selection.', ['term' => $string, 'page' => $integer], ['term']),
            'get_user' => $make('Read a verified user (customer or agent) by numeric ID, including name, email, organization and role.', ['id' => $integer], ['id']),
            'search_agents' => $make('Resolve a support agent by name or email only when the user explicitly asks for assigned tickets. Then use search_tickets with assignee_id:<verified ID>.', ['term' => $string, 'page' => $integer], ['term']),
            'search_organizations' => $make('Resolve a customer organization by name before listing its tickets (autocomplete: first matches only).', ['term' => $string], ['term']),
            'get_organization' => $make('Read a verified organization by numeric ID, including domains, tags, details and notes.', ['id' => $integer], ['id']),
            'get_ticket' => $make('Read a Zendesk ticket by its numeric ID.', ['id' => $integer], ['id']),
            'list_comments' => $make('Read a page of ticket comments (oldest first), including internal notes when enabled. Continue with next_cursor while has_more=true.', ['id' => $integer, 'cursor' => $string, 'per_page' => $integer], ['id']),
            'search_articles' => $make('Search published Help Center articles by keyword.', ['term' => $string, 'page' => $integer], ['term']),
            'get_article' => $make('Read a published Help Center article by ID.', ['id' => $integer], ['id']),
        ];
    }
}
