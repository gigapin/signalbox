/** Label effective plans and their recorded source consistently in member and operator contexts. */

/** Plan names. */
const PLANS: Record<string, string> = {
    free: 'Free',
    pro: 'Pro',
    business: 'Business',
    internal: 'Internal (unlimited testing)',
};

/** Entitlement source names. */
const SOURCES: Record<string, string> = {
    unlimited_override: 'Unlimited testing override',
    comped_override: 'Granted plan',
    subscription: 'Stored plan',
    trial: 'Trial',
    free: 'Free',
};

/**
 * @param plan Plan identifier.
 * @returns Display name, or the identifier when unknown.
 */
export function planLabel(plan: string): string {
    return PLANS[plan] ?? plan;
}

/**
 * @param source Entitlement source identifier.
 * @returns Display name, or the identifier when unknown.
 */
export function sourceLabel(source: string): string {
    return SOURCES[source] ?? source;
}
