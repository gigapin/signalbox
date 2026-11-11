/** Human labels for platform admin values, so pages never show raw identifiers alone. */

export { planLabel, sourceLabel } from '../billing/labels';

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
