<?php

/** Define stored plan identifiers without resolving subscription entitlements. */

namespace App\Enums\Billing;

/** Identify the plan vocabulary shared by teams and future billing rules. */
enum Plan: string
{
    /** Baseline plan when no paid or trial entitlement applies. */
    case Free = 'free';
    /** Professional plan, also granted temporarily by the initial trial. */
    case Pro = 'pro';
    /** Highest supported plan in the adopted product matrix. */
    case Business = 'business';
}
