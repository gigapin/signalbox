<?php

/** Register narrowly scoped authentication adapters and shared rate limits. */

namespace App\Providers;

use App\Actions\Identity\ResetUserPassword;
use App\Actions\Identity\TrackTwoFactorChallenge;
use App\Http\Requests\Auth\ChallengeRequest;
use App\Http\Responses\Auth\AuthenticatedResponse;
use App\Http\Responses\Auth\InvitationVerifiedResponse;
use App\Http\Responses\Auth\ResetLinkResponse;
use App\Services\Auth\ScopedTwoFactorProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Requests\TwoFactorLoginRequest;
use PragmaRX\Google2FA\Google2FA;

/** Reuse Fortify without registering profile, passkey or API features outside M1.02. */
class FortifyServiceProvider extends ServiceProvider
{
    /** Configure package boundaries before any package provider boots. */
    public function register(): void
    {
        $this->app->singleton(\Laravel\Fortify\Contracts\VerifyEmailResponse::class, InvitationVerifiedResponse::class);
        Fortify::ignoreRoutes();
        $this->app->bind(
            \Laravel\Fortify\Contracts\PasswordConfirmedResponse::class,
            \App\Http\Responses\Auth\PasswordConfirmedResponse::class,
        );
        $this->app->bind(
            \Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider::class,
            static fn (): ScopedTwoFactorProvider => new ScopedTwoFactorProvider(
                app(Google2FA::class),
                app(CacheFactory::class)->store(),
            ),
        );
        $this->app->bind(TwoFactorLoginRequest::class, ChallengeRequest::class);
        $this->app->bind(LoginResponse::class, AuthenticatedResponse::class);
        $this->app->bind(TwoFactorLoginResponse::class, AuthenticatedResponse::class);
        $this->app->bind(FailedPasswordResetLinkRequestResponse::class, ResetLinkResponse::class);
        $this->app->bind(SuccessfulPasswordResetLinkRequestResponse::class, ResetLinkResponse::class);
    }

    /** Register the password transition adapters and per-operation rate-limiter keys. */
    public function boot(): void
    {
        Fortify::twoFactorChallengeView(
            static fn (): \Inertia\Response => \Inertia\Inertia::render('Auth/TwoFactorChallenge'),
        );
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::redirectUserForTwoFactorAuthenticationUsing(TrackTwoFactorChallenge::class);
        RateLimiter::for(
            'login',
            static fn (Request $request): Limit => Limit::perMinute(5)->by(strtolower($request->string('email')->toString()).'|'.$request->ip()),
        );
        RateLimiter::for(
            'two-factor',
            static fn (Request $request): Limit => Limit::perMinute(5)->by((string) $request->session()->get('login.id').'|'.$request->ip()),
        );
        RateLimiter::for(
            'auth-public',
            static fn (Request $request): Limit => Limit::perMinute(5)->by($request->path().'|'.$request->ip()),
        );
        RateLimiter::for(
            'verification',
            static fn (Request $request): Limit => Limit::perMinute(6)->by((string) $request->user()?->getAuthIdentifier()),
        );
        RateLimiter::for(
            'password-confirmation',
            static fn (Request $request): Limit => Limit::perMinute(5)->by((string) $request->user()?->getAuthIdentifier()),
        );
        RateLimiter::for(
            'two-factor-setup',
            static fn (Request $request): Limit => Limit::perMinute(5)->by((string) $request->user()?->getAuthIdentifier()),
        );
    }
}
