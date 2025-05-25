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
     * Decrypt string.
     *
     * @param  string  $payload
     * @return string
     */
    public function decryptString($payload): string
    {
        return Cache::rememberForever('decrypted:' . md5($payload), fn ($payload) => parent::decryptString($payload));
    }
}
