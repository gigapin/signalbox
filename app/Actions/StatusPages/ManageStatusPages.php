<?php

declare(strict_types=1);

/** Create, change and delete status pages under fresh policy checks and the Team lock. */

namespace App\Actions\StatusPages;

use App\Data\StatusPages\StatusPageInput;
use App\Exceptions\StatusPageRejected;
use App\Models\Monitor;
use App\Models\Scopes\TeamScope;
use App\Models\StatusPage;
use App\Models\StatusPageComponent;
use App\Models\Team;
use App\Models\User;
use App\Services\Billing\PlanLimits;
use App\Services\Billing\TeamEntitlement;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;

/**
 * Creation counts pages under the Team lock, so concurrent creations cannot pass the plan limit. A monitor may be
 * selected only inside its own team and once per page; the database enforces both as well. Drafts count against the
 * limit. Pages above the limit after a downgrade keep working until the plan policy of the billing milestone.
 */
final readonly class ManageStatusPages
{
    /** @var int Longest title in characters, matching the database check. */
    public const int TITLE_MAX_LENGTH = 120;

    /** @var int Longest description in characters, matching the database check. */
    public const int DESCRIPTION_MAX_LENGTH = 500;

    /** @var int Longest component name in characters, matching the database check. */
    public const int COMPONENT_NAME_MAX_LENGTH = 100;

    /** @var int Most components on one page. */
    public const int COMPONENTS_PER_PAGE = 50;

    /** @var int Most monitors in one component. */
    public const int MONITORS_PER_COMPONENT = 100;

    /** @var int Shortest password in characters. */
    public const int PASSWORD_MIN_LENGTH = 8;

    /** @var int Longest password in bytes: bcrypt ignores whatever follows. */
    public const int PASSWORD_MAX_BYTES = 72;

    /**
     * @param TenantContext $context Explicit tenant boundary for each change.
     * @param PlanLimits $limits Single plan definition.
     * @param TeamEntitlement $entitlement Effective plan resolution.
     */
    public function __construct(
        private TenantContext $context,
        private PlanLimits $limits,
        private TeamEntitlement $entitlement,
    ) {
    }

    /**
     * @param User $actor Owner or admin creating the page.
     * @param int $teamId Active team.
     * @param StatusPageInput $input Submitted page.
     * @return StatusPage Created page.
     * @throws StatusPageRejected When the plan limit or a domain rule is violated.
     */
    public function create(User $actor, int $teamId, StatusPageInput $input): StatusPage
    {
        return $this->locked($teamId, function (Team $team) use ($actor, $input): StatusPage {
            Gate::forUser($actor)->authorize('create', StatusPage::class);
            $limit = $this->limits->statusPages($this->entitlement->effectivePlan($team));

            if (StatusPage::query()->where('team_id', $team->id)->count() >= $limit) {
                throw new StatusPageRejected("Your plan allows {$limit} status pages. Delete one or upgrade to add another.");
            }

            $page = new StatusPage();
            $page->forceFill(['created_by' => $actor->id]);

            return $this->store($page, $team->id, $input);
        });
    }

    /**
     * @param User $actor Owner or admin changing the page.
     * @param int $teamId Active team.
     * @param int $pageId Page of that team.
     * @param StatusPageInput $input Complete replacement; a null password keeps the current one.
     * @return StatusPage Updated page.
     * @throws StatusPageRejected When a domain rule is violated.
     */
    public function update(User $actor, int $teamId, int $pageId, StatusPageInput $input): StatusPage
    {
        return $this->locked($teamId, function (Team $team) use ($actor, $pageId, $input): StatusPage {
            $page = StatusPage::query()->lockForUpdate()->findOrFail($pageId);
            Gate::forUser($actor)->authorize('update', $page);

            return $this->store($page, $team->id, $input);
        });
    }

    /**
     * @param User $actor Owner or admin deleting the page.
     * @param int $teamId Active team.
     * @param int $pageId Page of that team; components are removed by the foreign keys.
     */
    public function delete(User $actor, int $teamId, int $pageId): void
    {
        $this->locked($teamId, function () use ($actor, $pageId): void {
            $page = StatusPage::query()->lockForUpdate()->findOrFail($pageId);
            Gate::forUser($actor)->authorize('delete', $page);
            $page->delete();
        });
    }

    /**
     * @param StatusPage $page New or locked existing page.
     * @param int $teamId Active team.
     * @param StatusPageInput $input Submitted page.
     * @return StatusPage Saved page with its components replaced.
     * @throws StatusPageRejected When a domain rule is violated.
     */
    private function store(StatusPage $page, int $teamId, StatusPageInput $input): StatusPage
    {
        $slug = $this->slug($page, $input->slug);
        $title = $this->title($input->title);
        $description = $this->description($input->description);
        $components = $this->components($input->components);

        $page->forceFill([
            'slug' => $slug,
            'title' => $title,
            'description' => $description,
            'theme_json' => $input->theme->toArray(),
            'is_public' => $input->isPublic,
            'password_hash' => $this->passwordHash($page, $input),
        ])->save();

        $this->replaceComponents($page, $teamId, $components);

        return $page;
    }

    /**
     * @param StatusPage $page Page being saved; its own address does not collide.
     * @param string $value Submitted address.
     * @return string Accepted address, free across all teams.
     * @throws StatusPageRejected When the format is invalid or another page uses the address.
     */
    private function slug(StatusPage $page, string $value): string
    {
        if (preg_match('/\A'.StatusPage::SLUG_PATTERN.'\z/', $value) !== 1) {
            throw new StatusPageRejected('An address must be 3 to 63 lowercase letters, digits or inner hyphens.');
        }

        // Addresses are global, so this is the one query that must see other teams; it returns only a boolean.
        $taken = StatusPage::withoutGlobalScope(TeamScope::class)
            ->where('slug', $value)
            ->when($page->exists, static fn ($query) => $query->whereKeyNot($page->id))
            ->exists();

        if ($taken) {
            throw new StatusPageRejected('This address is already taken.');
        }

        return $value;
    }

    /**
     * @param string $value Submitted title.
     * @return string Trimmed title.
     * @throws StatusPageRejected When blank or too long.
     */
    private function title(string $value): string
    {
        $title = trim($value);

        if ($title === '' || mb_strlen($title) > self::TITLE_MAX_LENGTH) {
            throw new StatusPageRejected('A title must be between 1 and '.self::TITLE_MAX_LENGTH.' characters.');
        }

        return $title;
    }

    /**
     * @param string|null $value Submitted description.
     * @return string|null Trimmed description; null when blank.
     * @throws StatusPageRejected When too long.
     */
    private function description(?string $value): ?string
    {
        $description = trim((string) $value);

        if (mb_strlen($description) > self::DESCRIPTION_MAX_LENGTH) {
            throw new StatusPageRejected('A description can have at most '.self::DESCRIPTION_MAX_LENGTH.' characters.');
        }

        return $description === '' ? null : $description;
    }

    /**
     * @param StatusPage $page Page holding the current hash, if any.
     * @param StatusPageInput $input Submitted password change.
     * @return string|null Hash to store; changing it invalidates earlier unlocks.
     * @throws StatusPageRejected When the request is contradictory or the password has the wrong length.
     */
    private function passwordHash(StatusPage $page, StatusPageInput $input): ?string
    {
        if ($input->password === null) {
            return $input->removePassword ? null : $page->password_hash;
        }

        if ($input->removePassword) {
            throw new StatusPageRejected('Choose either a new password or its removal.');
        }

        if (mb_strlen($input->password) < self::PASSWORD_MIN_LENGTH || strlen($input->password) > self::PASSWORD_MAX_BYTES) {
            throw new StatusPageRejected('A password must have at least '.self::PASSWORD_MIN_LENGTH.' characters and at most '.self::PASSWORD_MAX_BYTES.' bytes.');
        }

        return Hash::make($input->password);
    }

    /**
     * @param list<array{name: string, monitor_ids: list<int>}> $components Submitted components.
     * @return list<array{name: string, monitor_ids: list<int>}> Trimmed names and distinct monitors of the active team.
     * @throws StatusPageRejected When a count, name or monitor is invalid.
     */
    private function components(array $components): array
    {
        if (count($components) > self::COMPONENTS_PER_PAGE) {
            throw new StatusPageRejected('A page can have at most '.self::COMPONENTS_PER_PAGE.' components.');
        }

        $validated = [];
        $names = [];
        $referenced = [];

        foreach ($components as $index => $component) {
            $number = $index + 1;
            $name = trim($component['name']);
            $monitors = array_values(array_unique($component['monitor_ids']));
            $key = mb_strtolower($name);

            if ($name === '' || mb_strlen($name) > self::COMPONENT_NAME_MAX_LENGTH) {
                throw new StatusPageRejected('The name of component '.$number.' must be between 1 and '.self::COMPONENT_NAME_MAX_LENGTH.' characters.');
            }

            if (isset($names[$key])) {
                throw new StatusPageRejected('Two components cannot share the name "'.$name.'".');
            }

            if ($monitors === [] || count($monitors) > self::MONITORS_PER_COMPONENT) {
                throw new StatusPageRejected('Component '.$number.' must group between 1 and '.self::MONITORS_PER_COMPONENT.' monitors.');
            }

            if (array_intersect($monitors, $referenced) !== []) {
                throw new StatusPageRejected('A monitor can appear in only one component of a page.');
            }

            $names[$key] = true;
            $referenced = [...$referenced, ...$monitors];
            $validated[] = ['name' => $name, 'monitor_ids' => $monitors];
        }

        // The tenant scope hides another team's monitors, so a foreign identity is simply not found.
        if (Monitor::query()->whereKey($referenced)->count() !== count($referenced)) {
            throw new StatusPageRejected('Every component must group existing monitors of this team.');
        }

        return $validated;
    }

    /**
     * @param StatusPage $page Saved page.
     * @param int $teamId Active team.
     * @param list<array{name: string, monitor_ids: list<int>}> $components Validated components in display order.
     */
    private function replaceComponents(StatusPage $page, int $teamId, array $components): void
    {
        StatusPageComponent::query()->where('status_page_id', $page->id)->delete();

        foreach ($components as $position => $values) {
            $component = new StatusPageComponent();
            $component->forceFill(['status_page_id' => $page->id, 'name' => $values['name'], 'position' => $position])->save();

            $component->monitors()->attach($values['monitor_ids'], ['status_page_id' => $page->id, 'team_id' => $teamId]);
        }
    }

    /**
     * An address taken concurrently after the check becomes a StatusPageRejected.
     *
     * @template TResult
     * @param int $teamId Active team, locked for the whole change.
     * @param Closure(Team): TResult $operation Change applied while the team is locked.
     * @return TResult Operation result.
     */
    private function locked(int $teamId, Closure $operation): mixed
    {
        return $this->context->run($teamId, static function () use ($teamId, $operation): mixed {
            try {
                return DB::transaction(static fn (): mixed => $operation(Team::query()->lockForUpdate()->findOrFail($teamId)));
            } catch (UniqueConstraintViolationException $exception) {
                // The Team lock does not serialize different teams, so only the unique index can settle this race.
                if (str_contains($exception->getMessage(), 'status_pages_slug_unique')) {
                    throw new StatusPageRejected('This address is already taken.');
                }

                throw $exception;
            }
        });
    }
}
