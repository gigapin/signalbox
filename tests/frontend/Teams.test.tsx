/** Exercise team membership UI through actual Inertia forms with only transport boundaries intercepted. */
import { router } from '@inertiajs/react';
import { act, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { expect, test, vi } from 'vitest';
import Index from '../../resources/js/pages/Teams/Index';
import Invitation from '../../resources/js/pages/Teams/Invitation';
import Register from '../../resources/js/pages/Teams/Register';
import Show from '../../resources/js/pages/Teams/Show';
import Ownership from '../../resources/js/pages/Teams/Ownership';
import { renderInertia } from './renderInertia';

/** Shared safe team projection; individual cases supply only their authorized capabilities. */
const team = { id: 12, name: 'Synthetic team', ownerId: 1 };
/** Minimal verified current-user projection used for matching invitation acceptance. */
const auth = { user: { name: 'Invited User', email: 'invitee@example.test' } };

/** Select another accessible team through keyboard navigation and a CSRF-protected POST. */
async function switchesTeam(): Promise<void> {
    const user = userEvent.setup();

    const post = vi.spyOn(router, 'post').mockImplementation((_url, _data, options) => {
        options?.onStart?.({} as Parameters<NonNullable<typeof options.onStart>>[0]);
    });
    renderInertia('Signalbox', Index, {
        teams: [
            { id: 12, name: 'Alpha', role: 'viewer' },
            { id: 13, name: 'Beta', role: 'member' },
        ],
        currentTeamId: 12,
    });
    expect(screen.queryByRole('button', { name: 'Switch to Alpha' })).not.toBeInTheDocument();
    await user.tab();
    await user.click(screen.getByRole('button', { name: 'Switch to Beta' }));
    expect(post).toHaveBeenCalledWith('/teams/13/select', {}, expect.any(Object));
    expect(screen.getByRole('button', { name: 'Switching…' })).toBeDisabled();
}

/** An invited account with no accepted membership sees a truthful empty state. */
function showsNoTeam(): void {
    renderInertia('Signalbox', Index, { teams: [], currentTeamId: null });
    expect(screen.getByText(/do not belong to any teams yet/)).toBeInTheDocument();
    expect(screen.queryByRole('button')).not.toBeInTheDocument();
}

/** Invitation acceptance is explicit and server validation remains visible after a rejected POST. */
async function acceptsAndShowsErrors(): Promise<void> {
    const user = userEvent.setup();

    let callbacks: Parameters<typeof router.post>[2];

    const post = vi.spyOn(router, 'post').mockImplementation((_url, _data, options) => {
        callbacks = options;
        options?.onStart?.({} as Parameters<NonNullable<typeof options.onStart>>[0]);
    });
    renderInertia('Signalbox', Invitation, {
        auth,
        verified: true,
        invitation: {
            teamName: team.name,
            email: auth.user.email,
            role: 'member',
            accepted: false,
        },
    });
    expect(post).not.toHaveBeenCalled();
    await user.click(screen.getByRole('button', { name: 'Accept invitation' }));
    expect(post).toHaveBeenCalledWith('/invitations/accept', {}, expect.any(Object));
    expect(screen.getByRole('button', { name: 'Joining…' })).toBeDisabled();
    act(() => {
        callbacks?.onError?.({ invitation: 'This invitation has expired.' });
        callbacks?.onFinish?.(
            {} as Parameters<NonNullable<NonNullable<typeof callbacks>['onFinish']>>[0],
        );
    });
    expect(screen.getByRole('alert')).toHaveTextContent('This invitation has expired.');
}

/** A different authenticated identity must switch accounts rather than accepting the wrong email. */
async function switchesIdentity(): Promise<void> {
    const user = userEvent.setup();

    const post = vi.spyOn(router, 'post').mockImplementation(() => undefined);
    renderInertia('Signalbox', Invitation, {
        auth,
        verified: true,
        invitation: {
            teamName: team.name,
            email: 'another@example.test',
            role: 'member',
            accepted: false,
        },
    });
    expect(screen.queryByRole('button', { name: 'Accept invitation' })).not.toBeInTheDocument();
    expect(screen.getByRole('alert')).toHaveTextContent('another@example.test');
    await user.click(screen.getByRole('button', { name: 'Use another account' }));
    expect(post).toHaveBeenCalledWith('/invitations/switch-account', {}, expect.any(Object));
}

/** New invited registrations omit personal-team creation and display capability validation errors. */
async function registersWithoutTeam(): Promise<void> {
    const user = userEvent.setup();

    vi.spyOn(router, 'post').mockImplementation((_url, _data, options) => {
        options?.onError?.({ invitation: 'Request a new invitation.' });
    });
    renderInertia('Signalbox', Register, {
        email: 'invitee@example.test',
        errors: { invitation: 'Request a new invitation.' },
    });
    expect(screen.getByLabelText('Email')).toHaveValue('invitee@example.test');
    expect(screen.queryByLabelText('Team name')).not.toBeInTheDocument();
    await user.type(screen.getByLabelText('Your name'), 'Invited User');
    await user.type(screen.getByLabelText('Password (12–128 characters)'), 'secure-password-123');
    await user.type(screen.getByLabelText('Confirm password'), 'secure-password-123');
    await user.click(screen.getByRole('button', { name: 'Create account' }));
    expect(screen.getByRole('alert')).toHaveTextContent('Request a new invitation.');
}

/** Member/viewer projections reveal no invitation or role-management form. */
function respectsReadOnlyCapabilities(): void {
    renderInertia('Signalbox', Show, {
        team,
        actorId: 3,
        roles: [],
        members: [
            {
                id: 3,
                name: 'Viewer',
                email: 'viewer@example.test',
                role: 'viewer',
                manageable: false,
            },
        ],
        invitations: [],
    });
    expect(screen.queryByRole('button', { name: 'Send invitation' })).not.toBeInTheDocument();
    expect(screen.queryByRole('combobox')).not.toBeInTheDocument();
    expect(
        screen.queryByRole('link', { name: 'Transfer primary ownership' }),
    ).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Leave team' })).toBeInTheDocument();
}

/** Admin invitation controls expose only permitted roles and disable repeated delivery attempts. */
async function invitesWithAllowedRoles(): Promise<void> {
    const user = userEvent.setup();

    const post = vi.spyOn(router, 'post').mockImplementation((_url, _data, options) => {
        options?.onStart?.({} as Parameters<NonNullable<typeof options.onStart>>[0]);
    });
    renderInertia('Signalbox', Show, {
        team,
        actorId: 2,
        roles: ['member', 'viewer'],
        members: [],
        invitations: [],
    });
    expect(screen.queryByRole('option', { name: 'owner' })).not.toBeInTheDocument();
    await user.type(screen.getByLabelText('Invitation email'), 'new@example.test');
    await user.selectOptions(screen.getByLabelText('Invitation role'), 'viewer');
    await user.click(screen.getByRole('button', { name: 'Send invitation' }));
    expect(post).toHaveBeenCalledWith(
        '/teams/12/invitations',
        { email: 'new@example.test', role: 'viewer' },
        expect.any(Object),
    );
    expect(screen.getByRole('button', { name: 'Sending…' })).toBeDisabled();
}

/** Destructive departure requires confirmation; cancelling causes no request. */
async function confirmsDeparture(): Promise<void> {
    const user = userEvent.setup();

    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);

    const remove = vi.spyOn(router, 'delete').mockImplementation(() => undefined);
    renderInertia('Signalbox', Show, { team, actorId: 3, roles: [], members: [], invitations: [] });
    await user.click(screen.getByRole('button', { name: 'Leave team' }));
    expect(remove).not.toHaveBeenCalled();
    confirm.mockReturnValue(true);
    await user.click(screen.getByRole('button', { name: 'Leave team' }));
    expect(remove).toHaveBeenCalledWith('/teams/12/members/3', expect.any(Object));
}

