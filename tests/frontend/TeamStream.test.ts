/** Exercise the real Echo adapter with an in-memory Pusher transport and actual session/CSRF request options. */
import type { Options } from 'pusher-js';
import { afterEach, beforeEach, expect, test, vi } from 'vitest';
import { openTeamStream } from '../../resources/js/realtime/teamStream';
import type { TeamStreamCallbacks } from '../../resources/js/realtime/types';

/** Native Echo binds to this synthetic channel while fetch remains explicitly controlled by each test. */
const socket = vi.hoisted(() => ({
    options: undefined as Options | undefined,
    bindings: new Map<string, (data: unknown) => void>(),
    states: new Map<string, (data: { current: string }) => void>(),
    subscribe: vi.fn(),
    unsubscribe: vi.fn(),
    disconnect: vi.fn(),
}));

vi.mock('pusher-js', () => ({
    /** Supply only the native Pusher methods used by the real Echo connector/channel. */
    default: class {
        /** Native Echo and the application both listen to this connection emitter. */
        connection = {
            bind: (name: string, callback: (data: { current: string }) => void) => {
                socket.states.set(name, callback);
            },
            unbind: (name: string) => {
                socket.states.delete(name);
            },
        };
        /** Capture public transport options; constructing this double never connects to a service. */
        constructor(_key: string, options: Options) {
            socket.options = options;
        }
        /** Return a channel whose native Echo bindings can be inspected and triggered. */
        subscribe(name: string) {
            socket.subscribe(name);
            return {
                bind: (event: string, callback: (data: unknown) => void) => {
                    socket.bindings.set(event, callback);
                },
            };
        }
        /** Record native unsubscribe calls during scoped disposal. */
        unsubscribe(name: string) {
            socket.unsubscribe(name);
        }
        /** Record transport shutdown independently of channel cleanup. */
        disconnect() {
            socket.disconnect();
        }
    },
}));

/** Clean every transport created by a test, including failures before explicit disposal. */
const closes: (() => void)[] = [];

beforeEach(() => {
    socket.bindings.clear();
    socket.states.clear();
    document.cookie = 'XSRF-TOKEN=fresh%20token; path=/';
});
afterEach(() => {
    for (const close of closes.splice(0)) close();
    document.cookie = 'XSRF-TOKEN=; Max-Age=0; path=/';
});

/** Open the production Echo adapter with test-owned callbacks and no real Pusher client. */
function fixture() {
    const signals: TeamStreamCallbacks = {
        subscribed: vi.fn(),
        state: vi.fn(),
        event: vi.fn(),
        denied: vi.fn(),
    };
    const close = openTeamStream(
        { key: 'public-key', host: 'socket.example.test', port: 443, scheme: 'https' },
        7,
        signals,
    );
    closes.push(close);
    const authorization = socket.options?.channelAuthorization;
    if (!authorization || !('customHandler' in authorization))
        throw new Error('Expected explicit session authorizer');
    return { close, signals, authorize: authorization.customHandler };
}

test('uses the private team channel, short event names, browser TLS coordinates and no global request interceptors', () => {
    const f = fixture();
    expect(socket.options).toMatchObject({
        wsHost: 'socket.example.test',
        wsPort: 443,
        wssPort: 443,
        forceTLS: true,
        enabledTransports: ['ws', 'wss'],
        enableStats: false,
    });
    expect(socket.subscribe).toHaveBeenCalledWith('private-team.7');
    expect([...socket.bindings.keys()]).toEqual(
        expect.arrayContaining([
            'CheckCompleted',
            'IncidentOpened',
            'IncidentReopened',
            'IncidentResolved',
        ]),
    );
    socket.bindings.get('pusher:subscription_succeeded')?.({});
    socket.bindings.get('CheckCompleted')?.({ schema_version: 1 });
    expect(f.signals.subscribed).toHaveBeenCalledOnce();
    expect(f.signals.event).toHaveBeenCalledWith('CheckCompleted', { schema_version: 1 });
    f.close();
    f.close();
    socket.bindings.get('CheckCompleted')?.({ schema_version: 1 });
    expect(f.signals.event).toHaveBeenCalledTimes(1);
    expect(socket.unsubscribe).toHaveBeenCalledWith('private-team.7');
    expect(socket.disconnect).toHaveBeenCalledTimes(1);
    expect(socket.states.has('state_change')).toBe(false);
});

