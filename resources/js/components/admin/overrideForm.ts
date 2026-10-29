/** Form state of the plan override form and the payload derived from it. */

/** Choice offered by the radio group: no override, unlimited testing, or a granted plan. */
export type Choice = 'none' | 'unlimited' | 'comped-pro' | 'comped-business';

/** Form state; the payload sent to the server is derived from it. */
export interface OverrideForm {
    /** Selected choice. */
    choice: Choice;
    /** Inclusive UTC date, YYYY-MM-DD, or empty for no end. */
    valid_through: string;
    /** Justification recorded in the audit log. */
    reason: string;
}

/**
 * @param data Form state.
 * @returns Override payload with only the fields relevant to the choice.
 */
export function overridePayload(data: OverrideForm): Record<string, string> {
    if (data.choice === 'none') {
        return { reason: data.reason };
    }

    const payload: Record<string, string> =
        data.choice === 'unlimited'
            ? { kind: 'unlimited' }
            : { kind: 'comped', plan: data.choice === 'comped-business' ? 'business' : 'pro' };

    if (data.valid_through !== '') {
        payload.valid_through = data.valid_through;
    }

    return { ...payload, reason: data.reason };
}
