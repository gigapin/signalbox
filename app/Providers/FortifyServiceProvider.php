<?php

/** Register narrowly scoped authentication adapters and shared rate limits. */

namespace App\Providers;

use App\Actions\Identity\ResetUserPassword;
use App\Actions\Identity\TrackTwoFactorChallenge;
use App\Http\Requests\Auth\ChallengeRequest;
use App\Http\Responses\Auth\AuthenticatedResponse;
use App\Http\Responses\Auth\ResetLinkResponse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Requests\TwoFactorLoginRequest;

/** Reuse Fortify without registering profile, passkey or API features outside M1.02. */
class FortifyServiceProvider extends ServiceProvider
{
    /** Configure package boundaries before any package provider boots. */
    public function register(): void
    {
        Fortify::ignoreRoutes();
        $this->app->bind(\Laravel\Fortify\Contracts\PasswordConfirmedResponse::class, \App\Http\Responses\Auth\PasswordConfirmedResponse::class);
        $this->app->bind(\Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider::class, /** Resolve the maintained TOTP engine with the same shared cache used by authentication throttles. */ static fn (): \App\Services\Auth\ScopedTwoFactorProvider => new \App\Services\Auth\ScopedTwoFactorProvider(app(\PragmaRX\Google2FA\Google2FA::class), app(\Illuminate\Contracts\Cache\Factory::class)->store()));
        $this->app->bind(TwoFactorLoginRequest::class, ChallengeRequest::class);
        $this->app->bind(LoginResponse::class, AuthenticatedResponse::class);
        $this->app->bind(TwoFactorLoginResponse::class, AuthenticatedResponse::class);
        $this->app->bind(FailedPasswordResetLinkRequestResponse::class, ResetLinkResponse::class);
        $this->app->bind(SuccessfulPasswordResetLinkRequestResponse::class, ResetLinkResponse::class);
    }

    /** Register the password transition adapters and per-operation rate-limiter keys. */
    public function boot(): void
    {
        Fortify::twoFactorChallengeView(/** Render only the guest challenge page; credentials remain in the server session. */ static fn (): \Inertia\Response => \Inertia\Inertia::render('Auth/TwoFactorChallenge'));
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::redirectUserForTwoFactorAuthenticationUsing(TrackTwoFactorChallenge::class);
        RateLimiter::for('login', /**
     * @param Request $request Guest credential request.
     * @return Limit Per-email/IP login quota.
     */ static fn (Request $request): Limit => Limit::perMinute(5)->by(strtolower($request->string('email')->toString()).'|'.$request->ip()));
        RateLimiter::for('two-factor', /**
     * @param Request $request Pending guest challenge.
     * @return Limit Per-challenged-user/IP quota.
     */ static fn (Request $request): Limit => Limit::perMinute(5)->by((string) $request->session()->get('login.id').'|'.$request->ip()));
        RateLimiter::for('auth-public', /**
     * @param Request $request Public registration or reset-link request.
     * @return Limit Independent route/IP quota.
     */ static fn (Request $request): Limit => Limit::perMinute(5)->by($request->path().'|'.$request->ip()));
        RateLimiter::for('verification', /**
     * @param Request $request Authenticated verification request.
     * @return Limit Per-user verification quota.
     */ static fn (Request $request): Limit => Limit::perMinute(6)->by((string) $request->user()?->getAuthIdentifier()));
        RateLimiter::for('password-confirmation', /**
     * @param Request $request Authenticated password confirmation.
     * @return Limit Per-user confirmation quota.
     */ static fn (Request $request): Limit => Limit::perMinute(5)->by((string) $request->user()?->getAuthIdentifier()));
        RateLimiter::for('two-factor-setup', /**
     * @param Request $request Authenticated setup confirmation.
     * @return Limit Bounded authenticator setup verification.
     */ static fn (Request $request): Limit => Limit::perMinute(5)->by((string) $request->user()?->getAuthIdentifier()));
    }
}
