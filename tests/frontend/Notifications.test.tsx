/** Exercise the notification settings page, monitor escalation choice and delivery log through actual Inertia components. */
import { router } from '@inertiajs/react';
import { fireEvent, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, expect, test, vi } from 'vitest';
import EscalationPanel from '../../resources/js/components/notifications/EscalationPanel';
import DeliveryLog from '../../resources/js/components/notifications/DeliveryLog';
import QuietHoursForm from '../../resources/js/components/notifications/QuietHoursForm';
import { delayLabel } from '../../resources/js/components/notifications/labels';
import type { ChannelView, PolicyView } from '../../resources/js/components/notifications/types';
import Index from '../../resources/js/pages/Notifications/Index';
import { renderInertia } from './renderInertia';

/** Route team of every synthetic page. */
const team = { id: 7, name: 'Synthetic team' };

/** Families as projected by NotificationPresenter::channelTypes(). */
const channelTypes = [
    { value: 'email', label: 'Email', isStub: false, secretFields: [] },
    { value: 'slack', label: 'Slack', isStub: false, secretFields: ['webhook_url'] },
    { value: 'telegram', label: 'Telegram', isStub: false, secretFields: ['bot_token'] },
    { value: 'webhook', label: 'Webhook', isStub: false, secretFields: ['url'] },
    { value: 'sms', label: 'SMS (stub)', isStub: true, secretFields: [] },
];

/** Bounds as projected by NotificationPresenter::limits(). */
const limits = {
    channelsPerTeam: 25,
    policiesPerTeam: 10,
    stepsPerPolicy: 5,
    channelsPerStep: 10,
    maxStepDelayMinutes: 1440,
    nameMaxLength: 100,
    codeLength: 6,
};

afterEach(() => {
    vi.restoreAllMocks();
});

/**
 * @param id Channel identity.
 * @param extra Field overrides.
 * @returns Synthetic channel row without secrets.
 */
function channel(id: number, extra: Partial<ChannelView> = {}): ChannelView {
    return {
        id,
        type: 'email',
        name: `Channel ${id}`,
        hint: 'o•••@example.test',
        isActive: false,
        isStub: false,
        verifiedAt: null,
        verificationPending: false,
        verificationExpiresAt: null,
        editable: { address: 'oncall@example.test' },
        usedBy: [],
        quietHours: null,
        ...extra,
    };
}

/** Default policy escalating from the first channel to the second after 30 minutes. */
const policy: PolicyView = {
    id: 3,
    name: 'Primary',
    isDefault: true,
    monitorCount: 2,
    steps: [
        { afterMinutes: 0, channels: [{ id: 1, name: 'Channel 1', isActive: true }] },
        { afterMinutes: 30, channels: [{ id: 2, name: 'Channel 2', isActive: false }] },
    ],
};

/**
 * @param manage Whether the viewer is an owner or admin.
 * @param extra Overrides of the page props.
 * @returns Complete page props.
 */
function pageProps(manage: boolean, extra: Record<string, unknown> = {}) {
    return {
        team,
        channels: [
            channel(1, {
                isActive: true,
                verifiedAt: '2026-10-29T08:00:00+00:00',
                usedBy: ['Primary'],
            }),
            channel(2, {
                verificationPending: true,
                verificationExpiresAt: '2026-10-29T09:15:00+00:00',
            }),
        ],
        policies: [policy],
        unnotifiedMonitors: 0,
        channelTypes,
        limits,
        can: { manage },
        ...extra,
    };
}

test('shows masked channels and policies read-only to members', () => {
    renderInertia(
        'Signalbox',
        Index,
        pageProps(false, { channels: [channel(1, { editable: null })] }),
    );

    const list = screen.getByRole('list', { name: 'Notification channels' });
    expect(within(list).getByText('o•••@example.test')).toBeInTheDocument();
    expect(within(list).getByText('Not verified')).toBeInTheDocument();
    expect(screen.queryByRole('button')).not.toBeInTheDocument();
    expect(screen.queryByLabelText('Channel type')).not.toBeInTheDocument();
    expect(
        screen.getByText('Only owners and admins can change channels and escalation policies.'),
    ).toBeInTheDocument();

    const steps = screen.getByRole('list', { name: 'Steps of Primary' });
    const [first, second] = within(steps).getAllByRole('listitem');
    expect(first).toHaveTextContent('Immediately: Channel 1');
    expect(second).toHaveTextContent('After 30 min: Channel 2 (not active)');
});

test('shows empty states and warns when monitors notify nobody', () => {
    renderInertia(
        'Signalbox',
        Index,
        pageProps(true, { channels: [], policies: [], unnotifiedMonitors: 3 }),
    );

    expect(screen.getByText('No channels yet.')).toBeInTheDocument();
    expect(screen.getByText('No escalation policies yet.')).toBeInTheDocument();
    expect(screen.getByText(/3 monitors notify nobody/)).toBeInTheDocument();
    expect(screen.getByText('Create a channel first.')).toBeInTheDocument();
});

