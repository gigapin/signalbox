/** Define the public connection projection and the narrow event boundary used by the team client. */

/** Public runtime coordinates; application secrets and Docker publishing addresses are excluded. */
export interface ReverbConfiguration {
    /** Public application key used in the WebSocket handshake. */
    key: string;
    /** Browser-reachable hostname, without a URL scheme or path. */
    host: string;
    /** Browser-reachable TCP port. */
    port: number;
    /** Public transport scheme; HTTPS pages require HTTPS here. */
    scheme: 'http' | 'https';
}

/** Only these event names are registered on the private team channel. */
export const TEAM_EVENTS = [
    'CheckCompleted',
    'IncidentOpened',
    'IncidentReopened',
    'IncidentResolved',
] as const;

/** Short broadcastAs name, without Laravel's application namespace. */
export type TeamEventName = (typeof TEAM_EVENTS)[number];

/** Transport notifications are deliberately independent of React and page data. */
export interface TeamStreamCallbacks {
    /** Recover authoritative state after every successful subscription, including reconnects. */
    subscribed: () => void;
    /** Update transport feedback; connected alone does not prove a private subscription. */
    state: (state: 'connecting' | 'disconnected' | 'error') => void;
    /** Deliver an untrusted versioned payload for validation before invalidation. */
    event: (name: TeamEventName, payload: unknown) => void;
    /** Stop after an authorization denial; retry requires a deliberate user action. */
    denied: () => void;
}

/** Open one isolated team connection and return its idempotent disposal function. */
export type TeamStreamFactory = (
    configuration: ReverbConfiguration,
    teamId: number,
    callbacks: TeamStreamCallbacks,
) => () => void;

/** Display state of a scoped live page; live means subscribed with a successful authoritative read. */
export type LivePhase =
    'off' | 'connecting' | 'syncing' | 'live' | 'disconnected' | 'error' | 'denied';

/** Safely narrow arbitrary JSON without using an unchecked any. */
export function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

/** Validate the schema needed for invalidation; regional facts never become an aggregate monitor status. */
export function validTeamEvent(
    name: TeamEventName,
    payload: unknown,
): payload is Record<string, unknown> {
    if (!isRecord(payload) || payload.schema_version !== 1 || !positiveId(payload.monitor_id)) {
        return false;
    }

    if (name !== 'CheckCompleted') {
        return positiveId(payload.incident_id);
    }

    return (
        typeof payload.execution_id === 'string' &&
        /^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i.test(payload.execution_id) &&
        typeof payload.region === 'string' &&
        ['eu', 'us'].includes(payload.region) &&
        typeof payload.kind === 'string' &&
        ['observed', 'missed', 'infrastructure_failed', 'cancelled'].includes(payload.kind) &&
        typeof payload.status === 'string' &&
        ['up', 'down', 'unknown'].includes(payload.status) &&
        positiveId(payload.monitor_revision) &&
        instant(payload.slot_at) &&
        instant(payload.recorded_at) &&
        (payload.checked_at === null || instant(payload.checked_at)) &&
        (payload.response_time_ms === null ||
            (Number.isSafeInteger(payload.response_time_ms) &&
                Number(payload.response_time_ms) >= 0))
    );
}

/** Accept only positive integer identities exactly representable by the existing numeric page contract. */
function positiveId(value: unknown): boolean {
    return typeof value === 'number' && Number.isSafeInteger(value) && value > 0;
}

/** Accept the UTC ISO-8601 seconds emitted by TeamUpdate, excluding browser-dependent date coercion. */
function instant(value: unknown): boolean {
    return (
        typeof value === 'string' &&
        /^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:Z|\+00:00)$/.test(value) &&
        Number.isFinite(Date.parse(value))
    );
}
