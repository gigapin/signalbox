/** Verify private settings through actual Inertia forms, identity changes and owned transport outcomes. */
import { router } from '@inertiajs/react';
import { act, fireEvent, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { expect, test, vi } from 'vitest';
import StatusPages from '../../resources/js/pages/StatusPages/Index';
import Team from '../../resources/js/pages/Teams/Show';
import Ownership from '../../resources/js/pages/Teams/Ownership';
import Security from '../../resources/js/pages/Account/Security';
import { renderInertia } from './renderInertia';

/** Safe account identity shared by settings responses. */
const auth = { user: { name: 'Synthetic owner', email: 'owner@example.test' } };
/** Authorized route team, unrelated to any saved account preference. */
const team = { id: 7, name: 'Synthetic team', ownerId: 1 };
/** Public DNS proof; it is never a credential or proof that HTTPS is deployed. */
const domain = {
    hostname: 'status.example.com',
    proofName: '_signalbox.status.example.com',
    proofValue: 'signalbox-verification=synthetic',
    verifiedUntil: null,
    checkedAt: null,
};
/** Existing private manager projection with no stored password. */
const statusPage = {
    id: 9,
    slug: 'public-status',
    title: 'Service status',
    path: '/status/public-status',
    description: null,
    isPublic: true,
    hasPassword: true,
    theme: { mode: 'dark', accent: 'blue' },
    domain,
    components: [{ name: 'API', monitors: [{ id: 2, name: 'Private API' }] }],
};
/** At quota, so tests edit the existing page without a second creation form. */
const statusProps = {
    auth,
    team,
    pages: [statusPage],
    monitors: [{ id: 2, name: 'Private API' }],
    themeChoices: { modes: ['system', 'light', 'dark'], accents: ['blue', 'violet'] },
    limits: {
        pages: 1,
        used: 1,
        titleMaxLength: 120,
        descriptionMaxLength: 500,
        componentNameMaxLength: 100,
        componentsPerPage: 50,
        monitorsPerComponent: 100,
        passwordMinLength: 8,
    },
    can: { manage: true },
};
/** Editable persisted membership; the draft may differ from a later accepted role projection. */
const member = {
    id: 2,
    name: 'Operator',
    email: 'operator@example.test',
    role: 'member',
    manageable: true,
};
/** Current manager capabilities, without bearer invitation data. */
const teamProps = {
    auth,
    team,
    actorId: 1,
    roles: ['owner', 'admin', 'member', 'viewer'],
    members: [member],
    invitations: [],
};
/** Credential-free enrolled state. */
const securityProps = { auth, twoFactorEnabled: true, twoFactorPending: false };
/** Installed Inertia callbacks captured at the actual adapter boundary. */
type Visit = NonNullable<Parameters<typeof router.visit>[1]>;
/** Installed POST callbacks for named security and invitation endpoints. */
type Post = NonNullable<Parameters<typeof router.post>[2]>;

/** Replace a legitimate server snapshot while preserving same-page state, without a network request. */
async function replace(props: Record<string, unknown>, url: string): Promise<void> {
    vi.spyOn(window, 'scrollTo').mockImplementation(() => undefined);
    await act(async () => {
        await Promise.resolve(
            router.replace({
                url,
                props: { appName: 'Signalbox', errors: {}, ...props },
                preserveState: true,
            }),
        );
    });
}

/** A controlled read intentionally ignores abort, exercising the caller's late-response fence. */
function deferredRead() {
    let complete: ((response: Response) => void) | undefined;
    const read = vi.spyOn(globalThis, 'fetch').mockImplementation(
        () =>
            new Promise<Response>((resolve) => {
                complete = resolve;
            }),
    );
    return { read, complete: (body: unknown) => complete?.(new Response(JSON.stringify(body))) };
}

test('status search hides an editor without discarding its private password draft', async () => {
    const user = userEvent.setup();
    renderInertia('Signalbox', StatusPages, statusProps);
    expect(screen.getByText(/Private management\. Draft addresses return 404/)).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Edit Service status' }));
    const password = screen.getByLabelText('New password');
    await user.type(password, 'synthetic-password');
    await user.type(screen.getByLabelText('Find a status page'), 'missing');
    expect(password).not.toBeVisible();
    expect(screen.getByText('No status pages match this search.')).toBeVisible();
    await user.clear(screen.getByLabelText('Find a status page'));
    expect(password).toHaveValue('synthetic-password');
});

test('a status-page write sends once and preserves all draft fields after an uncertain finish', async () => {
    const user = userEvent.setup();
    let callbacks: Visit | undefined;
    const visit = vi.spyOn(router, 'visit').mockImplementation((_url, options) => {
        callbacks = options;
    });
    renderInertia('Signalbox', StatusPages, statusProps);
    await user.click(screen.getByRole('button', { name: 'Edit Service status' }));
    await user.type(screen.getByLabelText('Title'), ' draft');
    await user.type(screen.getByLabelText('New password'), 'synthetic-password');
    await user.dblClick(screen.getByRole('button', { name: 'Save page' }));
    expect(visit).toHaveBeenCalledOnce();
    act(() => callbacks?.onFinish?.({} as never));
    expect(screen.getByText('Outcome not confirmed')).toBeInTheDocument();
    expect(screen.getByLabelText('Title')).toHaveValue('Service status draft');
    expect(screen.getByLabelText('New password')).toHaveValue('synthetic-password');
    expect(screen.getByLabelText('Title')).toBeDisabled();
    await user.click(screen.getByRole('button', { name: 'I checked the saved data' }));
    expect(screen.getByLabelText('Title')).toBeEnabled();
    expect(visit).toHaveBeenCalledOnce();
});

test('a saved status-page edit closes and clears its ephemeral password', async () => {
    const user = userEvent.setup();
    let callbacks: Visit | undefined;
    vi.spyOn(router, 'visit').mockImplementation((_url, options) => {
        callbacks = options;
    });
    renderInertia('Signalbox', StatusPages, statusProps);
    await user.click(screen.getByRole('button', { name: 'Edit Service status' }));
    await user.type(screen.getByLabelText('New password'), 'synthetic-password');
    await user.click(screen.getByRole('button', { name: 'Save page' }));
    act(() => {
        callbacks?.onSuccess?.({} as never);
        callbacks?.onFinish?.({} as never);
    });
    expect(screen.queryByLabelText('New password')).not.toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Edit Service status' }));
    expect(screen.getByLabelText('New password')).toHaveValue('');
});

test('a dirty hostname survives a different saved hostname while pristine fields rebase', async () => {
    const user = userEvent.setup();
    renderInertia('Signalbox', StatusPages, statusProps, {
        component: 'StatusPages/Index',
        url: '/teams/7/status-pages',
    });
    await user.clear(screen.getByLabelText('Hostname'));
    await user.type(screen.getByLabelText('Hostname'), 'draft.example.com');
    await replace(
        {
            ...statusProps,
            pages: [{ ...statusPage, domain: { ...domain, hostname: 'saved.example.com' } }],
        },
        '/teams/7/status-pages',
    );
    expect(screen.getByLabelText('Hostname')).toHaveValue('draft.example.com');
});

test('a pristine hostname follows a new server claim without an unsaved departure warning', async () => {
    renderInertia('Signalbox', StatusPages, statusProps, {
        component: 'StatusPages/Index',
        url: '/teams/7/status-pages',
    });
    await replace(
        {
            ...statusProps,
            pages: [{ ...statusPage, domain: { ...domain, hostname: 'saved.example.com' } }],
        },
        '/teams/7/status-pages',
    );
    expect(screen.getByLabelText('Hostname')).toHaveValue('saved.example.com');
    const unload = new Event('beforeunload', { cancelable: true });
    fireEvent(window, unload);
    expect(unload.defaultPrevented).toBe(false);
});

test('domain removal refusal remains visible and duplicate hostname writes remain blocked', async () => {
    const user = userEvent.setup();
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    let callbacks: Visit | undefined;
    const visit = vi.spyOn(router, 'visit').mockImplementation((_url, options) => {
        callbacks = options;
    });
    renderInertia('Signalbox', StatusPages, statusProps);
    await user.dblClick(screen.getByRole('button', { name: 'Remove hostname' }));
    expect(visit).toHaveBeenCalledOnce();
    act(() => {
        callbacks?.onError?.({ domain: 'Removal refused' });
        callbacks?.onFinish?.({} as never);
    });
    expect(screen.getByText('Removal refused')).toBeInTheDocument();
    expect(screen.getByLabelText('Hostname')).toHaveValue(domain.hostname);
});

test('dirty member role survives another accepted role change and submits its actual draft', async () => {
    const user = userEvent.setup();
    renderInertia('Signalbox', Team, teamProps, { component: 'Teams/Show', url: '/teams/7' });
    await user.selectOptions(screen.getByLabelText('Role for Operator'), 'viewer');
    await replace({ ...teamProps, members: [{ ...member, role: 'admin' }] }, '/teams/7');
    expect(screen.getByLabelText('Role for Operator')).toHaveValue('viewer');
    const patch = vi.spyOn(router, 'patch').mockImplementation(() => undefined);
    await user.click(screen.getByRole('button', { name: 'Save role for Operator' }));
    expect(patch).toHaveBeenCalledWith(
        '/teams/7/members/2',
        { role: 'viewer' },
        expect.any(Object),
    );
});

test('pristine member role follows the saved value without creating a draft', async () => {
    renderInertia('Signalbox', Team, teamProps, { component: 'Teams/Show', url: '/teams/7' });
    await replace({ ...teamProps, members: [{ ...member, role: 'admin' }] }, '/teams/7');
    expect(screen.getByLabelText('Role for Operator')).toHaveValue('admin');
    const unload = new Event('beforeunload', { cancelable: true });
    fireEvent(window, unload);
    expect(unload.defaultPrevented).toBe(false);
});

test('a role removed from current choices must be replaced explicitly before saving', async () => {
    const user = userEvent.setup();
    renderInertia('Signalbox', Team, teamProps, { component: 'Teams/Show', url: '/teams/7' });
    await user.selectOptions(screen.getByLabelText('Role for Operator'), 'owner');
    await replace({ ...teamProps, roles: ['member', 'viewer'] }, '/teams/7');
    expect(screen.getByRole('button', { name: 'Save role for Operator' })).toBeDisabled();
    expect(
        screen.getByRole('option', { name: /owner \(choose a permitted role\)/ }),
    ).toBeDisabled();
    await user.selectOptions(screen.getByLabelText('Role for Operator'), 'viewer');
    expect(screen.getByRole('button', { name: 'Save role for Operator' })).toBeEnabled();
});

test('invitation draft survives refusal, freezes during pending and clears only after acceptance', async () => {
    const user = userEvent.setup();
    let callbacks: Post | undefined;
    const post = vi.spyOn(router, 'post').mockImplementation((_url, _data, options) => {
        callbacks = options;
    });
    renderInertia('Signalbox', Team, teamProps);
    await user.type(screen.getByLabelText('Invitation email'), 'new@example.test');
    await user.dblClick(screen.getByRole('button', { name: 'Send invitation' }));
    expect(post).toHaveBeenCalledOnce();
    expect(screen.getByLabelText('Invitation email')).toBeDisabled();
    act(() => {
        callbacks?.onError?.({ email: 'Recipient refused' });
        callbacks?.onFinish?.({} as never);
    });
    expect(screen.getByLabelText('Invitation email')).toHaveValue('new@example.test');
    await user.click(screen.getByRole('button', { name: 'Send invitation' }));
    act(() => {
        callbacks?.onSuccess?.({} as never);
        callbacks?.onFinish?.({} as never);
    });
    expect(screen.getByLabelText('Invitation email')).toHaveValue('');
});

test('management revocation removes role and invitation drafts and their departure guards', async () => {
    const user = userEvent.setup();
    renderInertia('Signalbox', Team, teamProps, { component: 'Teams/Show', url: '/teams/7' });
    await user.selectOptions(screen.getByLabelText('Role for Operator'), 'viewer');
    await user.type(screen.getByLabelText('Invitation email'), 'draft@example.test');
    await replace(
        { ...teamProps, roles: [], members: [{ ...member, manageable: false }] },
        '/teams/7',
    );
    expect(screen.queryByLabelText('Role for Operator')).not.toBeInTheDocument();
    expect(screen.queryByLabelText('Invitation email')).not.toBeInTheDocument();
    const unload = new Event('beforeunload', { cancelable: true });
    fireEvent(window, unload);
    expect(unload.defaultPrevented).toBe(false);
});

test('ownership transfer requires explicit confirmation and blocks repeated submissions', async () => {
    const user = userEvent.setup();
    const confirm = vi.spyOn(window, 'confirm').mockReturnValueOnce(false).mockReturnValue(true);
    const post = vi.spyOn(router, 'post').mockImplementation(() => undefined);
    renderInertia('Signalbox', Ownership, {
        auth,
        team,
        owners: [{ id: 2, name: 'Second owner' }],
    });
    await user.selectOptions(screen.getByLabelText('New primary owner'), '2');
    await user.click(screen.getByRole('button', { name: 'Transfer ownership' }));
    expect(post).not.toHaveBeenCalled();
    await user.dblClick(screen.getByRole('button', { name: 'Transfer ownership' }));
    expect(confirm).toHaveBeenCalledTimes(2);
    expect(post).toHaveBeenCalledOnce();
});

test('hiding pending recovery codes aborts the reveal and ignores a late credential response', async () => {
    const user = userEvent.setup();
    const response = deferredRead();
    renderInertia('Signalbox', Security, securityProps);
    await user.click(screen.getByRole('button', { name: 'Show recovery codes' }));
    const signal = response.read.mock.calls[0]?.[1]?.signal;
    await user.click(screen.getByRole('button', { name: 'Hide recovery codes' }));
    expect(signal?.aborted).toBe(true);
    await act(async () => {
        response.complete(['late-private-code']);
        await Promise.resolve();
    });
    expect(screen.queryByText('late-private-code')).not.toBeInTheDocument();
});

test('account replacement disposes a pending read and never renders the previous credentials', async () => {
    const user = userEvent.setup();
    const response = deferredRead();
    renderInertia('Signalbox', Security, securityProps, {
        component: 'Account/Security',
        url: '/account/security',
    });
    await user.click(screen.getByRole('button', { name: 'Show recovery codes' }));
    const signal = response.read.mock.calls[0]?.[1]?.signal;
    await replace(
        {
            ...securityProps,
            auth: { user: { name: 'Different account', email: 'different@example.test' } },
        },
        '/account/security',
    );
    expect(signal?.aborted).toBe(true);
    await act(async () => {
        response.complete(['old-account-code']);
        await Promise.resolve();
    });
    expect(screen.queryByText('old-account-code')).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Account menu' })).toHaveTextContent(
        'Different account',
    );
});

