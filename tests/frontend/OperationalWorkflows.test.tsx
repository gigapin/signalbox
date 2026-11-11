/** Exercise real operational pages and notification forms with controlled owned transport outcomes. */
import { router } from '@inertiajs/react';
import { act, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { expect, test, vi } from 'vitest';
import Maintenance from '../../resources/js/pages/Maintenance/Index';
import Deliveries from '../../resources/js/pages/Notifications/Deliveries';
import Notifications from '../../resources/js/pages/Notifications/Index';
import { renderInertia } from './renderInertia';

/** Installed Inertia form visit callbacks. */
type Visit = NonNullable<Parameters<typeof router.visit>[1]>;

/** Common canonical list response, independent of transport. */
const list = {
    team: { id: 7, name: 'Synthetic team' },
    filters: { q: 'API', state: null },
    rejectedFilters: [],
    generatedAt: '2026-11-11T12:00:00Z',
};

/** Single real-shaped window at the fixed snapshot, with a next page. */
const maintenanceProps = {
    ...list,
    states: ['upcoming', 'ongoing', 'finished'],
    listing: {
        data: [
            {
                id: 1,
                monitor: { id: 2, name: 'API' },
                phase: 'ongoing',
                reason: 'Database upgrade',
                startsAt: '2026-11-11T11:00:00Z',
                endsAt: '2026-11-11T13:00:00Z',
            },
        ],
        total: 21,
        currentPage: 1,
        lastPage: 2,
    },
};

/** Notification configuration with a secret that must start blank and a default policy. */
const configuration = {
    team: list.team,
    channels: [
        {
            id: 1,
            name: 'Ops Slack',
            type: 'slack',
            hint: 'hooks.slack.com/…1234',
            isActive: false,
            isStub: false,
            verifiedAt: null,
            verificationPending: false,
            verificationExpiresAt: null,
            editable: {},
            usedBy: [],
            quietHours: null,
        },
    ],
    policies: [
        {
            id: 3,
            name: 'Primary',
            isDefault: true,
            monitorCount: 2,
            steps: [{ afterMinutes: 0, channels: [{ id: 1, name: 'Ops Slack', isActive: false }] }],
        },
    ],
    unnotifiedMonitors: 0,
    channelTypes: [
        { value: 'slack', label: 'Slack', isStub: false, secretFields: ['webhook_url'] },
    ],
    limits: {
        channelsPerTeam: 25,
        policiesPerTeam: 10,
        stepsPerPolicy: 5,
        channelsPerStep: 10,
        maxStepDelayMinutes: 1440,
        nameMaxLength: 100,
        codeLength: 6,
    },
    can: { manage: true },
};

test('maintenance enters existing monitor management and preserves filters in bounded page links', () => {
    renderInertia('Signalbox', Maintenance, maintenanceProps);
    expect(
        within(screen.getByRole('navigation', { name: 'Team navigation' })).getByRole('link', {
            name: 'Maintenance',
        }),
    ).toHaveAttribute('aria-current', 'page');
    expect(screen.getByRole('link', { name: 'API' })).toHaveAttribute(
        'href',
        '/teams/7/monitors/2#maintenance-heading',
    );
    expect(screen.getByRole('link', { name: 'Next' })).toHaveAttribute(
        'href',
        '/teams/7/maintenance?q=API&page=2',
    );
    expect(screen.queryByRole('button', { name: 'Delete window' })).not.toBeInTheDocument();
});

test('a failed filter read retains rows and selection until explicit retry', async () => {
    const user = userEvent.setup();
    let callbacks: NonNullable<Parameters<typeof router.get>[2]> | undefined;
    const read = vi.spyOn(router, 'get').mockImplementation((_url, _data, options) => {
        callbacks = options;
        options?.onStart?.({} as never);
    });
    renderInertia('Signalbox', Maintenance, maintenanceProps);
    await user.selectOptions(screen.getByLabelText('State'), 'finished');
    await user.click(screen.getByRole('button', { name: 'Apply filters' }));
    expect(read.mock.calls[0]?.[0]).toBe('/teams/7/maintenance?q=API&state=finished');
    expect(screen.getByLabelText('State')).toBeDisabled();
    act(() => {
        callbacks?.onError?.({ filter: 'Refused' });
        callbacks?.onFinish?.({} as never);
    });
    expect(screen.getByText('Results could not be loaded')).toBeInTheDocument();
    expect(screen.getByLabelText('State')).toHaveValue('finished');
    expect(screen.getByText('Database upgrade')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Apply filters' }));
    expect(read).toHaveBeenCalledTimes(2);
});

test('refresh reads the accepted page and does not submit unaccepted search text', async () => {
    const user = userEvent.setup();
    const read = vi.spyOn(router, 'get').mockImplementation(() => undefined);
    renderInertia('Signalbox', Maintenance, {
        ...maintenanceProps,
        listing: { ...maintenanceProps.listing, currentPage: 2 },
    });
    await user.type(screen.getByLabelText('Monitor name'), ' draft');
    await user.click(screen.getByRole('button', { name: 'Refresh results' }));
    expect(read.mock.calls[0]?.[0]).toBe('/teams/7/maintenance?q=API&page=2');
});

test('out-of-range and invalid lists offer a safe first page and name rejected controls', () => {
    renderInertia('Signalbox', Maintenance, {
        ...maintenanceProps,
        rejectedFilters: ['state'],
        listing: { data: [], total: 0, currentPage: 999999999, lastPage: 1 },
    });
    expect(screen.getByText('Some filters were ignored')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Back to the first page' })).toHaveAttribute(
        'href',
        '/teams/7/maintenance?q=API',
    );
    expect(screen.getAllByRole('link', { name: /Previous|Back to the first page/ })).toHaveLength(
        2,
    );
});

test('delivery rows link actual incidents and distinguish absent send time from acceptance', () => {
    renderInertia('Signalbox', Deliveries, {
        ...list,
        states: ['pending', 'sent', 'failed', 'suppressed'],
        listing: {
            data: [
                {
                    id: 1,
                    incidentId: 42,
                    monitor: { id: 2, name: 'API' },
                    channelName: null,
                    channelType: 'email',
                    kind: 'opened',
                    stepIndex: 0,
                    status: 'failed',
                    attempts: 2,
                    response: 'Provider unavailable.',
                    sentAt: null,
                    createdAt: '2026-11-11T12:00:00Z',
                },
            ],
            total: 1,
            currentPage: 1,
            lastPage: 1,
        },
    });
    expect(screen.getByRole('link', { name: 'Incident 42 · API' })).toHaveAttribute(
        'href',
        '/teams/7/incidents/42',
    );
    expect(screen.getByText('Deleted email channel')).toBeInTheDocument();
    expect(screen.getByText('Not recorded')).toBeInTheDocument();
    expect(screen.getByText(/provider acceptance, not recipient reading/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Retry delivery/ })).not.toBeInTheDocument();
});

test('configuration links to deliveries and retains hidden editor drafts while filtering names', async () => {
    const user = userEvent.setup();
    renderInertia('Signalbox', Notifications, configuration);
    expect(screen.getByRole('link', { name: 'Notification deliveries' })).toHaveAttribute(
        'href',
        '/teams/7/notifications/deliveries',
    );
    await user.click(screen.getByRole('button', { name: 'Edit channel' }));
    const secret = within(
        screen.getByRole('list', { name: 'Notification channels' }),
    ).getByLabelText('Incoming webhook URL');
    await user.type(secret, 'https://hooks.slack.com/services/synthetic');
    await user.type(screen.getByLabelText('Find channels or policies'), 'missing');
    expect(screen.getByText('No channels match this search.')).toBeVisible();
    expect(secret).not.toBeVisible();
    await user.clear(screen.getByLabelText('Find channels or policies'));
    expect(secret).toHaveValue('https://hooks.slack.com/services/synthetic');
});

test('channel save freezes edits, blocks double submit and retains a refused draft', async () => {
    const user = userEvent.setup();
    let callbacks: Visit | undefined;
    const visit = vi.spyOn(router, 'visit').mockImplementation((_url, options) => {
        callbacks = options;
    });
    renderInertia('Signalbox', Notifications, configuration);
    await user.click(screen.getByRole('button', { name: 'Edit channel' }));
    const name = screen.getByDisplayValue('Ops Slack');
    await user.type(name, ' draft');
    const submit = screen.getByRole('button', { name: 'Save channel' });
    await user.dblClick(submit);
    expect(visit).toHaveBeenCalledOnce();
    expect(name).toBeDisabled();
    act(() => {
        callbacks?.onError?.({ name: 'Name refused' });
        callbacks?.onFinish?.({} as never);
    });
    expect(name).toHaveValue('Ops Slack draft');
    expect(name).toBeEnabled();
    expect(screen.getByText('Name refused')).toBeInTheDocument();
});

test('uncertain policy outcome preserves its name and requires review before manual retry', async () => {
    const user = userEvent.setup();
    let callbacks: Visit | undefined;
    const visit = vi.spyOn(router, 'visit').mockImplementation((_url, options) => {
        callbacks = options;
    });
    renderInertia('Signalbox', Notifications, configuration);
    await user.click(screen.getByRole('button', { name: 'Edit policy' }));
    const name = screen.getByDisplayValue('Primary');
    await user.type(name, ' draft');
    await user.click(screen.getByRole('button', { name: 'Save policy' }));
    act(() => callbacks?.onFinish?.({} as never));
    expect(screen.getByText('Outcome not confirmed')).toBeInTheDocument();
    expect(name).toHaveValue('Primary draft');
    expect(name).toBeDisabled();
    expect(visit).toHaveBeenCalledOnce();
    await user.click(screen.getByRole('button', { name: 'I checked the saved data' }));
    expect(name).toBeEnabled();
    expect(visit).toHaveBeenCalledOnce();
});

test('pending code confirmation retains its value on refusal and erases it only on acceptance', async () => {
    const user = userEvent.setup();
    let callbacks: NonNullable<Parameters<typeof router.post>[2]> | undefined;
    vi.spyOn(router, 'post').mockImplementation((_url, _data, options) => {
        callbacks = options;
    });
    renderInertia('Signalbox', Notifications, {
        ...configuration,
        channels: [{ ...configuration.channels[0], verificationPending: true }],
    });
    const input = screen.getByLabelText('Verification code');
    await user.type(input, '123456');
    await user.click(screen.getByRole('button', { name: 'Verify' }));
    act(() => {
        callbacks?.onError?.({ code: 'Wrong code' });
        callbacks?.onFinish?.({} as never);
    });
    expect(input).toHaveValue('123456');
    await user.click(screen.getByRole('button', { name: 'Verify' }));
    act(() => {
        callbacks?.onSuccess?.({} as never);
        callbacks?.onFinish?.({} as never);
    });
    expect(input).toHaveValue('');
});

test('member configuration is read-only and offers the same operational routes', () => {
    renderInertia('Signalbox', Notifications, {
        ...configuration,
        can: { manage: false },
        channels: [{ ...configuration.channels[0], editable: null }],
    });
    expect(screen.getByRole('link', { name: 'Notification deliveries' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Edit channel' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Edit policy' })).not.toBeInTheDocument();
});

test('a failed secret rotation blocks further writes and never exposes response details', async () => {
    const user = userEvent.setup();
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    document.cookie = 'XSRF-TOKEN=synthetic';
    const request = vi
        .spyOn(globalThis, 'fetch')
        .mockRejectedValue(new Error('private transport detail'));
    renderInertia('Signalbox', Notifications, {
        ...configuration,
        channels: [{ ...configuration.channels[0], type: 'webhook' }],
    });
    await user.click(screen.getByRole('button', { name: 'Create new signing secret' }));
    expect(screen.getByText(/Rotation was not confirmed/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Create new signing secret' })).toBeDisabled();
    expect(screen.queryByText('private transport detail')).not.toBeInTheDocument();
    expect(request).toHaveBeenCalledOnce();
});
