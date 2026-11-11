/** Exercise the private shell through real Inertia context without contacting team or account endpoints. */
import { router, usePage } from '@inertiajs/react';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { expect, test, vi } from 'vitest';
import AppLayout from '../../resources/js/components/app/AppLayout';
import type {
    AppTeam,
    BreadcrumbAncestor,
    TeamSection,
} from '../../resources/js/components/app/navigation';
import type { AuthProps } from '../../resources/js/components/auth/AuthLayout';
import Button from '../../resources/js/components/ui/Button';
import { renderInertia } from './renderInertia';

/** Deliberate route identity and page family supplied by each synthetic server response. */
interface FixtureProps extends AuthProps {
    /** Authorized route team. */
    team: AppTeam;
    /** Current section, including the section of nested detail pages. */
    section: TeamSection;
    /** Optional trusted breadcrumb ancestors. */
    ancestors?: BreadcrumbAncestor[];
}

/**
 * @returns A real shell with one heading/action and a deliberately independent local form draft.
 */
function LayoutFixture() {
    const { team, section, ancestors } = usePage<FixtureProps>().props;

    return (
        <AppLayout
            title="API details"
            team={team}
            section={section}
            ancestors={ancestors ?? []}
            description="Authorized route resource"
            actions={<Button>Edit monitor</Button>}
        >
            <label htmlFor="draft">Private draft</label>
            <input id="draft" defaultValue="Unsaved note" />
        </AppLayout>
    );
}

/** Safe identity projection; its unrelated saved preference must never choose shell links. */
const identity = {
    team: { id: 12, name: 'Route team' },
    section: 'monitors',
    currentTeamId: 99,
    auth: { user: { name: 'Synthetic user', email: 'actor@example.test' } },
};

test('uses configured branding and only real route-team destinations despite a different saved preference', () => {
    renderInertia('Custom product', LayoutFixture, identity);
    expect(screen.getByRole('link', { name: 'Custom product' })).toHaveAttribute(
        'href',
        '/teams/12/dashboard',
    );
    expect(screen.queryByText('SIGNALBOX')).not.toBeInTheDocument();
    const navigation = screen.getByRole('navigation', { name: 'Team navigation' });
    expect(
        within(navigation)
            .getAllByRole('link')
            .map((link) => link.getAttribute('href')),
    ).toEqual([
        '/teams/12/dashboard',
        '/teams/12/monitors',
        '/teams/12/incidents',
        '/teams/12/maintenance',
        '/teams/12/notifications',
        '/teams/12/status-pages',
        '/teams/12',
    ]);
    for (const absent of ['Billing', 'API']) {
        expect(within(navigation).queryByRole('link', { name: absent })).not.toBeInTheDocument();
    }
    expect(screen.getByText('Current team')).toBeVisible();
    expect(screen.getAllByText('Route team')).toHaveLength(2);
});

test.each([
    ['dashboard', 'Dashboard'],
    ['monitors', 'Monitors'],
    ['incidents', 'Incidents'],
    ['maintenance', 'Maintenance'],
    ['notifications', 'Notifications'],
    ['status-pages', 'Status pages'],
    ['team', 'Team'],
] as const)('marks only the current %s section including nested pages', (section, label) => {
    renderInertia('Signalbox', LayoutFixture, { ...identity, section });
    const navigation = screen.getByRole('navigation', { name: 'Team navigation' });
    const active = within(navigation).getByRole('link', { current: 'page' });
    expect(active).toHaveAccessibleName(label);
    expect(within(navigation).getAllByRole('link', { current: 'page' })).toHaveLength(1);
});

test('renders a single page heading, linked ancestors and a non-linked current breadcrumb', () => {
    renderInertia('Signalbox', LayoutFixture, {
        ...identity,
        ancestors: [{ label: 'Monitors', href: '/teams/12/monitors?q=API&page=2' }],
    });
    expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1);
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('API details');
    const breadcrumb = screen.getByRole('navigation', { name: 'Breadcrumb' });
    expect(within(breadcrumb).getByRole('link', { name: 'Monitors' })).toHaveAttribute(
        'href',
        '/teams/12/monitors?q=API&page=2',
    );
    expect(within(breadcrumb).queryByRole('link', { name: 'API details' })).not.toBeInTheDocument();
    expect(within(breadcrumb).getByText('API details')).toHaveAttribute('aria-current', 'page');
    expect(screen.getByRole('button', { name: 'Edit monitor' })).toBeVisible();
    expect(screen.getByText('Authorized route resource')).toBeVisible();
});

