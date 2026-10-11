<?php

namespace Padosoft\AskMyDocsConnectorZendesk\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorZendesk\Tests\TestCase;
use Padosoft\AskMyDocsConnectorZendesk\Tools\ZendeskArguments;
use Padosoft\AskMyDocsConnectorZendesk\Tools\ZendeskTools;

final class ToolsTest extends TestCase
{
    use FakesZendesk {
        authorized as private baseAuthorized;
    }

    /** Live tools are opt-in per installation; every test but the default-state one wants them on. */
    private function authorized(array $config = []): ConnectorInstallation
    {
        return $this->baseAuthorized(array_replace_recursive(['live_tools' => ['enabled' => true]], $config));
    }

    private function run_(string $action, array $arguments, ?Model $installation = null, array $config = []): array
    {
        $installation ??= $this->authorized($config);

        return app(ZendeskTools::class)->execute('zendesk_'.$installation->id.'_'.$action, $arguments, 'support');
    }

    private function fake(array $routes): void
    {
        Http::swap(new Factory);
        // A fresh Response per request: a streamed body can be read only once.
        Http::fake(array_map(fn ($body) => fn () => is_int($body) ? Http::response([], $body) : Http::response($body), $routes));
    }

    public function test_catalog_has_eleven_distinct_tools_per_active_installation_and_is_scoped(): void
    {
        $a = $this->authorized();
        $b = $this->authorized();
        $this->installation([], 'tenant-b');
        $tools = app(ZendeskTools::class);
        $names = array_column($tools->catalog('support'), 'name');
        $this->assertCount(22, $names);
        $this->assertCount(22, array_unique($names));
        $this->assertContains('zendesk_'.$a->id.'_list_tickets', $names);
        $this->assertSame([], $tools->catalog('other'));
        $this->assertSame([], $tools->catalog(null));
        config(['connector-zendesk.chat_tools.enabled' => false]);
        $this->assertSame([], $tools->catalog('support'));
    }

    public function test_every_tool_declares_schema_capability_and_resolvable_next_tools(): void
    {
        $this->authorized();
        $catalog = app(ZendeskTools::class)->catalog('support');
        $names = array_column($catalog, 'name');
        foreach ($catalog as $tool) {
            $this->assertFalse($tool['inputSchema']['additionalProperties'], $tool['name']);
            $this->assertSame('records', $tool['capability']['collection_path']);
            foreach ($tool['capability']['next_tools'] as $next) {
                $this->assertContains($next, $names, $tool['name'].' → '.$next);
            }
        }
    }

    public function test_a_foreign_tenant_installation_and_a_revoked_one_are_not_executable(): void
    {
        $other = $this->installation([], 'tenant-b');
        try {
            $this->run_('get_ticket', ['id' => 1], $other);
            $this->fail();
        } catch (\RuntimeException) {
            $this->addToAssertionCount(1);
        }
        $mine = $this->authorized();
        $mine->forceFill(['status' => 'disabled'])->save();
        $this->expectException(\RuntimeException::class);
        $this->run_('get_ticket', ['id' => 1], $mine);
    }