test('creates a channel with the fields of the chosen type and announces field errors', async () => {
    const user = userEvent.setup();
    // Simulate the server refusing the token: useForm receives errors through the visit callback.
    const visit = vi.spyOn(router, 'visit').mockImplementation((_url, options) => {
        (options as { onError?: (errors: Record<string, string>) => void } | undefined)?.onError?.({
            'config.bot_token': 'Enter the bot token issued by BotFather.',
        });
    });

    renderInertia('Signalbox', Index, pageProps(true));
    const form = screen.getByRole('heading', { name: 'Add a channel' })
        .parentElement as HTMLElement;
    await user.selectOptions(within(form).getByLabelText('Channel type'), 'telegram');
    expect(within(form).getByLabelText('Bot token')).toHaveAttribute('type', 'password');

    await user.type(within(form).getByLabelText('Name'), 'Ops chat');
    await user.type(within(form).getByLabelText('Bot token'), '123456:token');
    await user.type(within(form).getByLabelText('Chat ID'), '-100123');
    await user.click(within(form).getByRole('button', { name: 'Add channel' }));

    expect(visit).toHaveBeenCalledWith(
        '/teams/7/notification-channels',
        expect.objectContaining({
            method: 'post',
            data: {
                type: 'telegram',
                name: 'Ops chat',
                config: { bot_token: '123456:token', chat_id: '-100123' },
            },
        }),
    );
    expect(await within(form).findByRole('alert')).toHaveTextContent(
        'Enter the bot token issued by BotFather.',
    );
    expect(within(form).getByLabelText('Bot token')).toHaveAttribute('aria-invalid', 'true');
});

test('sends and confirms a verification code and pauses a verified channel', async () => {
    const user = userEvent.setup();
    const post = vi.spyOn(router, 'post').mockImplementation(() => undefined);
    const patch = vi.spyOn(router, 'patch').mockImplementation(() => undefined);

    renderInertia('Signalbox', Index, pageProps(true));
    const rows = within(screen.getByRole('list', { name: 'Notification channels' })).getAllByRole(
        'listitem',
    );
    const active = rows[0] as HTMLElement;
    const waiting = rows[1] as HTMLElement;

    expect(within(waiting).getByText('Waiting for code')).toBeInTheDocument();
    await user.type(within(waiting).getByLabelText('Verification code'), '123456');
    await user.click(within(waiting).getByRole('button', { name: 'Verify' }));
    expect(post).toHaveBeenCalledWith(
        '/teams/7/notification-channels/2/verification/confirm',
        { code: '123456' },
        expect.any(Object),
    );

    await user.click(within(waiting).getByRole('button', { name: 'Send a new code' }));
    expect(post).toHaveBeenLastCalledWith(
        '/teams/7/notification-channels/2/verification',
        {},
        expect.any(Object),
    );
    expect(
        within(waiting).queryByRole('button', { name: 'Activate channel' }),
    ).not.toBeInTheDocument();

    await user.click(within(active).getByRole('button', { name: 'Pause channel' }));
    expect(patch).toHaveBeenCalledWith(
        '/teams/7/notification-channels/1/activation',
        { active: false },
        expect.any(Object),
    );
});

test('edits a channel without prefilling secrets and deletes only after confirmation', async () => {
    const user = userEvent.setup();
    const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
    const destroy = vi.spyOn(router, 'delete').mockImplementation(() => undefined);
    vi.spyOn(window, 'confirm').mockReturnValueOnce(false).mockReturnValueOnce(true);

    renderInertia(
        'Signalbox',
        Index,
        pageProps(true, {
            channels: [
                channel(5, { type: 'slack', hint: 'hooks.slack.com/services/…WXYZ', editable: {} }),
            ],
        }),
    );
    const row = within(screen.getByRole('list', { name: 'Notification channels' })).getByRole(
        'listitem',
    );
    await user.click(within(row).getByRole('button', { name: 'Edit channel' }));
    const secret = within(row).getByLabelText('Incoming webhook URL');
    expect(secret).toHaveValue('');
    expect(within(row).getByText(/Leave empty to keep the stored value/)).toBeInTheDocument();
    await user.click(within(row).getByRole('button', { name: 'Save channel' }));
    expect(visit).toHaveBeenCalledWith(
        '/teams/7/notification-channels/5',
        expect.objectContaining({
            method: 'patch',
            data: { type: 'slack', name: 'Channel 5', config: {} },
        }),
    );

    await user.click(within(row).getByRole('button', { name: 'Delete channel' }));
    expect(destroy).not.toHaveBeenCalled();
    await user.click(within(row).getByRole('button', { name: 'Delete channel' }));
    expect(destroy).toHaveBeenCalledWith('/teams/7/notification-channels/5', expect.any(Object));
});

