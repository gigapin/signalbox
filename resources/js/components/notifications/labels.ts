/** Map notification values to readable text and describe the configuration fields of each channel family. */

/** One configuration input of a channel family. */
export interface ChannelField {
    /** Submitted key inside `config`. */
    name: string;
    /** Visible label. */
    label: string;
    /** Input type hint for the browser. */
    input: 'email' | 'url' | 'text' | 'tel' | 'password';
    /** Short guidance under the input. */
    hint: string;
}

/** Fields per channel family, mirroring ChannelConfigurationParser. */
const FIELDS: Record<string, ChannelField[]> = {
    email: [
        {
            name: 'address',
            label: 'Email address',
            input: 'email',
            hint: 'Receives incident emails.',
        },
    ],
    slack: [
        {
            name: 'webhook_url',
            label: 'Incoming webhook URL',
            input: 'password',
            hint: 'Starts with https://hooks.slack.com/services/. Stored encrypted and never shown again.',
        },
    ],
    telegram: [
        {
            name: 'bot_token',
            label: 'Bot token',
            input: 'password',
            hint: 'Issued by BotFather. Stored encrypted and never shown again.',
        },
        {
            name: 'chat_id',
            label: 'Chat ID',
            input: 'text',
            hint: 'Numeric chat ID or a public @channel name.',
        },
    ],
    webhook: [
        {
            name: 'url',
            label: 'Endpoint URL',
            input: 'password',
            hint: 'HTTPS only. Requests are signed with a secret you can reveal after saving.',
        },
    ],
    sms: [
        {
            name: 'phone',
            label: 'Phone number',
            input: 'tel',
            hint: 'International format, such as +15550001234. SMS is a stub: no message is sent.',
        },
    ],
};

/**
 * @param type Channel family.
 * @returns Configuration inputs of the family; empty for an unknown value.
 */
export function channelFields(type: string): ChannelField[] {
    return FIELDS[type] ?? [];
}

/**
 * @param minutes Delay after the incident confirmation.
 * @returns Readable delay such as "Immediately", "After 15 min" or "After 1 h 30 min".
 */
export function delayLabel(minutes: number): string {
    if (minutes === 0) {
        return 'Immediately';
    }

    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;

    if (hours === 0) {
        return `After ${rest} min`;
    }

    return rest === 0 ? `After ${hours} h` : `After ${hours} h ${rest} min`;
}

/** Readable delivery states. */
const STATUS: Record<string, string> = {
    pending: 'Pending',
    sent: 'Sent',
    failed: 'Failed',
    suppressed: 'Suppressed',
};

/** Readable incident moments. */
const KIND: Record<string, string> = {
    opened: 'Incident opened',
    escalated: 'Escalation',
    resolved: 'Resolution summary',
};

/**
 * @param status Delivery state value.
 * @returns Readable state, or the raw value when unknown.
 */
export function deliveryStatusLabel(status: string): string {
    return STATUS[status] ?? status;
}

/**
 * @param kind Incident moment value.
 * @returns Readable moment, or the raw value when unknown.
 */
export function deliveryKindLabel(kind: string): string {
    return KIND[kind] ?? kind;
}
