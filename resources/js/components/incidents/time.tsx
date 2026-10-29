/** Format stored UTC instants and convert browser-local form input into instants with an explicit offset. */
import type { ReactNode } from 'react';

/**
 * @param instant ISO-8601 UTC instant or null.
 * @param empty Text shown when the instant is absent.
 * @returns Time element formatted in the viewer's locale, or the fallback text.
 */
export function formatInstant(instant: string | null, empty: string): ReactNode {
    if (instant === null) {
        return empty;
    }

    return <time dateTime={instant}>{new Date(instant).toLocaleString()}</time>;
}

/**
 * @param value Two-digit field value.
 * @returns Zero-padded text.
 */
function pad(value: number): string {
    return String(value).padStart(2, '0');
}

/**
 * Prefill a `datetime-local` input, which has no timezone, with the browser-local wall time of an instant.
 * @param instant ISO-8601 instant.
 * @returns Local `YYYY-MM-DDTHH:mm` value.
 */
export function toLocalInput(instant: string): string {
    const date = new Date(instant);

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

/**
 * User decision: maintenance input is browser-local and travels as UTC with an explicit offset, so the
 * server never guesses a timezone. Unparseable text is sent unchanged for the server to reject.
 * @param value Local `datetime-local` value; empty means absent.
 * @returns ISO-8601 UTC instant, null when empty, or the raw text when it cannot be parsed.
 */
export function fromLocalInput(value: string): string | null {
    if (value.trim() === '') {
        return null;
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? value : date.toISOString();
}
