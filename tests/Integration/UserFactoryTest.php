<?php

/** Validate initial identity fixtures against PostgreSQL without seeding demo or production accounts. */

use App\Models\User;
use Illuminate\Support\Facades\Hash;

it(
    'creates distinct verified synthetic identities with hashed passwords',
    /** Exercise factory persistence, verification timestamps and protected model serialization. */
    function (): void {
        /** @var \Illuminate\Database\Eloquent\Collection<int, User> $users Synthetic identities rolled back by RefreshDatabase. */
        $users = User::factory()->count(2)->create();
        expect($users->pluck('email')->unique())->toHaveCount(2);
        /** @var User $user One synthetic identity with no real customer information. */
        foreach ($users as $user) {
            expect($user->email_verified_at)->not->toBeNull()
                ->and(Hash::check('password', $user->password))->toBeTrue()
                ->and($user->toArray())->not->toHaveKeys(['password', 'remember_token']);
            $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => $user->email]);
        }
    },
)->group('docker')->skip(getenv('RUN_DOCKER_TESTS') !== '1', 'Requires the isolated php-test PostgreSQL service.');

it(
    'creates identities that explicitly still need email verification',
    /** Verify the unverified factory state independently of the default verified identity. */
    function (): void {
        /** @var User $user Synthetic unverified identity confined to the test transaction. */
        $user = User::factory()->unverified()->create();
        expect($user->email_verified_at)->toBeNull();
        $this->assertDatabaseHas('users', ['id' => $user->id, 'email_verified_at' => null]);
    },
)->group('docker')->skip(getenv('RUN_DOCKER_TESTS') !== '1', 'Requires the isolated php-test PostgreSQL service.');
