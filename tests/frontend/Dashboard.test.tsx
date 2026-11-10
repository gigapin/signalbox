/** Exercise dashboard charts, truthful missing metrics, URL selection and live recovery through real Inertia components. */
import { act, fireEvent, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, expect, test, vi } from 'vitest';
import TimeSeriesChart from '../../resources/js/components/dashboard/TimeSeriesChart';
import type { DashboardView } from '../../resources/js/components/dashboard/types';
import Show from '../../resources/js/pages/Dashboard/Show';
import { openTeamStream } from '../../resources/js/realtime/teamStream';
import type { TeamStreamCallbacks } from '../../resources/js/realtime/types';
import contract from '../Fixtures/team-broadcast-payloads.json';
import { renderInertia } from './renderInertia';
import { dashboard, identity, timestamps } from './dashboardFixture';

vi.mock('../../resources/js/realtime/teamStream', () => ({ openTeamStream: vi.fn() }));

beforeEach(() => {
    vi.mocked(openTeamStream).mockReset();
});

/** Mount the actual dashboard page with optional deliberate projection/query errors. */
function renderDashboard(view = dashboard, rejectedFilters: string[] = []) {
    return renderInertia('Signalbox', Show, { ...identity, dashboard: view, rejectedFilters });
}

test('renders operational summaries and explicit unavailable uptime coverage and SLA without a false percentage', () => {
    renderDashboard();
    expect(screen.getByRole('heading', { name: 'Dashboard' })).toBeInTheDocument();
    expect(screen.getByRole('group', { name: 'Response time series' })).toBeInTheDocument();
    expect(screen.getByText('No open incidents.')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Checkout API' })).toHaveAttribute(
        'href',
        '/teams/7/monitors/11',
    );
    expect(screen.getAllByText('Unavailable')).toHaveLength(3);
    expect(screen.queryByText('100.00%')).not.toBeInTheDocument();
    expect(
        screen.getByText('Time-weighted uptime is unavailable for this window.'),
    ).toBeInTheDocument();
    expect(openTeamStream).not.toHaveBeenCalled();
});

test('switches measured regions from the keyboard and preserves gaps and real zero latency', async () => {
    const user = userEvent.setup();
    renderDashboard();
    const eu = screen.getByRole('img', { name: 'Response time: EU' });
    expect(eu.querySelector('path')?.getAttribute('d')?.match(/M/g)).toHaveLength(2);
    expect(eu.querySelectorAll('circle')).toHaveLength(2);
    const us = screen.getByRole('button', { name: 'US' });
    us.focus();
    await user.keyboard('{Enter}');
    expect(us).toHaveAttribute('aria-pressed', 'true');
    expect(
        screen.getByRole('img', { name: 'Response time: US' }).querySelectorAll('circle'),
    ).toHaveLength(3);
    await user.click(screen.getByText('View response time data'));
    expect(screen.getByRole('table', { name: 'Response time, US, UTC' })).toBeInTheDocument();
    expect(
        within(screen.getByRole('table', { name: 'Response time, US, UTC' })).getAllByText('400'),
    ).toHaveLength(3);
});

test('provides bounded page and chart selection links without changing the route team', () => {
    const next: DashboardView = {
        ...dashboard,
        monitors: {
            ...dashboard.monitors,
            currentPage: 2,
            lastPage: 3,
            data: [
                ...dashboard.monitors.data,
                { ...dashboard.monitors.data[0]!, id: 12, name: 'Other monitor' },
            ],
        },
    };
    renderDashboard(next);
    expect(screen.getByRole('link', { name: 'View charts for Other monitor' })).toHaveAttribute(
        'href',
        '/teams/7/dashboard?page=2&monitor=12',
    );
    expect(screen.getByRole('link', { name: 'Page 3' })).toHaveAttribute(
        'href',
        '/teams/7/dashboard?page=3&monitor=11',
    );
    expect(screen.getByRole('link', { name: 'Selected' })).toHaveAttribute('aria-current', 'true');
});

test('offers usable empty page and no-monitor states and reports invalid filter names', () => {
    renderDashboard(
        {
            ...dashboard,
            counts: { ...dashboard.counts, total: 0, up: 0 },
            selectedMonitor: null,
            monitors: { ...dashboard.monitors, data: [], total: 0 },
        },
        ['monitor'],
    );
    expect(screen.getByText(/No monitors yet/)).toBeInTheDocument();
    expect(screen.getByRole('alert')).toHaveTextContent('invalid and ignored: monitor');
    expect(screen.getByRole('link', { name: 'Manage monitors' })).toHaveAttribute(
        'href',
        '/teams/7/monitors',
    );
});

