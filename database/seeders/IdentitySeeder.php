<?php

/** Seed repeatable, explicitly synthetic identity data only in local or testing environments. */

namespace Database\Seeders;

use App\Actions\Identity\CreateTeam;
use App\Enums\Identity\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Provide two teams and all four roles without sending mail or contacting external services. */
class IdentitySeeder extends Seeder
{
    /**
     * Create missing fixtures while preserving existing ownership, trial history and credentials.
     *
     * @param CreateTeam $createTeam Transactional aggregate creator resolved by the container.
     * @throws \LogicException When invoked outside the explicitly permitted fixture environments.
     */
    public function run(CreateTeam $createTeam): void
    {
        if (!app()->environment('local', 'testing')) {
            throw new \LogicException('Synthetic identity seeding requires local or testing environment.');
        }

        DB::transaction(/** Keep the complete repeatable fixture dataset atomic. */ function () use ($createTeam): void {
            /** @var array<string, User> $users Synthetic identities indexed by their fixture purpose. */
            $users = [];
            /** @var string $key Stable fixture label used only under the reserved .test domain. */
            foreach (['owner', 'admin', 'member', 'viewer'] as $key) {
                $users[$key] = User::query()->firstOrCreate(
                    ['email' => $key.'@signalbox.test'],
                    ['name' => 'Synthetic '.ucfirst($key), 'password' => 'password'],
                );
            }

            /** @var string $slug Stable team fixture identifier for idempotent reruns. */
            foreach (['synthetic-alpha', 'synthetic-beta'] as $slug) {
                /** @var Team $team Existing fixture or newly created aggregate with initial owner and trial. */
                $team = Team::query()->where('slug', $slug)->first()
                    ?? $createTeam($users['owner'], 'Synthetic '.ucfirst(substr($slug, 10)), $slug);
                if ($team->owner_id !== $users['owner']->id) {
                    throw new \LogicException('Synthetic team slug is already assigned to another primary owner.');
                }
                $team->users()->syncWithoutDetaching([
                    $users['owner']->id => ['role' => TeamRole::Owner],
                    $users['admin']->id => ['role' => TeamRole::Admin],
                    $users['member']->id => ['role' => TeamRole::Member],
                    $users['viewer']->id => ['role' => TeamRole::Viewer],
                ]);
            }
        });
    }
}
