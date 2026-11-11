/** Verify context on the real detail page: provenance, phases, safe channel flags and incident navigation. */
import { screen, within } from '@testing-library/react';
import { expect, test } from 'vitest';
import Show from '../../resources/js/pages/Monitors/Show';
import type { MonitorContextView } from '../../resources/js/components/monitors/metrics';
import { detailProps } from './monitorDetailFixture';
import { renderInertia } from './renderInertia';

/** Explicit absent configuration, distinct from old history without context. */
const emptyContext: MonitorContextView = {
    certificate: { state: 'not_applicable', expiresAt: null, observedAt: null },
    notifications: null,
};

test('distinguishes unavailable old history from non-applicable TLS and unconfigured notifications', () => {
    renderInertia('Signalbox', Show, { ...detailProps, monitorContext: emptyContext });
    expect(screen.getByText('Not applicable to this monitor type.')).toBeInTheDocument();
    expect(screen.getByText('No effective notification policy configured.')).toBeInTheDocument();
    expect(screen.getByText('No maintenance currently in progress.')).toBeInTheDocument();
    expect(screen.getByText('No upcoming maintenance scheduled.')).toBeInTheDocument();
    expect(screen.getByText('No incidents recorded for this monitor.')).toBeInTheDocument();
});

test('asks for reload when context is absent rather than claiming nothing is configured', () => {
    renderInertia('Signalbox', Show, detailProps);
    expect(screen.getByText(/Notification context unavailable/)).toBeInTheDocument();
    expect(screen.getByText(/Certificate context unavailable/)).toBeInTheDocument();
    expect(
        screen.queryByText('No effective notification policy configured.'),
    ).not.toBeInTheDocument();
});

test.each(['missing', 'stale', 'current'] as const)(
    'renders %s certificate provenance without computing expiry from the browser clock',
    (state) => {
        renderInertia('Signalbox', Show, {
            ...detailProps,
            monitorContext: {
                ...emptyContext,
                certificate: {
                    state,
                    expiresAt: state === 'missing' ? null : '2026-12-01T00:00:00Z',
                    observedAt: state === 'current' ? '2026-11-10T12:00:00Z' : null,
                },
            },
        });
        const panel = within(screen.getByRole('region', { name: 'TLS certificate' }));
        if (state === 'missing') {
            expect(
                panel.getByText('No certificate expiry observation available.'),
            ).toBeInTheDocument();
        } else {
            expect(
                panel.getByText(state === 'current' ? 'Recent observation' : 'Stale observation'),
            ).toBeInTheDocument();
            expect(
                panel.getByText(
                    state === 'current'
                        ? /does not guarantee current/
                        : /does not mean the certificate has expired/,
                ),
            ).toBeInTheDocument();
            expect(
                screen.getByRole('region', { name: 'TLS certificate' }).querySelector('time'),
            ).toHaveAttribute('dateTime', '2026-12-01T00:00:00Z');
        }
    },
);

test('uses server phases and preserves all ongoing windows while showing only the next scheduled window', () => {
    renderInertia('Signalbox', Show, {
        ...detailProps,
        maintenance: {
            ...detailProps.maintenance,
            current: [
                {
                    id: 1,
                    startsAt: '2026-01-01T00:00:00Z',
                    endsAt: '2026-01-02T00:00:00Z',
                    phase: 'ongoing',
                    reason: 'Current work',
                },
                {
                    id: 2,
                    startsAt: '2026-01-01T01:00:00Z',
                    endsAt: '2026-01-02T01:00:00Z',
                    phase: 'ongoing',
                    reason: 'Overlapping work',
                },
                {
                    id: 3,
                    startsAt: '2026-01-03T00:00:00Z',
                    endsAt: '2026-01-04T00:00:00Z',
                    phase: 'upcoming',
                    reason: 'Next work',
                },
                {
                    id: 4,
                    startsAt: '2026-01-05T00:00:00Z',
                    endsAt: '2026-01-06T00:00:00Z',
                    phase: 'upcoming',
                    reason: 'Later work',
                },
            ],
        },
    });
    const panel = within(screen.getByRole('region', { name: 'Maintenance overview' }));
    for (const text of ['Current work', 'Overlapping work', 'Next work'])
        expect(panel.getByText(text)).toBeInTheDocument();
    expect(panel.queryByText('Later work')).not.toBeInTheDocument();
});

test.each(['default', 'override'] as const)(
    'shows %s policy targets with eligibility flags without promising delivery',
    (source) => {
        renderInertia('Signalbox', Show, {
            ...detailProps,
            monitorContext: {
                ...emptyContext,
                notifications: {
                    id: 3,
                    name: 'Operations policy',
                    source,
                    steps: [
                        {
                            afterMinutes: 0,
                            channels: [
                                {
                                    id: 1,
                                    name: 'Operations channel',
                                    type: 'email',
                                    isActive: true,
                                    isVerified: true,
                                    isStub: false,
                                },
                            ],
                        },
                        {
                            afterMinutes: 5,
                            channels: [
                                {
                                    id: 2,
                                    name: 'Fallback channel',
                                    type: 'sms',
                                    isActive: false,
                                    isVerified: false,
                                    isStub: true,
                                },
                            ],
                        },
                        { afterMinutes: 10, channels: [] },
                    ],
                },
            },
        });
        const panel = within(screen.getByRole('region', { name: 'Effective notifications' }));
        expect(
            panel.getByText(
                `Operations policy (${source === 'default' ? 'Team default' : 'Monitor override'})`,
            ),
        ).toBeInTheDocument();
        expect(panel.getByText('At incident opening')).toBeInTheDocument();
        expect(panel.getByText('After 5 minutes from incident opening')).toBeInTheDocument();
        expect(panel.getByText('Active · Verified')).toBeInTheDocument();
        expect(
            panel.getByText('Inactive · Unverified · Stub: no messages sent'),
        ).toBeInTheDocument();
        expect(panel.getByText('No available channels in this step.')).toBeInTheDocument();
        expect(panel.getByText(/Configuration does not guarantee delivery/)).toBeInTheDocument();
        expect(panel.queryByRole('button')).not.toBeInTheDocument();
    },
);

test('links recent incidents within the route team and preserves zero duration', () => {
    renderInertia('Signalbox', Show, {
        ...detailProps,
        recentIncidents: [
            {
                id: 9,
                monitor: { id: 1, name: 'API' },
                status: 'resolved',
                severity: 'major',
                cause: 'timeout',
                startedAt: '2026-11-10T10:00:00Z',
                confirmedAt: '2026-11-10T10:00:00Z',
                resolvedAt: '2026-11-10T10:00:00Z',
                durationSeconds: 0,
                acknowledged: false,
                reopenedCount: 0,
            },
            {
                id: 10,
                monitor: { id: 1, name: 'API' },
                status: 'investigating',
                severity: 'critical',
                cause: 'timeout',
                startedAt: '2026-11-10T11:00:00Z',
                confirmedAt: '2026-11-10T11:00:00Z',
                resolvedAt: null,
                durationSeconds: null,
                acknowledged: true,
                reopenedCount: 0,
            },
        ],
    });
    const panel = within(screen.getByRole('region', { name: 'Recent incidents' }));
    expect(panel.getByRole('link', { name: 'Incident #9' })).toHaveAttribute(
        'href',
        '/teams/7/incidents/9',
    );
    expect(panel.getByText('Duration: 0 s')).toBeInTheDocument();
    expect(panel.getByText('Ongoing; final duration unavailable')).toBeInTheDocument();
    expect(panel.getByText('Responsibility taken')).toBeInTheDocument();
});
