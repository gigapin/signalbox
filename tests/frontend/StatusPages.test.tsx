/** Exercise the status page settings, the public page and the password form through actual Inertia components. */
import { router } from '@inertiajs/react';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, expect, test, vi } from 'vitest';
import type { ManagedStatusPage } from '../../resources/js/components/status-pages/types';
import Index from '../../resources/js/pages/StatusPages/Index';
import Public from '../../resources/js/pages/StatusPages/Public';
import Unlock from '../../resources/js/pages/StatusPages/Unlock';
import { renderInertia } from './renderInertia';

/** Bounds as projected by StatusPagePresenter::limits(). */
const limits = {
    pages: 3,
    used: 0,
    titleMaxLength: 120,
    descriptionMaxLength: 500,
    componentNameMaxLength: 100,
    componentsPerPage: 50,
    monitorsPerComponent: 100,
    passwordMinLength: 8,
};

/** Monitors of the synthetic team. */
const monitors = [
    { id: 1, name: 'api-eu' },
    { id: 2, name: 'web-eu' },
];

/** A published page with two components. */
const page: ManagedStatusPage = {
    id: 9,
    slug: 'acme-status',
    path: '/status/acme-status',
    title: 'Acme status',
    description: null,
    theme: { mode: 'dark', accent: 'blue' },
    isPublic: true,
    hasPassword: false,
    domain: null,
    components: [
        { name: 'API', monitors: [{ id: 1, name: 'api-eu' }] },
        { name: 'Website', monitors: [{ id: 2, name: 'web-eu' }] },
    ],
};

/**
 * @param extra Prop overrides.
 * @returns Mounted settings page for an owner.
 */
function renderSettings(extra: Record<string, unknown> = {}) {
    return renderInertia('Signalbox', Index, {
        team: { id: 7, name: 'Synthetic team' },
        pages: [],
        monitors,
        themeChoices: {
            modes: ['system', 'light', 'dark'],
            accents: ['emerald', 'blue', 'violet', 'amber', 'slate'],
        },
        limits,
        can: { manage: true },
        ...extra,
    });
}

/** @returns Spy on Inertia form visits that prevents any network request. */
function spyOnVisits() {
    return vi.spyOn(router, 'visit').mockImplementation(() => undefined);
}

afterEach(() => {
    vi.restoreAllMocks();
});

test('builds a page with components, using each monitor once', async () => {
    const user = userEvent.setup();
    const visit = spyOnVisits();
    renderSettings();

    await user.type(screen.getByLabelText('Title'), 'Acme status');
    await user.type(screen.getByLabelText('Public address'), 'acme-status');
    await user.selectOptions(screen.getByLabelText('Color scheme'), 'dark');
    await user.selectOptions(screen.getByLabelText('Accent'), 'violet');
    await user.click(screen.getByRole('checkbox', { name: /Published/ }));
    await user.click(screen.getByRole('button', { name: 'Add component' }));
    await user.click(screen.getByRole('button', { name: 'Add component' }));

    const components = within(screen.getByRole('list', { name: 'Components' })).getAllByRole(
        'listitem',
    );
    const [first, second] = components as [HTMLElement, HTMLElement];

    await user.type(within(first).getByLabelText('Public name'), 'API');
    await user.click(within(first).getByRole('checkbox', { name: 'api-eu' }));
    await user.type(within(second).getByLabelText('Public name'), 'Website');

    expect(within(second).getByRole('checkbox', { name: /api-eu/ })).toBeDisabled();
    expect(within(second).getByText('api-eu (in another component)')).toBeInTheDocument();

    await user.click(within(second).getByRole('checkbox', { name: 'web-eu' }));
    await user.click(screen.getByRole('button', { name: 'Add status page' }));

    expect(visit).toHaveBeenCalledOnce();
    const [url, options] = visit.mock.calls[0] as [URL | string, Record<string, unknown>];

    expect(String(url)).toContain('/teams/7/status-pages');
    expect(options.method).toBe('post');
    expect(options.data).toMatchObject({
        slug: 'acme-status',
        title: 'Acme status',
        theme_mode: 'dark',
        theme_accent: 'violet',
        is_public: true,
        components: [
            { name: 'API', monitor_ids: [1] },
            { name: 'Website', monitor_ids: [2] },
        ],
    });
});

test('reorders components from the keyboard-reachable buttons', async () => {
    const user = userEvent.setup();
    const visit = spyOnVisits();
    renderSettings({ pages: [page], limits: { ...limits, used: 1 } });

    await user.click(screen.getByRole('button', { name: 'Edit Acme status' }));

    expect(screen.queryByRole('button', { name: 'Move component 1 up' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Move component 2 down' })).not.toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Move component 1 down' }));
    await user.click(screen.getAllByRole('button', { name: 'Save page' })[0] as HTMLElement);

    const [, options] = visit.mock.calls[0] as [URL | string, Record<string, unknown>];

    expect(options.method).toBe('patch');
    expect(options.data).toMatchObject({
        password: '',
        remove_password: false,
        components: [
            { name: 'Website', monitor_ids: [2] },
            { name: 'API', monitor_ids: [1] },
        ],
    });
});

test('removing the password disables the new password field', async () => {
    const user = userEvent.setup();
    spyOnVisits();
    renderSettings({
        pages: [{ ...page, hasPassword: true }],
        limits: { ...limits, used: 1, pages: 1 },
    });

    expect(screen.getByText('Password protected')).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Edit Acme status' }));
    await user.type(screen.getByLabelText('New password'), 'typed-password');
    await user.click(screen.getByRole('checkbox', { name: 'Remove the password' }));

    expect(screen.getByLabelText('New password')).toBeDisabled();
    expect(screen.getByLabelText('New password')).toHaveValue('');
});

