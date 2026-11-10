/** Verify confirmed team selection, private-content suspension and transport lifecycle boundaries. */
import { router, usePage } from '@inertiajs/react';
import { act, fireEvent, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, expect, test, vi } from 'vitest';
import AppLayout from '../../resources/js/components/app/AppLayout';
import TeamSwitcher from '../../resources/js/components/app/TeamSwitcher';
import type { TeamNavigationView } from '../../resources/js/components/app/navigation';
import type { AuthProps } from '../../resources/js/components/auth/AuthLayout';
import Account from '../../resources/js/pages/Account/Overview';
import Dashboard from '../../resources/js/pages/Dashboard/Show';
import { openTeamStream } from '../../resources/js/realtime/teamStream';
import type { TeamStreamCallbacks } from '../../resources/js/realtime/types';
import { dashboard } from './dashboardFixture';
import { renderInertia } from './renderInertia';

vi.mock('../../resources/js/realtime/teamStream', () => ({ openTeamStream: vi.fn() }));

/** Current memberships whose individual roles differ from the saved preference. */
const navigation: TeamNavigationView = {
    teams: [
        { id: 12, name: 'Alpha', role: 'viewer' },
        { id: 13, name: 'Beta', role: 'owner' },
    ],
    selectedTeamId: 13,
};

/** Safe current identity shared by all isolated layout fixtures. */
const auth = { user: { name: 'Viewer', email: 'viewer@example.test' } };

beforeEach(() => {
    document.cookie = 'XSRF-TOKEN=synthetic';
    vi.mocked(openTeamStream).mockReset();
});
afterEach(() => {
    document.cookie = 'XSRF-TOKEN=; Max-Age=0';
});

/** Render the same production boundary used by the dashboard. */
function LayoutFixture() {
    return (
        <AppLayout title="Dashboard" team={{ id: 12, name: 'Alpha' }} section="dashboard">
            <p>Private monitor results</p>
        </AppLayout>
    );
}

/** Render the account variant without implicitly choosing a membership. */
function AccountSelectorFixture() {
    const { teamNavigation } = usePage<AuthProps>().props;
    if (!teamNavigation) return null;
    return (
        <TeamSwitcher navigation={teamNavigation} activeTeamId={teamNavigation.selectedTeamId} />
    );
}

/** Choose Beta through the native form, without assuming a mutation occurs when changing the select. */
async function chooseBeta(): Promise<void> {
    const user = userEvent.setup();
    await user.selectOptions(screen.getByRole('combobox', { name: 'Team' }), '13');
    await user.click(screen.getByRole('button', { name: 'Switch team' }));
}

test('shows the route membership role even when the saved team has stronger privileges', () => {
    renderInertia('Signalbox', LayoutFixture, { auth, teamNavigation: navigation });
    expect(screen.getByText('Role: viewer')).toBeVisible();
    expect(screen.getByRole('combobox', { name: 'Team' })).toHaveValue('12');
    expect(screen.getByRole('button', { name: 'Switch team' })).toBeDisabled();
    expect(screen.queryByText('Role: owner')).not.toBeInTheDocument();
});

test('hides old private data immediately and sends a single CSRF-protected POST before navigating', async () => {
    let complete: ((response: Response) => void) | undefined;
    const request = vi.mocked(fetch).mockImplementation(
        () =>
            new Promise<Response>((resolve) => {
                complete = resolve;
            }),
    );
    const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
    renderInertia('Signalbox', LayoutFixture, { auth, teamNavigation: navigation });
    await chooseBeta();
    expect(screen.queryByText('Private monitor results')).not.toBeInTheDocument();
    expect(screen.queryByText('Role: viewer')).not.toBeInTheDocument();
    expect(screen.queryByRole('navigation', { name: 'Team navigation' })).not.toBeInTheDocument();
    expect(screen.getByRole('combobox')).toBeDisabled();
    expect(visit).not.toHaveBeenCalled();
    const pending = screen.getByRole('button', { name: 'Switching team…' });
    await userEvent.setup().click(pending);
    expect(request).toHaveBeenCalledTimes(1);
    const headers = new Headers(request.mock.calls[0]?.[1]?.headers);
    expect(headers.get('X-XSRF-TOKEN')).toBe('synthetic');
    expect(headers.get('X-Requested-With')).toBe('XMLHttpRequest');
    expect(request).toHaveBeenCalledWith(
        '/teams/13/select',
        expect.objectContaining({
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
        }),
    );
    await act(async () => {
        await Promise.resolve();
        complete?.(
            new Response(JSON.stringify({ destination: '/teams/13/dashboard' }), { status: 200 }),
        );
    });
    expect(visit).toHaveBeenCalledWith(
        '/teams/13/dashboard',
        expect.objectContaining({ preserveState: false, preserveScroll: false }),
    );
});

