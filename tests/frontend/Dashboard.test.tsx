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

test('renders future service percentages and an uptime series through the same nullable contract', () => {
    renderDashboard({
        ...dashboard,
        uptime: {
            observedPercent: 99.5,
            coveragePercent: 100,
            slaPercent: 99.5,
            series: timestamps.map((at, index) => ({ at, value: index === 1 ? null : 99.5 })),
        },
    });
    expect(screen.getAllByText('99.50%')).toHaveLength(2);
    expect(screen.getByText('100.00%')).toBeInTheDocument();
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
