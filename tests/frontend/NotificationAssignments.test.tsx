/** Exercise the dedicated assignment page with real shared selectors and the existing policy editor. */
import { router } from '@inertiajs/react';
import { act, cleanup, fireEvent, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, expect, test, vi } from 'vitest';
import Assignments from '../../resources/js/pages/Notifications/Assignments';
import { installDialogSupport } from './dialogSupport';
import { renderInertia } from './renderInertia';

/** Restore native dialog methods after disposing each page fixture. */
let restoreDialogs = () => undefined as void;
beforeEach(() => {
    restoreDialogs = installDialogSupport();
});
afterEach(() => {
    cleanup();
    restoreDialogs();
    vi.restoreAllMocks();
});

/** Safe monitor and policy projections supplied by the route, with no configuration or credentials. */
const props = {
    team: { id: 7, name: 'Synthetic team' },
    monitors: [
        { id: 11, name: 'API' },
        { id: 12, name: 'Worker' },
    ],
    selectedMonitor: {
        id: 11,
        name: 'API',
        canAssign: true,
        escalation: {
            policyId: null,
            effective: null,
            options: [{ id: 21, name: 'Primary', isDefault: false }],
        },
    },
};

test('saves a monitor assignment through the existing exact monitor endpoint', async () => {
    const user = userEvent.setup();
    const patch = vi.spyOn(router, 'patch').mockImplementation(() => undefined);
    renderInertia('Signalbox', Assignments, props);
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent(
        'Assign a policy to a monitor',
    );
    expect(
        screen.getByRole('link', { current: 'page', name: 'Assign a policy to a monitor' }),
    ).toHaveAttribute('href', '/teams/7/notifications/assignments');
    await user.click(screen.getByRole('button', { name: 'Assign policy' }));
    await user.selectOptions(screen.getByLabelText('Escalation policy'), '21');
    await user.click(screen.getByRole('button', { name: 'Save' }));
    expect(patch).toHaveBeenCalledWith(
        '/teams/7/monitors/11/escalation-policy',
        { escalation_policy_id: 21 },
        expect.any(Object),
    );
});

test('reads a newly selected monitor without mutating its assignment', async () => {
    const user = userEvent.setup();
    const read = vi.spyOn(router, 'get').mockImplementation(() => undefined);
    const patch = vi.spyOn(router, 'patch').mockImplementation(() => undefined);
    renderInertia('Signalbox', Assignments, { ...props, selectedMonitor: null });
    expect(screen.queryByLabelText('Escalation policy')).not.toBeInTheDocument();
    await user.click(screen.getByRole('combobox', { name: 'Find monitors' }));
    await user.click(screen.getByRole('option', { name: 'Worker' }));
    expect(read).toHaveBeenCalledWith(
        '/teams/7/notifications/assignments',
        { monitor_id: '12' },
        expect.any(Object),
    );
    expect(patch).not.toHaveBeenCalled();
});

test('keeps assignment details visible and omits editing for viewers', () => {
    renderInertia('Signalbox', Assignments, {
        ...props,
        selectedMonitor: { ...props.selectedMonitor, canAssign: false },
    });
    expect(
        screen.getByText('Nobody is notified when this monitor opens an incident.'),
    ).toBeInTheDocument();
    expect(
        screen.getByText('Your role has read-only access to policy assignments.'),
    ).toBeInTheDocument();
    expect(screen.queryByLabelText('Escalation policy')).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Save' })).not.toBeInTheDocument();
});

test('explains an empty team without mounting an assignment form', () => {
    renderInertia('Signalbox', Assignments, { ...props, monitors: [], selectedMonitor: null });
    expect(screen.getByText('No monitors available.')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Save' })).not.toBeInTheDocument();
});

test('assignment modal retains its choice after close and validation errors, then closes on acceptance', async () => {
    const user = userEvent.setup();
    let options: NonNullable<Parameters<typeof router.patch>[2]> | undefined;
    vi.spyOn(router, 'patch').mockImplementation((_path, _data, supplied) => {
        options = supplied;
    });
    renderInertia('Signalbox', Assignments, props);
    const trigger = screen.getByRole('button', { name: 'Assign policy' });
    await user.click(trigger);
    let modal = screen.getByRole('dialog', { name: 'Assign policy' });
    await user.selectOptions(within(modal).getByLabelText('Escalation policy'), '21');
    await user.click(within(modal).getByRole('button', { name: 'Close' }));
    await user.click(trigger);
    modal = screen.getByRole('dialog', { name: 'Assign policy' });
    expect(within(modal).getByLabelText('Escalation policy')).toHaveValue('21');
    await user.click(within(modal).getByRole('button', { name: 'Save' }));
    act(() => options?.onStart?.({} as never));
    expect(within(modal).getByRole('button', { name: 'Close' })).toBeDisabled();
    fireEvent(modal, new Event('cancel', { bubbles: true, cancelable: true }));
    expect(modal).toHaveAttribute('open');
    act(() => {
        options?.onError?.({ escalation_policy_id: 'Policy unavailable' });
        options?.onFinish?.({} as never);
    });
    expect(within(modal).getByText('Policy unavailable')).toBeInTheDocument();
    expect(within(modal).getByLabelText('Escalation policy')).toHaveValue('21');
    await user.click(within(modal).getByRole('button', { name: 'Save' }));
    act(() => {
        options?.onSuccess?.({} as never);
        options?.onFinish?.({} as never);
    });
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
});

test.each([
    ['default', 'Team default', 'success'],
    ['override', 'Monitor override', 'info'],
] as const)('shows the %s policy as a readable colored badge', (source, label, tone) => {
    renderInertia('Signalbox', Assignments, {
        ...props,
        selectedMonitor: {
            ...props.selectedMonitor,
            escalation: {
                ...props.selectedMonitor.escalation,
                effective: { id: 21, name: 'Primary', source },
            },
        },
    });
    expect(screen.getByText(label)).toHaveAttribute('data-tone', tone);
    expect(screen.getByText('Primary', { selector: 'p' })).toHaveClass('ui-notification-primary');
});
