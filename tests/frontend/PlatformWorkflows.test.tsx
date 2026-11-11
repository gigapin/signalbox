/** Verify operator isolation, password intent ownership, audit projection and ordinary team entitlements. */
import { router } from '@inertiajs/react';
import { act, fireEvent, screen, within, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { expect, test, vi } from 'vitest';
import Index from '../../resources/js/pages/Admin/Teams/Index';
import Show from '../../resources/js/pages/Admin/Teams/Show';
import Team from '../../resources/js/pages/Teams/Show';
import AuditHistory from '../../resources/js/components/admin/AuditHistory';
import TeamPlanPanel from '../../resources/js/components/billing/TeamPlanPanel';
import { renderInertia } from './renderInertia';

/** Operator identity independent of tenant membership. */
const auth = { user: { name: 'Operator', email: 'operator@example.test', isPlatformAdmin: true } };
/** Safe directory row, with no monitor configuration. */
const summary = {
    id: 9,
    name: 'Synthetic team',
    slug: 'synthetic-team',
    owner: { name: 'Owner', email: 'owner@example.test' },
    storedPlan: 'free',
    entitlement: { plan: 'free', source: 'free', expiresAt: null },
    monitors: 5,
    override: null,
};
/** Current actual detail; a filled audit is provided only by tests that need it. */
const detail = {
    ...summary,
    members: 2,
    trialStartedAt: '2026-09-01T00:00:00Z',
    trialEndsAt: '2026-09-15T00:00:00Z',
    createdAt: '2026-09-01T00:00:00Z',
    usage: { used: 5, limit: 5, minimumIntervalSeconds: 300 },
    events: [],
};
/** Canonical detail props, including a server-normalized directory return. */
const props = {
    auth,
    team: detail,
    options: { kinds: ['unlimited', 'comped'], plans: ['pro', 'business'] },
    reasonMaxLength: 500,
    directoryUrl: '/admin/teams?q=synthetic&page=2',
};
/** A second page of accepted search results. */
const directory = {
    auth,
    filters: { q: 'synthetic' },
    rejectedFilters: [],
    teams: {
        data: [summary],
        total: 26,
        currentPage: 2,
        lastPage: 2,
        perPage: 25,
        from: 26,
        to: 26,
    },
};
/** Member-safe plan data containing no operator-only fields or payment claims. */
const plan = {
    storedPlan: 'free',
    effectivePlan: 'pro',
    source: 'trial',
    expiresAt: '2026-12-01T00:00:00Z',
    trial: { state: 'active', startsAt: '2026-11-17T00:00:00Z', endsAt: '2026-12-01T00:00:00Z' },
    monitors: { used: 5, limit: 50 },
    statusPages: { used: 1, limit: 3 },
    minimumIntervalSeconds: 60,
    rollupRetentionDays: 365,
    generatedAt: '2026-11-18T00:00:00Z',
};
/** Installed write lifecycle behind the actual useForm adapter. */
type Put = NonNullable<Parameters<typeof router.put>[2]>;

/** Install a recent server confirmation without sending any real credentials. */
function recentPassword() {
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    return vi
        .spyOn(globalThis, 'fetch')
        .mockImplementation(() =>
            Promise.resolve(new Response(JSON.stringify({ confirmed: true }))),
        );
}

/** Replace a legitimate server page while preserving component state; authorization still belongs to the server. */
async function replace(next: Record<string, unknown>, url: string): Promise<void> {
    vi.spyOn(window, 'scrollTo').mockImplementation(() => undefined);
    await act(async () => {
        await Promise.resolve(
            router.replace({
                url,
                props: { appName: 'Signalbox', errors: {}, ...next },
                preserveState: true,
            }),
        );
    });
}

/** Start one override with an explicit reason through the native confirmation and actual preflight code. */
async function submitOverride() {
    const user = userEvent.setup();
    await user.click(screen.getByLabelText('Unlimited testing'));
    await user.type(screen.getByLabelText(/^Reason/), 'Keep this draft');
    await user.click(screen.getByRole('button', { name: 'Save override' }));
}

test('operator directory retains accepted search/page in detail links without offering a tenant switcher', () => {
    renderInertia('Signalbox', Index, directory);
    const link = screen.getByRole('link', { name: 'Synthetic team' });
    expect(new URL(link.getAttribute('href')!, location.href).searchParams.get('returnTo')).toBe(
        props.directoryUrl,
    );
    expect(screen.queryByRole('navigation', { name: 'Team navigation' })).not.toBeInTheDocument();
    expect(screen.queryByRole('combobox', { name: 'Team' })).not.toBeInTheDocument();
    expect(screen.getByRole('table', { name: 'Teams and their plans' })).toBeInTheDocument();
});

test('failed directory search retains the draft and previous rows for a manual retry', async () => {
    const user = userEvent.setup();
    let callbacks: NonNullable<Parameters<typeof router.get>[2]> | undefined;
    const read = vi.spyOn(router, 'get').mockImplementation((_url, _data, options) => {
        callbacks = options;
        options?.onStart?.({} as never);
    });
    renderInertia('Signalbox', Index, directory);
    await user.type(screen.getByLabelText('Search'), ' draft');
    await user.dblClick(screen.getByRole('button', { name: 'Search' }));
    expect(read).toHaveBeenCalledOnce();
    act(() => {
        callbacks?.onError?.({ q: 'refused' });
        callbacks?.onFinish?.({} as never);
    });
    expect(screen.getByText('Team search could not be loaded')).toBeInTheDocument();
    expect(screen.getByLabelText('Search')).toHaveValue('synthetic draft');
    expect(screen.getByRole('link', { name: 'Synthetic team' })).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Search' }));
    expect(read).toHaveBeenCalledTimes(2);
});

test('a history-like directory replacement restores its accepted search', async () => {
    renderInertia('Signalbox', Index, directory, {
        component: 'Admin/Teams/Index',
        url: props.directoryUrl,
    });
    fireEvent.change(screen.getByLabelText('Search'), { target: { value: 'unsent' } });
    await replace({ ...directory, filters: { q: 'owner' } }, '/admin/teams?q=owner');
    expect(screen.getByLabelText('Search')).toHaveValue('owner');
});

test('directory pagination remains bounded for a very large requested page', () => {
    renderInertia('Signalbox', Index, {
        ...directory,
        rejectedFilters: ['q'],
        teams: { ...directory.teams, data: [], currentPage: 999999999, lastPage: 1 },
    });
    expect(screen.getByText('Some controls were ignored')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Back to the first page' })).toHaveAttribute(
        'href',
        '/admin/teams?q=synthetic',
    );
    expect(
        within(screen.getByRole('navigation', { name: 'Pagination' })).getAllByRole('link'),
    ).toHaveLength(2);
});

test('override writes carry normalized directory context and preserve uncertain intent without replay', async () => {
    const user = userEvent.setup();
    recentPassword();
    let callbacks: Put | undefined;
    const put = vi.spyOn(router, 'put').mockImplementation((_url, _data, options) => {
        callbacks = options;
    });
    renderInertia('Signalbox', Show, props);
    expect(screen.getByRole('link', { name: 'Back to all teams' })).toHaveAttribute(
        'href',
        props.directoryUrl,
    );
    await submitOverride();
    await waitFor(() => expect(put).toHaveBeenCalledOnce());
    expect(put.mock.calls[0]?.[0]).toBe(
        `/admin/teams/9/plan-override?${new URLSearchParams({ returnTo: props.directoryUrl }).toString()}`,
    );
    act(() => callbacks?.onFinish?.({} as never));
    expect(screen.getByText('Outcome not confirmed')).toBeInTheDocument();
    expect(screen.getByLabelText(/^Reason/)).toHaveValue('Keep this draft');
    expect(screen.getByLabelText(/^Reason/)).toBeDisabled();
    await user.click(screen.getByRole('button', { name: 'I checked the saved data' }));
    expect(put).toHaveBeenCalledOnce();
    expect(screen.getByLabelText(/^Reason/)).toBeEnabled();
});

test('confirmed override acceptance clears only its reason and preserves selected settings', async () => {
    recentPassword();
    let callbacks: Put | undefined;
    const put = vi.spyOn(router, 'put').mockImplementation((_url, _data, options) => {
        callbacks = options;
    });
    renderInertia('Signalbox', Show, props);
    await submitOverride();
    await waitFor(() => expect(put).toHaveBeenCalledOnce());
    act(() => {
        callbacks?.onSuccess?.({} as never);
        callbacks?.onFinish?.({} as never);
    });
    expect(screen.getByLabelText(/^Reason/)).toHaveValue('');
    expect(screen.getByLabelText('Unlimited testing')).toBeChecked();
});

test('a disposed team preflight cannot apply its captured intent to a new inspected team', async () => {
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    let complete: ((response: Response) => void) | undefined;
    const read = vi.spyOn(globalThis, 'fetch').mockImplementation(
        () =>
            new Promise<Response>((resolve) => {
                complete = resolve;
            }),
    );
    const put = vi.spyOn(router, 'put').mockImplementation(() => undefined);
    renderInertia('Signalbox', Show, props, {
        component: 'Admin/Teams/Show',
        url: '/admin/teams/9',
    });
    await submitOverride();
    const signal = read.mock.calls[0]?.[1]?.signal;
    await replace(
        { ...props, team: { ...detail, id: 10, name: 'Other inspected team' } },
        '/admin/teams/10',
    );
    expect(signal?.aborted).toBe(true);
    expect(screen.getByLabelText(/^Reason/)).toHaveValue('');
    await act(async () => {
        complete?.(new Response(JSON.stringify({ confirmed: true })));
        await Promise.resolve();
    });
    expect(put).not.toHaveBeenCalled();
});

test('operator flag revocation tears down a typed password and the captured operation', async () => {
    const user = userEvent.setup();
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    vi.spyOn(globalThis, 'fetch').mockImplementation(() =>
        Promise.resolve(new Response(JSON.stringify({ confirmed: false }))),
    );
    renderInertia('Signalbox', Show, props, {
        component: 'Admin/Teams/Show',
        url: '/admin/teams/9',
    });
    await submitOverride();
    await user.type(await screen.findByLabelText('Current password'), 'temporary-password');
    await replace(
        { ...props, auth: { user: { ...auth.user, isPlatformAdmin: false } } },
        '/admin/teams/9',
    );
    expect(screen.queryByLabelText('Current password')).not.toBeInTheDocument();
    expect(screen.queryByLabelText(/^Reason/)).not.toBeInTheDocument();
    expect(screen.getByText('Private data hidden')).toBeInTheDocument();
    expect(screen.queryByText(detail.owner.email)).not.toBeInTheDocument();
});

test('denied password confirmation removes platform data instead of resuming the override', async () => {
    const user = userEvent.setup();
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    document.cookie = 'XSRF-TOKEN=synthetic';
    vi.spyOn(globalThis, 'fetch')
        .mockResolvedValueOnce(new Response(JSON.stringify({ confirmed: false })))
        .mockResolvedValueOnce(new Response('{}', { status: 403 }));
    const put = vi.spyOn(router, 'put').mockImplementation(() => undefined);
    renderInertia('Signalbox', Show, props);
    await submitOverride();
    await user.type(await screen.findByLabelText('Current password'), 'password{Enter}');
    expect(await screen.findByText('Private data hidden')).toBeInTheDocument();
    expect(put).not.toHaveBeenCalled();
});

test('a mutation access denial removes the audit, inspected identity and override form', async () => {
    recentPassword();
    const put = vi.spyOn(router, 'put').mockImplementation(() => undefined);
    renderInertia('Signalbox', Show, { ...props, directoryUrl: '/admin/teams' });
    await submitOverride();
    await waitFor(() => expect(put).toHaveBeenCalledOnce());
    act(() => {
        document.dispatchEvent(
            new CustomEvent('inertia:invalid', {
                cancelable: true,
                detail: {
                    response: { status: 404, config: { url: '/admin/teams/9/plan-override' } },
                },
            }),
        );
    });
    expect(screen.getByText('Private data hidden')).toBeInTheDocument();
    expect(screen.queryByRole('heading', { name: 'History' })).not.toBeInTheDocument();
    expect(screen.queryByText(detail.owner.email)).not.toBeInTheDocument();
});

test('dirty override choices survive a changed saved override while pristine choices follow it', async () => {
    const user = userEvent.setup();
    renderInertia('Signalbox', Show, props, {
        component: 'Admin/Teams/Show',
        url: '/admin/teams/9',
    });
    await user.click(screen.getByLabelText('Granted Business plan'));
    await user.type(screen.getByLabelText(/^Reason/), 'Keep reason');
    await replace(
        {
            ...props,
            team: {
                ...detail,
                override: {
                    kind: 'unlimited',
                    plan: null,
                    reason: 'Other operator',
                    expiresAt: null,
                    updatedAt: null,
                },
            },
        },
        '/admin/teams/9',
    );
    expect(screen.getByLabelText('Granted Business plan')).toBeChecked();
    expect(screen.getByLabelText(/^Reason/)).toHaveValue('Keep reason');
});

test('pristine override choices rebase without creating an unsaved departure warning', async () => {
    renderInertia('Signalbox', Show, props, {
        component: 'Admin/Teams/Show',
        url: '/admin/teams/9',
    });
    await replace(
        {
            ...props,
            team: {
                ...detail,
                override: {
                    kind: 'unlimited',
                    plan: null,
                    reason: 'Other operator',
                    expiresAt: null,
                    updatedAt: null,
                },
            },
        },
        '/admin/teams/9',
    );
    expect(screen.getByLabelText('Unlimited testing')).toBeChecked();
    const unload = new Event('beforeunload', { cancelable: true });
    fireEvent(window, unload);
    expect(unload.defaultPrevented).toBe(false);
});

test('audit renders known before/after fields and never stringifies arbitrary nested data', () => {
    renderInertia('Signalbox', () => (
        <AuditHistory
            events={[
                {
                    id: 1,
                    action: 'team.plan_override.granted',
                    actor: null,
                    reason: 'Grant',
                    createdAt: null,
                    changes: {
                        before: null,
                        after: {
                            kind: 'comped',
                            plan: 'pro',
                            expires_at: null,
                            secret: 'never-project-this',
                        },
                        token: 'hidden-token',
                    },
                },
            ]}
        />
    ));
    expect(screen.getByText('No override')).toBeInTheDocument();
    expect(screen.getByText('Pro')).toBeInTheDocument();
    expect(screen.queryByText('never-project-this')).not.toBeInTheDocument();
    expect(screen.queryByText('hidden-token')).not.toBeInTheDocument();
    expect(screen.getByText(/command line or deleted account/)).toBeInTheDocument();
});

test('ordinary team members see real plan and quotas without an Admin or payment action', () => {
    renderInertia('Signalbox', Team, {
        auth: { user: { name: 'Viewer', email: 'viewer@example.test', isPlatformAdmin: false } },
        team: { id: 7, name: 'Member team', ownerId: 1 },
        actorId: 3,
        roles: [],
        members: [],
        invitations: [],
        planSummary: plan,
    });
    const panel = screen.getByRole('region', { name: 'Plan and usage' });
    expect(panel).toHaveTextContent('Pro · Trial');
    expect(panel).toHaveTextContent('5 of 50');
    expect(panel).toHaveTextContent('1 of 3');
    expect(panel).toHaveTextContent('60 seconds');
    expect(screen.queryByRole('link', { name: 'Admin' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Pay|Upgrade|Checkout/ })).not.toBeInTheDocument();
});

test('over-quota and internal profiles retain actual finite counts and distinguish stored access from payments', () => {
    const view = renderInertia('Signalbox', () => (
        <TeamPlanPanel
            summary={{
                ...plan,
                effectivePlan: 'internal',
                source: 'unlimited_override',
                expiresAt: null,
                monitors: { used: 1001, limit: 1000 },
            }}
        />
    ));
    expect(screen.getByText(/1001 of 1000/)).toBeInTheDocument();
    expect(screen.getByText(/finite limits shown above/)).toBeInTheDocument();
    view.unmount();
    renderInertia('Signalbox', () => (
        <TeamPlanPanel
            summary={{
                ...plan,
                storedPlan: 'business',
                effectivePlan: 'business',
                source: 'subscription',
                expiresAt: null,
            }}
        />
    ));
    expect(screen.getByText(/does not confirm a payment/)).toBeInTheDocument();
});

test('old member history without plan data asks for reload instead of claiming Free or zero usage', () => {
    renderInertia('Signalbox', TeamPlanPanel);
    expect(screen.getByText(/Plan information is unavailable/)).toBeInTheDocument();
    expect(screen.queryByText('Free')).not.toBeInTheDocument();
});