test('signs with the current CSRF cookie and same-origin credentials without redirects or caching', async () => {
    const read = vi
        .fn<typeof fetch>()
        .mockResolvedValue(new Response(JSON.stringify({ auth: 'public-key:signature' })));
    vi.stubGlobal('fetch', read);
    const f = fixture();
    const done = vi.fn();
    f.authorize({ socketId: '123.456', channelName: 'private-team.7' }, done);
    await vi.waitFor(() =>
        expect(done).toHaveBeenCalledWith(null, { auth: 'public-key:signature' }),
    );
    expect(read.mock.calls[0]?.[0]).toBe('/broadcasting/auth');
    expect(read.mock.calls[0]?.[1]).toMatchObject({
        method: 'POST',
        credentials: 'same-origin',
        redirect: 'error',
        cache: 'no-store',
        headers: { 'X-XSRF-TOKEN': 'fresh token' },
        body: JSON.stringify({ socket_id: '123.456', channel_name: 'private-team.7' }),
    });
});

test.each([401, 403, 419])('reports terminal authorization denial for HTTP %s', async (status) => {
    vi.stubGlobal('fetch', vi.fn<typeof fetch>().mockResolvedValue(new Response('{}', { status })));
    const f = fixture();
    const done = vi.fn();
    f.authorize({ socketId: '123.456', channelName: 'private-team.7' }, done);
    await vi.waitFor(() => expect(f.signals.denied).toHaveBeenCalledOnce());
    expect(done).toHaveBeenCalledWith(expect.any(Error), null);
});

test('refuses other channels and missing CSRF without any HTTP request', async () => {
    const read = vi.fn<typeof fetch>();
    vi.stubGlobal('fetch', read);
    const f = fixture();
    f.authorize({ socketId: '123.456', channelName: 'private-team.8' }, vi.fn());
    document.cookie = 'XSRF-TOKEN=; Max-Age=0; path=/';
    f.authorize({ socketId: '123.456', channelName: 'private-team.7' }, vi.fn());
    await vi.waitFor(() => expect(f.signals.state).toHaveBeenCalledWith('error'));
    expect(read).not.toHaveBeenCalled();
});

test('aborts a pending grant on disposal and refuses its late successful response', async () => {
    let finish: (value: Response) => void = () => {
        throw new Error('Authorization did not start');
    };
    const read = vi.fn<typeof fetch>().mockImplementation(
        () =>
            new Promise((resolve) => {
                finish = resolve;
            }),
    );
    vi.stubGlobal('fetch', read);
    const f = fixture();
    const done = vi.fn();
    f.authorize({ socketId: '123.456', channelName: 'private-team.7' }, done);
    const signal = read.mock.calls[0]?.[1]?.signal;
    f.close();
    finish(new Response(JSON.stringify({ auth: 'old-grant' })));
    await Promise.resolve();
    await Promise.resolve();
    expect(signal?.aborted).toBe(true);
    expect(done).not.toHaveBeenCalled();
    expect(f.signals.denied).not.toHaveBeenCalled();
});

test('distinguishes connection state changes from private subscription success', () => {
    const f = fixture();
    for (const state of ['connecting', 'connected', 'unavailable', 'failed'])
        socket.states.get('state_change')?.({ current: state });
    expect(f.signals.state).toHaveBeenCalledWith('disconnected');
    expect(f.signals.state).toHaveBeenCalledWith('error');
    expect(f.signals.subscribed).not.toHaveBeenCalled();
});

test.each(['server-error', 'invalid-grant'])(
    'reports %s without passing an invalid grant to Pusher',
    async (failure) => {
        const reply =
            failure === 'server-error'
                ? new Response('{}', { status: 503 })
                : new Response(JSON.stringify({ auth: 123 }));
        vi.stubGlobal('fetch', vi.fn<typeof fetch>().mockResolvedValue(reply));
        const f = fixture();
        const done = vi.fn();
        f.authorize({ socketId: '123.456', channelName: 'private-team.7' }, done);
        await vi.waitFor(() => expect(f.signals.state).toHaveBeenCalledWith('error'));
        expect(done).toHaveBeenCalledWith(expect.any(Error), null);
        expect(f.signals.denied).not.toHaveBeenCalled();
    },
);

