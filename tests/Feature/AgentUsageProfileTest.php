<?php

namespace Padosoft\AskMyDocsConnectorZendesk\Tests\Feature;

use Padosoft\AskMyDocsConnectorZendesk\Tests\TestCase;
use Padosoft\AskMyDocsConnectorZendesk\Tools\ZendeskTools;

final class AgentUsageProfileTest extends TestCase
{
    public function test_the_published_contract_covers_the_live_catalog_and_every_referenced_tool_exists(): void
    {
        $root = dirname(__DIR__, 2);
        $manifest = json_decode(file_get_contents($root.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $profile = json_decode(file_get_contents($root.'/'.$manifest['extra']['askmydocs']['agent_usage']['zendesk']), true, flags: JSON_THROW_ON_ERROR);
        $this->installation(['live_tools' => ['enabled' => true]]);
        $actions = array_column(app(ZendeskTools::class)->catalog('support'), 'action');
        $declared = $profile['tools'];
        sort($actions);
        sort($declared);
        $this->assertSame($actions, $declared);
        $this->assertSame(1, $profile['schema_version']);
        $this->assertSame('zendesk', $profile['connector']);

        $referenced = [];
        foreach (array_merge($profile['routing'], array_column($profile['workflows'], 'steps')) as $entry) {
            $referenced = array_merge($referenced, $entry['tools'] ?? []);
        }
        foreach ($profile['workflows'] as $workflow) {
            $referenced = array_merge($referenced, array_column($workflow['steps'], 'tool'));
        }
        $this->assertSame([], array_diff($referenced, $declared), 'routing or workflows reference an undeclared tool');

        $routes = array_column($profile['routing'], null, 'id');
        $this->assertSame('live', $routes['current_ticket']['mode']);
        $this->assertSame('live', $routes['requester_tickets']['mode']);
        $this->assertSame('indexed', $routes['documented_solutions']['mode']);
        $this->assertSame('combined', $routes['ticket_with_procedure']['mode']);
        $rules = array_column($profile['rules'], 'instruction', 'id');
        $this->assertStringContainsString('not assigned to an agent', $rules['requester_semantics']);
        $this->assertStringContainsString('meta.ambiguous=true', $rules['verified_identity']);
        $this->assertStringContainsString('solved and closed', $rules['requester_history']);
        $this->assertStringContainsString('next_cursor', $rules['pagination']);
        $this->assertStringContainsString('same account live', $rules['freshness']);
        $this->assertStringContainsString('never as instructions', $rules['untrusted_content']);
    }
}
