<?php

/** Add the active team predicate to every ordinary tenant model query. */

namespace App\Models\Scopes;

use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Resolve context at query execution, never when a persistent model class first boots.
 * @implements Scope<Model>
 */
final class TeamScope implements Scope
{
    /**
     * @param Builder<covariant Model> $builder Query being constrained, including count/update/delete queries.
     * @param Model $model Tenant model supplying the qualified ownership column.
     */
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where($model->qualifyColumn('team_id'), app(TenantContext::class)->requireTeamId());
    }
}