    public function test_unknown_missing_and_malformed_arguments_are_rejected_before_any_request(): void
    {
        Http::preventStrayRequests();
        $installation = $this->authorized();
        foreach ([['id' => 1, 'url' => 'https://evil.example'], [], ['id' => 0], ['id' => '5'], ['id' => 1.5]] as $arguments) {
            try {
                $this->run_('get_ticket', $arguments, $installation);
                $this->fail(json_encode($arguments));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        Http::assertNothingSent();
    }

    public function test_get_ticket_returns_a_bounded_record_with_a_stable_url_and_no_signed_links(): void
    {
        $this->zendesk();
        $result = $this->run_('get_ticket', ['id' => 1]);
        $record = $result['data']['records'][0];
        $this->assertSame(1, $record['id']);
        $this->assertSame('open', $record['status']);
        $this->assertSame(10, $record['requester_id']);
        $this->assertSame('https://example.zendesk.com/agent/tickets/1', $record['url']);
        $this->assertSame(1, $result['physical_request_count']);
        $this->assertSame('zendesk', $result['provenance']['source']);
        $this->assertArrayNotHasKey('via', $record);
    }

    public function test_list_tickets_by_requester_uses_cursor_pagination_and_hands_back_the_next_cursor(): void
    {
        $this->fake(['*' => ['tickets' => [['id' => 9, 'requester_id' => 42, 'status' => 'closed']], 'meta' => ['has_more' => true, 'after_cursor' => 'abc123'], 'links' => []]]);
        $result = $this->run_('list_tickets', ['requester_id' => 42, 'per_page' => 1]);
        Http::assertSent(fn ($r) => str_contains(urldecode($r->url()), '/users/42/tickets/requested') && str_contains(urldecode($r->url()), 'page[size]=1'));
        $this->assertTrue($result['data']['has_more']);
        $this->assertSame('abc123', $result['data']['next_cursor']);
        $this->assertFalse($result['data']['coverage']['complete']);
        $this->assertSame('closed', $result['data']['records'][0]['status']);
        $this->assertSame(42, $result['data']['coverage']['requester_id']);
    }

    public function test_list_tickets_follows_a_cursor_and_rejects_a_forged_one(): void
    {
        $this->fake(['*' => ['tickets' => [], 'meta' => ['has_more' => false]]]);
        $installation = $this->authorized();
        $this->run_('list_tickets', ['organization_id' => 5, 'cursor' => 'abc123'], $installation);
        Http::assertSent(fn ($r) => str_contains(urldecode($r->url()), '/organizations/5/tickets') && str_contains(urldecode($r->url()), 'page[after]=abc123'));
        $bad = $this->run_('list_tickets', ['cursor' => "x\ny"], $installation);
        $this->assertSame('invalid_query', $bad['error_code']);
        $this->assertSame(0, $bad['physical_request_count']);
    }

    public function test_list_tickets_by_email_goes_through_search_and_never_logs_the_address(): void
    {
        $this->fake(['*' => ['results' => [['id' => 3, 'result_type' => 'ticket', 'status' => 'open']], 'next_page' => null, 'count' => 1]]);
        $result = $this->run_('list_tickets', ['email' => 'anne@example.test']);
        Http::assertSent(fn ($r) => str_contains(urldecode($r->url()), 'type:ticket requester:"anne@example.test"'));
        $this->assertNull($result['data']['coverage']['requester_id']);
        $this->assertSame(hash('sha256', 'anne@example.test'), $result['data']['coverage']['email_hash']);
        $this->assertStringNotContainsString('anne@example.test', json_encode($result['data']['coverage']));
        $this->assertSame(1, $result['data']['total']);
    }

    public function test_list_tickets_requires_a_single_selector(): void
    {
        Http::preventStrayRequests();
        $result = $this->run_('list_tickets', ['requester_id' => 42, 'email' => 'a@b.co']);
        $this->assertSame('invalid_query', $result['error_code']);
        $this->assertSame(0, $result['physical_request_count']);
    }

    public function test_search_tickets_forces_type_ticket_and_refuses_other_types(): void
    {
        $this->fake(['*' => ['results' => [], 'next_page' => null, 'count' => 0]]);
        $installation = $this->authorized();
        $this->run_('search_tickets', ['query' => 'status:open priority>normal printer'], $installation);
        Http::assertSent(fn ($r) => str_contains(urldecode($r->url()), 'query=type:ticket status:open priority>normal printer'));
        foreach (['type:user', 'status:open type:organization', '-type:ticket', 'TYPE:user', 'say "hi'] as $query) {
            $result = $this->run_('search_tickets', ['query' => $query], $installation);
            $this->assertSame('invalid_query', $result['error_code'], $query);
            $this->assertSame(0, $result['physical_request_count']);
        }
    }

    public function test_search_pagination_stops_at_the_1000_result_cap_and_says_so(): void
    {
        $this->fake(['*' => ['results' => [['id' => 1, 'result_type' => 'ticket']], 'next_page' => 'https://example.zendesk.com/api/v2/search?page=11', 'count' => 5000]]);
        $installation = $this->authorized();
        $last = $this->run_('search_tickets', ['query' => 'status:open', 'page' => 10], $installation);
        $this->assertTrue($last['data']['has_more']);
        $this->assertContains('search_result_cap_1000', $last['data']['coverage']['limitations']);
        $this->assertFalse($last['data']['coverage']['complete']);
        $beyond = $this->run_('search_tickets', ['query' => 'status:open', 'page' => 11], $installation);
        $this->assertSame('invalid_query', $beyond['error_code']);
        $this->assertSame(0, $beyond['physical_request_count']);
    }

    public function test_person_lookup_requires_selection_for_partial_or_duplicate_matches(): void
    {
        $installation = $this->authorized();
        $this->fake(['*' => ['users' => [['id' => 42, 'name' => 'Anne Richard', 'email' => 'anne@example.test', 'role' => 'end-user']]]]);
        $exact = $this->run_('search_users', ['term' => 'anne richard'], $installation);
        $this->assertFalse($exact['data']['meta']['ambiguous']);
        $byEmail = $this->run_('search_users', ['term' => 'ANNE@example.test'], $installation);
        $this->assertFalse($byEmail['data']['meta']['ambiguous']);
        $partial = $this->run_('search_users', ['term' => 'Anne'], $installation);
        $this->assertTrue($partial['data']['meta']['ambiguous']);
        $this->fake(['*' => ['users' => [['id' => 42, 'name' => 'Anne Richard', 'role' => 'end-user'], ['id' => 43, 'name' => 'Anne Richard', 'role' => 'end-user']]]]);
        $this->assertTrue($this->run_('search_users', ['term' => 'Anne Richard'], $installation)['data']['meta']['ambiguous']);
    }

    public function test_customer_search_never_returns_agents_but_get_user_can(): void
    {
        $installation = $this->authorized();
        $this->fake(['*' => ['users' => [['id' => 7, 'name' => 'Sam Agent', 'email' => 'sam@example.com', 'role' => 'agent'], ['id' => 8, 'name' => 'Sam Customer', 'role' => 'end-user']]]]);
        $result = $this->run_('search_users', ['term' => 'Sam'], $installation);
        $this->assertSame([8], array_column($result['data']['records'], 'id'));
        $this->fake(['*' => ['users' => [['id' => 7, 'name' => 'Sam Agent', 'role' => 'agent']]]]);
        $onlyAgent = $this->run_('search_users', ['term' => 'Sam Agent'], $installation);
        $this->assertSame([], $onlyAgent['data']['records'], 'an exact agent match must not look like a customer');
        $this->assertFalse($onlyAgent['data']['meta']['ambiguous']);
        $this->fake(['*' => ['user' => ['id' => 7, 'name' => 'Sam Agent', 'role' => 'agent']]]);
        $this->assertSame('agent', $this->run_('get_user', ['id' => 7], $installation)['data']['records'][0]['role']);
    }

    public function test_agent_search_filters_on_the_agent_role_and_organization_search_uses_autocomplete(): void
    {
        $installation = $this->authorized();
        $this->fake(['*' => ['results' => [['id' => 7, 'name' => 'Sam Agent', 'email' => 'sam@example.com', 'result_type' => 'user']]]]);
        $agent = $this->run_('search_agents', ['term' => 'sam@example.com'], $installation);
        Http::assertSent(fn ($r) => str_contains(urldecode($r->url()), 'type:user role:agent sam@example.com'));
        $this->assertFalse($agent['data']['meta']['ambiguous']);
        $this->fake(['*' => ['organizations' => [['id' => 5, 'name' => 'Acme Corp', 'domain_names' => ['acme.test']]]]]);
        $org = $this->run_('search_organizations', ['term' => 'acme'], $installation);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/organizations/autocomplete'));
        $this->assertTrue($org['data']['meta']['ambiguous']);
        $this->assertContains('autocomplete_returns_first_matches_only', $org['data']['coverage']['limitations']);
    }

    public function test_get_user_and_organization_carry_details_and_notes_as_body(): void
    {
        $installation = $this->authorized();
        $this->fake(['*' => ['user' => ['id' => 42, 'name' => 'Anne', 'email' => 'anne@example.test', 'role' => 'end-user', 'notes' => 'VIP', 'details' => 'Prefers phone', 'password' => 'must-not-appear']]]);
        $user = $this->run_('get_user', ['id' => 42], $installation)['data']['records'][0];
        $this->assertSame('anne@example.test', $user['email']);
        $this->assertStringContainsString('Prefers phone', $user['body']);
        $this->assertStringNotContainsString('must-not-appear', json_encode($user));
        $this->assertSame('https://example.zendesk.com/agent/users/42', $user['url']);
        $this->fake(['*' => ['organization' => ['id' => 5, 'name' => 'Acme', 'notes' => 'Key account']]]);
        $org = $this->run_('get_organization', ['id' => 5], $installation)['data']['records'][0];
        $this->assertStringContainsString('Key account', $org['body']);
    }

    public function test_comments_hide_internal_notes_when_disabled_and_never_expose_attachment_urls(): void
    {
        $this->zendesk(['/tickets/1/comments' => ['comments' => [
            ['id' => 1, 'author_id' => 10, 'public' => true, 'created_at' => 'a', 'plain_body' => 'Public', 'attachments' => [['id' => 5, 'file_name' => 'x.pdf', 'content_type' => 'application/pdf', 'size' => 9, 'content_url' => 'https://example.zendesk.com/attachments/token/SIGNED/', 'mapped_content_url' => 'https://z/SIGNED2']]],
            ['id' => 2, 'author_id' => 11, 'public' => false, 'created_at' => 'b', 'plain_body' => 'Internal'],
        ], 'meta' => ['has_more' => true, 'after_cursor' => 'next-1']]]);
        $installation = $this->authorized(['include_internal_notes' => false]);
        $result = $this->run_('list_comments', ['id' => 1], $installation);
        $this->assertCount(1, $result['data']['records']);
        $this->assertStringNotContainsString('SIGNED', json_encode($result));
        $this->assertSame([['id' => 5, 'file_name' => 'x.pdf', 'content_type' => 'application/pdf', 'size' => 9]], $result['data']['records'][0]['attachments']);
        $this->assertSame('next-1', $result['data']['next_cursor']);
        $withNotes = $this->run_('list_comments', ['id' => 1], $this->authorized());
        $this->assertCount(2, $withNotes['data']['records']);
    }

    public function test_drafts_are_hidden_from_article_tools(): void
    {
        $installation = $this->authorized();
        $this->fake(['*' => ['article' => ['id' => 5, 'title' => 'Draft', 'draft' => true, 'body' => '<p>x</p>']]]);
        $this->assertSame([], $this->run_('get_article', ['id' => 5], $installation)['data']['records']);
        $this->fake(['*' => ['results' => [['id' => 1, 'title' => 'Draft', 'draft' => true], ['id' => 2, 'title' => 'Public', 'draft' => false, 'body' => '<p>Safe answer</p>', 'html_url' => 'https://example.zendesk.com/hc/en-us/articles/2']], 'next_page' => null]]);
        $result = $this->run_('search_articles', ['term' => 'answer'], $installation);
        $this->assertSame([2], array_column($result['data']['records'], 'id'));
        $this->assertStringContainsString('Safe answer', $result['data']['records'][0]['body']);
        $this->assertSame('https://example.zendesk.com/hc/en-us/articles/2', $result['data']['records'][0]['url']);
    }

    public function test_an_article_url_on_a_foreign_host_is_replaced_by_the_account_url(): void
    {
        $this->fake(['*' => ['article' => ['id' => 3, 'title' => 'T', 'draft' => false, 'html_url' => 'https://evil.example/phish', 'body' => 'x']]]);
        $record = $this->run_('get_article', ['id' => 3])['data']['records'][0];
        $this->assertSame('https://example.zendesk.com/hc/articles/3', $record['url']);
    }

    public function test_results_are_capped_and_truncation_is_declared(): void
    {
        $this->fake(['*' => ['ticket' => ['id' => 1, 'subject' => 's', 'description' => str_repeat('x', 17000)]]]);
        $large = $this->run_('get_ticket', ['id' => 1]);
        $this->assertTrue($large['data']['truncated']);
        $this->assertFalse($large['data']['coverage']['complete']);
        $this->assertSame(16000, mb_strlen($large['data']['records'][0]['body']));
        config(['connector-zendesk.chat_tools.max_result_bytes' => 60]);
        $tiny = $this->run_('get_ticket', ['id' => 1]);
        $this->assertTrue($tiny['data']['truncated']);
        $this->assertSame([], $tiny['data']['records']);
    }

    public function test_failures_are_structured_sanitised_and_never_an_empty_list(): void
    {
        $installation = $this->authorized();
        foreach ([400 => 'invalid_query', 401 => 'authentication_failed', 403 => 'permission_denied', 404 => 'resource_not_found', 429 => 'rate_limited'] as $status => $code) {
            $this->fake(['*' => $status]);
            $result = $this->run_('get_ticket', ['id' => 1], $installation);
            $this->assertArrayNotHasKey('data', $result);
            $this->assertSame($code, $result['error_code']);
            $this->assertSame($status, $result['http_status']);
            $this->assertSame($status === 429, $result['retryable']);
            $this->assertSame(1, $result['physical_request_count']);
        }
        $this->fake(['*' => 503]);
        $failed = $this->run_('get_ticket', ['id' => 1], $installation);
        $this->assertTrue($failed['retryable']);
        $this->assertSame(3, $failed['physical_request_count']);
    }

    public function test_argument_helpers(): void
    {
        $this->assertSame(['page' => 1, 'query' => 'a b'], ZendeskArguments::normalize('search_tickets', ['query' => "  a \n b "]));
        $this->assertSame(100, ZendeskArguments::normalize('list_tickets', ['per_page' => 5000])['per_page']);
        $this->assertSame(30, ZendeskArguments::normalize('list_comments', [])['per_page']);
        $this->assertTrue(ZendeskArguments::validTicketQuery('status:open priority>normal "exact phrase"'));
        $this->assertTrue(ZendeskArguments::validTicketQuery('subject:"type of printer"'));
        $this->assertFalse(ZendeskArguments::validTicketQuery(str_repeat('a', 513)));
        $this->assertFalse(ZendeskArguments::validTicketQuery(''));
        $this->assertTrue(ZendeskArguments::validCursor('eyJvIjoiaWQiLCJ2IjoiYVFFPSJ9'));
        $this->assertFalse(ZendeskArguments::validCursor('a b'));
        $this->assertFalse(ZendeskArguments::validTerm("a\x00b"));
    }

    public function test_live_tools_are_off_until_the_installation_opts_in(): void
    {
        $installation = $this->baseAuthorized();
        $tools = app(ZendeskTools::class);
        $this->assertSame([], $tools->catalog('support'));
        $this->expectException(\RuntimeException::class);
        $tools->execute('zendesk_'.$installation->id.'_get_ticket', ['id' => 1], 'support');
    }

    public function test_the_user_name_map_never_pushes_a_result_past_the_size_cap(): void
    {
        $users = array_map(fn ($i) => ['id' => $i, 'name' => 'Person number '.$i.str_repeat('x', 30)], range(1, 200));
        $this->fake(['*' => ['ticket' => ['id' => 1, 'subject' => 's', 'description' => 'short'], 'users' => $users]]);
        $roomy = $this->run_('get_ticket', ['id' => 1]);
        $this->assertCount(200, $roomy['data']['meta']['users']);

        config(['connector-zendesk.chat_tools.max_result_bytes' => 4000]);
        $capped = $this->run_('get_ticket', ['id' => 1]);
        $this->assertCount(1, $capped['data']['records']);
        $this->assertArrayNotHasKey('users', $capped['data']['meta']);
        $this->assertTrue($capped['data']['meta']['users_omitted']);
        $this->assertLessThanOrEqual(4000, strlen(json_encode($capped['data'])));
    }

    public function test_get_ticket_does_not_return_the_description_when_internal_notes_are_off(): void
    {
        $this->fake(['*' => ['ticket' => ['id' => 1, 'subject' => 's', 'description' => 'PRIVATE-FIRST-NOTE']]]);
        $off = $this->run_('get_ticket', ['id' => 1], null, ['include_internal_notes' => false]);
        $this->assertStringNotContainsString('PRIVATE-FIRST-NOTE', json_encode($off));
        $on = $this->run_('get_ticket', ['id' => 1]);
        $this->assertSame('PRIVATE-FIRST-NOTE', $on['data']['records'][0]['body']);
    }
}