test.each([403, 404, 419, 500])(
    'keeps private data hidden on HTTP %s and requires a read instead of replay',
    async (status) => {
        const request = vi.mocked(fetch).mockResolvedValue(new Response('{}', { status }));
        const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
        renderInertia('Signalbox', LayoutFixture, { auth, teamNavigation: navigation });
        await chooseBeta();
        expect(await screen.findByRole('alert')).toHaveTextContent('could not be confirmed');
        expect(screen.getByRole('link', { name: 'Check current teams' })).toHaveAttribute(
            'href',
            '/teams',
        );
        expect(screen.queryByText('Private monitor results')).not.toBeInTheDocument();
        expect(visit).not.toHaveBeenCalled();
        window.dispatchEvent(new Event('online'));
        expect(request).toHaveBeenCalledTimes(1);
    },
);

test.each([
    '{}',
    '{"destination":"https://untrusted.example.test"}',
    '{"destination":"/teams/12/dashboard"}',
    'not-json',
])('rejects an unacknowledged or foreign destination %s', async (body) => {
    vi.mocked(fetch).mockResolvedValue(new Response(body, { status: 200 }));
    const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
    renderInertia('Signalbox', LayoutFixture, { auth, teamNavigation: navigation });
    await chooseBeta();
    expect(await screen.findByRole('alert')).toHaveTextContent('could not be confirmed');
    expect(visit).not.toHaveBeenCalled();
});

test('handles a lost response without exposing the old content or automatically retrying', async () => {
    const request = vi.mocked(fetch).mockRejectedValue(new TypeError('Private diagnostics'));
    renderInertia('Signalbox', LayoutFixture, { auth, teamNavigation: navigation });
    await chooseBeta();
    expect(await screen.findByRole('alert')).not.toHaveTextContent('Private diagnostics');
    expect(screen.queryByText('Private monitor results')).not.toBeInTheDocument();
    expect(request).toHaveBeenCalledTimes(1);
});

test('aborts a pending switch on unmount and ignores its late acknowledgement', async () => {
    let complete: ((response: Response) => void) | undefined;
    const request = vi.mocked(fetch).mockImplementation(
        () =>
            new Promise<Response>((resolve) => {
                complete = resolve;
            }),
    );
    const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
    const view = renderInertia('Signalbox', LayoutFixture, { auth, teamNavigation: navigation });
    await chooseBeta();
    const signal = request.mock.calls[0]?.[1]?.signal;
    view.unmount();
    expect(signal?.aborted).toBe(true);
    await act(async () => {
        await Promise.resolve();
        complete?.(
            new Response(JSON.stringify({ destination: '/teams/13/dashboard' }), { status: 200 }),
        );
    });
    expect(visit).not.toHaveBeenCalled();
});

test('clears inaccessible route content and does not select another membership as fallback', () => {
    renderInertia('Signalbox', LayoutFixture, {
        auth,
        teamNavigation: { ...navigation, teams: [navigation.teams[1]], selectedTeamId: null },
    });
    expect(screen.queryByText('Private monitor results')).not.toBeInTheDocument();
    expect(screen.getByRole('combobox')).toHaveValue('');
    expect(screen.queryByText('Role: owner')).not.toBeInTheDocument();
});