/** Ownership transfer uses only an eligible existing owner and requires explicit confirmation. */
async function transfersOwnership(): Promise<void> {
    const user = userEvent.setup();
    vi.spyOn(window, 'confirm').mockReturnValue(true);

    const post = vi.spyOn(router, 'post').mockImplementation(() => undefined);
    renderInertia('Signalbox', Ownership, { team, owners: [{ id: 4, name: 'Second owner' }] });
    await user.selectOptions(screen.getByLabelText('New primary owner'), '4');
    await user.click(screen.getByRole('button', { name: 'Transfer ownership' }));
    expect(post).toHaveBeenCalledWith('/teams/12/ownership', { user_id: '4' }, expect.any(Object));
}

/** Invalid bearer links have no invitation metadata or acceptance controls. */
function showsInvalidLink(): void {
    renderInertia('Signalbox', Invitation, { invitation: null, verified: false });
    expect(screen.getByRole('alert')).toHaveTextContent('unavailable or expired');
    expect(screen.queryByRole('button', { name: 'Accept invitation' })).not.toBeInTheDocument();
}

test('switches accessible teams with a loading state', switchesTeam);
test('shows an empty state before invitation acceptance', showsNoTeam);
test('accepts explicitly and displays capability errors', acceptsAndShowsErrors);
test('offers account switching for a different invitation email', switchesIdentity);
test('registers an invited identity without a personal team', registersWithoutTeam);
test('hides management controls from read-only members', respectsReadOnlyCapabilities);
test('invites only permitted roles and prevents duplicate submissions', invitesWithAllowedRoles);
test('requires confirmation before leaving a team', confirmsDeparture);
test('transfers primary ownership to an existing owner explicitly', transfersOwnership);
test('shows invalid invitations without leaking metadata', showsInvalidLink);
