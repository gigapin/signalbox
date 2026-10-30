/** Prove event-driven recovery, duplicate handling and lifecycle fences without real sockets or request delays. */
import { afterEach, expect, test, vi } from 'vitest';
import { LivePageSession, type LivePageScope } from '../../resources/js/realtime/LivePageSession';
import {
    validTeamEvent,
    type TeamStreamCallbacks,
    type TeamStreamFactory,
} from '../../resources/js/realtime/types';

/** Public-only connection fixture, intentionally unrelated to Compose's publishing host. */
const configuration = { key: 'public-key', host: 'localhost', port: 8080, scheme: 'http' as const };
/** Existing authorized page data; form errors must not be replaced by live snapshots. */
const props = {
    team: { id: 7 },
    auth: { user: { email: 'actor@example.test' } },
    realtime: configuration,
    monitor: { id: 11, lastStatus: 'unknown' },
    errors: { name: 'Keep this draft error' },
};
/** Versioned regional fact; its down status is deliberately different from the authoritative aggregate. */
const check = {
    schema_version: 1,
    monitor_id: 11,
    execution_id: '00000000-0000-4000-8000-000000000001',
    region: 'eu',
    monitor_revision: 1,
    kind: 'observed',
    status: 'down',
    response_time_ms: 123,
    slot_at: '2026-10-30T12:00:00+00:00',
    checked_at: '2026-10-30T12:00:01+00:00',
    recorded_at: '2026-10-30T12:00:01+00:00',
};
/** Active sessions are disposed even if an assertion fails. */
const sessions: LivePageSession<typeof props>[] = [];

afterEach(() => {
    for (const session of sessions.splice(0)) session.stop();
});

/** Build the actual Inertia partial response shape with replaceable boundary data. */
function response(next: Record<string, unknown> = {}, status = 200): Response {
    return new Response(
        JSON.stringify({
            component: 'Monitors/Show',
            props: { ...props, monitor: { id: 11, lastStatus: 'up' }, ...next },
        }),
        { status, headers: { 'X-Inertia': 'true', 'Content-Type': 'application/json' } },
    );
}

/** Create a real coordinator with separately controlled socket callbacks and HTTP promises. */
function fixture(overrides: Partial<LivePageScope<typeof props>> = {}) {
    vi.useFakeTimers();
    const callbacks: TeamStreamCallbacks[] = [];
    const closes: ReturnType<typeof vi.fn>[] = [];
    const connect = vi.fn<TeamStreamFactory>((_config, _team, signals) => {
        callbacks.push(signals);
        const close = vi.fn();
        closes.push(close);
        return close;
    });
    const read = vi.fn<typeof fetch>().mockImplementation(() => Promise.resolve(response()));
    const session = new LivePageSession(
        {
            teamId: 7,
            component: 'Monitors/Show',
            url: '/teams/7/monitors/11?status=down&page=2',
            version: 'asset-v1',
            props,
            keys: ['monitor'],
            actorEmail: 'actor@example.test',
            configuration,
            ...overrides,
        },
        connect,
        read,
    );
    sessions.push(session);
    session.start();
    return { session, read, connect, callbacks, closes };
}

/** Await coalescing and fetch/json microtasks using the controlled clock. */
async function tick(milliseconds = 0): Promise<void> {
    await vi.advanceTimersByTimeAsync(milliseconds);
}

test('waits for private admission then recovers the filtered URL and only whitelisted local props', async () => {
    const f = fixture();
    expect(f.read).not.toHaveBeenCalled();
    f.callbacks[0]?.subscribed();
    await tick();
    expect(f.read.mock.calls[0]?.[0]).toBe('/teams/7/monitors/11?status=down&page=2');
    expect(f.read.mock.calls[0]?.[1]).toMatchObject({
        credentials: 'same-origin',
        cache: 'no-store',
        redirect: 'error',
        headers: {
            'X-Inertia-Version': 'asset-v1',
            'X-Inertia-Partial-Component': 'Monitors/Show',
            'X-Inertia-Partial-Data': 'monitor,team,auth,realtime',
        },
    });
    expect(f.session.getSnapshot()).toMatchObject({
        phase: 'live',
        props: { monitor: { lastStatus: 'up' }, errors: props.errors },
    });
    await tick(60_000);
    expect(f.read).toHaveBeenCalledTimes(1);
});

