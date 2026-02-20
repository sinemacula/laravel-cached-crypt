<?php

declare(strict_types = 1);

namespace Tests\Integration;

use Illuminate\Encryption\Encrypter as LaravelEncrypter;
use Illuminate\Support\Facades\Crypt;
use Laravel\SerializableClosure\SerializableClosure;
use Laravel\SerializableClosure\Serializers\Signed;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\CachedCrypt\CachedCryptServiceProvider;
use SineMacula\CachedCrypt\Encrypter;
use Tests\Support\TestCase;

/**
 * Service provider integration tests.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(CachedCryptServiceProvider::class)]
final class CachedCryptServiceProviderIntegrationTest extends TestCase
{
    /**
     * Ensure package defaults provide drop-in optimized encrypter binding.
     *
     * @return void
     */
    public function testRegisterUsesPackageDefaultsForDropInOptimization(): void
    {
        config()->set('cached_crypt', []);
        config()->set('cached-crypt', []);

        $provider = new CachedCryptServiceProvider($this->application());

        $provider->register();

        $resolved_encrypter = $this->application()->make('encrypter');

        self::assertInstanceOf(Encrypter::class, $resolved_encrypter);
    }

    /**
     * Ensure disabled mode keeps framework encrypter binding.
     *
     * @return void
     */
    public function testRegisterUsesFrameworkEncrypterWhenDisabled(): void
    {
        $this->setCachedCryptConfig([
            'enabled' => false,
        ]);

        $provider = new CachedCryptServiceProvider($this->application());

        $provider->register();

        $resolved_encrypter = $this->application()->make('encrypter');

        self::assertInstanceOf(LaravelEncrypter::class, $resolved_encrypter);
        self::assertSame(LaravelEncrypter::class, $resolved_encrypter::class);
    }

    /**
     * Ensure enabled mode binds package encrypter and supports previous keys.
     *
     * @return void
     */
    public function testRegisterBindsCachedEncrypterAndDecryptsPreviousKeyPayload(): void
    {
        $this->setCachedCryptConfig([
            'enabled'            => true,
            'cache_plaintext'    => true,
            'memo_only'          => false,
            'min_bytes_to_cache' => 0,
        ]);

        $provider = new CachedCryptServiceProvider($this->application());

        $provider->register();

        $resolved_encrypter = $this->application()->make('encrypter');
        $legacy_encrypter   = new LaravelEncrypter(str_repeat('b', 32), 'aes-256-cbc');
        $legacy_payload     = $legacy_encrypter->encrypt('legacy-value');

        self::assertInstanceOf(Encrypter::class, $resolved_encrypter);
        self::assertSame('legacy-value', $resolved_encrypter->decrypt($legacy_payload));
    }

    /**
     * Ensure register configures serializable-closure key when disabled.
     *
     * @return void
     */
    public function testRegisterConfiguresSerializableClosureSecurityKeyWhenDisabled(): void
    {
        $this->assertRegisterConfiguresSerializableClosureSecurityKey(false);
    }

    /**
     * Ensure register configures serializable-closure key when enabled.
     *
     * @return void
     */
    public function testRegisterConfiguresSerializableClosureSecurityKeyWhenEnabled(): void
    {
        $this->assertRegisterConfiguresSerializableClosureSecurityKey(true);
    }

    /**
     * Ensure facade swapping only occurs when the package is enabled.
     *
     * @return void
     */
    public function testBootSwapsCryptFacadeOnlyWhenEnabled(): void
    {
        $dummy_encrypter = new class {
            /**
             * Marker method.
             *
             * @return string
             */
            public function marker(): string
            {
                return 'dummy';
            }
        };

        Crypt::swap($dummy_encrypter);

        $disabled_provider = new CachedCryptServiceProvider($this->application());

        $this->setCachedCryptConfig([
            'enabled' => false,
        ]);
        $disabled_provider->boot();

        self::assertSame($dummy_encrypter, Crypt::getFacadeRoot());

        $enabled_provider = new CachedCryptServiceProvider($this->application());

        $this->setCachedCryptConfig([
            'enabled' => true,
        ]);
        $enabled_provider->register();
        $enabled_provider->boot();

        self::assertSame($this->application()->make('encrypter'), Crypt::getFacadeRoot());
    }

    /**
     * Assert register configures serializable-closure security key.
     *
     * @param  bool  $enabled
     * @return void
     */
    private function assertRegisterConfiguresSerializableClosureSecurityKey(bool $enabled): void
    {
        SerializableClosure::setSecretKey(null);

        $this->setCachedCryptConfig([
            'enabled' => $enabled,
        ]);

        $provider = new CachedCryptServiceProvider($this->application());

        $provider->register();

        self::assertNotNull(Signed::$signer);
    }
}