test('sensitive reads are single-flight and setup refresh preserves the typed authenticator code', async () => {
    const user = userEvent.setup();
    const read = vi
        .spyOn(globalThis, 'fetch')
        .mockResolvedValue(new Response('{}', { status: 500 }));
    renderInertia('Signalbox', Security, { auth, twoFactorEnabled: false, twoFactorPending: true });
    await user.type(screen.getByLabelText('Authentication code'), '123456');
    await user.dblClick(screen.getByRole('button', { name: 'Show authenticator setup' }));
    await waitFor(() =>
        expect(screen.getByText(/Unable to load authenticator setup/)).toBeInTheDocument(),
    );
    expect(read).toHaveBeenCalledTimes(2);
    expect(screen.getByLabelText('Authentication code')).toHaveValue('123456');
});

test('two expired paired setup reads request password confirmation only once and retain no key', async () => {
    const user = userEvent.setup();
    vi.spyOn(globalThis, 'fetch').mockImplementation(() =>
        Promise.resolve(new Response('{}', { status: 423 })),
    );
    const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
    renderInertia('Signalbox', Security, { auth, twoFactorEnabled: false, twoFactorPending: true });
    await user.click(screen.getByRole('button', { name: 'Show authenticator setup' }));
    expect(visit).toHaveBeenCalledOnce();
    expect(visit).toHaveBeenCalledWith('/account/security');
    expect(screen.queryByRole('img')).not.toBeInTheDocument();
});