test('handles no-team and absent preferences without offering a fictional team creation action', () => {
    const view = renderInertia('Signalbox', AccountSelectorFixture, {
        teamNavigation: { teams: [], selectedTeamId: null },
    });
    expect(screen.getByText(/No accessible teams/)).toBeVisible();
    expect(screen.queryByRole('combobox')).not.toBeInTheDocument();
    view.unmount();
    renderInertia('Signalbox', AccountSelectorFixture, {
        teamNavigation: { ...navigation, selectedTeamId: null },
    });
    expect(screen.getByRole('combobox')).toHaveValue('');
    expect(screen.getByRole('button', { name: 'Switch team' })).toBeDisabled();
});

test('provides the verified dashboard entry from account without granting team-management permissions', async () => {
    renderInertia('Signalbox', Account, { auth, currentTeam: { id: 12, name: 'Alpha' } });
    await waitFor(() =>
        expect(screen.getByRole('link', { name: 'Open dashboard' })).toHaveAttribute(
            'href',
            '/teams/12/dashboard',
        ),
    );
});

test('unmounts the real dashboard subscription and aborts old reads before the switch is acknowledged', async () => {
    const close = vi.fn();
    let callbacks: TeamStreamCallbacks | undefined;
    vi.mocked(openTeamStream).mockImplementation((_configuration, _teamId, handlers) => {
        callbacks = handlers;
        return close;
    });
    const request = vi
        .mocked(fetch)
        .mockImplementation(() => new Promise<Response>(() => undefined));
    const view = renderInertia('Signalbox', Dashboard, {
        auth,
        teamNavigation: navigation,
        team: { id: 12, name: 'Alpha' },
        dashboard,
        rejectedFilters: [],
        reportTimezone: 'UTC',
        realtime: { key: 'public', host: 'localhost', port: 8080, scheme: 'http' },
    });
    expect(openTeamStream).toHaveBeenCalledTimes(1);
    expect(screen.getByRole('link', { name: 'Checkout API' })).toBeVisible();
    act(() => callbacks?.subscribed());
    await waitFor(() => expect(request).toHaveBeenCalledTimes(1));
    const oldRead = request.mock.calls[0]?.[1]?.signal;
    expect(oldRead?.aborted).toBe(false);
    await chooseBeta();
    expect(oldRead?.aborted).toBe(true);
    expect(close).toHaveBeenCalledTimes(1);
    expect(screen.queryByText('Checkout API')).not.toBeInTheDocument();
    view.unmount();
});

test('removes the real dashboard and stale role after private-channel authorization is revoked', () => {
    let callbacks: TeamStreamCallbacks | undefined;
    const close = vi.fn();
    vi.mocked(openTeamStream).mockImplementation((_configuration, _teamId, handlers) => {
        callbacks = handlers;
        return close;
    });
    renderInertia('Signalbox', Dashboard, {
        auth,
        teamNavigation: navigation,
        team: { id: 12, name: 'Alpha' },
        dashboard,
        rejectedFilters: [],
        reportTimezone: 'UTC',
        realtime: { key: 'public', host: 'localhost', port: 8080, scheme: 'http' },
    });
    act(() => callbacks?.denied());
    expect(screen.queryByText('Checkout API')).not.toBeInTheDocument();
    expect(screen.queryByText('Role: viewer')).not.toBeInTheDocument();
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('Team selection');
    expect(screen.queryByRole('combobox')).not.toBeInTheDocument();
    expect(close).toHaveBeenCalledTimes(1);
});

test('bounds a hanging request and offers recovery without replay after timeout', async () => {
    vi.useFakeTimers();
    const request = vi.mocked(fetch).mockImplementation(
        (_input, init) =>
            new Promise<Response>((_resolve, reject) => {
                init?.signal?.addEventListener('abort', () =>
                    reject(new DOMException('Aborted', 'AbortError')),
                );
            }),
    );
    renderInertia('Signalbox', LayoutFixture, { auth, teamNavigation: navigation });
    fireEvent.change(screen.getByRole('combobox'), { target: { value: '13' } });
    fireEvent.click(screen.getByRole('button', { name: 'Switch team' }));
    await act(async () => {
        await vi.advanceTimersByTimeAsync(10_000);
    });
    expect(screen.getByRole('alert')).toHaveTextContent('could not be confirmed');
    expect(request).toHaveBeenCalledTimes(1);
});
