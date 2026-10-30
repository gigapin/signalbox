/** Bind a disposable team live-page store to Inertia identity and React's external-store lifecycle. */
import { usePage } from '@inertiajs/react';
import { useEffect, useMemo, useSyncExternalStore } from 'react';
import type { AuthProps } from '../components/auth/AuthLayout';
import { LivePageSession } from '../realtime/LivePageSession';
import type { ReverbConfiguration } from '../realtime/types';

/** Stable prop-root selections for the existing monitoring screens; forms retain their own local drafts. */
const PAGE_KEYS = {
    'Monitors/Index': ['monitors', 'usage', 'can'],
    'Monitors/Show': ['monitor', 'recentIncidents', 'maintenance', 'escalation', 'can'],
    'Incidents/Index': ['incidents'],
    'Incidents/Show': ['incident', 'deliveries', 'postableStatuses', 'can'],
} as const;

/** Shared runtime props are optional for old history/test pages and null when broadcasting is disabled. */
interface LiveProps extends AuthProps {
    /** Public runtime coordinates; never serialized application secrets. */
    realtime?: ReverbConfiguration | null;
}

/**
 * Return a local authoritative projection plus accessible connection feedback and explicit retry.
 * @param teamId Authorized route team, never the saved preference.
 * @param component Existing screen whose mutable data should be recovered.
 * @param monitorId Detail-page scope, or undefined for a complete filtered list.
 */
export function useLivePage<P extends LiveProps>(
    teamId: number,
    component: keyof typeof PAGE_KEYS,
    monitorId?: number,
) {
    const page = usePage<P>();
    const session = useMemo(
        () =>
            new LivePageSession({
                teamId,
                component,
                ...(monitorId === undefined ? {} : { monitorId }),
                url: page.url,
                version: page.version,
                props: page.props,
                keys: PAGE_KEYS[component],
                actorEmail: page.props.auth?.user?.email ?? '',
                configuration: page.props.realtime ?? null,
            }),
        [teamId, component, monitorId, page.url, page.version, page.props],
    );
    useEffect(() => session.start(), [session]);
    const snapshot = useSyncExternalStore(
        session.subscribe,
        session.getSnapshot,
        session.getSnapshot,
    );
    return { ...snapshot, retry: session.retry };
}
