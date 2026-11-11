/** Audit native Echo → recovery → actual monitor detail, preserving drafts and isolating replaced identities. */
import { router } from '@inertiajs/react';
import { act, fireEvent, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { StrictMode } from 'react';
import { afterEach, beforeEach, expect, test, vi } from 'vitest';
import Show from '../../resources/js/pages/Monitors/Show';
import { chartsProps, chartMetrics } from './monitorChartsFixture';
import { MemoryPusher } from './MemoryPusher';
import contract from '../Fixtures/team-broadcast-payloads.json';
import { renderInertia } from './renderInertia';

vi.mock('pusher-js', async () => {
    const { MemoryPusher: Client } = await import('./MemoryPusher');
    return { default: Client };
});

/** Public browser transport coordinates; no socket is opened in this test. */
const configuration = { key: 'public-key', host: 'localhost', port: 8080, scheme: 'http' };
/** Exact current URL whose accepted selection must survive every background recovery. */
const url =
    '/teams/7/monitors/11?period=24h&region=all&returnTo=%2Fteams%2F7%2Fmonitors%3Fq%3Dapi%26page%3D2';
/** Bounded editable upcoming maintenance fixture, independent of the browser clock. */
const windowView = {
    id: 1,
    startsAt: '2026-11-12T00:00:00Z',
    endsAt: '2026-11-12T01:00:00Z',
    reason: 'Saved maintenance',
    phase: 'upcoming',
};
/** Full safe response roots, with policy options permitting an unsent choice. */
const initial = {
    ...chartsProps,
    realtime: configuration,
    can: { update: true, delete: true, pause: true, resume: true },
    monitorContext: {
        certificate: { state: 'not_applicable', expiresAt: null, observedAt: null },
        notifications: null,
    },
    escalation: {
        policyId: 1,
        effective: { id: 1, name: 'Saved first', source: 'override' },
        options: [
            { id: 1, name: 'Saved first', isDefault: false },
            { id: 2, name: 'Saved second', isDefault: false },
            { id: 3, name: 'Draft choice', isDefault: false },
        ],
    },
    maintenance: { ...chartsProps.maintenance, current: [windowView] },
};

beforeEach(() => {
    vi.useFakeTimers();
    document.head.querySelectorAll('title').forEach((element) => element.remove());
    MemoryPusher.instances = [];
    document.cookie = 'XSRF-TOKEN=initial-token; path=/';
    vi.spyOn(window, 'scrollTo').mockImplementation(() => undefined);
});
afterEach(() => {
    document.cookie = 'XSRF-TOKEN=; Max-Age=0; path=/';
});

/** Advance only the controlled coalescing/deadline clock and settle React/promises. */
async function advance(milliseconds = 0): Promise<void> {
    await act(async () => {
        await vi.advanceTimersByTimeAsync(milliseconds);
    });
}

/** Encode the existing Inertia response; shared identity is validated before mutable roots are accepted. */
function response(props: Record<string, unknown>): Response {
    return new Response(JSON.stringify({ component: 'Monitors/Show', props }), {
        headers: { 'X-Inertia': 'true' },
    });
}

/** StrictMode wrapper exercises actual hook setup/cleanup/setup rather than duplicating session logic. */
function StrictShow() {
    return (
        <StrictMode>
            <Show />
        </StrictMode>
    );
}

/** Mount production detail/native Echo and expose transport-only controls for future snapshots. */
function fixture(strict = false) {
    let next: Record<string, unknown> = structuredClone(initial);
    let reply: (() => Promise<Response>) | null = null;
    const reads: RequestInit[] = [];
    const grants: RequestInit[] = [];
    const fetcher = vi.fn<typeof fetch>().mockImplementation((path, options) => {
        if (path === '/broadcasting/auth') {
            grants.push(options ?? {});
            return Promise.resolve(
                new Response(JSON.stringify({ auth: 'public-key:synthetic-grant' })),
            );
        }
        reads.push(options ?? {});
        return reply ? reply() : Promise.resolve(response(next));
    });
    vi.stubGlobal('fetch', fetcher);
    const view = renderInertia('Signalbox', strict ? StrictShow : Show, initial, {
        component: 'Monitors/Show',
        url,
    });
    const client = MemoryPusher.instances.at(-1);
    if (!client) throw new Error('Expected the native Echo adapter to create a client.');
    return {
        view,
        client,
        fetcher,
        reads,
        grants,
        next: (overrides: Record<string, unknown>) => {
            next = { ...next, ...overrides };
        },
        reply: (transport: () => Promise<Response>) => {
            reply = transport;
        },
    };
}

/** Deliver an existing schema-valid incident invalidation; the frame itself never becomes UI state. */
function invalidate(client: MemoryPusher): void {
    act(() => client.receive('private-team.7', 'IncidentResolved', contract.IncidentResolved));
}

test('keeps an unsent escalation choice when the saved override changes in a live snapshot', async () => {
    const f = fixture();
    fireEvent.change(screen.getByLabelText('Escalation policy'), { target: { value: '3' } });
    fireEvent.change(screen.getByLabelText('Reason'), {
        target: { value: 'Unsent maintenance draft' },
    });
    f.next({
        escalation: {
            ...initial.escalation,
            policyId: 2,
            effective: { id: 2, name: 'Saved second', source: 'override' },
        },
    });
    act(() => f.client.establish());
    await advance();
    expect(screen.getByLabelText('Escalation policy')).toHaveValue('3');
    expect(screen.getByLabelText('Reason')).toHaveValue('Unsent maintenance draft');
    expect(screen.getByText(/Incidents follow the policy Saved second/)).toBeInTheDocument();
});

test('updates a pristine escalation selector without manufacturing an unsaved draft', async () => {
    const f = fixture();
    f.next({
        escalation: {
            ...initial.escalation,
            policyId: 2,
            effective: { id: 2, name: 'Saved second', source: 'override' },
        },
    });
    act(() => f.client.establish());
    await advance();
    expect(screen.getByLabelText('Escalation policy')).toHaveValue('2');
    // A same-page metric link must not trigger the departure prompt after a server-only synchronization.
    const get = vi.spyOn(router, 'get').mockImplementation(() => undefined);
    fireEvent.click(
        within(screen.getByRole('navigation', { name: 'Chart period' })).getByRole('link', {
            name: '7 days',
        }),
    );
    expect(get).toHaveBeenCalledOnce();
});

test.each(['permission', 'phase'])(
    'removes an already-open maintenance editor after a live %s change',
    async (change) => {
        const f = fixture();
        fireEvent.click(screen.getByRole('button', { name: 'Edit window' }));
        expect(screen.getByRole('button', { name: 'Save window' })).toBeInTheDocument();
        const editor = screen.getByRole('button', { name: 'Save window' }).closest('form');
        if (!editor) throw new Error('Expected the open maintenance editor.');
        fireEvent.change(within(editor).getByLabelText('Reason'), {
            target: { value: 'Unsaved editor draft' },
        });
        fireEvent.change(screen.getByLabelText('Escalation policy'), { target: { value: '3' } });
        if (change === 'permission')
            f.next({ can: { update: false, delete: false, pause: false, resume: false } });
        else
            f.next({
                maintenance: {
                    ...initial.maintenance,
                    current: [{ ...windowView, phase: 'ongoing' }],
                },
            });
        act(() => f.client.establish());
        await advance();
        expect(screen.queryByRole('button', { name: 'Save window' })).not.toBeInTheDocument();
        if (change === 'permission') {
            expect(screen.queryByLabelText('Escalation policy')).not.toBeInTheDocument();
            expect(
                screen.queryByRole('button', { name: 'Delete monitor' }),
            ).not.toBeInTheDocument();
        }
    },
);

test('coalesces frames, recovers after reconnect without events and keeps controls/context/drafts', async () => {
    const f = fixture();
    act(() => f.client.establish());
    await advance();
    fireEvent.change(screen.getByLabelText('Reason'), { target: { value: 'Retain this draft' } });
    f.next({ monitor: { ...initial.monitor, lastStatus: 'down' } });
    act(() => {
        for (let index = 0; index < 50; index++)
            f.client.receive('private-team.7', 'CheckCompleted', contract.CheckCompleted);
    });
    await advance(250);
    expect(f.reads).toHaveLength(2);
    expect(
        within(screen.getByRole('region', { name: 'Monitor status' })).getByText('Down'),
    ).toBeInTheDocument();
    expect(screen.getAllByRole('region', { name: 'Last 24 hours' })).toHaveLength(1);
    act(() => f.client.connection.change('disconnected'));
    expect(screen.getByText('Connection lost. Reconnecting…')).toBeInTheDocument();
    await advance(60_000);
    expect(f.reads).toHaveLength(2);
    document.cookie = 'XSRF-TOKEN=reconnected-token; path=/';
    act(() => f.client.establish('333.444'));
    await advance();
    expect(f.reads).toHaveLength(3);
    expect(f.grants[1]?.headers).toMatchObject({ 'X-XSRF-TOKEN': 'reconnected-token' });
    expect(
        f.fetcher.mock.calls
            .filter(([path]) => path !== '/broadcasting/auth')
            .every(([path]) => path === url),
    ).toBe(true);
    expect(screen.getByLabelText('Reason')).toHaveValue('Retain this draft');
    expect(screen.getByRole('link', { name: 'Back to monitors' })).toHaveAttribute(
        'href',
        initial.monitorListUrl,
    );
    expect(screen.getByText('Live updates connected.')).toBeInTheDocument();
    await advance(60_000);
    expect(f.reads).toHaveLength(3);
});

test.each(['team', 'actor'])(
    'removes previous drafts and fences pending JSON after a preserved %s replacement',
    async (change) => {
        const f = fixture();
        fireEvent.change(screen.getByLabelText('Reason'), {
            target: { value: 'Previous identity private draft' },
        });
        const slow = response(initial);
        let complete: (value: unknown) => void = () => {
            throw new Error('JSON did not start');
        };
        vi.spyOn(slow, 'json').mockImplementation(
            () =>
                new Promise((resolve) => {
                    complete = resolve;
                }),
        );
        f.reply(() => Promise.resolve(slow));
        act(() => f.client.establish());
        await advance();
        const signal = f.reads[0]?.signal;
        const next =
            change === 'team'
                ? {
                      ...initial,
                      team: { id: 8, name: 'Second synthetic team' },
                      monitor: { ...initial.monitor, id: 12, name: 'Second team monitor' },
                      monitorListUrl: '/teams/8/monitors',
                  }
                : {
                      ...initial,
                      auth: { user: { name: 'Second actor', email: 'second@example.test' } },
                  };
        await act(async () => {
            router.replace({
                url: change === 'team' ? '/teams/8/monitors/12' : url,
                props: { appName: 'Signalbox', errors: {}, ...next },
                preserveState: true,
            });
            await vi.advanceTimersByTimeAsync(0);
        });
        expect(f.client.disconnected).toBe(true);
        expect(signal?.aborted).toBe(true);
        expect(screen.getByLabelText('Reason')).toHaveValue('');
        complete({
            component: 'Monitors/Show',
            props: { ...initial, monitor: { ...initial.monitor, name: 'Stale private title' } },
        });
        await advance();
        expect(screen.queryByText('Stale private title')).not.toBeInTheDocument();
        expect(screen.getByLabelText('Reason')).toHaveValue('');
        expect(MemoryPusher.instances.filter((client) => !client.disconnected)).toHaveLength(1);
    },
);

test.each(['snapshot', 'grant'])(
    'removes private DOM and document title after authoritative %s denial',
    async (source) => {
        const f = fixture();
        act(() => f.client.establish());
        await advance(100);
        expect(document.title).toContain(initial.monitor.name);
        if (source === 'snapshot') {
            f.reply(() => Promise.resolve(new Response('', { status: 403 })));
            invalidate(f.client);
            await advance(250);
        } else {
            f.fetcher.mockImplementationOnce(() =>
                Promise.resolve(new Response('', { status: 403 })),
            );
            act(() => {
                f.client.connection.change('disconnected');
                f.client.establish('333.444');
            });
            await advance(100);
        }
        await advance(100);
        expect(screen.getByText('Team data hidden')).toBeInTheDocument();
        expect(screen.queryByRole('region', { name: 'TLS certificate' })).not.toBeInTheDocument();
        expect(document.title).not.toContain(initial.monitor.name);
        expect(f.client.disconnected).toBe(true);
        invalidate(f.client);
        fireEvent.online(window);
        await advance(60_000);
        expect(f.reads).toHaveLength(source === 'snapshot' ? 2 : 1);
    },
);

test('StrictMode leaves only one active client and no persistent traffic after unmount', async () => {
    const f = fixture(true);
    expect(MemoryPusher.instances.filter((client) => !client.disconnected)).toHaveLength(1);
    act(() => f.client.establish());
    await advance();
    expect(f.reads).toHaveLength(1);
    f.view.unmount();
    fireEvent.online(window);
    fireEvent(document, new Event('visibilitychange'));
    invalidate(f.client);
    await advance(60_000);
    expect(f.reads).toHaveLength(1);
    expect(MemoryPusher.instances.every((client) => client.disconnected)).toBe(true);
    expect(vi.getTimerCount()).toBe(0);
});

test('retains a deleted draft policy explicitly and prevents sending a different implicit selection', async () => {
    const f = fixture();
    fireEvent.change(screen.getByLabelText('Escalation policy'), { target: { value: '3' } });
    f.next({
        escalation: {
            ...initial.escalation,
            options: initial.escalation.options.filter((option) => option.id !== 3),
        },
    });
    act(() => f.client.establish());
    await advance();
    expect(screen.getByLabelText('Escalation policy')).toHaveValue('3');
    expect(screen.getByRole('option', { name: 'Draft policy unavailable' })).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Save' })).toBeDisabled();
    fireEvent.change(screen.getByLabelText('Escalation policy'), { target: { value: '' } });
    expect(screen.getByRole('button', { name: 'Save' })).toBeEnabled();
});

test('ignores foreign and malformed monitor frames and never assigns regional status directly', async () => {
    const f = fixture();
    act(() => f.client.establish());
    await advance();
    act(() => {
        f.client.receive('private-team.7', 'CheckCompleted', {
            ...contract.CheckCompleted,
            monitor_id: 999,
        });
        f.client.receive('private-team.7', 'CheckCompleted', {
            ...contract.CheckCompleted,
            schema_version: 99,
        });
    });
    await advance(250);
    expect(f.reads).toHaveLength(1);
    act(() =>
        f.client.receive('private-team.7', 'CheckCompleted', {
            ...contract.CheckCompleted,
            status: 'down',
        }),
    );
    await advance(250);
    expect(f.reads).toHaveLength(2);
    expect(
        within(screen.getByRole('region', { name: 'Monitor status' })).getByText('Up'),
    ).toBeInTheDocument();
});

test('logout removes private drafts and title instead of treating disabled realtime as an authenticated page', async () => {
    const f = fixture();
    fireEvent.change(screen.getByLabelText('Reason'), { target: { value: 'Logout draft' } });
    act(() => f.client.establish());
    await advance(100);
    await act(async () => {
        router.replace({
            props: {
                appName: 'Signalbox',
                errors: {},
                ...initial,
                auth: { user: null },
                realtime: null,
            },
            preserveState: true,
        });
        await vi.advanceTimersByTimeAsync(100);
    });
    expect(f.client.disconnected).toBe(true);
    expect(screen.queryByLabelText('Reason')).not.toBeInTheDocument();
    expect(screen.getByText('Team data hidden')).toBeInTheDocument();
    expect(document.title).not.toContain(initial.monitor.name);
    expect(MemoryPusher.instances.filter((client) => !client.disconnected)).toHaveLength(0);
});

test('authoritative identity replacement cancels a pending action and rejects its late callbacks', async () => {
    const f = fixture();
    let callbacks: NonNullable<Parameters<typeof router.post>[2]> | undefined;
    const post = vi.spyOn(router, 'post').mockImplementation((_path, _data, options) => {
        callbacks = options;
    });
    fireEvent.click(screen.getByRole('button', { name: 'Pause monitor' }));
    fireEvent.click(screen.getByRole('button', { name: 'Confirm pause' }));
    const cancel = vi.fn();
    act(() => callbacks?.onCancelToken?.({ cancel }));
    await act(async () => {
        router.replace({
            props: {
                appName: 'Signalbox',
                errors: {},
                ...initial,
                auth: { user: { name: 'Second actor', email: 'second@example.test' } },
            },
            preserveState: true,
        });
        await vi.advanceTimersByTimeAsync(0);
    });
    expect(cancel).toHaveBeenCalledOnce();
    expect(f.client.disconnected).toBe(true);
    act(() => {
        callbacks?.onError?.({ monitor: 'Old actor refusal' });
        callbacks?.onFinish?.({} as never);
    });
    expect(screen.queryByText('Old actor refusal')).not.toBeInTheDocument();
    expect(screen.queryByText('Waiting for the server to confirm…')).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Pause monitor' })).toBeEnabled();
    expect(post).toHaveBeenCalledOnce();
});

test('preserves same-identity drafts and the selected URL while rejecting a previous metric response', async () => {
    const f = fixture();
    fireEvent.change(screen.getByLabelText('Reason'), { target: { value: 'Same identity draft' } });
    const slow = response(initial);
    let complete: (value: unknown) => void = () => {
        throw new Error('JSON did not start');
    };
    vi.spyOn(slow, 'json').mockImplementation(
        () =>
            new Promise((resolve) => {
                complete = resolve;
            }),
    );
    f.reply(() => Promise.resolve(slow));
    act(() => f.client.establish());
    await advance();
    const nextUrl = url.replace('period=24h&region=all', 'period=7d&region=eu');
    const next = { ...initial, metrics: chartMetrics('7d', 'eu') };
    await act(async () => {
        router.replace({
            url: nextUrl,
            props: { appName: 'Signalbox', errors: {}, ...next },
            preserveState: true,
        });
        await vi.advanceTimersByTimeAsync(0);
    });
    expect(f.reads[0]?.signal?.aborted).toBe(true);
    complete({ component: 'Monitors/Show', props: initial });
    await advance();
    expect(screen.getByLabelText('Reason')).toHaveValue('Same identity draft');
    expect(
        within(screen.getByRole('navigation', { name: 'Chart period' })).getByRole('link', {
            name: '7 days',
        }),
    ).toHaveAttribute('aria-current', 'true');
    const nextClient = MemoryPusher.instances.at(-1);
    if (!nextClient) throw new Error('Expected the replacement page scope.');
    f.reply(() => Promise.resolve(response(next)));
    act(() => nextClient.establish());
    await advance();
    expect(f.fetcher.mock.calls.at(-1)?.[0]).toBe(nextUrl);
    expect(screen.getByLabelText('Reason')).toHaveValue('Same identity draft');
});

test('a keyboard retry reauthorizes failed monitor recovery without losing a draft or starting idle polling', async () => {
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTimeAsync });
    const f = fixture();
    fireEvent.change(screen.getByLabelText('Reason'), {
        target: { value: 'Retain after failed recovery' },
    });
    f.reply(() => Promise.reject(new TypeError('Synthetic unavailable transport')));
    act(() => f.client.establish());
    await advance();
    expect(screen.getByText(/Displayed data may be out of date/)).toBeInTheDocument();
    await advance(60_000);
    expect(f.reads).toHaveLength(1);
    const retry = screen.getByRole('button', { name: 'Retry live updates' });
    retry.focus();
    const pressed = user.keyboard('{Enter}');
    // Drain the DOM library's final zero-delay async-wrapper task as well as the keyboard events.
    await advance(10);
    await pressed;
    expect(f.client.disconnected).toBe(true);
    f.reply(() => Promise.resolve(response(initial)));
    const replacement = MemoryPusher.instances.at(-1);
    if (!replacement) throw new Error('Expected a replacement authorization lifetime.');
    act(() => replacement.establish());
    await advance();
    expect(f.reads).toHaveLength(2);
    expect(f.grants).toHaveLength(2);
    expect(screen.getByText('Live updates connected.')).toBeInTheDocument();
    expect(screen.getByLabelText('Reason')).toHaveValue('Retain after failed recovery');
});
