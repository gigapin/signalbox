<?php

declare(strict_types=1);

/** Name the operator-granted entitlement overrides a team can carry. */

namespace App\Enums\Billing;

/** Each kind maps to an effective plan in TeamEntitlement; the stored team plan is never changed. */
enum PlanOverrideKind: string
{
    /** Testing without plan constraints, resolved to the finite Internal profile. */
    case Unlimited = 'unlimited';
    /** A Pro or Business plan granted without a subscription, for example to a customer. */
    case Comped = 'comped';
}
