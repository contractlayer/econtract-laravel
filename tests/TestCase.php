<?php

namespace ContractLayer\EContract\Tests;

use ContractLayer\EContract\EContractServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [EContractServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('econtract.api_key', 'cl_test_key');
        $app['config']->set('econtract.base_url', 'https://api.example.test');
        $app['config']->set('econtract.webhook_secret', 'whsec_test');
        $app['config']->set('econtract.retries', 1);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
    }
}
