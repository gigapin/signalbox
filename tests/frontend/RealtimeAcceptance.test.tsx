/** Connect real Echo, private authorization, the scoped store and actual dashboard DOM using transport-only doubles. */
import { router } from '@inertiajs/react';
import { act, screen, within } from '@testing-library/react';
import { beforeEach, afterEach, expect, test, vi } from 'vitest';
import type { DashboardView } from '../../resources/js/components/dashboard/types';
import type { IncidentSummary } from '../../resources/js/components/incidents/types';
import Show from '../../resources/js/pages/Dashboard/Show';
import contract from '../Fixtures/team-broadcast-payloads.json';
import { MemoryPusher } from './MemoryPusher';
import { dashboard, identity } from './dashboardFixture';
import { renderInertia } from './renderInertia';

vi.mock('pusher-js', async () => {
    const { MemoryPusher: Client } = await import('./MemoryPusher');
    return { default: Client };
});

/** Existing public runtime configuration; no app secret is available in this client fixture. */
const configuration = { key: 'public-key', host: 'localhost', port: 8080, scheme: 'http' };

/** One current open incident; duplicate/reordered notifications must never append another row. */
const incident: IncidentSummary = {
    id: 31,
    monitor: { id: 11, name: 'Checkout API' },
    status: 'investigating',
    severity: 'major',
    cause: 'unexpected_status',
    startedAt: '2026-10-30T10:00:00+00:00',
    confirmedAt: '2026-10-30T10:00:01+00:00',
    resolvedAt: null,
    durationSeconds: null,
    acknowledged: false,
    reopenedCount: 1,
};

beforeEach(() => {
    vi.useFakeTimers();
    MemoryPusher.instances = [];
    document.cookie = 'XSRF-TOKEN=first%20token; path=/';
    vi.spyOn(window, 'scrollTo').mockImplementation(() => undefined);
});

afterEach(() => {
    document.cookie = 'XSRF-TOKEN=; Max-Age=0; path=/';
});

/** Await React, authorization/fetch promises and one bounded timer step without real waits. */
async function advance(milliseconds = 0): Promise<void> {
    await act(async () => {
        await vi.advanceTimersByTimeAsync(milliseconds);
    });
}

/** Mount the actual dashboard with native Echo and separately observed authorization/snapshot HTTP traffic. */
function fixture() {
    let next: DashboardView = structuredClone(dashboard);
    const grants: RequestInit[] = [];
    const reads: RequestInit[] = [];
    let snapshotStatus = 200;
    let authStatus = 200;
    const read = vi.fn<typeof fetch>().mockImplementation((url, options) => {
        if (url === '/broadcasting/auth') {
            grants.push(options ?? {});
            return Promise.resolve(
                new Response(JSON.stringify({ auth: 'public-key:synthetic-grant' }), {
                    status: authStatus,
                }),
            );
        }
        reads.push(options ?? {});
        return Promise.resolve(
            new Response(
                JSON.stringify({
                    component: 'Dashboard/Show',
                    props: { ...identity, realtime: configuration, dashboard: next },
                }),
                { status: snapshotStatus, headers: { 'X-Inertia': 'true' } },
            ),
        );
    });
    vi.stubGlobal('fetch', read);
    const mounted = renderInertia('Signalbox', Show, {
        ...identity,
        realtime: configuration,
        dashboard: structuredClone(dashboard),
        rejectedFilters: [],
    });
    const client = MemoryPusher.instances[0];
    if (!client) throw new Error('Expected the production adapter to create a client.');
    return {
        client,
        mounted,
        grants,
        reads,
        read,
        next: (value: DashboardView) => {
            next = value;
        },
        denySnapshot: () => {
            snapshotStatus = 403;
        },
        denyAuthorization: () => {
            authStatus = 403;
        },
    };
}