test('renders service percentages, durations and an uptime series through the same nullable contract', () => {
    renderDashboard({
        ...dashboard,
        uptime: {
            observedPercent: 99.5,
            coveragePercent: 100,
            slaPercent: 99.5,
            durations: {
                upSeconds: 3661,
                downSeconds: 61,
                unknownSeconds: 0,
                excludedSeconds: 7200,
            },
            series: timestamps.map((at, index) => ({ at, value: index === 1 ? null : 99.5 })),
        },
    });
    expect(screen.getAllByText('99.50%')).toHaveLength(2);
    expect(screen.getByText('100.00%')).toBeInTheDocument();
    expect(screen.getByText('1h 1m 1s')).toBeInTheDocument();
    expect(screen.getByText('1m 1s')).toBeInTheDocument();
    expect(screen.getByText('0s')).toBeInTheDocument();
    expect(screen.getByText('2h 0m 0s')).toBeInTheDocument();
    expect(
        screen
            .getByRole('img', { name: 'Observed uptime: Observed uptime' })
            .querySelector('path')
            ?.getAttribute('d')
            ?.match(/M/g),
    ).toHaveLength(2);
});

test('recovers check-driven numeric payloads and reconnects without polling or direct regional status assignment', async () => {
    vi.useFakeTimers();
    let signals: TeamStreamCallbacks | undefined;
    const close = vi.fn();
    vi.mocked(openTeamStream).mockImplementation((_config, _team, callbacks) => {
        signals = callbacks;
        return close;
    });
    const configuration = { key: 'public-key', host: 'localhost', port: 8080, scheme: 'http' };
    let next = dashboard;
    const read = vi.fn<typeof fetch>().mockImplementation(() =>
        Promise.resolve(
            new Response(
                JSON.stringify({
                    component: 'Dashboard/Show',
                    props: { ...identity, realtime: configuration, dashboard: next },
                }),
                { headers: { 'X-Inertia': 'true' } },
            ),
        ),
    );
    vi.stubGlobal('fetch', read);
    renderInertia('Signalbox', Show, {
        ...identity,
        realtime: configuration,
        dashboard,
        rejectedFilters: [],
    });
    act(() => signals?.subscribed());
    await act(async () => {
        await vi.advanceTimersByTimeAsync(0);
    });
    act(() => signals?.event('CheckCompleted', contract.CheckCompleted));
    expect(screen.getByText('Up', { selector: 'span' })).toBeInTheDocument();
    next = {
        ...dashboard,
        can: { create: false },
        counts: { ...dashboard.counts, up: 0, down: 1 },
        monitors: {
            ...dashboard.monitors,
            data: dashboard.monitors.data.map((row) => ({ ...row, lastStatus: 'down' })),
        },
    };
    await act(async () => {
        await vi.advanceTimersByTimeAsync(250);
    });
    expect(screen.getByText('Down', { selector: 'span' })).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'New monitor' })).not.toBeInTheDocument();
    expect(read).toHaveBeenCalledTimes(2);
    expect(read.mock.calls[0]?.[1]?.headers).toMatchObject({
        'X-Inertia-Partial-Data': 'dashboard,team,auth,realtime',
    });
    act(() => signals?.state('disconnected'));
    act(() => signals?.subscribed());
    await act(async () => {
        await vi.advanceTimersByTimeAsync(0);
    });
    expect(read).toHaveBeenCalledTimes(3);
    await act(async () => {
        await vi.advanceTimersByTimeAsync(60_000);
    });
    expect(read).toHaveBeenCalledTimes(3);
});

test('renders no invented line when all samples are absent or percentages are invalid', () => {
    renderInertia('Signalbox', () => (
        <TimeSeriesChart
            title="Coverage"
            description="Measured window"
            unit="%"
            timestamps={timestamps}
            series={[{ id: 'coverage', label: 'Coverage', values: [null, Number.NaN, 101] }]}
            empty="No coverage data."
        />
    ));
    expect(screen.queryByRole('img')).not.toBeInTheDocument();
    expect(screen.getByText('No coverage data.')).toBeInTheDocument();
    fireEvent.click(screen.getByText('View coverage data'));
    expect(
        within(screen.getByRole('table', { name: 'Coverage, Coverage, UTC' })).getAllByText(
            'No data',
        ),
    ).toHaveLength(3);
});

