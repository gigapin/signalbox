<?php

/** Enforce ownership for ordinary Eloquent reads and model writes. */

namespace App\Models\Concerns;

use App\Models\Scopes\TeamScope;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Bulk writes and raw SQL still require explicit ownership predicates and immutable team IDs. */
trait BelongsToTeam
{
    /** Install the query scope and validate writes independently of whether a query loaded the model. */
    public static function bootBelongsToTeam(): void
    {
        static::addGlobalScope(new TeamScope());

        static::saving(static function (self $model): void {
            $model->guardTenantWrite();
        });

        // Model increment/decrement operations emit updating but bypass saving.
        static::updating(static function (self $model): void {
            $model->guardTenantWrite();
        });

        static::deleting(static function (Model $model): void {
            $teamId = app(TenantContext::class)->requireTeamId();

            if ((int) $model->getRawOriginal('team_id') !== $teamId || (int) $model->getAttribute('team_id') !== $teamId) {
                throw new LogicException('A tenant model cannot be deleted outside its owning team.');
            }
        });
    }

    /**
     * Enforce immutable persisted ownership and derive new ownership from the active context.
     *
     * @throws \App\Exceptions\MissingTenantContext When no execution identity is established.
     * @throws LogicException When an existing or submitted team differs from the active tenant.
     */
    private function guardTenantWrite(): void
    {
        $teamId = app(TenantContext::class)->requireTeamId();
        $assigned = $this->getAttribute('team_id');

        if ($this->exists && (int) $this->getRawOriginal('team_id') !== $teamId) {
            throw new LogicException('A tenant model cannot be moved between teams.');
        }

        if ($assigned !== null && (int) $assigned !== $teamId) {
            throw new LogicException('The supplied team differs from the active tenant.');
        }

        $this->setAttribute('team_id', $teamId);
    }

    /**
     * Keep model reloading scoped; Laravel's default fresh query explicitly removes global scopes.
     *
     * @param array<array-key, mixed>|string $with Relations to reload with the record.
     * @return static|null Fresh instance in the active team, or null when inaccessible.
     */
    public function fresh($with = []): ?static
    {
        if (!$this->exists) {
            return null;
        }

        return $this->setKeysForSelectQuery($this->newQuery())
            ->useWritePdo()
            ->with(is_string($with) ? func_get_args() : $with)
            ->first();
    }

    /** @return $this Current model reloaded through the active tenant predicate. */
    public function refresh(): static
    {
        if (!$this->exists) {
            return $this;
        }

        return $this->refreshUsingQuery($this->newQuery());
    }

    /** @return $this Current model reloaded under a tenant-constrained write lock. */
    public function refreshForUpdate(): static
    {
        if (!$this->exists) {
            return $this;
        }

        return $this->refreshUsingQuery($this->newQuery()->lockForUpdate());
    }

    /**
     * Preserve the fail-closed boundary if a future caller attempts premature queue rehydration.
     *
     * @param list<int>|int $ids Scalar identifiers restored by framework serialization.
     * @return Builder<static> Query that still requires the execution context.
     */
    public function newQueryForRestoration($ids): Builder
    {
        return $this->newQuery()->whereKey($ids);
    }
}
