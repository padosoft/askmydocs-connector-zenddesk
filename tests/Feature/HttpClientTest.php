<?php

namespace Padosoft\AskMyDocsConnectorZendesk\Tests\Feature;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Padosoft\AskMyDocsConnectorZendesk\Exceptions\ZendeskApiException;
use Padosoft\AskMyDocsConnectorZendesk\Http\UrlPolicy;
use Padosoft\AskMyDocsConnectorZendesk\Http\ZendeskClient;
use Padosoft\AskMyDocsConnectorZendesk\Tests\TestCase;

final class HttpClientTest extends TestCase
{
    public function test_subdomains_are_normalised_and_hostile_input_is_refused(): void
    {
        foreach (['company', 'Company', ' company.zendesk.com ', 'https://company.zendesk.com/', 'my-co'] as $good) {
            $this->assertSame(strtolower(trim(str_replace(['https://', '.zendesk.com', '/'], '', $good))), UrlPolicy::subdomain($good));
        }
        foreach (['', 'evil.example', 'company.zendesk.com.evil.example', 'https://company.zendesk.com@evil.example', 'a/b', '-bad', 'bad-', 'sp ace', 'a.b', "co\nmpany", str_repeat('a', 64)] as $bad) {
            try {
                UrlPolicy::subdomain($bad);
                $this->fail('Accepted: '.json_encode($bad));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_api_urls_must_be_https_account_hosts_and_downloads_must_match_the_allow_list(): void
    {
        foreach (['http://example.zendesk.com/x', 'https://example.zendesk.com:8443/x', 'https://u:p@example.zendesk.com/x', 'https://evil.example/x', 'https://zendesk.com/x'] as $bad) {
            try {
                UrlPolicy::options($bad);
                $this->fail('Accepted: '.$bad);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame([], UrlPolicy::options('https://example.zendesk.com/api/v2/users/me'));
        $this->assertSame([], UrlPolicy::options('https://p1.zdusercontent.com/f', true));
        $this->expectException(\InvalidArgumentException::class);
        UrlPolicy::options('https://evil.example/f', true);
    }

    public function test_api_token_mode_uses_email_slash_token_basic_auth(): void
    {
        Http::fake(['*' => Http::response(['ok' => true])]);
        (new ZendeskClient('example', 'tok', 'api_token', 'agent@example.com'))->get('/users/me');
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Basic '.base64_encode('agent@example.com/token:tok')) && $r->url() === 'https://example.zendesk.com/api/v2/users/me');
    }

    public function test_oauth_mode_uses_a_bearer_header(): void
    {
        Http::fake(['*' => Http::response(['ok' => true])]);
        (new ZendeskClient('example', 'oauth-abc', 'oauth_token'))->get('/users/me');
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer oauth-abc'));
    }

    public function test_constructor_rejects_missing_secret_email_or_mode(): void
    {
        foreach ([['example', '', 'api_token', 'a@b.co'], ['example', 'tok', 'api_token', 'not-an-email'], ['example', 'tok', 'api_token', ''], ['example', 'tok', 'magic', 'a@b.co'], ['evil.example', 'tok', 'oauth_token', '']] as $args) {
            try {
                new ZendeskClient(...$args);
                $this->fail('Accepted: '.json_encode($args));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_paths_cannot_escape_the_api_prefix(): void
    {
        $client = new ZendeskClient('example', 'tok', 'oauth_token');
        foreach (['users/me', '/users/../x?y=1', '/users/me?x=1', 'https://evil.example/', '//evil.example/x', '/Users'] as $bad) {
            try {
                $client->get($bad);
                $this->fail('Accepted: '.$bad);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_locale_scoped_paths_with_a_hyphen_are_accepted(): void
    {
        Http::fake(['*' => fn () => Http::response(['articles' => []])]);
        $this->assertSame(['articles' => []], (new ZendeskClient('example', 'tok', 'oauth_token'))->get('/help_center/en-us/articles'));
        Http::assertSent(fn ($r) => $r->url() === 'https://example.zendesk.com/api/v2/help_center/en-us/articles');
    }

    public function test_5xx_is_retried_and_counted_then_succeeds(): void
    {
        Http::fake(['*' => Http::sequence()->push([], 503)->push(['id' => 1])]);
        $count = 0;
        $client = new ZendeskClient('example', 'tok', 'oauth_token', '', function () use (&$count) {
            $count++;
        });
        $this->assertSame(['id' => 1], $client->get('/users/me'));
        $this->assertSame(2, $count);
        $this->assertSame(2, $client->physicalRequests);
    }

    public function test_a_budget_callback_can_interrupt_before_any_request(): void
    {
        Http::fake();
        $this->expectException(\DomainException::class);
        (new ZendeskClient('example', 'tok', 'oauth_token', '', fn () => throw new \DomainException('budget')))->get('/users/me');
        Http::assertNothingSent();
    }

    public function test_403_is_sanitised_and_not_retried(): void
    {
        Http::fake(['*' => Http::response(['error' => 'secret-tok leaked in body'], 403)]);
        $client = new ZendeskClient('example', 'secret-tok', 'oauth_token');
        try {
            $client->get('/users/me');
            $this->fail();
        } catch (ZendeskApiException $exception) {
            $this->assertSame(403, $exception->status);
            $this->assertStringNotContainsString('secret-tok', $exception->getMessage());
            $this->assertSame(1, $client->physicalRequests);
            $this->assertFalse($exception->diagnostics()['retryable']);
            $this->assertSame('permission_denied', $exception->diagnostics()['error_code']);
        }
    }

    public function test_429_exposes_retry_after_and_falls_back_to_a_sane_default(): void
    {
        Http::fake(['*' => Http::response([], 429, ['Retry-After' => '17'])]);
        try {
            (new ZendeskClient('example', 'tok', 'oauth_token'))->get('/users/me');
        } catch (ZendeskApiException $exception) {
            $this->assertSame(17, $exception->retryAfter);
            $this->assertTrue($exception->diagnostics()['retryable']);
        }
        Http::swap(new Factory);
        Http::fake(['*' => Http::response([], 429)]);
        try {
            (new ZendeskClient('example', 'tok', 'oauth_token'))->get('/users/me');
        } catch (ZendeskApiException $exception) {
            $this->assertSame(60, $exception->retryAfter);
        }
    }

    public function test_validation_errors_expose_only_allow_listed_field_names_and_codes(): void
    {
        Http::fake(['*' => Http::response(['error' => 'RecordInvalid', 'description' => 'secret-tok', 'details' => ['start_time' => [['description' => 'secret-tok', 'error' => 'TooRecent']], 'Bad Field!' => [['error' => 'x']]]], 422)]);
        try {
            (new ZendeskClient('example', 'secret-tok', 'oauth_token'))->get('/incremental/tickets/cursor', ['start_time' => 1]);
            $this->fail();
        } catch (ZendeskApiException $exception) {
            $this->assertSame([['field' => 'start_time', 'code' => 'TooRecent']], $exception->validationFields);
            $this->assertStringNotContainsString('secret-tok', json_encode($exception->diagnostics()));
            $this->assertSame('invalid_query', $exception->diagnostics()['error_code']);
        }
    }

    public function test_oversized_and_malformed_bodies_are_rejected(): void
    {
        config(['connector-zendesk.http.max_json_bytes' => 10]);
        Http::fake(['*' => Http::response(['padding' => str_repeat('x', 100)])]);
        try {
            (new ZendeskClient('example', 'tok', 'oauth_token'))->get('/users/me');
            $this->fail();
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('size limit', $exception->getMessage());
        }
        config(['connector-zendesk.http.max_json_bytes' => 1000]);
        Http::swap(new Factory);
        Http::fake(['*' => Http::response('{not json')]);
        $this->expectExceptionMessage('invalid JSON');
        (new ZendeskClient('example', 'tok', 'oauth_token'))->get('/users/me');
    }

    public function test_download_enforces_redirect_limit_scheme_and_size(): void
    {
        $client = new ZendeskClient('example', 'tok', 'oauth_token');
        Http::fake(['*' => Http::response('', 302, ['Location' => 'http://127.0.0.1/private'])]);
        try {
            $client->download('https://example.zendesk.com/a', 10);
            $this->fail();
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('redirect is invalid', $exception->getMessage());
        }
        Http::swap(new Factory);
        Http::fake(['*' => Http::response('', 302, ['Location' => 'https://p1.zdusercontent.com/loop'])]);
        try {
            $client->download('https://example.zendesk.com/a', 10);
            $this->fail();
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('redirect limit', $exception->getMessage());
        }
        Http::swap(new Factory);
        Http::fake(['*' => Http::response('123456789012')]);
        $this->expectExceptionMessage('size limit');
        $client->download('https://example.zendesk.com/a', 5);
    }
}
