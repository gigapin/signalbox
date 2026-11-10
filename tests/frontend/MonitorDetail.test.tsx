/** Verify truthful metric cards/bars, status separation, action permissions and live snapshot lifecycle on the actual detail page. */
import { act, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, expect, test, vi } from 'vitest';
import Show from '../../resources/js/pages/Monitors/Show';
import AvailabilityHistory from '../../resources/js/components/monitors/AvailabilityHistory';
import {
    metricDuration,
    metricPercentage,
} from '../../resources/js/components/monitors/metricFormatting';
import { monitorDestination } from '../../resources/js/components/monitors/MonitorSnapshot';
import { openTeamStream } from '../../resources/js/realtime/teamStream';
import type { TeamStreamCallbacks } from '../../resources/js/realtime/types';
import { detailMetrics, detailProps, detailMonitor } from './monitorDetailFixture';
import { renderInertia } from './renderInertia';

vi.mock('../../resources/js/realtime/teamStream', () => ({ openTeamStream: vi.fn() }));

beforeEach(() => {
    vi.mocked(openTeamStream).mockReset();
});

test('shows the safe destination once and keeps header actions authorized and return context intact', () => {
    renderInertia('Signalbox', Show, detailProps);
    expect(screen.getByRole('heading', { level: 1, name: 'Checkout API' })).toBeInTheDocument();
    expect(screen.getAllByText('https://example.test/health?key=[REDACTED]')).toHaveLength(1);
    expect(
        within(screen.getByRole('group', { name: 'Monitor actions' })).queryByRole('button'),
    ).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Edit monitor' })).not.toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Back to monitors' })).toHaveAttribute(
        'href',
        '/teams/7/monitors?q=api&page=2',
    );
});

test('renders authoritative percentages coverage and downtime without claiming continuous uptime', () => {
    renderInertia('Signalbox', Show, detailProps);
    const day = within(screen.getByRole('region', { name: 'Last 24 hours' }));
    expect(day.getByText('98.80%')).toBeInTheDocument();
    expect(day.getByText('94.38%')).toBeInTheDocument();
    expect(day.getByText('Unavailable')).toBeInTheDocument();
    expect(day.getByText('15 min 0 s')).toBeInTheDocument();
    expect(day.getByText(/eligible time remains unknown/)).toBeInTheDocument();
    expect(screen.getByRole('region', { name: 'Last 7 days' })).toBeInTheDocument();
    expect(screen.getByRole('region', { name: 'Last 30 days' })).toBeInTheDocument();
    expect(screen.queryByText(/Up for|Down for/)).not.toBeInTheDocument();
});

test.each(['paused', 'suspended'])(
    'keeps last observation and current %s activity separate during maintenance',
    (state) => {
        renderInertia('Signalbox', Show, {
            ...detailProps,
            monitor: { ...detailMonitor, state },
            maintenance: {
                ...detailProps.maintenance,
                current: [
                    {
                        id: 1,
                        startsAt: '2026-11-10T11:00:00Z',
                        endsAt: '2026-11-10T13:00:00Z',
                        reason: 'Synthetic work',
                        phase: 'ongoing',
                    },
                ],
            },
        });
        const status = within(screen.getByRole('region', { name: 'Monitor status' }));
        expect(status.getByText('Up')).toBeInTheDocument();
        expect(
            status.getByText(state === 'paused' ? 'Paused' : 'Suspended by plan'),
        ).toBeInTheDocument();
        expect(status.getByText('Maintenance in progress')).toBeInTheDocument();
        expect(screen.getByText('Not running while paused or suspended')).toBeInTheDocument();
    },
);

test('does not treat an upcoming maintenance or missing observation as current observed success', () => {
    renderInertia('Signalbox', Show, {
        ...detailProps,
        monitor: { ...detailMonitor, lastCheckedAt: null },
        maintenance: {
            ...detailProps.maintenance,
            current: [
                {
                    id: 1,
                    startsAt: '2026-11-11T11:00:00Z',
                    endsAt: '2026-11-11T13:00:00Z',
                    reason: 'Future work',
                    phase: 'upcoming',
                },
            ],
        },
    });
    const status = within(screen.getByRole('region', { name: 'Monitor status' }));
    expect(status.getByText('Unknown')).toBeInTheDocument();
    expect(status.getByText('No ongoing maintenance')).toBeInTheDocument();
    expect(status.queryByText('Up')).not.toBeInTheDocument();
    expect(screen.getByText('No check recorded')).toBeInTheDocument();
});

