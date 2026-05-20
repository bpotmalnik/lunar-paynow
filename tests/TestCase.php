<?php

namespace Bpotmalnik\LunarPaynow\Tests;

use Illuminate\Support\Facades\Http;
use Kalnoy\Nestedset\NestedSetServiceProvider;
use Lunar\LunarServiceProvider;
use Bpotmalnik\LunarPaynow\PaynowServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Spatie\Activitylog\ActivitylogServiceProvider;
use Spatie\LaravelBlink\BlinkServiceProvider;
use Spatie\MediaLibrary\MediaLibraryServiceProvider;

class TestCase extends OrchestraTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    protected function getPackageProviders($app): array
    {
        return [
            LunarServiceProvider::class,
            MediaLibraryServiceProvider::class,
            ActivitylogServiceProvider::class,
            NestedSetServiceProvider::class,
            BlinkServiceProvider::class,
            PaynowServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('lunar.paynow.api_key', 'test-api-key');
        $app['config']->set('lunar.paynow.signature_key', 'test-signature-key');
        $app['config']->set('lunar.paynow.sandbox', true);
        $app['config']->set('lunar.paynow.status_mapping', [
            'CONFIRMED' => 'payment-received',
            'REJECTED'  => 'payment-failed',
            'ABANDONED' => 'payment-failed',
            'EXPIRED'   => 'payment-failed',
            'ERROR'     => 'payment-failed',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadLaravelMigrations();
    }
}
