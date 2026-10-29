<?php

declare(strict_types=1);

/** Render the team page that manages notification channels and escalation policies. */

namespace App\Http\Controllers\Notifications;

use App\Http\Controllers\Controller;
use App\Http\Presenters\Notifications\NotificationPresenter;
use App\Models\EscalationPolicy;
use App\Models\Monitor;
use App\Models\NotificationChannel;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Every member may read the page; only owners and admins receive editable values and management controls,
 * and every change is authorized again under lock.
 */
final class NotificationPageController extends Controller
{
    /** @param NotificationPresenter $presenter Safe page projections. */
    public function __construct(private readonly NotificationPresenter $presenter)
    {
    }

    /**
     * @param int $team Route team, already established as tenant context.
     * @return Response Channels, policies, monitors without any policy, offered types, limits and permissions.
     */
    public function index(int $team): Response
    {
        Gate::authorize('viewAny', NotificationChannel::class);
        $identity = Team::query()->findOrFail($team);
        $manager = Gate::allows('create', NotificationChannel::class);
        $now = CarbonImmutable::now('UTC');

        $channels = NotificationChannel::query()->orderBy('name')->orderBy('id')->get();
        $policies = EscalationPolicy::query()->orderBy('name')->get();
        $byId = $channels->keyBy('id')->all();

        $usage = Monitor::query()
            ->whereNotNull('escalation_policy_id')
            ->selectRaw('escalation_policy_id, count(*) as aggregate')
            ->groupBy('escalation_policy_id')
            ->pluck('aggregate', 'escalation_policy_id')
            ->all();

        $channelRows = [];

        foreach ($channels as $channel) {
            $usedBy = array_values($policies
                ->filter(static fn (EscalationPolicy $policy): bool => in_array($channel->id, $policy->channelIds(), true))
                ->map(static fn (EscalationPolicy $policy): string => $policy->name)
                ->all());

            $channelRows[] = $this->presenter->channel($channel, $manager, $usedBy, $now);
        }

        $policyRows = [];

        foreach ($policies as $policy) {
            $policyRows[] = $this->presenter->policy($policy, $byId, (int) ($usage[$policy->id] ?? 0));
        }

        // Without a default, monitors that have no override notify nobody.
        $hasDefault = $policies->contains(static fn (EscalationPolicy $policy): bool => $policy->is_default);
        $unnotified = $hasDefault ? 0 : Monitor::query()->whereNull('escalation_policy_id')->count();

        return Inertia::render('Notifications/Index', [
            'team' => ['id' => $identity->id, 'name' => $identity->name],
            'channels' => $channelRows,
            'policies' => $policyRows,
            'unnotifiedMonitors' => $unnotified,
            'channelTypes' => NotificationPresenter::channelTypes(),
            'limits' => $this->presenter->limits(),
            'can' => ['manage' => $manager],
        ]);
    }
}
