/** Verify the dashboard frame for account/platform pages without inventing a tenant context. */
import { screen, within } from '@testing-library/react';
import { expect, test } from 'vitest';
import PrivateContextLayout from '../../resources/js/components/app/PrivateContextLayout';
import { renderInertia } from './renderInertia';

/** Render an account workspace with content protected by the context layout. */
function AccountFixture() {
    return (
        <PrivateContextLayout title="Your account">
            <p>Private account content</p>
        </PrivateContextLayout>
    );
}

/** Render a platform inspection that must not select the inspected team. */
function PlatformFixture() {
    return (
        <PrivateContextLayout title="Inspected team" context="platform">
            <p>Operator content</p>
        </PrivateContextLayout>
    );
}

test('keeps account pages in the dashboard frame with the actors own selected team links', () => {
    const { container } = renderInertia(
        'Signalbox',
        AccountFixture,
        {
            auth: { user: { name: 'Owner', email: 'owner@example.test' } },
            teamNavigation: {
                teams: [{ id: 12, name: 'Own team', role: 'viewer' }],
                selectedTeamId: 12,
            },
        },
        { url: '/account' },
    );
    expect(container.querySelector('.ui-app-frame > .ui-sidebar')).not.toBeNull();
    const navigation = within(screen.getByRole('navigation', { name: 'Workspace navigation' }));
    expect(navigation.getByRole('link', { name: 'Account' })).toHaveAttribute(
        'aria-current',
        'page',
    );
    expect(navigation.queryByRole('link', { name: 'Administration' })).not.toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Monitors' })).toHaveAttribute(
        'href',
        '/teams/12/monitors',
    );
    expect(screen.getByRole('heading', { level: 1, name: 'Your account' })).toBeInTheDocument();
});

test('keeps platform navigation in the dashboard shell without exposing inspected tenant destinations', () => {
    const { container } = renderInertia(
        'Signalbox',
        PlatformFixture,
        {
            auth: {
                user: { name: 'Operator', email: 'operator@example.test', isPlatformAdmin: true },
            },
            team: { id: 99, name: 'Inspected team' },
            teamNavigation: {
                teams: [{ id: 12, name: 'Own team', role: 'owner' }],
                selectedTeamId: 12,
            },
        },
        { url: '/admin/teams/99' },
    );
    expect(container.querySelector('.ui-sidebar')).not.toBeNull();
    const navigation = within(screen.getByRole('navigation', { name: 'Workspace navigation' }));
    expect(navigation.getByRole('link', { name: 'Administration' })).toHaveAttribute(
        'aria-current',
        'page',
    );
    expect(navigation.getByRole('link', { name: 'Dashboard' })).toHaveAttribute(
        'href',
        '/dashboard',
    );
    expect(screen.queryByRole('navigation', { name: 'Team navigation' })).not.toBeInTheDocument();
    expect(screen.queryByRole('combobox', { name: 'Team' })).not.toBeInTheDocument();
});

test('hides protected content and sidebar when operator access is missing', () => {
    const { container } = renderInertia('Signalbox', PlatformFixture, {
        auth: { user: { name: 'Viewer', email: 'viewer@example.test' } },
    });
    expect(container.querySelector('.ui-sidebar')).toBeNull();
    expect(screen.queryByText('Operator content')).not.toBeInTheDocument();
});
