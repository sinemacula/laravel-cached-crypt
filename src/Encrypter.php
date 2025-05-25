<?php

namespace SineMacula\CachedCrypt;

use Illuminate\Encryption\Encrypter as LaravelEncrypter;
use Illuminate\Support\Facades\Cache;

/**
 * The encrypter.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2025 Sine Macula Limited.
 */
class Encrypter extends LaravelEncrypter
{
    /**
     * Decrypt the given value.
     *
     * @param  string  $payload
     * @param  bool  $unserialize
     * @return mixed
     *
     * @throws \Illuminate\Contracts\Encryption\DecryptException
     */
    public function decrypt($payload, $unserialize = true): mixed
    {
        $cache_key = implode(':', [
            'decrypted',
            md5($payload),
            ($unserialize ? '1' : '0')
        ]);

        return Cache::rememberForever($cache_key, fn () => parent::decrypt($payload, $unserialize));
    }
}
