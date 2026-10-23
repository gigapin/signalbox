<?php

declare(strict_types=1);

/** Exercise the only path that grants or revokes platform administration. */

use App\Models\User;
use Illuminate\Support\Facades\DB;

pest()->group('docker');
beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated php-test PostgreSQL.');
    }
});

/**
 * @param User $user Account to inspect.
 * @return bool Persisted operator flag.
 */
function operatorFlag(User $user): bool
{
    return DB::table('users')->where('id', $user->id)->value('is_platform_admin') === true;
}

it('grants after confirmation, normalizing the email, and records a command-line audit event', function (): void {
    $user = User::factory()->create(['email' => 'operator@example.test']);

    $this->artisan('signalbox:platform-admin', ['email' => ' Operator@Example.TEST '])
        ->expectsConfirmation('Grant platform administration to operator@example.test?', 'yes')
        ->expectsOutput('Platform administration granted.')
        ->assertSuccessful();

    $event = DB::table('platform_audit_events')->sole();

    expect(operatorFlag($user))->toBeTrue()
        ->and($event->actor_id)->toBeNull()
        ->and($event->action)->toBe('user.platform_admin.granted')
        ->and($event->subject_type)->toBe('user')
        ->and($event->subject_id)->toBe($user->id)
        ->and(json_decode($event->changes, true))->toEqual(['is_platform_admin' => ['before' => false, 'after' => true]]);
});

it('changes nothing when the confirmation is declined', function (): void {
    $user = User::factory()->create();

    $this->artisan('signalbox:platform-admin', ['email' => $user->email])
        ->expectsConfirmation("Grant platform administration to {$user->email}?", 'no')
        ->expectsOutput('Nothing changed.')
        ->assertSuccessful();

    expect(operatorFlag($user))->toBeFalse()->and(DB::table('platform_audit_events')->count())->toBe(0);
});

it('is idempotent and revokes with its own audit event', function (): void {
    $user = User::factory()->platformAdmin()->create();

    $this->artisan('signalbox:platform-admin', ['email' => $user->email, '--force' => true])
        ->expectsOutput('Nothing changed: the account already had that status.')
        ->assertSuccessful();

    $this->artisan('signalbox:platform-admin', ['email' => $user->email, '--revoke' => true, '--force' => true])
        ->expectsOutput('Platform administration revoked.')
        ->assertSuccessful();

    expect(operatorFlag($user))->toBeFalse()
        ->and(DB::table('platform_audit_events')->pluck('action')->all())->toBe(['user.platform_admin.revoked']);
});

it('refuses unknown and unverified accounts with the same message', function (string $state): void {
    $email = $state === 'unknown' ? 'nobody@example.test' : User::factory()->unverified()->create()->email;

    $this->artisan('signalbox:platform-admin', ['email' => $email, '--force' => true])
        ->expectsOutput('No verified account with that email.')
        ->assertFailed();

    expect(DB::table('users')->where('is_platform_admin', true)->count())->toBe(0);
})->with(['unknown', 'unverified']);

it('requires an email unless listing', function (): void {
    $this->artisan('signalbox:platform-admin')->expectsOutput('Provide an account email or --list.')->assertFailed();
});

it('lists operators with their second-factor state', function (): void {
    $this->artisan('signalbox:platform-admin', ['--list' => true])->expectsOutput('No platform administrators.')->assertSuccessful();

    $operator = User::factory()->platformAdmin()->create(['email' => 'list@example.test']);
    User::factory()->create();

    $this->artisan('signalbox:platform-admin', ['--list' => true])
        ->expectsTable(['ID', 'Email', '2FA'], [[$operator->id, 'list@example.test', 'no']])
        ->assertSuccessful();
});
