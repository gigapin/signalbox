<?php

declare(strict_types=1);

/** Signal notification channel input whose destination or credentials are malformed. */

namespace App\Exceptions;

use DomainException;

/** Carries one message per configuration field; messages never echo submitted values, which may be secrets. */
final class ChannelConfigurationInvalid extends DomainException
{
    /** @param array<string, string> $errors Message per configuration field name, such as `webhook_url`. */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('The channel configuration is invalid.');
    }
}
