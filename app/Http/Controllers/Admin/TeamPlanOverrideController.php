<?php

declare(strict_types=1);

/** Translate operator override submissions into the lock-aware action. */

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\ManagePlanOverrides;
use App\Http\Controllers\Controller;
use App\Http\Navigation\PlatformDirectoryReturn;
use App\Http\Requests\Admin\ClearPlanOverrideRequest;
use App\Http\Requests\Admin\SavePlanOverrideRequest;
use Illuminate\Http\RedirectResponse;

/** Both mutations redirect back to the team page with a status flash. */
final class TeamPlanOverrideController extends Controller
{
    /**
     * @param ManagePlanOverrides $overrides Transactional override changes.
     * @param PlatformDirectoryReturn $links Canonical directory context after accepted writes.
     */
    public function __construct(private readonly ManagePlanOverrides $overrides, private readonly PlatformDirectoryReturn $links)
    {
    }

    /**
     * @param SavePlanOverrideRequest $request Validated override.
     * @param int $team Target team.
     * @return RedirectResponse Target team detail with `plan-override-saved`, independent of session history.
     */
    public function update(SavePlanOverrideRequest $request, int $team): RedirectResponse
    {
        $this->overrides->grant(
            $this->authenticatedUser($request),
            $team,
            $request->kind(),
            $request->plan(),
            $request->expiresAt(),
            $request->reason(),
        );

        return redirect()->to($this->links->detail($request, $team))->with('status', 'plan-override-saved');
    }

    /**
     * @param ClearPlanOverrideRequest $request Validated reason.
     * @param int $team Target team.
     * @return RedirectResponse Target team detail with `plan-override-cleared`, independent of session history.
     */
    public function destroy(ClearPlanOverrideRequest $request, int $team): RedirectResponse
    {
        $this->overrides->clear($this->authenticatedUser($request), $team, $request->reason());

        return redirect()->to($this->links->detail($request, $team))->with('status', 'plan-override-cleared');
    }
}
