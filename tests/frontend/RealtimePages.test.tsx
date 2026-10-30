/** Exercise live page rendering, retained drafts and team/account cleanup through the actual Inertia provider. */
import { router, usePage } from '@inertiajs/react';
import { act, fireEvent, screen } from '@testing-library/react';
import { useState } from 'react';
import { beforeEach, expect, test, vi } from 'vitest';
import LiveUpdates from '../../resources/js/components/LiveUpdates';
import type { AuthProps } from '../../resources/js/components/auth/AuthLayout';
import { useLivePage } from '../../resources/js/hooks/useLivePage';
import Index from '../../resources/js/pages/Monitors/Index';
import { openTeamStream } from '../../resources/js/realtime/teamStream';
import type { TeamStreamCallbacks } from '../../resources/js/realtime/types';
import { renderInertia } from './renderInertia';

vi.mock('../../resources/js/realtime/teamStream', () => ({ openTeamStream: vi.fn() }));

/** Public connection fixture; tests never open a network socket. */
const configuration = { key: 'public-key', host: 'localhost', port: 8080, scheme: 'http' };
/** Actor, route team and list fixture shared by the actual page and a lifecycle probe. */
const base = {
    auth: { user: { name: 'Actor', email: 'actor@example.test' } },
    realtime: configuration,
    team: { id: 7, name: 'Synthetic team' },
    monitors: {
        data: [
            {
                id: 11,
                name: 'Checkout API',
                type: 'http',
                lastStatus: 'unknown',
                state: 'active',
                intervalSeconds: 300,
                regions: ['eu', 'us'],
                lastCheckedAt: null,
            },
        ],
        total: 1,
        from: 1,
        to: 1,
        currentPage: 1,
        lastPage: 1,
        perPage: 20,
    },
    filters: { q: '', type: null, status: null, state: null },
    filtered: false,
    rejectedFilters: [],
    options: {
        types: ['http'],
        statuses: ['unknown', 'up', 'down'],
        states: ['active'],
        regions: ['eu', 'us'],
    },
    usage: { used: 1, limit: 5, minimumIntervalSeconds: 300, full: false },
    can: { create: true },
};
/** Captured lifetimes let tests fire old callbacks after a page/account switch. */
let streams: { signals: TeamStreamCallbacks; close: ReturnType<typeof vi.fn> }[] = [];

beforeEach(() => {
    vi.spyOn(window, 'scrollTo').mockImplementation(() => undefined);
    vi.useFakeTimers();
    streams = [];
    vi.mocked(openTeamStream).mockImplementation((_config, _team, signals) => {
        const close = vi.fn();
        streams.push({ signals, close });
        return close;
    });
});

/** Build a native Inertia partial response while retaining only synthetic page data. */
function response(component: string, props: Record<string, unknown>): Response {
    return new Response(JSON.stringify({ component, props }), { headers: { 'X-Inertia': 'true' } });
}

/** Flush a bounded timer step and all React updates produced by socket/fetch callbacks. */
async function advance(milliseconds = 0): Promise<void> {
    await act(async () => {
        await vi.advanceTimersByTimeAsync(milliseconds);
    });
}

/** Keep the hook's identity boundary visible independently of any table markup. */
function Probe() {
    const page = usePage<AuthProps & { team: { id: number }; monitors: { total: number } }>();
    const live = useLivePage<typeof page.props>(page.props.team.id, 'Monitors/Index');
    const [draft, setDraft] = useState('unsaved');
    return (
        <>
            <LiveUpdates phase={live.phase} retry={live.retry} />
            <p>
                Team {live.props.team.id}: {live.props.monitors.total} monitors
            </p>
            <input
                aria-label="Draft"
                value={draft}
                onChange={(event) => setDraft(event.target.value)}
            />
        </>
    );
}

