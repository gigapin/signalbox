<?php

declare(strict_types=1);

/** Model confirmed, lost and uncertain broker deliveries without networking. */

namespace Tests\Support;

use App\Contracts\Monitoring\CheckWorkPublisher;
use Closure;

/** Tests control the post-send outcome to reproduce the commit/send/ack crash boundaries. */
final class RecordingCheckPublisher implements CheckWorkPublisher
{
    /** @var list<array{team: int, execution: int, region: string}> Submitted scalar envelopes. */
    public array $messages = [];

    /** @var Closure|null Optional broker outcome invoked after recording a submission. */
    public ?Closure $afterSend = null;

    /**
     * @param int $teamId Envelope tenant.
     * @param int $executionId Persisted work identity.
     * @param string $region Simulated region.
     */
    public function publish(int $teamId, int $executionId, string $region): void
    {
        $this->messages[] = ['team' => $teamId, 'execution' => $executionId, 'region' => $region];

        if ($this->afterSend !== null) {
            ($this->afterSend)();
        }
    }
}
