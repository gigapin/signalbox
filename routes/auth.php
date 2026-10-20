<?php

/** Declare the exact browser authentication surface and its authorization boundaries. */

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Auth\AuthPageController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\SecurityController;
use App\Http\Middleware\NoStoreAuthentication;
use App\Http\Middleware\ProtectTwoFactorState;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;
use Laravel\Fortify\Http\Controllers\EmailVerificationNotificationController;
use Laravel\Fortify\Http\Controllers\PasswordResetLinkController;
use Laravel\Fortify\Http\Controllers\RecoveryCodeController;
use Laravel\Fortify\Http\Controllers\TwoFactorAuthenticatedSessionController;
use Laravel\Fortify\Http\Controllers\TwoFactorAuthenticationController;
use Laravel\Fortify\Http\Controllers\TwoFactorQrCodeController;
use Laravel\Fortify\Http\Controllers\TwoFactorSecretKeyController;
use Laravel\Fortify\Http\Controllers\VerifyEmailController;

Route::middleware(NoStoreAuthentication::class)->group(/** Register only authentication and account routes, never tenant domain resources. */ function (): void {
    Route::middleware('guest')->group(/** Guest entrypoints, keeping incomplete second-factor sessions unauthenticated. */ function (): void {
        Route::get('/register', AuthPageController::class)->name('register');
        Route::post('/register', RegisterController::class)->middleware('throttle:auth-public')->name('register.store');
        Route::get('/login', AuthPageController::class)->name('login');
        Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:login')->name('login.store');
        Route::get('/forgot-password', AuthPageController::class)->name('password.request');
        Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])->middleware('throttle:auth-public')->name('password.email');
        Route::get('/reset-password/{token}', AuthPageController::class)->name('password.reset');
        Route::post('/reset-password', [SecurityController::class, 'reset'])->middleware('throttle:auth-public')->name('password.update');
        Route::get('/two-factor-challenge', [TwoFactorAuthenticatedSessionController::class, 'create'])->middleware(ProtectTwoFactorState::class)->name('two-factor.login');
        Route::post('/two-factor-challenge', [TwoFactorAuthenticatedSessionController::class, 'store'])->middleware(['throttle:two-factor', ProtectTwoFactorState::class])->name('two-factor.login.store');
    });

    Route::middleware('auth')->group(/** Routes available while an account is still awaiting email verification. */ function (): void {
        Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
        Route::get('/email/verify', AuthPageController::class)->name('verification.notice');
        Route::get('/email/verify/{id}/{hash}', [VerifyEmailController::class, '__invoke'])->middleware(['signed', 'throttle:verification'])->name('verification.verify');
        Route::post('/email/verification-notification', [EmailVerificationNotificationController::class, 'store'])->middleware('throttle:verification')->name('verification.send');

        Route::middleware('verified')->group(/** Account-only destinations requiring verified identity, without claiming tenant authorization. */ function (): void {
            Route::get('/account', [AccountController::class, 'show'])->name('account');
            Route::get('/user/confirm-password', AuthPageController::class)->name('password.confirm');
            Route::post('/user/confirm-password', [SecurityController::class, 'password'])->middleware('throttle:password-confirmation')->name('password.confirm.store');
            Route::get('/account/security', [AccountController::class, 'security'])->middleware('password.confirm')->name('account.security');
            Route::middleware(['password.confirm', ProtectTwoFactorState::class])->group(/** Fresh password and shared user lock protect all second-factor state access. */ function (): void {
                Route::post('/user/two-factor-authentication', [TwoFactorAuthenticationController::class, 'store'])->name('two-factor.enable');
                Route::delete('/user/two-factor-authentication', [TwoFactorAuthenticationController::class, 'destroy'])->name('two-factor.disable');
                Route::post('/user/confirmed-two-factor-authentication', [SecurityController::class, 'twoFactor'])->middleware('throttle:two-factor-setup')->name('two-factor.confirm');
                Route::get('/user/two-factor-qr-code', [TwoFactorQrCodeController::class, 'show'])->name('two-factor.qr-code');
                Route::get('/user/two-factor-secret-key', [TwoFactorSecretKeyController::class, 'show'])->name('two-factor.secret-key');
                Route::get('/user/two-factor-recovery-codes', [RecoveryCodeController::class, 'index'])->name('two-factor.recovery-codes');
                Route::post('/user/two-factor-recovery-codes', [RecoveryCodeController::class, 'store'])->name('two-factor.regenerate-recovery-codes');
            });
        });
    });
});