test('reveals a rotated webhook secret once from a JSON response', async () => {
    const user = userEvent.setup();
    const secret = 'a'.repeat(64);
    document.cookie = 'XSRF-TOKEN=synthetic-token';
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    const fetch = vi
        .spyOn(globalThis, 'fetch')
        .mockResolvedValue(new Response(JSON.stringify({ secret }), { status: 200 }));

    renderInertia(
        'Signalbox',
        Index,
        pageProps(true, { channels: [channel(9, { type: 'webhook', editable: {} })] }),
    );
    await user.click(screen.getByRole('button', { name: 'Create new signing secret' }));

    expect(fetch).toHaveBeenCalledWith(
        '/teams/7/notification-channels/9/signing-secret',
        expect.objectContaining({ method: 'POST', cache: 'no-store' }),
    );
    expect(await screen.findByText(secret)).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Hide secret' }));
    expect(screen.queryByText(secret)).not.toBeInTheDocument();
});

test('builds policy steps with increasing delays and submits integers', async () => {
    const user = userEvent.setup();
    const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);

    renderInertia(
        'Signalbox',
        Index,
        pageProps(true, { errors: { policy: 'Step 2 must wait longer than the step before it.' } }),
    );
    expect(screen.getByText('Step 2 must wait longer than the step before it.')).toHaveAttribute(
        'role',
        'alert',
    );

    const form = screen.getByRole('heading', { name: 'Add a policy' }).parentElement as HTMLElement;
    await user.type(within(form).getByLabelText('Policy name'), 'Night shift');
    await user.click(within(form).getByLabelText(/Team default/));
    const steps = within(form).getByRole('list', { name: 'Escalation steps' });
    await user.click(within(steps).getByLabelText('Channel 1'));
    await user.click(within(form).getByRole('button', { name: 'Add step' }));
    const second = within(steps).getAllByRole('listitem')[1] as HTMLElement;
    expect(within(second).getByLabelText('Minutes after the incident is confirmed')).toHaveValue(
        15,
    );
    await user.click(within(second).getByLabelText('Channel 2 (not active)'));
    await user.click(within(form).getByRole('button', { name: 'Add policy' }));

    expect(visit).toHaveBeenCalledWith(
        '/teams/7/escalation-policies',
        expect.objectContaining({
            method: 'post',
            data: {
                name: 'Night shift',
                is_default: true,
                steps: [
                    { after_minutes: 0, channel_ids: [1] },
                    { after_minutes: 15, channel_ids: [2] },
                ],
            },
        }),
    );
});

test('chooses a monitor policy or the team default and explains who is notified', async () => {
    const user = userEvent.setup();
    const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
    const options = [
        { id: 3, name: 'Primary', isDefault: true },
        { id: 4, name: 'Database team', isDefault: false },
    ];

    const view = renderInertia('Signalbox', () => (
        <EscalationPanel
            teamId={7}
            action="/teams/7/monitors/11/escalation-policy"
            escalation={{
                policyId: null,
                effective: { id: 3, name: 'Primary', source: 'default' },
                options,
            }}
            canManage
        />
    ));
    expect(
        screen.getByText('Incidents follow the policy Primary (team default).'),
    ).toBeInTheDocument();
    expect(screen.getByRole('option', { name: 'Team default (Primary)' })).toBeInTheDocument();
    await user.selectOptions(screen.getByLabelText('Escalation policy'), '4');
    await user.click(screen.getByRole('button', { name: 'Save' }));
    expect(visit).toHaveBeenCalledWith(
        '/teams/7/monitors/11/escalation-policy',
        expect.objectContaining({ method: 'patch', data: { escalation_policy_id: 4 } }),
    );
    view.unmount();

    renderInertia('Signalbox', () => (
        <EscalationPanel
            teamId={7}
            action="/teams/7/monitors/11/escalation-policy"
            escalation={{ policyId: null, effective: null, options: [] }}
            canManage={false}
        />
    ));
    expect(
        screen.getByText('Nobody is notified when this monitor opens an incident.'),
    ).toBeInTheDocument();
    expect(screen.queryByRole('combobox')).not.toBeInTheDocument();
});

