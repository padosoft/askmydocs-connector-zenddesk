<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorZendesk;

use Illuminate\Support\ServiceProvider;

final class ZendeskServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/connector-zendesk.php', 'connector-zendesk');
        // Composer-extra discovery reads the host lockfile; a `path` repository
        // used during local development is not in it, so the connector is also
        // registered through `connectors.built_in`.
        $builtIn = (array) config('connectors.built_in', []);
        $builtIn[] = ZendeskConnector::class;
        config(['connectors.built_in' => array_values(array_unique($builtIn))]);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        if (! $this->app->runningInConsole()) {
            return;
        }
        $this->publishes([__DIR__.'/../config/connector-zendesk.php' => config_path('connector-zendesk.php')], 'connector-zendesk-config');
        $this->publishes([__DIR__.'/../public/icons/zendesk.svg' => public_path('connectors/zendesk.svg')], 'connector-zendesk-assets');
    }
}
