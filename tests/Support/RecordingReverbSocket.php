<?php

declare(strict_types=1);

/** Record native Reverb text-frame delivery without opening a WebSocket or replacing protocol authorization. */

namespace Tests\Support;

use Laravel\Reverb\Contracts\WebSocketConnection;
use LogicException;

/** A transport-only test double; application identity, channels and signatures use the real Reverb classes. */
final class RecordingReverbSocket implements WebSocketConnection
{
    /** @var list<string> Text frames sent by the real protocol connection, in delivery order. */
    public array $messages = [];

    /** Whether the real connection requested transport termination. */
    public bool $closed = false;

    /** @param int $identifier Synthetic transport identity; Reverb separately generates its protocol socket ID. */
    public function __construct(private readonly int $identifier)
    {
    }

    /** @return int Stable in-memory transport identity. */
    public function id(): int
    {
        return $this->identifier;
    }

    /**
     * @param mixed $message Native text frame; control frames are outside these acceptance scenarios.
     * @throws LogicException When a test unexpectedly requires a non-text transport implementation.
     */
    public function send(mixed $message): void
    {
        if (!is_string($message)) {
            throw new LogicException('The acceptance socket records text frames only.');
        }

        $this->messages[] = $message;
    }

    /** @param mixed $message Optional transport shutdown payload, unused by the acceptance recorder. */
    public function close(mixed $message = null): void
    {
        $this->closed = true;
    }
}
