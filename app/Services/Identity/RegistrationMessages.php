<?php

declare(strict_types=1);

/** Deliver post-commit registration follow-ups without exposing mail/cache failures as an account oracle. */

namespace App\Services\Identity;

use App\Models\User;
use App\Notifications\ExistingRegistrationNotice;
use Closure;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Existing-account notices use a shared atomic one-minute budget; rejected attempts never extend it. */
final readonly class RegistrationMessages
{
    /** @var int Minimum seconds between existing-owner notices across IPs and application instances. */
    private const int NOTICE_COOLDOWN_SECONDS = 60;

    /** @param Factory $cache Explicit shared Redis store, independent of the default cache driver. */
    public function __construct(private Factory $cache)
    {
    }

    /**
     * Publish the standard event once, retaining Laravel's verification lifecycle and registered listeners.
     *
     * @param User $user Newly committed identity; called only from the creator's after-commit callback.
     */
    public function registered(User $user): void
    {
        $this->deliver(static function () use ($user): void {
            event(new Registered($user));
        });
    }

    /** @param User $user Existing owner, with credentials and membership left untouched. */
    public function existing(User $user): void
    {
        DB::afterCommit(function () use ($user): void {
            $this->deliver(function () use ($user): void {
                // Atomic add bounds concurrent requests; no submitted secret or mailbox enters the key.
                if ($this->cache->store('redis')->add('registration-existing-notice:'.$user->id, true, self::NOTICE_COOLDOWN_SECONDS)) {
                    $user->notify(new ExistingRegistrationNotice());
                }
            });
        });
    }

    /**
     * Follow-up failures receive the same public acknowledgement and a secret-free operational log.
     *
     * @param Closure(): void $delivery Immediately invoked post-commit work, never aggregate persistence.
     */
    private function deliver(Closure $delivery): void
    {
        try {
            $delivery();
        } catch (Throwable) {
            Log::warning('Registration follow-up could not be delivered.');
        }
    }
}
