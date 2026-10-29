<?php

declare(strict_types=1);

/** Signal an escalation policy that is malformed, references unusable channels or exceeds the team limits. */

namespace App\Exceptions;

use DomainException;

/** Raised by policy actions; transport layers translate it into a validation response on the `policy` key. */
final class EscalationPolicyRejected extends DomainException
{
}
