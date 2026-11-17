/** Verify team selection and honest empty states within the neutral dashboard. */
import { screen } from '@testing-library/react';
import { expect, test } from 'vitest';
import DashboardIndex from '../../resources/js/pages/Dashboard/Index';
import { renderInertia } from './renderInertia';

test('offers accessible teams inside the dashboard without rendering tenant metrics', () => {
    renderInertia('Signalbox', DashboardIndex, {
        auth: { user: { name: 'Owner', email: 'owner@example.test' } },
        teamNavigation: {
            teams: [
                { id: 1, name: 'Alpha', role: 'owner' },
                { id: 2, name: 'Beta', role: 'viewer' },
            ],
            selectedTeamId: null,
        },
    });
    expect(screen.getByRole('heading', { name: 'Dashboard', level: 1 })).toBeInTheDocument();
    expect(screen.getByRole('combobox', { name: 'Team' })).toBeInTheDocument();
    expect(screen.getByRole('option', { name: 'Beta · viewer' })).toBeInTheDocument();
    expect(screen.queryByRole('img')).not.toBeInTheDocument();
});

test('keeps account and invitations available when there are no memberships', () => {
    renderInertia('Signalbox', DashboardIndex, {
        auth: { user: { name: 'Owner', email: 'owner@example.test' } },
        teamNavigation: { teams: [], selectedTeamId: null },
    });
    expect(screen.getByText('You do not belong to any teams yet.')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Your account and invitations' })).toHaveAttribute(
        'href',
        '/account',
    );
});
