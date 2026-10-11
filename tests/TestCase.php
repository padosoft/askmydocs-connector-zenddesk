<?php

namespace Padosoft\AskMyDocsConnectorZendesk\Tests;

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\TestCase as Orchestra;
use Padosoft\AskMyDocsConnectorBase\ConnectorServiceProvider;
use Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use Padosoft\AskMyDocsConnectorZendesk\ZendeskServiceProvider;

abstract class TestCase extends Orchestra
{
    public FakeIngestion $ingestion;

    protected function getPackageProviders($app): array
    {
        return [ConnectorServiceProvider::class, ZendeskServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        $app['config']->set('connector-zendesk.http.resolve_dns', false);
        $app['config']->set('connector-zendesk.sync.batch_items', 200);
        $app['config']->set('queue.default', 'database');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate')->run();
        app(TenantContext::class)->set('tenant-a');
        $this->ingestion = new FakeIngestion;
        $this->app->instance(ConnectorIngestionContract::class, $this->ingestion);
        Storage::fake('local');
        Queue::fake();
    }

    /** @param array<string,mixed> $config */
    public function installation(array $config = [], string $tenant = 'tenant-a', string $project = 'support'): ConnectorInstallation
    {
        return ConnectorInstallation::query()->create([
            'tenant_id' => $tenant, 'connector_name' => 'zendesk', 'label' => 'account-'.uniqid(), 'project_key' => $project, 'status' => 'active',
            'config_json' => array_replace_recursive(['connection' => ['subdomain' => 'example', 'auth_mode' => 'api_token', 'email' => 'agent@example.com']], $config),
        ]);
    }
}
