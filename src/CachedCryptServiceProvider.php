<?php

declare(strict_types = 1);

namespace SineMacula\CachedCrypt;

use Illuminate\Encryption\EncryptionServiceProvider;
use Illuminate\Support\Facades\Crypt;

/**
 * Cached crypt service provider.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
class CachedCryptServiceProvider extends EncryptionServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot(): void
    {
        $this->offerPublishing();

        if ($this->cachedCryptEnabled()) {
            Crypt::swap($this->app['encrypter']);
        }
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    #[\Override]
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/cached-crypt.php',
            'cached-crypt',
        );

        parent::register();
    }

    /**
     * Bind the encrypter to the service container.
     *
     * @return void
     */
    #[\Override]
    protected function registerEncrypter(): void
    {
        if (!$this->cachedCryptEnabled()) {
            parent::registerEncrypter();
            return;
        }

        $this->app->singleton('encrypter', function ($app) {
            $config = $app->make('config')->get('app');

            return (new Encrypter($this->parseKey($config), $config['cipher']))
                ->previousKeys(
                    array_map(
                        fn ($key) => $this->parseKey(['key' => $key]),
                        $config['previous_keys'] ?? [],
                    ),
                );
        });
    }

    /**
     * Publish any package specific configuration and assets.
     *
     * @return void
     */
    private function offerPublishing(): void
    {
        if (!$this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__ . '/../config/cached-crypt.php' => config_path('cached-crypt.php'),
        ], 'config');
    }

    /**
     * Determine whether cached-crypt is enabled.
     *
     * @return bool
     */
    private function cachedCryptEnabled(): bool
    {
        return (new CachedCryptConfiguration)->enabled();
    }
}
