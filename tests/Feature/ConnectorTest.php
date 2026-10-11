<?php

namespace Padosoft\AskMyDocsConnectorZendesk\Tests\Feature;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Padosoft\AskMyDocsConnectorBase\Access\SourceAccess;
use Padosoft\AskMyDocsConnectorBase\Access\SourcePrincipal;
use Padosoft\AskMyDocsConnectorBase\Auth\OAuthCredentialVault;
use Padosoft\AskMyDocsConnectorBase\ConnectorRegistry;
use Padosoft\AskMyDocsConnectorBase\Contracts\DeclaresProvenance;
use Padosoft\AskMyDocsConnectorBase\Contracts\SupportsConnectionSettings;
use Padosoft\AskMyDocsConnectorBase\Contracts\SupportsCredentialForm;
use Padosoft\AskMyDocsConnectorBase\Contracts\SupportsSourceAcl;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorAuthException;
use Padosoft\AskMyDocsConnectorBase\ProvenanceTier;
use Padosoft\AskMyDocsConnectorBase\Support\CredentialField;
use Padosoft\AskMyDocsConnectorZendesk\Support\RemoteId;
use Padosoft\AskMyDocsConnectorZendesk\Support\SourceAccessPolicy;
use Padosoft\AskMyDocsConnectorZendesk\Tests\TestCase;
use Padosoft\AskMyDocsConnectorZendesk\ZendeskConnector;

final class ConnectorTest extends TestCase
{
    use FakesZendesk;

    private function connector(): ZendeskConnector
    {
        return app(ZendeskConnector::class);
    }

    private function state(int $installationId): string
    {
        parse_str((string) parse_url($this->connector()->initiateOAuth($installationId), PHP_URL_QUERY), $query);

        return $query['state'];
    }

    public function test_the_connector_is_registered_and_implements_every_declared_capability(): void
    {
        $connector = app(ConnectorRegistry::class)->get('zendesk');
        $this->assertInstanceOf(ZendeskConnector::class, $connector);
        foreach ([SupportsCredentialForm::class, SupportsConnectionSettings::class, DeclaresProvenance::class, SupportsSourceAcl::class] as $interface) {
            $this->assertInstanceOf($interface, $connector);
        }
        $this->assertSame('zendesk', $connector->key());
        $this->assertSame('Zendesk', $connector->displayName());
    }

    public function test_credential_form_routes_the_token_to_the_vault_and_everything_else_to_config(): void
    {
        $fields = array_column($this->connector()->credentialFormSchema(), null, 'name');
        $this->assertSame('secret', $fields['api_token']['target']);
        $this->assertTrue($fields['api_token']['secret']);
        foreach (['subdomain', 'auth_mode', 'email'] as $name) {
            $this->assertSame('connection', $fields[$name]['target']);
            $this->assertFalse($fields[$name]['secret']);
        }
        $this->assertSame(['field' => 'auth_mode', 'equals' => 'api_token'], $fields['email']['showIf']);
        foreach ($this->connector()->connectionSettingsSchema() as $field) {
            $this->assertSame('config', $field['target']);
            $this->assertFalse($field['secret'], $field['name']);
            $this->assertSame(CredentialField::class, (new \ReflectionClass(CredentialField::class))->getName());
        }
    }

    public function test_credentials_are_verified_against_users_me_before_they_reach_the_vault(): void
    {
        $installation = $this->installation();
        $this->zendesk();
        $this->connector()->handleOAuthCallback($installation->id, Request::create('/', 'POST', ['state' => $this->state($installation->id), 'api_token' => 'secret-token']));
        $this->assertSame('secret-token', app(OAuthCredentialVault::class)->getAccessToken($installation->id));
        $this->assertStringNotContainsString('secret-token', json_encode($installation->fresh()->config_json));
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Basic '.base64_encode('agent@example.com/token:secret-token')));
    }

    public function test_the_state_token_is_single_use(): void
    {
        $installation = $this->installation();
        $this->zendesk();
        $state = $this->state($installation->id);
        $this->connector()->handleOAuthCallback($installation->id, Request::create('/', 'POST', ['state' => $state, 'api_token' => 'secret-token']));
        $this->expectException(ConnectorAuthException::class);
        $this->connector()->handleOAuthCallback($installation->id, Request::create('/', 'POST', ['state' => $state, 'api_token' => 'secret-token']));
    }

    public function test_an_anonymous_answer_or_an_end_user_is_rejected_and_the_old_secret_survives(): void
    {
        $installation = $this->authorized();
        foreach ([['user' => ['id' => null, 'role' => 'end-user']], ['user' => ['id' => 5, 'role' => 'end-user']]] as $me) {
            $this->zendesk(['/users/me' => $me]);
            try {
                $this->connector()->handleOAuthCallback($installation->id, Request::create('/', 'POST', ['state' => $this->state($installation->id), 'api_token' => 'bad-token']));
                $this->fail('Credentials without agent rights must be refused.');
            } catch (ConnectorAuthException) {
                $this->assertSame('secret-token', app(OAuthCredentialVault::class)->getAccessToken($installation->id));
            }
        }
    }