test('links disjoint team counters to matching active or operational filters', () => {
    renderDashboard({
        ...dashboard,
        counts: {
            total: 15,
            up: 1,
            down: 2,
            unknown: 3,
            paused: 4,
            suspended: 5,
            openIncidents: 6,
        },
    });
    const counts = within(screen.getByLabelText('Monitor states'));
    for (const [label, query] of [
        ['Up', 'state=active&status=up'],
        ['Down', 'state=active&status=down'],
        ['Unknown', 'state=active&status=unknown'],
        ['Paused', 'state=paused'],
        ['Suspended', 'state=suspended'],
    ] as const) {
        expect(counts.getByRole('link', { name: label })).toHaveAttribute(
            'href',
            `/teams/7/monitors?${query}`,
        );
    }
    expect(counts.getByRole('link', { name: 'Open incidents' })).toHaveAttribute(
        'href',
        '/teams/7/incidents?state=open',
    );
    expect(counts.getByText('5')).toBeInTheDocument();
    expect(screen.getByText(/active monitors. Paused and suspended/)).toBeInTheDocument();
});

test('offers creation only with fresh permission and available plan capacity', () => {
    renderDashboard();
    expect(screen.getByRole('link', { name: 'New monitor' })).toHaveAttribute(
        'href',
        '/teams/7/monitors/create',
    );
    expect(screen.getByText(/Plan usage: 1 of 5 monitors/)).toHaveTextContent(
        'Minimum check interval: 300 seconds',
    );
});

test.each([
    { create: false, full: false, historical: false },
    { create: true, full: true, historical: false },
    { create: false, full: false, historical: true },
])('does not offer unauthorized full-quota or unconfirmed historical creation: %j', (hints) => {
    const view: DashboardView = {
        ...dashboard,
        can: { create: hints.create },
        usage: { used: 5, limit: 5, minimumIntervalSeconds: 300, full: hints.full },
    };
    if (hints.historical) {
        delete view.can;
        delete view.usage;
    }
    renderDashboard(view);
    expect(screen.queryByRole('link', { name: 'New monitor' })).not.toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Manage monitors' })).toBeInTheDocument();
    if (hints.full) expect(screen.getByText(/Monitor limit reached/)).toBeInTheDocument();
});

test.each([true, false])(
    'explains the first monitor and role-aware next step with create permission %s',
    (create) => {
        renderDashboard({
            ...dashboard,
            can: { create },
            usage: { used: 0, limit: 5, minimumIntervalSeconds: 300, full: false },
            counts: {
                total: 0,
                up: 0,
                down: 0,
                unknown: 0,
                paused: 0,
                suspended: 0,
                openIncidents: 0,
            },
            monitors: { ...dashboard.monitors, total: 0, data: [] },
            selectedMonitor: null,
        });
        expect(screen.getByRole('heading', { name: 'No monitors yet' })).toBeInTheDocument();
        expect(screen.getByText(/No checks or uptime data exist/)).toBeInTheDocument();
        expect(
            screen.queryByRole('group', { name: 'Response time series' }),
        ).not.toBeInTheDocument();
        if (create) expect(screen.getByText(/Use New monitor above/)).toBeInTheDocument();
        else expect(screen.getByText(/Ask a team owner, admin or member/)).toBeInTheDocument();
    },
);

test('separates projection generation and actual last-check timestamps without inventing a team percentage', () => {
    const checked = '2026-10-30T11:53:00Z';
    renderDashboard({
        ...dashboard,
        monitors: {
            ...dashboard.monitors,
            data: [{ ...dashboard.monitors.data[0]!, lastCheckedAt: checked }],
        },
    });
    expect(screen.getByText(/Snapshot generated/).querySelector('time')).toHaveAttribute(
        'dateTime',
        dashboard.generatedAt,
    );
    const table = screen.getByRole('table', { name: 'Monitors of Synthetic team' });
    expect(table.querySelector('time')).toHaveAttribute('dateTime', checked);
    expect(screen.getByText(/Metrics for this monitor only/)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Open monitor details' })).toHaveAttribute(
        'href',
        '/teams/7/monitors/11',
    );
});

test('keeps incident review before monitors and reports independent missing checks', () => {
    renderDashboard();
    const headings = screen
        .getAllByRole('heading', { level: 2 })
        .map((element) => element.textContent);
    expect(headings.indexOf('Open incidents')).toBeLessThan(headings.indexOf('Monitors'));
    expect(
        screen.getByText(/Monitor status and incident confirmation are separate/),
    ).toBeInTheDocument();
    expect(screen.getByText('No check recorded')).toBeInTheDocument();
});

test('preserves an explicit chart selection when recovering from an empty monitor page', () => {
    renderDashboard({
        ...dashboard,
        monitors: { ...dashboard.monitors, data: [], currentPage: 9, lastPage: 2 },
    });
    expect(screen.getByRole('link', { name: 'Back to the first page' })).toHaveAttribute(
        'href',
        '/teams/7/dashboard?monitor=11',
    );
    expect(screen.getByRole('link', { name: 'Open monitor details' })).toHaveAttribute(
        'href',
        '/teams/7/monitors/11',
    );
});