test('links only published pages and hides forms from readers', () => {
    renderSettings({
        pages: [
            page,
            { ...page, id: 10, title: 'Draft page', isPublic: false, path: '/status/d-1' },
        ],
        limits: { ...limits, used: 2 },
        can: { manage: false },
    });

    expect(screen.getByRole('link', { name: '/status/acme-status' })).toHaveAttribute(
        'href',
        '/status/acme-status',
    );
    expect(screen.queryByRole('link', { name: /d-1/ })).not.toBeInTheDocument();
    expect(screen.getByText('/status/d-1 (not reachable yet)')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Edit/ })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Add status page' })).not.toBeInTheDocument();
    expect(screen.getByText('Only owners and admins can change status pages.')).toBeInTheDocument();
});

test('shows a domain refusal and the reached plan limit', () => {
    renderSettings({
        pages: [page],
        limits: { ...limits, used: 1, pages: 1 },
        errors: { page: 'This address is already taken.' },
    });

    expect(screen.getByRole('alert')).toHaveTextContent('This address is already taken.');
    expect(screen.queryByRole('button', { name: 'Add status page' })).not.toBeInTheDocument();
    expect(screen.getByText(/reached its plan limit of 1/)).toBeInTheDocument();
});

test('renders the public page with the chosen appearance and an empty state', () => {
    const { unmount } = renderInertia('Signalbox', Public, {
        page: {
            title: 'Acme status',
            description: 'Current state of Acme.',
            theme: { mode: 'dark', accent: 'blue' },
            status: 'partial_outage',
            components: [
                {
                    name: 'Public API',
                    status: 'outage',
                    uptimePercent: 99.5,
                    days: [
                        {
                            date: '2026-10-30',
                            state: 'none',
                            uptimePercent: null,
                            coveragePercent: null,
                        },
                        {
                            date: '2026-10-31',
                            state: 'down',
                            uptimePercent: 99.5,
                            coveragePercent: 100,
                        },
                    ],
                },
                { name: 'Website', status: 'operational', uptimePercent: null, days: [] },
            ],
            incidents: [
                {
                    component: 'Public API',
                    status: 'monitoring',
                    updates: [
                        {
                            status: 'monitoring',
                            message: 'Fix applied.',
                            publishedAt: '2026-10-31T10:05:00Z',
                        },
                        {
                            status: 'investigating',
                            message: 'Looking into it.',
                            publishedAt: '2026-10-31T09:00:00Z',
                        },
                    ],
                },
            ],
        },
    });

    expect(screen.getByRole('heading', { name: 'Acme status' })).toBeInTheDocument();
    expect(screen.getByText('Current state of Acme.')).toBeInTheDocument();
    expect(screen.getByRole('status')).toHaveTextContent('Some systems are having problems');
    expect(screen.getByRole('main')).toHaveClass('bg-slate-950');
    expect(screen.getByTestId('accent')).toHaveClass('bg-blue-500');

    const [api, website] = within(screen.getByRole('list', { name: 'Components' })).getAllByRole(
        'listitem',
    ) as [HTMLElement, HTMLElement];

    expect(within(api).getByText('Outage')).toBeInTheDocument();
    expect(within(api).getByText(/99.5% observed uptime in the last 90 days/)).toBeInTheDocument();
    expect(
        within(api).getByRole('img', { name: 'Last 2 days: 1 with downtime, 1 without data.' }),
    ).toBeInTheDocument();
    expect(within(api).getByTitle('2026-10-30: no data')).toHaveClass('bg-slate-400');
    expect(
        within(api).getByTitle('2026-10-31: 99.5% uptime, 100% of the day observed'),
    ).toHaveClass('bg-red-600');
    expect(within(website).getByText('Operational')).toBeInTheDocument();
    expect(within(website).getByText('No uptime data in the last 90 days.')).toBeInTheDocument();

    const feed = screen.getByRole('list', { name: 'Published incidents' });

    expect(
        within(feed).getByRole('heading', { name: 'Public API: Monitoring' }),
    ).toBeInTheDocument();
    expect(within(feed).getByText('Fix applied.')).toBeInTheDocument();
    expect(within(feed).getByText('2026-10-31 10:05 UTC')).toHaveAttribute(
        'datetime',
        '2026-10-31T10:05:00Z',
    );

    unmount();
    renderInertia('Signalbox', Public, {
        page: {
            title: 'Acme status',
            description: null,
            theme: { mode: 'light', accent: 'emerald' },
            status: 'unknown',
            components: [],
            incidents: [],
        },
    });

    expect(screen.getByText('This page has no components yet.')).toBeInTheDocument();
    expect(screen.getByRole('status')).toHaveTextContent('Current status not fully known');
    expect(screen.getByText('Nothing published in the last 90 days.')).toBeInTheDocument();
    expect(screen.getByRole('main')).toHaveClass('bg-white');
});

test('posts the password to the address of the page', async () => {
    const user = userEvent.setup();
    const visit = spyOnVisits();
    renderInertia('Signalbox', Unlock, { slug: 'acme-status', errors: {} });

    expect(screen.getByRole('heading')).toHaveTextContent('This status page is protected');

    await user.type(screen.getByLabelText('Password'), 'secret-value');
    await user.click(screen.getByRole('button', { name: 'Open page' }));

    const [url, options] = visit.mock.calls[0] as [URL | string, Record<string, unknown>];

    expect(String(url)).toContain('/status/acme-status/unlock');
    expect(options.method).toBe('post');
    expect(options.data).toEqual({ password: 'secret-value' });
});
