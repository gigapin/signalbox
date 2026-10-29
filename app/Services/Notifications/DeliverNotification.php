<?php

declare(strict_types=1);

/** Execute one provider attempt outside database transactions and persist only safe, fenced outcomes. */

namespace App\Services\Notifications;

use App\Exceptions\ChannelDeliveryFailed;
use App\Services\Security\SecretRedactor;
use RuntimeException;
use Throwable;

/** Expected provider failures schedule durable retries; unexpected errors cannot leak private exception chains to failed_jobs. */
final readonly class DeliverNotification
{
    /**
     * @param NotificationDeliveryLedger $ledger Attempt admission, completion and retry state machine.
     * @param ChannelAdapterRegistry $adapters Shared verification/incident provider strategies.
     * @param SecretRedactor $redactor Defence in depth for provider adapter diagnostics.
     */
    public function __construct(
        private NotificationDeliveryLedger $ledger,
        private ChannelAdapterRegistry $adapters,
        private SecretRedactor $redactor,
    ) {
    }

    /**
     * @param int $teamId Committed owner.
     * @param int $deliveryId Committed delivery identity.
     * @param string $token Unique queue-envelope fence.
     * @throws RuntimeException On an unexpected failure after recording a generic safe outcome.
     */
    public function run(int $teamId, int $deliveryId, string $token): void
    {
        $attempt = $this->ledger->acquire($teamId, $deliveryId, $token);

        if ($attempt === null) {
            return;
        }

        try {
            $receipt = $this->adapters->for($attempt->configuration->type())->send($attempt->configuration, $attempt->message);
        } catch (ChannelDeliveryFailed $exception) {
            $secrets = [...array_values($attempt->configuration->toArray()), $attempt->message->subject, $attempt->message->text];

            foreach ($attempt->message->data as $value) {
                if (is_string($value)) {
                    $secrets[] = $value;
                }
            }

            $this->ledger->fail($teamId, $deliveryId, $token, $this->redactor->message($exception->getMessage(), $secrets));

            return;
        } catch (Throwable) {
            $this->ledger->fail($teamId, $deliveryId, $token, 'The delivery worker encountered an internal error.');

            throw new RuntimeException('The notification delivery worker encountered an internal error.');
        }

        $this->ledger->complete($teamId, $deliveryId, $token, $receipt);
    }
}
