/** Exercise the dedicated assignment page with real shared selectors and the existing policy editor. */
import { router } from '@inertiajs/react';
import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { expect, test, vi } from 'vitest';
import Assignments from '../../resources/js/pages/Notifications/Assignments';
import { renderInertia } from './renderInertia';

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
    await user.selectOptions(screen.getByLabelText('Monitor'), '12');
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