test('coalesces duplicate and out-of-order regional facts without applying their status directly', async () => {
    const f = fixture();
    f.callbacks[0]?.subscribed();
    await tick();
    f.callbacks[0]?.event('CheckCompleted', check);
    f.callbacks[0]?.event('CheckCompleted', check);
    f.callbacks[0]?.event('CheckCompleted', {
        ...check,
        execution_id: '00000000-0000-4000-8000-000000000002',
        slot_at: '2026-10-29T12:00:00+00:00',
    });
    expect(f.session.getSnapshot().props.monitor.lastStatus).toBe('up');
    expect(f.session.getSnapshot().phase).toBe('live');
    await tick(249);
    expect(f.read).toHaveBeenCalledTimes(1);
    await tick(1);
    expect(f.read).toHaveBeenCalledTimes(2);
    f.callbacks[0]?.event('CheckCompleted', check);
    await tick(5000);
    expect(f.read).toHaveBeenCalledTimes(2);
});

test('recovers every reconnect even when no event was received during the outage', async () => {
    const f = fixture();
    f.callbacks[0]?.subscribed();
    await tick();
    f.callbacks[0]?.state('disconnected');
    expect(f.session.getSnapshot().phase).toBe('disconnected');
    f.callbacks[0]?.event('CheckCompleted', check);
    await tick(5000);
    expect(f.read).toHaveBeenCalledTimes(1);
    f.callbacks[0]?.subscribed();
    await tick();
    expect(f.read).toHaveBeenCalledTimes(2);
    expect(f.session.getSnapshot().phase).toBe('live');
});

test('serializes reads and follows an event received during a read with exactly one more snapshot', async () => {
    const f = fixture();
    let complete: (value: Response) => void = () => {
        throw new Error('Request did not start');
    };
    f.read.mockImplementationOnce(
        () =>
            new Promise((resolve) => {
                complete = resolve;
            }),
    );
    f.callbacks[0]?.subscribed();
    await tick();
    for (let i = 0; i < 100; i++)
        f.callbacks[0]?.event('IncidentResolved', {
            schema_version: 1,
            monitor_id: 11,
            incident_id: 99,
        });
    await tick(1000);
    expect(f.read).toHaveBeenCalledTimes(1);
    complete(response());
    await tick(250);
    expect(f.read).toHaveBeenCalledTimes(2);
    await tick(5000);
    expect(f.read).toHaveBeenCalledTimes(2);
});

test.each([401, 403, 404, 419])(
    'closes the live channel when a snapshot denies access with %s',
    async (status) => {
        const f = fixture();
        f.read.mockResolvedValueOnce(response({}, status));
        f.callbacks[0]?.subscribed();
        await tick();
        expect(f.session.getSnapshot().phase).toBe('denied');
        expect(f.closes[0]).toHaveBeenCalledTimes(1);
        f.callbacks[0]?.subscribed();
        f.callbacks[0]?.event('CheckCompleted', check);
        await tick(60_000);
        expect(f.read).toHaveBeenCalledTimes(1);
    },
);

test.each(['team', 'actor', 'component', 'missing-root', 'non-inertia', 'network'])(
    'rejects %s responses and retains the previous authorized projection',
    async (failure) => {
        const f = fixture();
        if (failure === 'team') f.read.mockResolvedValueOnce(response({ team: { id: 8 } }));
        if (failure === 'actor')
            f.read.mockResolvedValueOnce(
                response({ auth: { user: { email: 'foreign@example.test' } } }),
            );
        if (failure === 'component')
            f.read.mockResolvedValueOnce(
                new Response(JSON.stringify({ component: 'Other', props }), {
                    headers: { 'X-Inertia': 'true' },
                }),
            );
        if (failure === 'missing-root')
            f.read.mockResolvedValueOnce(
                new Response(
                    JSON.stringify({
                        component: 'Monitors/Show',
                        props: { team: props.team, auth: props.auth },
                    }),
                    { headers: { 'X-Inertia': 'true' } },
                ),
            );
        if (failure === 'non-inertia')
            f.read.mockResolvedValueOnce(new Response('<html>Login</html>'));
        if (failure === 'network')
            f.read.mockRejectedValueOnce(new TypeError('Network unavailable'));
        f.callbacks[0]?.subscribed();
        await tick();
        expect(f.session.getSnapshot()).toEqual({
            phase: failure === 'team' || failure === 'actor' ? 'denied' : 'error',
            props,
        });
        await tick(60_000);
        expect(f.read).toHaveBeenCalledTimes(1);
        f.session.retry();
        f.callbacks[1]?.subscribed();
        await tick();
        expect(f.session.getSnapshot().phase).toBe('live');
    },
);