test('updates an actual monitor table from authoritative data and preserves an unsent search draft', async () => {
    const read = vi.fn<typeof fetch>().mockImplementation(() =>
        Promise.resolve(
            response('Monitors/Index', {
                ...base,
                monitors: {
                    ...base.monitors,
                    data: base.monitors.data.map((row) => ({ ...row, lastStatus: 'up' })),
                },
            }),
        ),
    );
    vi.stubGlobal('fetch', read);
    renderInertia('Signalbox', Index, base);
    expect(screen.getByRole('status')).toHaveTextContent('Connecting');
    fireEvent.change(screen.getByLabelText('Search'), { target: { value: 'unsent filter' } });
    act(() => streams[0]?.signals.subscribed());
    await advance();
    expect(screen.getByRole('status')).toHaveTextContent('Live updates connected');
    expect(screen.getByText('Up', { selector: 'span' })).toBeInTheDocument();
    expect(screen.getByLabelText('Search')).toHaveValue('unsent filter');
    await advance(60_000);
    expect(read).toHaveBeenCalledTimes(1);
});

test.each(['team', 'account'])(
    'disposes the old %s connection and rejects a late snapshot during a same-component visit',
    async (change) => {
        let complete: (value: Response) => void = () => {
            throw new Error('Snapshot did not start');
        };
        const read = vi.fn<typeof fetch>().mockImplementationOnce(
            () =>
                new Promise((resolve) => {
                    complete = resolve;
                }),
        );
        vi.stubGlobal('fetch', read);
        renderInertia('Signalbox', Probe, base);
        act(() => streams[0]?.signals.subscribed());
        await advance();
        const signal = read.mock.calls[0]?.[1]?.signal;
        const next =
            change === 'team'
                ? {
                      ...base,
                      team: { id: 8, name: 'Second team' },
                      monitors: { ...base.monitors, total: 2 },
                  }
                : {
                      ...base,
                      auth: { user: { name: 'Other actor', email: 'other@example.test' } },
                      monitors: { ...base.monitors, total: 2 },
                  };
        await act(async () => {
            router.replace({
                url: change === 'team' ? '/teams/8/monitors' : '/teams/7/monitors',
                props: { appName: 'Signalbox', errors: {}, ...next },
                preserveState: true,
            });
            await vi.advanceTimersByTimeAsync(0);
        });
        expect(streams[0]?.close).toHaveBeenCalledOnce();
        expect(signal?.aborted).toBe(true);
        expect(streams).toHaveLength(2);
        complete(
            response('Monitors/Index', { ...base, monitors: { ...base.monitors, total: 999 } }),
        );
        act(() => streams[0]?.signals.subscribed());
        await advance();
        expect(screen.queryByText(/999 monitors/)).not.toBeInTheDocument();
        expect(screen.getByText(/2 monitors/)).toBeInTheDocument();
        expect(read).toHaveBeenCalledTimes(1);
    },
);

test('offers keyboard-operable retry after a failed snapshot and keeps a local form draft', async () => {
    const read = vi
        .fn<typeof fetch>()
        .mockRejectedValueOnce(new TypeError('offline'))
        .mockImplementation(() => Promise.resolve(response('Monitors/Index', base)));
    vi.stubGlobal('fetch', read);
    renderInertia('Signalbox', Probe, base);
    fireEvent.change(screen.getByLabelText('Draft'), { target: { value: 'keep me' } });
    act(() => streams[0]?.signals.subscribed());
    await advance();
    expect(screen.getByRole('status')).toHaveTextContent('may be out of date');
    const retry = screen.getByRole('button', { name: 'Retry live updates' });
    retry.focus();
    expect(retry).toHaveFocus();
    fireEvent.click(retry);
    act(() => streams[1]?.signals.subscribed());
    await advance();
    expect(screen.getByRole('status')).toHaveTextContent('Live updates connected');
    expect(screen.getByLabelText('Draft')).toHaveValue('keep me');
});

test('does not keep a subscription after logout removes the runtime configuration', async () => {
    renderInertia('Signalbox', Probe, base);
    await act(async () => {
        router.replace({
            props: {
                appName: 'Signalbox',
                errors: {},
                ...base,
                auth: { user: null },
                realtime: null,
            },
            preserveState: true,
        });
        await vi.advanceTimersByTimeAsync(0);
    });
    expect(streams[0]?.close).toHaveBeenCalledOnce();
    expect(streams).toHaveLength(1);
    expect(screen.getByRole('status')).toHaveTextContent('disabled');
});
