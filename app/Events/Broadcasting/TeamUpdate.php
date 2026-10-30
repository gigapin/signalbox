<?php

declare(strict_types=1);

/** Adapt committed domain events into immutable, explicitly selected private-team wire frames. */

namespace App\Events\Broadcasting;

use App\Events\Incidents\IncidentOpened;
use App\Events\Incidents\IncidentReopened;
use App\Events\Incidents\IncidentResolved;
use App\Events\Monitoring\CheckCompleted;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Connection;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;

/** Only this transport may retry; checks remain single observations. Frames contain no Eloquent models or credentials. */
#[Connection('redis')]
#[Queue('broadcasts')]
#[Tries(3)]
#[Timeout(10)]
#[Backoff(1, 5)]
final readonly class TeamUpdate implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    /** @var int Trusted owner used only to choose the private channel, never reflected into the payload. */
    private int $teamId;

    /** @var string Stable short event name; Echo consumers use the corresponding leading-dot listener. */
    private string $name;

    /** @var array<string, string|int|null> Whitelisted immutable wire data; no automatic model/public-property serialization. */
    private array $data;

    /**
     * @param CheckCompleted|IncidentOpened|IncidentReopened|IncidentResolved $event Trusted committed event, validated within its tenant by the publisher.
     * Check status is regional; incident frames invalidate current authorized state rather than replaying mutable snapshots.
     */
    public function __construct(CheckCompleted|IncidentOpened|IncidentReopened|IncidentResolved $event)
    {
        $this->teamId = $event->teamId;
        $this->name = match (true) {
            $event instanceof CheckCompleted => 'CheckCompleted',
            $event instanceof IncidentOpened => 'IncidentOpened',
            $event instanceof IncidentReopened => 'IncidentReopened',
            $event instanceof IncidentResolved => 'IncidentResolved',
        };
        $data = ['schema_version' => 1, 'monitor_id' => $event->monitorId];

        if ($event instanceof CheckCompleted) {
            $data += [
                'execution_id' => $event->executionId,
                'region' => $event->region->value,
                'slot_at' => $event->slotAt->utc()->toIso8601String(),
                'monitor_revision' => $event->monitorRevision,
                'kind' => $event->kind->value,
                'status' => $event->status->value,
                'response_time_ms' => $event->responseTimeMs,
                'checked_at' => $event->checkedAt?->utc()->toIso8601String(),
                'recorded_at' => $event->recordedAt->utc()->toIso8601String(),
            ];
        } else {
            $data['incident_id'] = $event->incidentId;
        }

        $this->data = $data;
    }

    /** @return list<PrivateChannel> Only the committed owner's private channel; no public/presence channel is emitted. */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('team.'.$this->teamId)];
    }

    /** @return string Explicit public event name, independent of PHP namespaces. */
    public function broadcastAs(): string
    {
        return $this->name;
    }

    /** @return array<string, string|int|null> Minimal versioned frame, with no URLs, headers, bodies, errors or private incident text. */
    public function broadcastWith(): array
    {
        return $this->data;
    }
}