    public function test_a_plain_agent_is_refused_because_incremental_exports_are_admin_only(): void
    {
        $installation = $this->authorized();
        $this->zendesk(['/users/me' => ['user' => ['id' => 5, 'role' => 'agent', 'name' => 'Plain Agent']]]);
        try {
            $this->connector()->handleOAuthCallback($installation->id, Request::create('/', 'POST', ['state' => $this->state($installation->id), 'api_token' => 'agent-token']));
            $this->fail('An agent would be accepted here and then fail every scan with a 403.');
        } catch (ConnectorAuthException $exception) {
            $this->assertStringContainsString('admin', $exception->getMessage());
            $this->assertSame('secret-token', app(OAuthCredentialVault::class)->getAccessToken($installation->id));
        }
        $this->assertSame('errored', $this->connector()->health($installation->id)->state);
    }

    public function test_a_401_from_zendesk_becomes_an_auth_error_without_the_upstream_body(): void
    {
        $installation = $this->authorized();
        Http::fake(['*' => Http::response(['error' => 'Couldn\'t authenticate you: bad-token'], 401)]);
        try {
            $this->connector()->handleOAuthCallback($installation->id, Request::create('/', 'POST', ['state' => $this->state($installation->id), 'api_token' => 'bad-token']));
            $this->fail();
        } catch (ConnectorAuthException $exception) {
            $this->assertStringNotContainsString('bad-token', $exception->getMessage());
        }
    }

    public function test_health_reports_healthy_and_errored(): void
    {
        $installation = $this->authorized();
        $this->zendesk();
        $this->assertSame('healthy', $this->connector()->health($installation->id)->state);
        $this->zendesk(['/users/me' => 401]);
        $this->assertSame('errored', $this->connector()->health($installation->id)->state);
    }

    public function test_sync_entry_points_queue_a_scan_and_ignore_the_since_argument(): void
    {
        $installation = $this->authorized();
        $result = $this->connector()->syncIncremental($installation->id, now()->subYear());
        $this->assertSame(0, $result->totalChanged());
        $this->assertDatabaseCount('zendesk_sync_runs', 1);
        $this->connector()->syncFull($installation->id);
        $this->assertDatabaseCount('zendesk_sync_runs', 1); // a queued scan is reused, never duplicated
    }

    public function test_disconnect_cancels_the_scan_and_clears_the_secret(): void
    {
        $installation = $this->authorized();
        $this->connector()->syncFull($installation->id);
        $this->connector()->disconnect($installation->id);
        $this->assertNull(app(OAuthCredentialVault::class)->getAccessToken($installation->id));
        $this->assertDatabaseHas('zendesk_sync_runs', ['installation_id' => $installation->id, 'status' => 'cancelled']);
    }

    public function test_provenance_defaults_to_untrusted_external_and_never_fails_open(): void
    {
        $this->assertSame(ProvenanceTier::UntrustedExternal, $this->connector()->provenanceTier($this->installation()->id));
        $this->assertSame(ProvenanceTier::TrustedInternal, $this->connector()->provenanceTier($this->installation(['provenance_tier' => 'trusted-internal'])->id));
        $this->assertSame(ProvenanceTier::UntrustedExternal, $this->connector()->provenanceTier($this->installation(['provenance_tier' => 'something-newer'])->id));
        $this->assertSame(ProvenanceTier::UntrustedExternal, $this->connector()->provenanceTier($this->installation(['provenance_tier' => ['x']])->id));
    }

    public function test_provenance_is_resolved_per_installation_without_a_network_call(): void
    {
        $internal = $this->installation(['provenance_tier' => 'trusted-internal']);
        $external = $this->installation();
        Http::preventStrayRequests();
        $this->assertTrue($this->connector()->provenanceTier($external->id)->isExternallyAuthored());
        $this->assertFalse($this->connector()->provenanceTier($internal->id)->isExternallyAuthored());
    }

    public function test_acl_for_a_public_article_is_anyone_and_for_a_segment_is_that_group(): void
    {
        $installation = $this->authorized();
        $this->zendesk();
        $public = $this->connector()->readAcl(RemoteId::format($installation->id, 'article', '7'));
        $this->assertTrue($public->isPublic());
        $this->zendesk(['/help_center/articles/7' => ['article' => ['id' => 7, 'user_segment_id' => 12]]]);
        $restricted = $this->connector()->readAcl(RemoteId::format($installation->id, 'article', '7'));
        $this->assertFalse($restricted->isPublic());
        $this->assertSame('zendesk:user_segment:12', $restricted->principals[0]->externalId);
        $this->assertSame(SourcePrincipal::TYPE_GROUP, $restricted->principals[0]->type);
    }