test('fences a late JSON response and callbacks after disposal and StrictMode restart', async () => {
    const f = fixture();
    let json: (value: unknown) => void = () => {
        throw new Error('JSON did not start');
    };
    const slow = response();
    vi.spyOn(slow, 'json').mockImplementation(
        () =>
            new Promise((resolve) => {
                json = resolve;
            }),
    );
    f.read.mockResolvedValueOnce(slow);
    f.callbacks[0]?.subscribed();
    await tick();
    const signal = f.read.mock.calls[0]?.[1]?.signal;
    f.session.stop();
    expect(signal?.aborted).toBe(true);
    expect(vi.getTimerCount()).toBe(0);
    f.session.start();
    f.callbacks[0]?.subscribed();
    json({
        component: 'Monitors/Show',
        props: { ...props, monitor: { id: 999, lastStatus: 'down' } },
    });
    await tick();
    expect(f.session.getSnapshot().props.monitor.id).toBe(11);
    expect(f.read).toHaveBeenCalledTimes(1);
    f.callbacks[1]?.subscribed();
    await tick();
    expect(f.session.getSnapshot().phase).toBe('live');
});

test('stops all traffic when disabled and ignores malformed or irrelevant detail events', async () => {
    const off = fixture({ configuration: null });
    await tick(60_000);
    expect(off.connect).not.toHaveBeenCalled();
    expect(off.read).not.toHaveBeenCalled();
    const f = fixture({ monitorId: 11 });
    f.callbacks[0]?.subscribed();
    await tick();
    f.callbacks[0]?.event('CheckCompleted', { ...check, monitor_id: 12 });
    f.callbacks[0]?.event('CheckCompleted', { ...check, schema_version: 2 });
    f.callbacks[0]?.event('IncidentOpened', { schema_version: 1, monitor_id: 11 });
    f.callbacks[0]?.event('CheckCompleted', null);
    await tick(5000);
    expect(f.read).toHaveBeenCalledTimes(1);
    expect(validTeamEvent('CheckCompleted', check)).toBe(true);
});

test('recovers on visibility/online events and disposes their listeners when leaving the page', async () => {
    const f = fixture();
    f.callbacks[0]?.subscribed();
    await tick();
    window.dispatchEvent(new Event('online'));
    document.dispatchEvent(new Event('visibilitychange'));
    await tick(250);
    expect(f.read).toHaveBeenCalledTimes(2);
    f.session.stop();
    window.dispatchEvent(new Event('online'));
    document.dispatchEvent(new Event('visibilitychange'));
    await tick(60_000);
    expect(f.read).toHaveBeenCalledTimes(2);
    expect(vi.getTimerCount()).toBe(0);
});

test('keeps duplicate memory bounded and still refreshes a continuous sequence of new facts', async () => {
    const f = fixture();
    f.callbacks[0]?.subscribed();
    await tick();
    for (let i = 1; i <= 513; i++)
        f.callbacks[0]?.event('CheckCompleted', {
            ...check,
            execution_id: `00000000-0000-4000-8000-${String(i).padStart(12, '0')}`,
        });
    await tick(250);
    expect(f.read).toHaveBeenCalledTimes(2);
    f.callbacks[0]?.event('CheckCompleted', check);
    await tick(250);
    expect(f.read).toHaveBeenCalledTimes(3);
});

test('bounds a stalled snapshot to ten seconds without adding periodic retries', async () => {
    const f = fixture();
    f.read.mockImplementationOnce(
        (_url, options) =>
            new Promise((_resolve, reject) => {
                options?.signal?.addEventListener('abort', () =>
                    reject(new DOMException('Aborted', 'AbortError')),
                );
            }),
    );
    f.callbacks[0]?.subscribed();
    await tick();
    await tick(10_000);
    expect(f.session.getSnapshot().phase).toBe('error');
    expect(f.read.mock.calls[0]?.[1]?.signal?.aborted).toBe(true);
    expect(vi.getTimerCount()).toBe(0);
    await tick(60_000);
    expect(f.read).toHaveBeenCalledTimes(1);
});

test('closes an admitted channel when a recovery response disables live publication', async () => {
    const f = fixture();
    f.read.mockResolvedValueOnce(response({ realtime: null }));
    f.callbacks[0]?.subscribed();
    await tick();
    expect(f.session.getSnapshot().phase).toBe('off');
    expect(f.closes[0]).toHaveBeenCalledOnce();
    f.callbacks[0]?.subscribed();
    window.dispatchEvent(new Event('online'));
    document.dispatchEvent(new Event('visibilitychange'));
    f.session.retry();
    await tick(60_000);
    expect(f.connect).toHaveBeenCalledTimes(1);
    expect(f.read).toHaveBeenCalledTimes(1);
});

test('never sends a page recovery request to a different origin', async () => {
    const f = fixture({ url: 'https://foreign.example.test/teams/7/monitors' });
    f.callbacks[0]?.subscribed();
    await tick();
    expect(f.session.getSnapshot().phase).toBe('error');
    expect(f.read).not.toHaveBeenCalled();
});
