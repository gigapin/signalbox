<?php

declare(strict_types=1);

/** Explain where a team's effective plan comes from, for operators and future billing reconciliation. */

namespace App\Enums\Billing;

/** Sources are listed in TeamEntitlement precedence order, highest first. */
enum EntitlementSource: string
{
    /** Active unlimited testing override. */
    case UnlimitedOverride = 'unlimited_override';
    /** Active plan granted by an operator without a subscription. */
    case CompedOverride = 'comped_override';
    /** Stored Pro or Business plan; a placeholder for the Stripe subscription until M9. */
    case Subscription = 'subscription';
    /** Initial 14-day trial of a Free team. */
    case Trial = 'trial';
    /** No paid, trial or granted entitlement applies. */
    case Free = 'free';
}
