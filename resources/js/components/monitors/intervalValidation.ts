/** Validate editable cadence locally using server-projected limits without replacing save-time policy checks. */

/**
 * @param value Raw seconds as typed; never coerce or replace the user's input.
 * @param minimumSeconds Effective plan minimum projected by the server.
 * @param maximumSeconds Operational upper bound projected by the server.
 * @param originalValue Persisted cadence when editing, or null when creating.
 * @returns An inline error, or undefined when the client can submit the cadence.
 * An unchanged legacy cadence may stay below a downgraded plan's minimum, matching the domain policy.
 */
export function intervalValidationError(
    value: string,
    minimumSeconds: number,
    maximumSeconds: number,
    originalValue: string | null,
): string | undefined {
    if (value === '') {
        return 'Enter an interval in seconds.';
    }

    if (!/^(0|[1-9][0-9]*)$/.test(value) || !Number.isSafeInteger(Number(value))) {
        return 'Enter a whole number of seconds using digits only.';
    }

    const seconds = Number(value);

    if (seconds > maximumSeconds) {
        return `Enter an interval of at most ${maximumSeconds} seconds.`;
    }

    if (seconds < minimumSeconds && (originalValue === null || seconds !== Number(originalValue))) {
        return `Your plan requires an interval of at least ${minimumSeconds} seconds.`;
    }

    return undefined;
}
