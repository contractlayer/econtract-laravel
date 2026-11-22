<?php

namespace ContractLayer\EContract;

use ContractLayer\EContract\Contracts\SignatureProvider;
use ContractLayer\EContract\EContract\EContractClient;
use ContractLayer\EContract\EContract\EContractProvider;
use Illuminate\Support\ServiceProvider;

class EContractServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/econtract.php', 'econtract');

        $this->app->singleton(EContractClient::class, fn ($app) => new EContractClient(
            apiKey: (string) $app['config']->get('econtract.api_key'),
            baseUrl: (string) $app['config']->get('econtract.base_url'),
            timeout: (int) $app['config']->get('econtract.timeout'),
            retries: (int) $app['config']->get('econtract.retries'),
        ));

        // Your code depends on the interface: switching provider is a binding change
        $this->app->bind(SignatureProvider::class, EContractProvider::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/econtract.php');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->publishes([__DIR__.'/../config/econtract.php' => config_path('econtract.php')], 'econtract-config');
        if ($this->app->runningInConsole()) {
            $this->commands([Console\ReconcileSignatureRequests::class]);
        }
    }
}
