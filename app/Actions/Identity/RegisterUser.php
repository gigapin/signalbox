<?php

/** Create the initial account aggregate without depending on a browser session. */

namespace App\Actions\Identity;

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Commit user, team, owner membership and trial together before notifying the user. */
final class RegisterUser
{
    /**
     * @param CreateTeam $createTeam Existing transactional team aggregate creator.
     */
    public function __construct(private readonly CreateTeam $createTeam)
    {
    }

    /**
     * Persist validated registration data, including a server-owned unique team slug.
     * @param string $name User display name.
     * @param string $email Normalized validated email address.
     * @param string $password Plaintext validated password, hashed by User and never logged.
     * @param string $teamName Initial team display name; it need not be unique.
     * @return User New unverified identity with a persisted initial team preference.
     * @throws \Illuminate\Database\QueryException When any aggregate write fails; all writes roll back.
     */
    public function __invoke(string $name, string $email, string $password, string $teamName): User
    {
        return DB::transaction(/** Keep account creation atomic and notification outside its commit boundary. */ function () use ($name, $email, $password, $teamName): User {
            /** @var User $user New account, unverified until a signed verification link is used. */
            $user = User::query()->create(['name' => $name, 'email' => $email, 'password' => $password]);
            /** @var string $prefix Human-readable ASCII slug segment, bounded to fit the UUID suffix. */
            $prefix = substr(Str::slug($teamName) ?: 'team', 0, 210);
            ($this->createTeam)($user, $teamName, rtrim($prefix, '-').'-'.Str::uuid()->toString());
            DB::afterCommit(/** Publish only an identity whose complete aggregate has committed. */ static function () use ($user): void {
                event(new Registered($user));
            });

            return $user->refresh();
        });
    }
}
