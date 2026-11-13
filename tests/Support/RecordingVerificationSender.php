<?php

/** Record verification messages instead of delivering them, optionally failing like an unavailable provider. */

namespace Tests\Support;

use App\Contracts\Notifications\ChannelConfiguration;
use App\Contracts\Notifications\ChannelVerificationSender;
use App\Exceptions\ChannelDeliveryFailed;

/** Bound in place of the real sender by tests that need to read the plaintext code. */
final class RecordingVerificationSender implements ChannelVerificationSender
{
    /** @var list<array{configuration: ChannelConfiguration, code: string}> Messages in send order. */
    public array $sent = [];

    /** @var string|null Failure message to raise instead of recording, or null to succeed. */
    public ?string $failWith = null;

    /**
     * @param ChannelConfiguration $configuration Destination.
     * @param string $code Plaintext code.
     * @throws ChannelDeliveryFailed When a failure is configured.
     */
    public function send(ChannelConfiguration $configuration, string $code): void
    {
        if ($this->failWith !== null) {
            throw new ChannelDeliveryFailed($this->failWith);
        }

        $this->sent[] = ['configuration' => $configuration, 'code' => $code];
    }

    /** @return string Code of the most recent message. */
    public function lastCode(): string
    {
        return $this->sent[array_key_last($this->sent)]['code'];
    }

    /** @return self Fresh recorder bound as the application's sender. */
    public static function bind(): self
    {
        $sender = new self();
        app()->instance(ChannelVerificationSender::class, $sender);

        return $sender;
    }
}
