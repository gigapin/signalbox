<?php

declare(strict_types=1);

/** Signal that a channel adapter could not deliver a message. */

namespace App\Exceptions;

use RuntimeException;

/** The message is safe to show to managers: adapters must never include credentials or provider payloads. */
final class ChannelDeliveryFailed extends RuntimeException
{
}