test('account access denial removes the private content after a credential read', async () => {
    const user = userEvent.setup();
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response('{}', { status: 403 }));
    renderInertia('Signalbox', Security, securityProps);
    await user.click(screen.getByRole('button', { name: 'Show recovery codes' }));
    expect(await screen.findByText('Private data hidden')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Show recovery codes' })).not.toBeInTheDocument();
    expect(screen.queryByText('Synthetic owner')).not.toBeInTheDocument();
});

test('refused authenticator confirmation preserves its code and accepted confirmation clears it', async () => {
    const user = userEvent.setup();
    let callbacks: Post | undefined;
    vi.spyOn(router, 'post').mockImplementation((_url, _data, options) => {
        callbacks = options;
    });
    renderInertia('Signalbox', Security, { auth, twoFactorEnabled: false, twoFactorPending: true });
    await user.type(screen.getByLabelText('Authentication code'), '123456');
    await user.click(screen.getByRole('button', { name: 'Confirm authenticator' }));
    act(() => {
        callbacks?.onError?.({ code: 'Code refused' });
        callbacks?.onFinish?.({} as never);
    });
    expect(screen.getByLabelText('Authentication code')).toHaveValue('123456');
    await user.click(screen.getByRole('button', { name: 'Confirm authenticator' }));
    act(() => {
        callbacks?.onSuccess?.({} as never);
        callbacks?.onFinish?.({} as never);
    });
    expect(screen.getByLabelText('Authentication code')).toHaveValue('');
});