test('provides a keyboard skip link targeting the main content and preserves local content during disclosure changes', async () => {
    const user = userEvent.setup();
    renderInertia('Signalbox', LayoutFixture, identity);
    await user.tab();
    const skip = screen.getByRole('link', { name: 'Skip to content' });
    expect(skip).toHaveFocus();
    const main = screen.getByRole('main');
    expect(skip).toHaveAttribute('href', `#${main.id}`);
    expect(main).toHaveAttribute('tabindex', '-1');
    await user.type(screen.getByLabelText('Private draft'), ' changed');
    await user.click(screen.getByRole('button', { name: 'Account menu' }));
    await user.keyboard('{Escape}');
    expect(screen.getByLabelText('Private draft')).toHaveValue('Unsaved note changed');
});

test('opens account links with the keyboard and Escape closes the disclosure with restored focus', async () => {
    const user = userEvent.setup();
    renderInertia('Signalbox', LayoutFixture, identity);
    const trigger = screen.getByRole('button', { name: 'Account menu' });
    expect(trigger).toHaveAttribute('aria-expanded', 'false');
    expect(
        screen.queryByRole('navigation', { name: 'Account navigation' }),
    ).not.toBeInTheDocument();
    trigger.focus();
    await user.keyboard('{Enter}');
    expect(trigger).toHaveAttribute('aria-expanded', 'true');
    await user.tab();
    expect(screen.getByRole('link', { name: 'Your account' })).toHaveFocus();
    expect(screen.getByRole('link', { name: 'Your teams' })).toHaveAttribute('href', '/teams');
    expect(screen.getByRole('link', { name: 'Account security' })).toHaveAttribute(
        'href',
        '/account/security',
    );
    await user.keyboard('{Escape}');
    expect(trigger).toHaveFocus();
    expect(trigger).toHaveAttribute('aria-expanded', 'false');
});

test('closes the account disclosure on outside interaction without stealing focus', async () => {
    const user = userEvent.setup();
    renderInertia('Signalbox', LayoutFixture, identity);
    await user.click(screen.getByRole('button', { name: 'Account menu' }));
    await user.click(screen.getByLabelText('Private draft'));
    expect(screen.getByRole('button', { name: 'Account menu' })).toHaveAttribute(
        'aria-expanded',
        'false',
    );
    expect(screen.getByLabelText('Private draft')).toHaveFocus();
});

test.each(['owner', 'admin', 'member', 'viewer'])(
    'does not grant platform navigation from the %s team role',
    async (role) => {
        const user = userEvent.setup();
        renderInertia('Signalbox', LayoutFixture, { ...identity, role });
        await user.click(screen.getByRole('button', { name: 'Account menu' }));
        expect(screen.queryByRole('link', { name: 'Admin' })).not.toBeInTheDocument();
    },
);

test('reveals the operator link only from the explicit server flag and closes after normal navigation', async () => {
    const user = userEvent.setup();
    const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
    renderInertia('Signalbox', LayoutFixture, {
        ...identity,
        auth: { user: { ...identity.auth.user, isPlatformAdmin: true } },
    });
    await user.click(screen.getByRole('button', { name: 'Account menu' }));
    const admin = screen.getByRole('link', { name: 'Admin' });
    expect(admin).toHaveAttribute('href', '/admin/teams');
    await user.click(admin);
    expect(visit).toHaveBeenCalledWith('/admin/teams', expect.any(Object));
    expect(screen.getByRole('button', { name: 'Account menu' })).toHaveAttribute(
        'aria-expanded',
        'false',
    );
});

test('issues one explicit logout POST and disables duplicate submission while pending', async () => {
    const user = userEvent.setup();
    const post = vi.spyOn(router, 'post').mockImplementation((_url, _data, options) => {
        options?.onStart?.({} as Parameters<NonNullable<typeof options.onStart>>[0]);
    });
    renderInertia('Signalbox', LayoutFixture, identity);
    expect(post).not.toHaveBeenCalled();
    await user.click(screen.getByRole('button', { name: 'Account menu' }));
    await user.click(screen.getByRole('button', { name: 'Sign out' }));
    expect(post).toHaveBeenCalledWith('/logout', {}, expect.any(Object));
    const pending = screen.getByRole('button', { name: 'Signing out…' });
    expect(pending).toBeDisabled();
    await user.click(pending);
    expect(post).toHaveBeenCalledTimes(1);
    expect(screen.queryByRole('link', { name: 'Sign out' })).not.toBeInTheDocument();
});

test('renders safe shared acknowledgements while keeping the current page visible', () => {
    renderInertia('Signalbox', LayoutFixture, { ...identity, status: 'Monitor saved.' });
    expect(screen.getByRole('status')).toHaveTextContent('Monitor saved.');
    expect(screen.getByRole('main')).toContainElement(screen.getByLabelText('Private draft'));
});
