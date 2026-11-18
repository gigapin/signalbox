<?php

/** Own profile transactions, private files and monouse verified email changes. */

namespace App\Services\Account;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Coordinate account-only transactions and post-commit file/mail effects. */
final class AccountProfile
{
    /**
     * @param User $actor Session owner.
     * @param array<string,mixed> $data Validated form data.
     * @param UploadedFile|null $file Bounded private raster.
     * @return string User-visible delivery outcome.
     */
    public function save(User $actor, array $data, ?UploadedFile $file): string
    {
        $newPath = $file?->store('account-avatars', 'local');
        if ($file && !is_string($newPath)) {
            throw ValidationException::withMessages(['avatar' => 'The image could not be stored.']);
        }
        $oldPath = null;
        try {
            $token = DB::transaction(function () use ($actor, $data, $newPath, &$oldPath): ?string {
                $user = User::query()->lockForUpdate()->findOrFail($actor->id);
                foreach (['first_name','last_name','phone','country','company_name','vat_number'] as $key) {
                    $user->setAttribute($key, $data[$key] ?? null);
                }
                $user->name = trim(($user->first_name ?? '').' '.($user->last_name ?? '')) ?: 'Account';
                if ($newPath || ($data['remove_avatar'] ?? false)) {
                    $oldPath = $user->avatar_path;
                    $user->avatar_path = $newPath ?: null;
                }
                $email = strtolower(trim((string)$data['email']));
                $token = null;
                if ($email !== $user->email && $email !== $user->pending_email) {
                    if (User::query()->whereRaw('lower(email) = ?', [$email])->exists()) {
                        throw ValidationException::withMessages(['email' => 'This email cannot be used.']);
                    }
                    if ($user->email_change_sent_at?->gt(now()->subMinute())) {
                        throw ValidationException::withMessages(['email' => 'Wait one minute before requesting another email.']);
                    }
                    $token = bin2hex(random_bytes(32));
                    $user->pending_email = $email;
                    $user->email_change_token_hash = hash('sha256', $token);
                    $user->email_change_expires_at = now()->addHour();
                    $user->email_change_sent_at = now();
                }
                $user->save();
                return $token;
            });
        } catch (Throwable $error) {
            if ($newPath) {
                $this->delete($newPath);
            } throw $error;
        }
        if ($oldPath) {
            $this->delete($oldPath);
        }
        if ($token !== null) {
            return $this->deliver($actor->refresh(), $token);
        }
        return 'Account details saved.';
    }
    /**
     * @param User $actor Session owner.
     * @return string Acknowledgement after replacing the old verification capability.
     */
    public function resend(User $actor): string
    {
        $token = DB::transaction(function () use ($actor): string {
            $user = User::query()->lockForUpdate()->findOrFail($actor->id);
            if (!$user->pending_email) {
                throw ValidationException::withMessages(['email' => 'No email change is pending.']);
            }
            if ($user->email_change_sent_at?->gt(now()->subMinute())) {
                throw ValidationException::withMessages(['email' => 'Wait one minute before resending.']);
            }
            $token = bin2hex(random_bytes(32));
            $user->email_change_token_hash = hash('sha256', $token);
            $user->email_change_expires_at = now()->addHour();
            $user->email_change_sent_at = now();
            $user->save();
            return $token;
        });
        return $this->deliver($actor->refresh(), $token);
    }
    /**
     * @param User $actor Session owner.
     * @param string $token Capability from the signed link.
     * @return bool Whether confirmation remains valid.
     */
    public function valid(User $actor, string $token): bool
    {
        return $actor->pending_email !== null && $actor->email_change_expires_at?->isFuture() === true && is_string($actor->email_change_token_hash) && hash_equals($actor->email_change_token_hash, hash('sha256', $token));
    }
    /**
     * @param User $actor Session owner.
     * @param string $token Signed-link capability. Commit the new login identity once.
     */
    public function confirm(User $actor, string $token): void
    {
        try {
            $old = DB::transaction(function () use ($actor, $token): string {
                $user = User::query()->lockForUpdate()->findOrFail($actor->id);
                abort_unless($this->valid($user, $token), 403);
                if (User::query()->whereKeyNot($user->id)->whereRaw('lower(email) = ?', [$user->pending_email])->exists()) {
                    throw ValidationException::withMessages(['email' => 'This email cannot be used.']);
                }
                $old = $user->email;
                $email = $user->pending_email;
                abort_unless(is_string($email), 403);
                $user->email = $email;
                $user->email_verified_at = now();
                $this->clear($user);
                $user->save();
                return $old;
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => 'This email cannot be used.']);
        }
        try {
            Mail::raw('Your Signalbox sign-in email has changed.', fn ($message) => $message->to($old)->subject('Signalbox email changed'));
        } catch (Throwable) {
            Log::warning('Account email change notice delivery failed.');
        }
    }
    /**
     * @param User $actor Locked owner. Clear pending state without modifying the active email.
     */
    public function clear(User $actor): void
    {
        $actor->pending_email = null;
        $actor->email_change_token_hash = null;
        $actor->email_change_expires_at = null;
        // Retain the send timestamp so cancel/recreate cannot bypass the shared cooldown.
    }
    /**
     * @param User $user Committed owner.
     * @param string $token Plaintext monouse capability, never logged.
     * @return string Delivery acknowledgement.
     */
    private function deliver(User $user, string $token): string
    {
        try {
            $url = URL::temporarySignedRoute('account.email.review', now()->addHour(), ['token' => $token]);
            Mail::raw('Confirm your new Signalbox email: '.$url, fn ($message) => $message->to($user->pending_email)->subject('Confirm your Signalbox email'));
            return 'Account details saved. Check your new email to confirm the change.';
        } catch (Throwable) {
            Log::warning('Account email verification delivery failed.');
            return 'Account details saved, but the verification email could not be sent. Please resend it.';
        }
    }
    /**
     * @param string $path Server-generated private path. Failed cleanup never reverses a committed profile.
     */
    private function delete(string $path): void
    {
        try {
            if (!Storage::disk('local')->delete($path)) {
                Log::warning('Account avatar cleanup failed.');
            }
        } catch (Throwable) {
            Log::warning('Account avatar cleanup failed.');
        }
    }
}