test('regeneration requires confirmation and never fetches codes after an uncertain write', async () => {
    const user = userEvent.setup();
    vi.spyOn(window, 'confirm').mockReturnValueOnce(false).mockReturnValue(true);
    let callbacks: Post | undefined;
    const post = vi.spyOn(router, 'post').mockImplementation((_url, _data, options) => {
        callbacks = options;
    });
    const read = vi.spyOn(globalThis, 'fetch');
    renderInertia('Signalbox', Security, securityProps);
    await user.click(screen.getByRole('button', { name: 'Regenerate recovery codes' }));
    expect(post).not.toHaveBeenCalled();
    await user.dblClick(screen.getByRole('button', { name: 'Regenerate recovery codes' }));
    expect(post).toHaveBeenCalledOnce();
    act(() => callbacks?.onFinish?.({} as never));
    expect(screen.getByText('Outcome not confirmed')).toBeInTheDocument();
    expect(read).not.toHaveBeenCalled();
    await user.click(screen.getByRole('button', { name: 'I checked the saved data' }));
    expect(post).toHaveBeenCalledOnce();
});

test('a mutation access denial removes the complete account credential subtree', async () => {
    const user = userEvent.setup();
    vi.spyOn(router, 'post').mockImplementation(() => undefined);
    renderInertia('Signalbox', Security, {
        auth,
        twoFactorEnabled: false,
        twoFactorPending: false,
    });
    await user.click(screen.getByRole('button', { name: 'Enable two-factor authentication' }));
    act(() => {
        document.dispatchEvent(
            new CustomEvent('inertia:invalid', {
                cancelable: true,
                detail: {
                    response: { status: 403, config: { url: '/user/two-factor-authentication' } },
                },
            }),
        );
    });
    expect(screen.getByText('Private data hidden')).toBeInTheDocument();
    expect(screen.queryByRole('heading', { name: 'Authenticator' })).not.toBeInTheDocument();
});

