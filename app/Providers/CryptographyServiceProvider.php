<?php

declare(strict_types=1);

/** Bind native Laravel encryption and URL signing to independent derived keys. */

namespace App\Providers;

use App\Enums\Security\KeyPurpose;
use App\Services\Security\PurposeKeys;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Encryption\Encrypter;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\ServiceProvider;

/** Framework crypto implementations remain unchanged; only their key sources are adapted. */
final class CryptographyServiceProvider extends ServiceProvider
{
    /** Override the lazily resolved framework cipher before HTTP, casts or Fortify resolve it. */
    public function register(): void
    {
        $this->app->singleton('encrypter', static fn (Application $app): Encrypter => $app->make(PurposeKeys::class)->encrypter());
        $this->app->afterResolving('url', static function (UrlGenerator $url, Application $app): void {
            $url->setKeyResolver(static fn (): array => $app->make(PurposeKeys::class)->ring(KeyPurpose::SignedUrls));
        });
    }
}
