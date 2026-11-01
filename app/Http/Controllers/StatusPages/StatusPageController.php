<?php

declare(strict_types=1);

/** Render the team's status page settings and translate browser changes into lock-aware domain actions. */

namespace App\Http\Controllers\StatusPages;

use App\Actions\StatusPages\ManageStatusPages;
use App\Exceptions\StatusPageRejected;
use App\Http\Controllers\Controller;
use App\Http\Presenters\StatusPages\StatusPagePresenter;
use App\Http\Requests\StatusPages\StatusPageRequest;
use App\Models\Monitor;
use App\Models\StatusPage;
use App\Models\Team;
use App\Services\Billing\PlanLimits;
use App\Services\Billing\TeamEntitlement;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Domain refusals become a session error on the `page` key; every change redirects back. */
final class StatusPageController extends Controller
{
    /**
     * @param ManageStatusPages $pages Transactional page changes.
     * @param StatusPagePresenter $presenter Safe page projections.
     */
    public function __construct(private readonly ManageStatusPages $pages, private readonly StatusPagePresenter $presenter)
    {
    }

    /**
     * @param PlanLimits $limits Single plan definition.
     * @param TeamEntitlement $entitlement Effective plan resolution.
     * @param int $team Route team, already established as tenant context.
     * @return Response Pages, selectable monitors, theme choices, limits and permissions.
     */
    public function index(PlanLimits $limits, TeamEntitlement $entitlement, int $team): Response
    {
        Gate::authorize('viewAny', StatusPage::class);
        $identity = Team::query()->findOrFail($team);

        $pages = StatusPage::query()->with('components.monitors')->orderBy('title')->orderBy('id')->get();
        $monitors = Monitor::query()->orderBy('name')->orderBy('id')->get(['id', 'name']);

        return Inertia::render('StatusPages/Index', [
            'team' => ['id' => $identity->id, 'name' => $identity->name],
            'pages' => $pages->map(fn (StatusPage $page): array => $this->presenter->page($page))->all(),
            'monitors' => $monitors->map(static fn (Monitor $monitor): array => ['id' => $monitor->id, 'name' => $monitor->name])->all(),
            'themeChoices' => $this->presenter->themeChoices(),
            'limits' => $this->presenter->limits($limits->statusPages($entitlement->effectivePlan($identity)), $pages->count()),
            'can' => ['manage' => Gate::allows('create', StatusPage::class)],
        ]);
    }

    /**
     * @param StatusPageRequest $request Authorized page shape.
     * @param int $team Route team.
     * @return RedirectResponse Previous page with `status-page-created` status.
     */
    public function store(StatusPageRequest $request, int $team): RedirectResponse
    {
        $actor = $this->authenticatedUser($request);
        $this->rejectable(fn (): StatusPage => $this->pages->create($actor, $team, $request->pageInput()));

        return back()->with('status', 'status-page-created');
    }

    /**
     * @param StatusPageRequest $request Authorized replacement.
     * @param int $team Route team.
     * @param StatusPage $statusPage Page bound inside the route team.
     * @return RedirectResponse Previous page with `status-page-updated` status.
     */
    public function update(StatusPageRequest $request, int $team, StatusPage $statusPage): RedirectResponse
    {
        $actor = $this->authenticatedUser($request);
        $this->rejectable(fn (): StatusPage => $this->pages->update($actor, $team, $statusPage->id, $request->pageInput()));

        return back()->with('status', 'status-page-updated');
    }

    /**
     * @param Request $request Verified browser identity; the action authorizes under lock.
     * @param int $team Route team.
     * @param StatusPage $statusPage Page bound inside the route team.
     * @return RedirectResponse Previous page with `status-page-deleted` status.
     */
    public function destroy(Request $request, int $team, StatusPage $statusPage): RedirectResponse
    {
        $actor = $this->authenticatedUser($request);
        $this->pages->delete($actor, $team, $statusPage->id);

        return back()->with('status', 'status-page-deleted');
    }

    /**
     * @template TResult
     * @param Closure(): TResult $change Domain change that may refuse the request.
     * @return TResult Change result.
     * @throws ValidationException When the page is invalid for the team.
     */
    private function rejectable(Closure $change): mixed
    {
        try {
            return $change();
        } catch (StatusPageRejected $exception) {
            throw ValidationException::withMessages(['page' => $exception->getMessage()]);
        }
    }
}
