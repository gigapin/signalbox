/** Verify shared search, pagination and suspended results on the actual account teams page. */
import { screen, within, waitFor, act } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { expect, test, vi } from 'vitest';
import Teams from '../../resources/js/pages/Teams/Index';
import { installDialogSupport } from './dialogSupport';
import { cleanup } from '@testing-library/react';
import { router } from '@inertiajs/react';
import { renderInertia } from './renderInertia';

/** Create safe memberships without granting tenant management capabilities. */
function renderTeams(count: number) {
    return renderInertia('Signalbox', Teams, {
        teams: Array.from({ length: count }, (_, index) => ({
            id: index + 1,
            name: `Team ${index + 1}`,
            role: index === 0 ? 'owner' : 'viewer',
        })),
        currentTeamId: count > 0 ? 1 : null,
    });
}

/** Results retain explicit dashboard links rather than implicitly changing the preference. */
test('shows role and current-team badges with the search and selector in the heading', () => {
    renderTeams(2);
    const heading = screen.getByRole('heading', { name: 'Your teams', level: 1 }).closest('header');
    expect(heading).toContainElement(screen.getByRole('combobox', { name: 'Find a team' }));
    expect(heading).toContainElement(screen.getByRole('combobox', { name: 'Team' }));
    expect(screen.getByRole('link', { name: 'Team 1' })).toHaveAttribute(
        'href',
        '/teams/1/dashboard',
    );
    expect(screen.getByText('Current team')).toHaveAttribute('data-tone', 'success');
    expect(
        within(screen.getByRole('list', { name: 'Your teams' })).getByText('owner'),
    ).toHaveAttribute('data-tone', 'info');
    expect(screen.queryByText(/Each team has its own role/)).not.toBeInTheDocument();
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
});

/** Pagination appears only for multiple result pages. */
test('paginates eleven memberships and filters without changing the current preference', async () => {
    const user = userEvent.setup();
    renderTeams(11);
    const list = screen.getByRole('list', { name: 'Your teams' });
    expect(list.querySelectorAll(':scope > li:not([hidden])')).toHaveLength(10);
    await user.click(screen.getByRole('button', { name: 'Next' }));
    expect(screen.getByRole('link', { name: 'Team 11' })).toBeVisible();
    await user.type(screen.getByRole('combobox', { name: 'Find a team' }), 'missing');
    expect(screen.getByText('No teams match this search.')).toBeVisible();
    expect(screen.getByRole('combobox', { name: 'Team' })).toHaveValue('1');
    await user.clear(screen.getByRole('combobox', { name: 'Find a team' }));
    expect(screen.getByRole('link', { name: 'Team 1' })).toBeVisible();
});

/** The selected suggestion is revealed and receives focus after the DOM update. */
test('reveals a suggested team beyond the first page', async () => {
    const user = userEvent.setup();
    const original = Object.getOwnPropertyDescriptor(HTMLElement.prototype, 'scrollIntoView');
    Object.defineProperty(HTMLElement.prototype, 'scrollIntoView', {
        configurable: true,
        value: vi.fn(),
    });
    try {
        renderTeams(11);
        await user.click(screen.getByRole('combobox', { name: 'Find a team' }));
        await user.click(screen.getByRole('option', { name: 'Team 11' }));
        await waitFor(() => expect(document.getElementById('team-11')).toHaveFocus());
        expect(screen.getByRole('link', { name: 'Team 11' })).toBeVisible();
    } finally {
        if (original) Object.defineProperty(HTMLElement.prototype, 'scrollIntoView', original);
        else Reflect.deleteProperty(HTMLElement.prototype, 'scrollIntoView');
    }
});

/** A failed switch keeps previous private results removed until the explicit review navigation. */
test('suspends results and retains review controls after a refused team switch', async () => {
    const user = userEvent.setup();
    document.cookie = 'XSRF-TOKEN=synthetic';
    vi.mocked(fetch).mockResolvedValue(new Response('{}', { status: 403 }));
    renderTeams(2);
    await user.selectOptions(screen.getByRole('combobox', { name: 'Team' }), '2');
    await user.click(screen.getByRole('button', { name: 'Switch team' }));
    expect(await screen.findByRole('link', { name: 'Check current teams' })).toHaveAttribute(
        'href',
        '/teams',
    );
    expect(screen.queryByRole('list', { name: 'Your teams' })).not.toBeInTheDocument();
    expect(screen.getByRole('combobox', { name: 'Find a team' })).toBeDisabled();
});

/** Card actions address the represented team without persisting a selection. */
test('opens management panels for the card team and links to separate directories', async () => {
    const user = userEvent.setup();
    const get = vi.spyOn(router, 'get').mockImplementation(() => undefined);
    renderTeams(1);
    expect(screen.getByRole('link', { name: 'Members' })).toHaveAttribute(
        'href',
        '/teams/1/members',
    );
    expect(screen.getByRole('link', { name: 'Invitations' })).toHaveAttribute(
        'href',
        '/teams/1/invitations',
    );
    await user.click(screen.getByRole('button', { name: 'Plan and usage' }));
    expect(get).toHaveBeenCalledWith('/teams', { team_id: '1', panel: 'plan' }, expect.any(Object));
});

/** The directory invite modal preserves the draft on dismissal and posts a closed return context. */
test('shares the invitation modal on the team card', async () => {
    const restore = installDialogSupport();
    const user = userEvent.setup();
    try {
        renderInertia('Signalbox', Teams, {
            teams: [{ id: 1, name: 'Alpha', role: 'owner' }],
            currentTeamId: 1,
            panel: {
                kind: 'invite',
                team: { id: 1, name: 'Alpha', ownerId: 1 },
                actorId: 1,
                roles: ['member', 'viewer'],
                planSummary: null,
            },
        });
        const dialog = screen.getByRole('dialog');
        await user.type(within(dialog).getByLabelText('Invitation email'), 'member@example.test');
        await user.click(within(dialog).getByRole('button', { name: 'Close' }));
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(screen.getByLabelText('Invitation email')).toHaveValue('member@example.test');
        await user.click(screen.getByRole('button', { name: 'Invite a member' }));
        expect(within(screen.getByRole('dialog')).getByLabelText('Invitation email')).toHaveValue(
            'member@example.test',
        );
    } finally {
        cleanup();
        restore();
    }
});

/** Uncertain card invitations must offer the actual directory as their review destination. */
test('reviews an uncertain card invitation on Teams without replaying the write', async () => {
    const restore = installDialogSupport();
    const user = userEvent.setup();
    let callbacks: Parameters<typeof router.visit>[1];
    const visit = vi.spyOn(router, 'visit').mockImplementation((_url, options) => {
        callbacks = options;
    });
    try {
        renderInertia('Signalbox', Teams, {
            teams: [{ id: 1, name: 'Alpha', role: 'owner' }],
            currentTeamId: 1,
            panel: {
                kind: 'invite',
                team: { id: 1, name: 'Alpha', ownerId: 1 },
                actorId: 1,
                roles: ['member'],
                planSummary: null,
            },
        });
        await user.type(screen.getByLabelText('Invitation email'), 'member@example.test');
        await user.click(screen.getByRole('button', { name: 'Send invitation' }));
        act(() => callbacks?.onFinish?.({} as never));
        expect(screen.getByRole('link', { name: 'Review saved data (new tab)' })).toHaveAttribute(
            'href',
            '/teams',
        );
        expect(visit).toHaveBeenCalledOnce();
    } finally {
        cleanup();
        restore();
    }
});
