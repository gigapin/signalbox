<?php

declare(strict_types=1);

/** Grant, revoke or list platform operators; the only way to change the operator flag. */

namespace App\Console\Commands;

use App\Models\PlatformAuditEvent;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * No HTTP route can promote a user, so taking over a web session never yields operator rights by itself.
 * Each change locks the User row and records an audit event without an actor, marking a CLI origin.
 */
final class PlatformAdminCommand extends Command
{
    /** @var string $signature Email of an existing verified account, or --list. */
    protected $signature = 'signalbox:platform-admin
        {email? : Account email to grant or revoke}
        {--revoke : Remove the operator flag instead of granting it}
        {--list : List current operators}
        {--force : Skip the confirmation prompt}';

    /** @var string $description Shown by artisan list. */
    protected $description = 'Grant, revoke or list platform administrators';

    /** @return int Command::SUCCESS on change, no-op or listing; Command::FAILURE on refusal. */
    public function handle(): int
    {
        if ($this->option('list')) {
            return $this->listOperators();
        }

        $argument = $this->argument('email');
        $email = is_string($argument) ? Str::lower(trim($argument)) : '';

        if ($email === '') {
            $this->error('Provide an account email or --list.');

            return self::FAILURE;
        }

        $grant = !$this->option('revoke');
        $user = User::query()->where('email', $email)->first();

        if ($user === null || $user->email_verified_at === null) {
            // One message for both cases, so the command does not confirm which accounts exist.
            $this->error('No verified account with that email.');

            return self::FAILURE;
        }

        $question = $grant ? "Grant platform administration to {$email}?" : "Revoke platform administration from {$email}?";

        if (!$this->option('force') && !$this->confirm($question)) {
            $this->line('Nothing changed.');

            return self::SUCCESS;
        }

        $changed = $this->apply($user->id, $grant);

        $this->info(match (true) {
            !$changed => 'Nothing changed: the account already had that status.',
            $grant => 'Platform administration granted.',
            default => 'Platform administration revoked.',
        });

        return self::SUCCESS;
    }

    /**
     * @param int $userId Verified account.
     * @param bool $grant Desired flag value.
     * @return bool Whether the flag changed; an unchanged flag writes no audit event.
     */
    private function apply(int $userId, bool $grant): bool
    {
        return DB::transaction(function () use ($userId, $grant): bool {
            $user = User::query()->lockForUpdate()->findOrFail($userId);

            if ($user->is_platform_admin === $grant) {
                return false;
            }

            $user->forceFill(['is_platform_admin' => $grant])->save();

            PlatformAuditEvent::record(
                null,
                $grant ? 'user.platform_admin.granted' : 'user.platform_admin.revoked',
                $user,
                ['is_platform_admin' => ['before' => !$grant, 'after' => $grant]],
                'Changed with signalbox:platform-admin',
            );

            return true;
        });
    }

    /** @return int Command::SUCCESS after printing operators ordered by email. */
    private function listOperators(): int
    {
        $rows = User::query()
            ->where('is_platform_admin', true)
            ->orderBy('email')
            ->get(['id', 'email', 'two_factor_confirmed_at'])
            ->map(static fn (User $user): array => [$user->id, $user->email, $user->two_factor_confirmed_at === null ? 'no' : 'yes'])
            ->all();

        if ($rows === []) {
            $this->line('No platform administrators.');

            return self::SUCCESS;
        }

        $this->table(['ID', 'Email', '2FA'], $rows);

        return self::SUCCESS;
    }
}
