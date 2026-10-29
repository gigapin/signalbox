<?php

declare(strict_types=1);

/** Translate browser escalation policy changes into lock-aware domain actions. */

namespace App\Http\Controllers\Notifications;

use App\Actions\Notifications\AssignEscalationPolicy;
use App\Actions\Notifications\ManageEscalationPolicies;
use App\Exceptions\EscalationPolicyRejected;
use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\AssignEscalationPolicyRequest;
use App\Http\Requests\Notifications\EscalationPolicyRequest;
use App\Models\EscalationPolicy;
use App\Models\Monitor;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Domain refusals become a session error on the `policy` key; every change redirects back. */
final class EscalationPolicyController extends Controller
{
    /** @param ManageEscalationPolicies $policies Transactional policy changes. */
    public function __construct(private readonly ManageEscalationPolicies $policies)
    {
    }

    /**
     * @param EscalationPolicyRequest $request Authorized policy shape.
     * @param int $team Route team.
     * @return RedirectResponse Previous page with `policy-created` status.
     */
    public function store(EscalationPolicyRequest $request, int $team): RedirectResponse
    {
        $actor = $this->authenticatedUser($request);
        $this->rejectable(fn (): EscalationPolicy => $this->policies->create($actor, $team, $request->string('name')->toString(), $request->steps(), $request->boolean('is_default')));

        return back()->with('status', 'policy-created');
    }

    /**
     * @param EscalationPolicyRequest $request Authorized replacement.
     * @param int $team Route team.
     * @param EscalationPolicy $policy Policy bound inside the route team.
     * @return RedirectResponse Previous page with `policy-updated` status.
     */
    public function update(EscalationPolicyRequest $request, int $team, EscalationPolicy $policy): RedirectResponse
    {
        $actor = $this->authenticatedUser($request);
        $this->rejectable(fn (): EscalationPolicy => $this->policies->update($actor, $team, $policy->id, $request->string('name')->toString(), $request->steps(), $request->boolean('is_default')));

        return back()->with('status', 'policy-updated');
    }

    /**
     * @param Request $request Verified browser identity; the action authorizes under lock.
     * @param int $team Route team.
     * @param EscalationPolicy $policy Policy bound inside the route team.
     * @return RedirectResponse Previous page with `policy-deleted` status.
     */
    public function destroy(Request $request, int $team, EscalationPolicy $policy): RedirectResponse
    {
        $actor = $this->authenticatedUser($request);
        $this->policies->delete($actor, $team, $policy->id);

        return back()->with('status', 'policy-deleted');
    }

    /**
     * @param AssignEscalationPolicyRequest $request Authorized choice for the route monitor.
     * @param AssignEscalationPolicy $assign Lock-aware assignment.
     * @param int $team Route team.
     * @param Monitor $monitor Monitor bound inside the route team.
     * @return RedirectResponse Previous page with `monitor-escalation-saved` status.
     */
    public function assign(AssignEscalationPolicyRequest $request, AssignEscalationPolicy $assign, int $team, Monitor $monitor): RedirectResponse
    {
        $actor = $this->authenticatedUser($request);
        $this->rejectable(fn (): Monitor => $assign->assign($actor, $team, $monitor->id, $request->policyId()));

        return back()->with('status', 'monitor-escalation-saved');
    }

    /**
     * @template TResult
     * @param Closure(): TResult $change Domain change that may refuse the request.
     * @return TResult Change result.
     * @throws ValidationException When the policy is invalid for the team.
     */
    private function rejectable(Closure $change): mixed
    {
        try {
            return $change();
        } catch (EscalationPolicyRejected $exception) {
            throw ValidationException::withMessages(['policy' => $exception->getMessage()]);
        }
    }
}