test('an enrollment state replacement disposes the previous authenticator-code draft', async () => {
    const user = userEvent.setup();
    renderInertia(
        'Signalbox',
        Security,
        { auth, twoFactorEnabled: false, twoFactorPending: true },
        { component: 'Account/Security', url: '/account/security' },
    );
    await user.type(screen.getByLabelText('Authentication code'), '123456');
    await replace(securityProps, '/account/security');
    expect(screen.queryByLabelText('Authentication code')).not.toBeInTheDocument();
    await replace({ auth, twoFactorEnabled: false, twoFactorPending: true }, '/account/security');
    expect(screen.getByLabelText('Authentication code')).toHaveValue('');
});

test('explicit logout removes revealed account credentials and private identity', async () => {
    const user = userEvent.setup();
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(
        new Response(JSON.stringify(['revealed-private-code'])),
    );
    renderInertia('Signalbox', Security, securityProps, {
        component: 'Account/Security',
        url: '/account/security',
    });
    await user.click(screen.getByRole('button', { name: 'Show recovery codes' }));
    expect(await screen.findByText('revealed-private-code')).toBeInTheDocument();
    await replace({ ...securityProps, auth: { user: null } }, '/account/security');
    expect(screen.queryByText('revealed-private-code')).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Account menu' })).not.toBeInTheDocument();
    expect(screen.getByText('Private data hidden')).toBeInTheDocument();
});