test('retains all duration categories and not-monitored life in the equivalent hourly data', async () => {
    renderInertia('Signalbox', Show, detailProps);
    const bars = within(screen.getByRole('list', { name: 'Hourly availability' })).getAllByRole(
        'img',
    );
    expect(bars).toHaveLength(24);
    expect(bars[0]).toHaveAccessibleName(/Not monitored 30 min 0 s/);
    expect(bars[1]).toHaveAccessibleName(/Unknown 15 min 0 s, Excluded 15 min 0 s/);
    await userEvent.click(screen.getByText('View hourly availability data'));
    const table = within(screen.getByRole('table', { name: 'Hourly availability durations, UTC' }));
    expect(table.getAllByRole('row')).toHaveLength(25);
    expect(table.getByRole('columnheader', { name: 'Not monitored' })).toBeInTheDocument();
});

test('never paints an hour fully up merely because its observed percentage is one hundred', () => {
    const bucket = {
        ...detailMetrics.recent[0]!,
        observedPercent: 100,
        coveragePercent: 50,
        durations: { upSeconds: 1800, downSeconds: 0, unknownSeconds: 1800, excludedSeconds: 0 },
    };
    renderInertia('Signalbox', () => (
        <AvailabilityHistory metrics={{ ...detailMetrics, recent: [bucket] }} />
    ));
    const bar = screen.getByRole('img');
    expect(bar.querySelector<HTMLElement>('[data-kind="up"]')?.style.height).toBe('50%');
    expect(bar.querySelector<HTMLElement>('[data-kind="unknown"]')?.style.height).toBe('50%');
});

test('renders unavailable periods and unavailable buckets without invented ratios or durations', () => {
    const unavailable = { ...detailMetrics.periods['30d'], available: false, durations: null };
    renderInertia('Signalbox', Show, {
        ...detailProps,
        metrics: {
            ...detailMetrics,
            periods: { ...detailMetrics.periods, '30d': unavailable },
            recent: [unavailable],
        },
    });
    const month = within(screen.getByRole('region', { name: 'Last 30 days' }));
    expect(month.queryByText('98.80%')).not.toBeInTheDocument();
    expect(month.getByText(/Retained data cannot reconstruct/)).toBeInTheDocument();
    expect(screen.getByRole('img')).toHaveAccessibleName(/UTC: Unavailable/);
});

test('keeps fully excluded and zero-life periods distinct from unknown time', () => {
    const excluded = {
        ...detailMetrics.periods['24h'],
        observedPercent: null,
        coveragePercent: null,
        slaPercent: null,
        durations: { upSeconds: 0, downSeconds: 0, unknownSeconds: 0, excludedSeconds: 3600 },
    };
    const zero = { ...excluded, durations: { ...excluded.durations, excludedSeconds: 0 } };
    renderInertia('Signalbox', Show, {
        ...detailProps,
        metrics: {
            ...detailMetrics,
            periods: {
                '24h': excluded,
                '7d': zero,
                '30d': { ...zero, durations: { ...zero.durations, unknownSeconds: 3600 } },
            },
        },
    });
    expect(screen.getByText(/All monitored time was excluded/)).toBeInTheDocument();
    expect(screen.getByText('No monitored time in this period.')).toBeInTheDocument();
    expect(screen.getByText('No observed availability in this period.')).toBeInTheDocument();
});

test('supports old history entries without metric props with an honest unavailable state', () => {
    const old: Partial<typeof detailProps> = { ...detailProps };
    delete old.metrics;
    renderInertia('Signalbox', Show, old);
    expect(
        screen.getByText(/Availability history is unavailable in this snapshot/),
    ).toBeInTheDocument();
    expect(screen.getByText('Hourly history is unavailable in this snapshot.')).toBeInTheDocument();
});

test.each([
    [0, '0 s'],
    [0.25, '0.25 s'],
    [0.000001, '0.000001 s'],
    [3661, '1 h 1 min 1 s'],
    [86400, '1 d 0 h 0 min 0 s'],
    [null, 'Unavailable'],
    [Number.NaN, 'Unavailable'],
    [-1, 'Unavailable'],
] as const)('formats aggregate duration %s as %s', (seconds, expected) => {
    expect(metricDuration(seconds)).toBe(expected);
});