test('bounds pending grant acquisition to ten seconds and clears all timers on disposal', async () => {
    vi.useFakeTimers();
    const read = vi.fn<typeof fetch>().mockImplementation(
        (_url, options) =>
            new Promise((_resolve, reject) => {
                options?.signal?.addEventListener('abort', () =>
                    reject(new DOMException('Aborted', 'AbortError')),
                );
            }),
    );
    vi.stubGlobal('fetch', read);
    const f = fixture();
    const done = vi.fn();
    f.authorize({ socketId: '123.456', channelName: 'private-team.7' }, done);
    await vi.advanceTimersByTimeAsync(10_000);
    expect(done).toHaveBeenCalledWith(expect.any(Error), null);
    expect(f.signals.state).toHaveBeenCalledWith('error');
    f.close();
    expect(vi.getTimerCount()).toBe(0);
});

test('cancels an old socket grant on disconnect without poisoning the replacement subscription', async () => {
    let finishOld: (response: Response) => void = () => {
        throw new Error('Old request did not start');
    };
    const read = vi
        .fn<typeof fetch>()
        .mockImplementationOnce(
            () =>
                new Promise((resolve) => {
                    finishOld = resolve;
                }),
        )
        .mockResolvedValueOnce(new Response(JSON.stringify({ auth: 'new-socket-grant' })));
    vi.stubGlobal('fetch', read);
    const f = fixture();
    const oldDone = vi.fn();
    const newDone = vi.fn();
    f.authorize({ socketId: '111.222', channelName: 'private-team.7' }, oldDone);
    const oldSignal = read.mock.calls[0]?.[1]?.signal;
    socket.states.get('state_change')?.({ current: 'disconnected' });
    expect(oldSignal?.aborted).toBe(true);
    socket.states.get('state_change')?.({ current: 'connected' });
    f.authorize({ socketId: '333.444', channelName: 'private-team.7' }, newDone);
    await vi.waitFor(() =>
        expect(newDone).toHaveBeenCalledWith(null, { auth: 'new-socket-grant' }),
    );
    finishOld(new Response(JSON.stringify({ auth: 'old-socket-grant' })));
    await Promise.resolve();
    await Promise.resolve();
    expect(oldDone).not.toHaveBeenCalled();
    expect(f.signals.state).not.toHaveBeenCalledWith('error');
});

test.each(['resolve', 'reject'])(
    'ignores an old grant body that finishes with %s after a replacement socket is ready',
    async (completion) => {
        vi.useFakeTimers();
        let resolveBody: (value: unknown) => void = () => {
            throw new Error('Body did not start');
        };
        let rejectBody: (reason: Error) => void = () => {
            throw new Error('Body did not start');
        };
        const oldResponse = new Response('{}');
        const json = vi.spyOn(oldResponse, 'json').mockImplementation(
            () =>
                new Promise((resolve, reject) => {
                    resolveBody = resolve;
                    rejectBody = reject;
                }),
        );
        vi.stubGlobal(
            'fetch',
            vi
                .fn<typeof fetch>()
                .mockResolvedValueOnce(oldResponse)
                .mockResolvedValueOnce(new Response(JSON.stringify({ auth: 'replacement-grant' }))),
        );
        const f = fixture();
        const oldDone = vi.fn();
        const newDone = vi.fn();
        f.authorize({ socketId: '111.222', channelName: 'private-team.7' }, oldDone);
        await vi.waitFor(() => expect(json).toHaveBeenCalledOnce());
        socket.states.get('state_change')?.({ current: 'disconnected' });
        socket.states.get('state_change')?.({ current: 'connected' });
        f.authorize({ socketId: '333.444', channelName: 'private-team.7' }, newDone);
        await vi.waitFor(() =>
            expect(newDone).toHaveBeenCalledWith(null, { auth: 'replacement-grant' }),
        );
        socket.bindings.get('pusher:subscription_succeeded')?.({});
        if (completion === 'resolve') resolveBody({ auth: 'stale-grant' });
        else rejectBody(new TypeError('Stale body failed'));
        await vi.advanceTimersByTimeAsync(0);
        expect(oldDone).not.toHaveBeenCalled();
        expect(f.signals.state).not.toHaveBeenCalledWith('error');
        expect(f.signals.subscribed).toHaveBeenCalledOnce();
        expect(vi.getTimerCount()).toBe(0);
    },
);