test('authorizes the private native Echo channel and batches duplicate numeric check frames into one authoritative DOM update', async () => {
    const f = fixture();
    expect([...f.client.channels.keys()]).toEqual(['private-team.7']);
    act(() => f.client.establish());
    await advance();
    expect(screen.getByRole('status')).toHaveTextContent('Live updates connected');
    expect(f.grants[0]).toMatchObject({
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-XSRF-TOKEN': 'first token' },
        body: JSON.stringify({ socket_id: '111.222', channel_name: 'private-team.7' }),
    });
    const next = {
        ...dashboard,
        counts: { ...dashboard.counts, up: 0, down: 1 },
        monitors: {
            ...dashboard.monitors,
            data: dashboard.monitors.data.map((row) => ({ ...row, lastStatus: 'down' })),
        },
    };
    f.next(next);
    act(() => {
        for (let i = 0; i < 50; i++)
            f.client.receive('private-team.7', 'CheckCompleted', contract.CheckCompleted);
    });
    expect(screen.getByText('Up', { selector: 'span' })).toBeInTheDocument();
    await advance(250);
    expect(screen.getByText('Down', { selector: 'span' })).toBeInTheDocument();
    expect(f.reads).toHaveLength(2);
    expect(
        within(screen.getByRole('table', { name: 'Monitors of Synthetic team' })).getAllByRole(
            'row',
        ),
    ).toHaveLength(2);
    await advance(60_000);
    expect(f.reads).toHaveLength(2);
});

test('treats repeated and reordered open resolved and reopen frames as invalidations without duplicating incident rows', async () => {
    const f = fixture();
    act(() => f.client.establish());
    await advance();
    f.next({
        ...dashboard,
        incidents: [incident],
        counts: { ...dashboard.counts, openIncidents: 1 },
    });
    act(() => {
        for (const event of [
            'IncidentOpened',
            'IncidentResolved',
            'IncidentReopened',
            'IncidentResolved',
            'IncidentReopened',
        ] as const)
            f.client.receive('private-team.7', event, contract[event]);
    });
    await advance(250);
    expect(f.reads).toHaveLength(2);
    expect(
        within(screen.getByRole('table', { name: 'Latest open incidents' })).getAllByRole('row'),
    ).toHaveLength(2);
    expect(screen.getByRole('link', { name: 'Incident #31' })).toBeInTheDocument();
    f.next(dashboard);
    act(() => f.client.receive('private-team.7', 'IncidentResolved', contract.IncidentResolved));
    await advance(250);
    expect(screen.queryByRole('table', { name: 'Latest open incidents' })).not.toBeInTheDocument();
    expect(screen.getByText('No open incidents.')).toBeInTheDocument();
});

test('ignores foreign/public lookalikes unsupported names and malformed frames at the real adapter boundary', async () => {
    const f = fixture();
    act(() => f.client.establish());
    await advance();
    act(() => {
        f.client.receive('private-team.8', 'CheckCompleted', contract.CheckCompleted);
        f.client.receive('team.7', 'CheckCompleted', contract.CheckCompleted);
        f.client.receive('private-team.7', 'App.Events.CheckCompleted', contract.CheckCompleted);
        f.client.receive('private-team.7', 'CheckCompleted', {
            ...contract.CheckCompleted,
            schema_version: 2,
        });
        f.client.receive('private-team.7', 'CheckCompleted', {
            ...contract.CheckCompleted,
            execution_id: 'not-a-ledger-id',
        });
    });
    await advance(5000);
    expect(f.reads).toHaveLength(1);
    expect(f.grants).toHaveLength(1);
});

test('reauthorizes a replacement socket with the current cookie and repairs an outage without any delivered event', async () => {
    const f = fixture();
    act(() => f.client.establish());
    await advance();
    act(() => f.client.connection.change('disconnected'));
    f.next({ ...dashboard, counts: { ...dashboard.counts, up: 0, unknown: 1 } });
    document.cookie = 'XSRF-TOKEN=rotated%20token; path=/';
    act(() => f.client.establish('333.444'));
    await advance();
    expect(f.grants).toHaveLength(2);
    expect(f.grants[1]).toMatchObject({
        headers: { 'X-XSRF-TOKEN': 'rotated token' },
        body: JSON.stringify({ socket_id: '333.444', channel_name: 'private-team.7' }),
    });
    expect(f.reads).toHaveLength(2);
    expect(screen.getByRole('status')).toHaveTextContent('Live updates connected');
});