test.each([null, Number.NaN, 101, -1])(
    'does not coerce unavailable or invalid percentage %s to success',
    (value) => {
        expect(metricPercentage(value)).toBe('Unavailable');
    },
);

test('distinguishes measured zero and complete coverage and safe non-HTTP destinations', () => {
    expect(metricPercentage(0)).toBe('0.00%');
    expect(metricPercentage(100)).toBe('100.00%');
    expect(monitorDestination({ type: 'tls', tls_host: 'example.test', tls_port: 8443 })).toBe(
        'example.test:8443',
    );
    expect(monitorDestination({ type: 'dns', dns_name: 'example.test' })).toBe('example.test');
    expect(monitorDestination({ type: 'heartbeat' })).toBe('Receives heartbeat signals');
});

test('refreshes cards and header identity through one scoped live read and disposes metrics after denial', async () => {
    vi.useFakeTimers();
    let signals: TeamStreamCallbacks | undefined;
    const close = vi.fn();
    vi.mocked(openTeamStream).mockImplementation((_config, _team, callbacks) => {
        signals = callbacks;
        return close;
    });
    let denied = false;
    const read = vi.fn<typeof fetch>().mockImplementation(() =>
        Promise.resolve(
            denied
                ? new Response('', { status: 403 })
                : new Response(
                      JSON.stringify({
                          component: 'Monitors/Show',
                          props: {
                              ...detailProps,
                              realtime: {
                                  key: 'public-key',
                                  host: 'localhost',
                                  port: 8080,
                                  scheme: 'http',
                              },
                              monitor: {
                                  ...detailMonitor,
                                  name: 'Checkout renamed',
                                  lastStatus: 'down',
                              },
                              metrics: {
                                  ...detailMetrics,
                                  periods: {
                                      ...detailMetrics.periods,
                                      '24h': {
                                          ...detailMetrics.periods['24h'],
                                          observedPercent: 50,
                                      },
                                  },
                              },
                          },
                      }),
                      { headers: { 'X-Inertia': 'true' } },
                  ),
        ),
    );
    vi.stubGlobal('fetch', read);
    renderInertia('Signalbox', Show, {
        ...detailProps,
        realtime: { key: 'public-key', host: 'localhost', port: 8080, scheme: 'http' },
    });
    act(() => signals?.subscribed());
    await act(async () => {
        await vi.advanceTimersByTimeAsync(0);
    });
    expect(screen.getByRole('heading', { level: 1, name: 'Checkout renamed' })).toBeInTheDocument();
    expect(
        within(screen.getByRole('region', { name: 'Last 24 hours' })).getByText('50.00%'),
    ).toBeInTheDocument();
    expect(read.mock.calls[0]?.[1]?.headers).toMatchObject({
        'X-Inertia-Partial-Data':
            'monitor,recentIncidents,maintenance,escalation,can,metrics,team,auth,realtime',
    });
    denied = true;
    act(() => signals?.state('disconnected'));
    act(() => signals?.subscribed());
    await act(async () => {
        await vi.advanceTimersByTimeAsync(0);
    });
    expect(
        screen.queryByRole('region', { name: 'Availability by period' }),
    ).not.toBeInTheDocument();
    expect(screen.getByText(/Live access is no longer available/)).toBeInTheDocument();
    expect(close).toHaveBeenCalledOnce();
});

test('does not offer a runnable resume while a paused monitor exceeds the current plan quota', () => {
    renderInertia('Signalbox', Show, {
        ...detailProps,
        monitor: { ...detailMonitor, state: 'paused', withinQuota: false },
        can: { update: false, delete: false, pause: false, resume: true },
    });
    expect(screen.getByRole('button', { name: 'Resume monitor' })).toBeDisabled();
    expect(
        screen.getByText(/outside the current plan quota and cannot be resumed/),
    ).toBeInTheDocument();
});

test('does not round partial availability up to full success or lose exact service precision', () => {
    expect(metricPercentage(99.999)).toBe('99.999%');
    expect(metricPercentage(4.35)).toBe('4.35%');
});
