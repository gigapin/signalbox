/** Human labels for platform admin values, so pages never show raw identifiers alone. */

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

/**
 * @param instant ISO-8601 instant or null.
 * @param empty Text for a missing value.
 * @returns Date and time in UTC, matching how expiries are stored.
 */
export function utcLabel(instant: string | null, empty = 'None'): string {
    if (instant === null) {
        return empty;
    }

    return `${instant.slice(0, 16).replace('T', ' ')} UTC`;
}
