<?php

declare(strict_types = 1);

namespace Tests\Support;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

/**
 * Base test case for package tests.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
abstract class TestCase extends OrchestraTestCase
{
    /**
     * Perform post-setup cache reset.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        if (config()->has('cache.stores.array')) {
            Cache::store('array')->clear();
        }
    }

    /**
     * Define environment setup.
     *
     * @param  mixed  $app
     * @return void
     */
    #[\Override]
    protected function getEnvironmentSetUp(mixed $app): void
    {
        if (!$app instanceof Application) {
            return;
        }

        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app->make(ConfigRepository::class);

        $config->set('app.key', sprintf('base64:%s', base64_encode(str_repeat('a', 32))));
        $config->set('app.cipher', 'aes-256-cbc');
        $config->set('app.previous_keys', [sprintf('base64:%s', base64_encode(str_repeat('b', 32)))]);

        $config->set('cache.default', 'array');
        $config->set('cache.stores.array', [
            'driver'    => 'array',
            'serialize' => true,
        ]);

        $config->set('cached-crypt', [
            'enabled'              => false,
            'cache_plaintext'      => false,
            'memo_only'            => true,
            'ttl_seconds'          => 120,
            'epoch'                => 'v1',
            'key_fingerprint'      => null,
            'store'                => null,
            'min_bytes_to_cache'   => 0,
            'max_memo_bytes'       => 262144,
            'max_bytes_to_cache'   => 262144,
            'use_tags'             => false,
            'eligibility_resolver' => null,
            'metrics'              => [
                'enabled'     => false,
                'sample_rate' => 0.10,
            ],
        ]);
    }

    /**
     * Set cached-crypt config with defaults.
     *
     * @param  array<string, mixed>  $overrides
     * @return void
     */
    protected function setCachedCryptConfig(array $overrides): void
    {
        $defaults = config('cached-crypt');

        if (!is_array($defaults)) {
            $defaults = [];
        }

        config()->set('cached-crypt', array_replace_recursive($defaults, $overrides));
    }

    /**
     * Resolve the initialized application instance.
     *
     * @return \Illuminate\Contracts\Foundation\Application
     */
    protected function application(): Application
    {
        assert($this->app instanceof Application);

        return $this->app;
    }
}
