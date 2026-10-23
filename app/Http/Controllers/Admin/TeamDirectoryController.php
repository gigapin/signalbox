<?php

declare(strict_types=1);

/** Render the platform team directory and team pages for operators. */

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\ManagePlanOverrides;
use App\Http\Controllers\Controller;
use App\Http\Presenters\Admin\PlatformTeamPresenter;
use App\Http\Requests\Admin\ListPlatformTeamsRequest;
use App\Models\Team;
use App\Services\Admin\PlatformTeamDirectory;
use Inertia\Inertia;
use Inertia\Response;

/** Reads only; EnsurePlatformAdmin has admitted the user. No tenant context is established here. */
final class TeamDirectoryController extends Controller
{
    /**
     * @param PlatformTeamDirectory $directory Cross-team reads.
     * @param PlatformTeamPresenter $presenter Page projections.
     */
    public function __construct(
        private readonly PlatformTeamDirectory $directory,
        private readonly PlatformTeamPresenter $presenter,
    ) {
    }

    /**
     * @param ListPlatformTeamsRequest $request Search text and page.
     * @return Response Paginated directory.
     */
    public function index(ListPlatformTeamsRequest $request): Response
    {
        $search = $request->search();
        $page = $this->directory->page($search, $request->page());
        $ids = array_values(array_map(static fn (Team $team): int => $team->id, $page->items()));

        return Inertia::render('Admin/Teams/Index', [
            'teams' => $this->presenter->page($page, $this->directory->monitorCounts($ids)),
            'filters' => ['q' => $search],
        ]);
    }

    /**
     * @param int $team Any team ID.
     * @return Response Team detail with override form data and history.
     */
    public function show(int $team): Response
    {
        $model = Team::query()->with(['owner:id,name,email', 'planOverride'])->findOrFail($team);
        $monitors = $this->directory->monitorCounts([$model->id])[$model->id] ?? 0;

        return Inertia::render('Admin/Teams/Show', [
            'team' => $this->presenter->detail($model, $monitors, $this->directory->memberCount($model), $this->directory->events($model)),
            'options' => PlatformTeamPresenter::options(),
            'reasonMaxLength' => ManagePlanOverrides::REASON_MAX_LENGTH,
        ]);
    }
}