test('closes the real Echo scope after access loss and refuses late channel callbacks or browser wake', async () => {
    const f = fixture();
    act(() => f.client.establish());
    await advance();
    const oldChannel = f.client.channels.get('private-team.7');
    f.denySnapshot();
    act(() => f.client.receive('private-team.7', 'CheckCompleted', contract.CheckCompleted));
    await advance(250);
    expect(screen.getByRole('status')).toHaveTextContent('Live access is no longer available');
    expect(f.client.disconnected).toBe(true);
    expect(f.client.channels.size).toBe(0);
    act(() => {
        oldChannel?.emit('CheckCompleted', { ...contract.CheckCompleted, execution_id: 22 });
        window.dispatchEvent(new Event('online'));
    });
    await advance(60_000);
    expect(f.reads).toHaveLength(2);
    expect(vi.getTimerCount()).toBe(0);
});

test('never requests a private snapshot after real adapter authorization is denied', async () => {
    const f = fixture();
    f.denyAuthorization();
    act(() => f.client.establish());
    await advance();
    expect(f.reads).toHaveLength(0);
    expect(f.client.disconnected).toBe(true);
    expect(screen.getByRole('status')).toHaveTextContent('Live access is no longer available');
});

test.each(['team', 'account'])(
    'disposes native Echo when a same-component %s change replaces the page identity',
    async (change) => {
        const f = fixture();
        act(() => f.client.establish());
        await advance();
        const oldChannel = f.client.channels.get('private-team.7');
        const nextIdentity =
            change === 'team'
                ? { ...identity, team: { id: 8, name: 'Second team' } }
                : {
                      ...identity,
                      auth: { user: { name: 'Another account', email: 'another@example.test' } },
                  };
        await act(async () => {
            router.replace({
                url: change === 'team' ? '/teams/8/dashboard' : '/teams/7/dashboard',
                props: {
                    appName: 'Signalbox',
                    errors: {},
                    ...nextIdentity,
                    realtime: configuration,
                    dashboard: structuredClone(dashboard),
                    rejectedFilters: [],
                },
                preserveState: true,
            });
            await vi.advanceTimersByTimeAsync(0);
        });
        expect(f.client.disconnected).toBe(true);
        expect(MemoryPusher.instances).toHaveLength(2);
        act(() => oldChannel?.emit('CheckCompleted', contract.CheckCompleted));
        await advance(1000);
        expect(f.reads).toHaveLength(1);
        expect(f.grants).toHaveLength(1);
        f.mounted.unmount();
        expect(MemoryPusher.instances[1]?.disconnected).toBe(true);
        // Inertia's Head provider may flush one final document update after unmount; it is not a live-page timer.
        await advance(60_000);
        expect(f.reads).toHaveLength(1);
        expect(f.grants).toHaveLength(1);
        expect(vi.getTimerCount()).toBe(0);
    },
);

test.each(['missed', 'cancelled', 'infrastructure_failed'])(
    'recovers a %s frame with nullable observation fields without inventing a status or latency',
    async (kind) => {
        const f = fixture();
        act(() => f.client.establish());
        await advance();
        act(() =>
            f.client.receive('private-team.7', 'CheckCompleted', {
                ...contract.CheckCompleted,
                kind,
                status: 'unknown',
                response_time_ms: null,
                checked_at: null,
            }),
        );
        await advance(250);
        expect(f.reads).toHaveLength(2);
        expect(screen.getByText('Up', { selector: 'span' })).toBeInTheDocument();
        expect(screen.getAllByText('Unavailable')).toHaveLength(3);
    },
);