test('lists deliveries with deleted channels and an empty state', () => {
    const view = renderInertia('Signalbox', () => <DeliveryLog deliveries={[]} />);
    expect(
        screen.getByText('No notification has been recorded for this incident.'),
    ).toBeInTheDocument();
    view.unmount();

    renderInertia('Signalbox', () => (
        <DeliveryLog
            deliveries={[
                {
                    id: 1,
                    channelName: null,
                    channelType: 'slack',
                    kind: 'escalated',
                    stepIndex: 1,
                    status: 'failed',
                    attempts: 3,
                    response: 'HTTP 500 from provider.',
                    sentAt: null,
                    createdAt: '2026-10-29T09:00:00+00:00',
                },
            ]}
        />
    ));
    const table = screen.getByRole('table', { name: 'Notification deliveries, oldest first' });
    expect(within(table).getByText('Deleted slack channel')).toBeInTheDocument();
    expect(within(table).getByText('Escalation · step 2')).toBeInTheDocument();
    expect(within(table).getByText('Failed')).toBeInTheDocument();
    expect(within(table).getByText('Not sent')).toBeInTheDocument();
});

test('formats step delays in minutes and hours', () => {
    expect(delayLabel(0)).toBe('Immediately');
    expect(delayLabel(45)).toBe('After 45 min');
    expect(delayLabel(120)).toBe('After 2 h');
    expect(delayLabel(90)).toBe('After 1 h 30 min');
});

test('shows quiet hours to members with no editing controls', () => {
    renderInertia(
        'Signalbox',
        Index,
        pageProps(false, {
            channels: [
                channel(1, {
                    quietHours: { start: '22:00', end: '07:00', timezone: 'Europe/Rome' },
                }),
            ],
        }),
    );
    expect(screen.getByText('Quiet hours daily: 22:00–07:00 (Europe/Rome).')).toBeInTheDocument();
    expect(screen.queryByText('Configure quiet hours')).not.toBeInTheDocument();
});

test('enables and saves an overnight interval with an explicit timezone', async () => {
    const user = userEvent.setup();
    const patch = vi.spyOn(router, 'patch').mockImplementation(() => undefined);
    renderInertia('Signalbox', () => (
        <QuietHoursForm
            path="/teams/7/notification-channels/1"
            idPrefix="quiet-test"
            hours={null}
        />
    ));
    expect(screen.getByLabelText('Quiet hours start')).toBeDisabled();
    await user.click(screen.getByLabelText('Enable daily quiet hours'));
    fireEvent.change(screen.getByLabelText('Quiet hours start'), { target: { value: '23:00' } });
    fireEvent.change(screen.getByLabelText('Quiet hours end'), { target: { value: '06:30' } });
    await user.clear(screen.getByLabelText('Quiet hours timezone'));
    await user.type(screen.getByLabelText('Quiet hours timezone'), 'Europe/Rome');
    await user.click(screen.getByRole('button', { name: 'Save quiet hours' }));
    expect(patch).toHaveBeenCalledWith(
        '/teams/7/notification-channels/1/quiet-hours',
        {
            quiet_hours: { start: '23:00', end: '06:30', timezone: 'Europe/Rome' },
        },
        expect.any(Object),
    );
});

test('prefills the saved interval and disables it with an explicit null', async () => {
    const user = userEvent.setup();
    const patch = vi.spyOn(router, 'patch').mockImplementation(() => undefined);
    renderInertia('Signalbox', () => (
        <QuietHoursForm
            path="/teams/7/notification-channels/1"
            idPrefix="quiet-test"
            hours={{ start: '22:00', end: '07:00', timezone: 'America/New_York' }}
        />
    ));
    expect(screen.getByLabelText('Quiet hours timezone')).toHaveValue('America/New_York');
    expect(screen.getByLabelText('Quiet hours start')).toHaveValue('22:00');
    await user.click(screen.getByLabelText('Enable daily quiet hours'));
    await user.click(screen.getByRole('button', { name: 'Save quiet hours' }));
    expect(patch).toHaveBeenCalledWith(
        '/teams/7/notification-channels/1/quiet-hours',
        { quiet_hours: null },
        expect.any(Object),
    );
});

test('announces per-field quiet-hours errors and retains the submitted interval', async () => {
    const user = userEvent.setup();
    vi.spyOn(router, 'patch').mockImplementation((_url, _data, options) => {
        options?.onError?.({ 'quiet_hours.timezone': 'Choose a supported IANA timezone.' });
    });
    renderInertia('Signalbox', () => (
        <QuietHoursForm
            path="/teams/7/notification-channels/1"
            idPrefix="quiet-test"
            hours={{ start: '22:00', end: '07:00', timezone: 'Mars/Olympus' }}
        />
    ));
    await user.click(screen.getByRole('button', { name: 'Save quiet hours' }));
    expect(screen.getByRole('alert')).toHaveTextContent('Choose a supported IANA timezone.');
    expect(screen.getByLabelText('Quiet hours timezone')).toHaveAttribute('aria-invalid', 'true');
    expect(screen.getByLabelText('Quiet hours timezone')).toHaveValue('Mars/Olympus');
    expect(screen.getByLabelText('Quiet hours start')).toHaveValue('22:00');
});