    public function test_acl_for_a_ticket_is_unknown_never_all_agents_and_never_the_requester(): void
    {
        $installation = $this->authorized();
        $this->zendesk();
        $acl = $this->connector()->readAcl(RemoteId::format($installation->id, 'ticket', '1'));
        // Agents may be restricted to assigned tickets, groups, brands or organizations: no blanket agent grant.
        $this->assertFalse($acl->complete);
        $this->assertFalse($acl->isPublic());
        $this->assertStringNotContainsString('zendesk:agents', json_encode($acl->toArray()));
        $this->assertStringNotContainsString('anne@example.test', json_encode($acl->toArray()));
    }

    public function test_a_tickets_support_group_is_reported_only_as_a_partial_hint(): void
    {
        $ids = fn ($acl) => array_map(fn ($p) => $p->externalId, $acl->principals);
        foreach ([98738, '98738'] as $group) {
            $acl = SourceAccessPolicy::agentRecord($group);
            $this->assertFalse($acl->complete);
            $this->assertSame(['zendesk:group:98738'], $ids($acl));
        }
        foreach ([null, '', 'x'] as $group) {
            $this->assertSame([], $ids(SourceAccessPolicy::agentRecord($group)));
        }
    }

    public function test_article_acl_honours_plural_segments_and_never_reads_a_missing_field_as_public(): void
    {
        $ids = fn ($acl) => array_map(fn ($p) => $p->externalId, $acl->principals);
        $plural = SourceAccessPolicy::article(['user_segment_id' => null, 'user_segment_ids' => [3, '4']]);
        $this->assertFalse($plural->isPublic());
        $this->assertSame(['zendesk:user_segment:3', 'zendesk:user_segment:4'], $ids($plural));
        $both = SourceAccessPolicy::article(['user_segment_id' => 3, 'user_segment_ids' => [3, 9]]);
        $this->assertSame(['zendesk:user_segment:3', 'zendesk:user_segment:9'], $ids($both));
        $this->assertTrue(SourceAccessPolicy::article(['user_segment_id' => null, 'user_segment_ids' => []])->isPublic());
        $this->assertTrue(SourceAccessPolicy::article(['user_segment_id' => null])->isPublic());
        foreach ([['title' => 'no visibility fields'], ['user_segment_ids' => 'oops'], ['user_segment_ids' => [3, 'x']], ['user_segment_id' => '12oops'], ['user_segment_id' => 12.5], ['user_segment_id' => [12]]] as $payload) {
            $unknown = SourceAccessPolicy::article($payload);
            $this->assertFalse($unknown->complete, json_encode($payload));
            $this->assertFalse($unknown->isPublic(), json_encode($payload));
        }
    }

    public function test_acl_distinguishes_unknown_from_nobody_and_never_swallows_auth_failures(): void
    {
        $installation = $this->authorized();
        $this->zendesk(['/help_center/articles/7' => 500]);
        $unknown = $this->connector()->readAcl(RemoteId::format($installation->id, 'article', '7'));
        $this->assertFalse($unknown->complete);
        $this->zendesk(['/help_center/articles/7' => 403]);
        $this->expectException(ConnectorAuthException::class);
        $this->connector()->readAcl(RemoteId::format($installation->id, 'article', '7'));
    }

    public function test_acl_for_profiles_is_unknown_and_for_attachments_is_inherited(): void
    {
        $installation = $this->authorized();
        $user = $this->connector()->readAcl(RemoteId::format($installation->id, 'user', '10'));
        $this->assertInstanceOf(SourceAccess::class, $user);
        $this->assertFalse($user->complete);
        $this->assertSame([], $user->principals);
        $this->assertTrue($this->connector()->readAcl(RemoteId::format($installation->id, 'attachment', '55'))->inheritsFromParent);
    }

    public function test_remote_ids_reject_anything_but_the_exact_shape(): void
    {
        foreach (['', '1:ticket', '1:ticket:0', '1:ticket:abc', '0:ticket:1', '1:secret:1', '1:ticket:1:extra', "1:ticket:1\n", '../1:ticket:1'] as $bad) {
            try {
                RemoteId::parse($bad);
                $this->fail('Accepted: '.json_encode($bad));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(['installation_id' => 3, 'kind' => 'article', 'remote_id' => '9'], RemoteId::parse('3:article:9'));
    }

    public function test_acl_for_another_tenants_installation_is_not_readable(): void
    {
        $other = $this->installation([], 'tenant-b');
        $this->expectException(ModelNotFoundException::class);
        $this->connector()->readAcl(RemoteId::format($other->id, 'article', '7'));
    }
}
