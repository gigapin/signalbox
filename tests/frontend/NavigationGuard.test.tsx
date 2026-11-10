/** Verify private departure decisions without storing drafts or replaying domain mutations. */
import { Link, router, useForm } from '@inertiajs/react';
import { act, cleanup, fireEvent, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useEffect, useState, type FormEvent } from 'react';
import { afterEach, beforeEach, expect, test, vi } from 'vitest';
import AppLayout from '../../resources/js/components/app/AppLayout';
import PrivateContextLayout from '../../resources/js/components/app/PrivateContextLayout';
import {
    useFormProtection,
    useLeaveProtection,
    useNavigationGuard,
} from '../../resources/js/components/app/NavigationGuard';
import { renderInertia } from './renderInertia';

/** Preserve native prototype descriptors outside each jsdom-only modal double. */
const originalShow = Object.getOwnPropertyDescriptor(HTMLDialogElement.prototype, 'showModal');
/** Restore close after fixtures have been unmounted. */
const originalClose = Object.getOwnPropertyDescriptor(HTMLDialogElement.prototype, 'close');

beforeEach(() => {
    document.cookie = 'XSRF-TOKEN=guard-fixture';
    Object.defineProperty(HTMLDialogElement.prototype, 'showModal', {
        configurable: true,
        value(this: HTMLDialogElement) {
            this.setAttribute('open', '');
        },
    });
    Object.defineProperty(HTMLDialogElement.prototype, 'close', {
        configurable: true,
        value(this: HTMLDialogElement) {
            this.removeAttribute('open');
        },
    });
});
afterEach(() => {
    cleanup();
    if (originalShow) Object.defineProperty(HTMLDialogElement.prototype, 'showModal', originalShow);
    else Reflect.deleteProperty(HTMLDialogElement.prototype, 'showModal');
    if (originalClose) Object.defineProperty(HTMLDialogElement.prototype, 'close', originalClose);
    else Reflect.deleteProperty(HTMLDialogElement.prototype, 'close');
    document.cookie = 'XSRF-TOKEN=; Max-Age=0';
});

/** Server-safe identity and two independent roles, without a persisted draft. */
const identity = {
    auth: { user: { name: 'Member', email: 'member@example.test' } },
    teamNavigation: {
        teams: [
            { id: 12, name: 'Alpha', role: 'member' },
            { id: 13, name: 'Beta', role: 'viewer' },
        ],
        selectedTeamId: 12,
    },
};

/** A real Inertia form whose transport callbacks remain unchanged by navigation protection. */
function DraftForm() {
    const form = useForm({ message: '' });
    useFormProtection(form);
    return (
        <form
            onSubmit={(event: FormEvent) => {
                event.preventDefault();
                form.post('/teams/12/synthetic-save');
            }}
        >
            <label htmlFor="message">Draft message</label>
            <input
                id="message"
                value={form.data.message}
                onChange={(event) => form.setData('message', event.target.value)}
            />
            <button disabled={form.processing}>Save draft</button>
            <Link href="/teams/12/incidents?state=open&page=2">Leave draft</Link>
        </form>
    );
}

/** Keep form lifetime inside the actual removable team shell. */
function TeamFixture() {
    return (
        <AppLayout title="Draft screen" team={{ id: 12, name: 'Alpha' }} section="monitors">
            <DraftForm />
        </AppLayout>
    );
}

/** Represent a credential read or password preflight that is not an Inertia form request. */
function FetchFixture() {
    const [busy, setBusy] = useState(false);
    useLeaveProtection(false, busy);
    const guard = useNavigationGuard();
    return (
        <>
            <button onClick={() => setBusy(true)}>Start request</button>
            <button onClick={() => setBusy(false)}>Finish request</button>
            <Link href="/teams">Leave page</Link>
            <button
                onClick={() =>
                    guard.runAuthorizedNavigation(() =>
                        document.dispatchEvent(
                            new CustomEvent('inertia:before', {
                                cancelable: true,
                                detail: {
                                    visit: {
                                        method: 'get',
                                        url: new URL('/account/security', location.origin),
                                    },
                                },
                            }),
                        ),
                    )
                }
            >
                Reconfirm access
            </button>
        </>
    );
}

test('clean private links navigate without a prompt', async () => {
    const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
    renderInertia('Signalbox', TeamFixture, identity);
    await userEvent.setup().click(screen.getByRole('link', { name: 'Leave draft' }));
    expect(visit).toHaveBeenCalledWith('/teams/12/incidents?state=open&page=2', expect.any(Object));
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
});

test('Stay retains a draft and Discard resumes exactly one original filtered visit', async () => {
    const user = userEvent.setup();
    const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
    renderInertia('Signalbox', TeamFixture, identity);
    await user.type(screen.getByLabelText('Draft message'), 'Private note');
    await user.click(screen.getByRole('link', { name: 'Leave draft' }));
    expect(screen.getByRole('dialog', { name: 'Discard unsaved changes?' })).toBeVisible();
    expect(screen.getByRole('button', { name: 'Stay' })).toHaveFocus();
    expect(visit).not.toHaveBeenCalled();
    await user.click(screen.getByRole('button', { name: 'Stay' }));
    expect(screen.getByLabelText('Draft message')).toHaveValue('Private note');
    await user.click(screen.getByRole('link', { name: 'Leave draft' }));
    await user.click(screen.getByRole('button', { name: 'Discard and leave' }));
    await waitFor(() => expect(visit).toHaveBeenCalledTimes(1));
    expect(visit).toHaveBeenCalledWith('/teams/12/incidents?state=open&page=2', expect.any(Object));
});

test('Escape cancellation retains the draft and restores the departure trigger', async () => {
    const user = userEvent.setup();
    renderInertia('Signalbox', TeamFixture, identity);
    await user.type(screen.getByLabelText('Draft message'), 'Keep me');
    const link = screen.getByRole('link', { name: 'Leave draft' });
    await user.click(link);
    fireEvent(screen.getByRole('dialog'), new Event('cancel', { cancelable: true }));
    expect(screen.getByLabelText('Draft message')).toHaveValue('Keep me');
    expect(link).toHaveFocus();
});

test('changing team asks once and sends no POST until Discard is explicitly chosen', async () => {
    const user = userEvent.setup();
    const request = vi
        .mocked(fetch)
        .mockResolvedValue(
            new Response(JSON.stringify({ destination: '/teams/13/dashboard' }), { status: 200 }),
        );
    const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
    renderInertia('Signalbox', TeamFixture, identity);
    await user.type(screen.getByLabelText('Draft message'), 'Team-specific draft');
    await user.selectOptions(screen.getByRole('combobox'), '13');
    await user.click(screen.getByRole('button', { name: 'Switch team' }));
    expect(request).not.toHaveBeenCalled();
    await user.click(screen.getByRole('button', { name: 'Stay' }));
    expect(screen.getByLabelText('Draft message')).toHaveValue('Team-specific draft');
    await user.click(screen.getByRole('button', { name: 'Switch team' }));
    await user.click(screen.getByRole('button', { name: 'Discard and leave' }));
    await waitFor(() => expect(request).toHaveBeenCalledTimes(1));
    expect(screen.queryByLabelText('Draft message')).not.toBeInTheDocument();
    expect(visit).toHaveBeenCalledWith('/teams/13/dashboard', expect.any(Object));
});

test('pending domain submission blocks navigation and team selection without replaying the mutation', async () => {
    const user = userEvent.setup();
    const post = vi.spyOn(router, 'post').mockImplementation((_url, _data, options) => {
        options?.onStart?.({} as Parameters<NonNullable<typeof options.onStart>>[0]);
    });
    const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
    renderInertia('Signalbox', TeamFixture, identity);
    await user.type(screen.getByLabelText('Draft message'), 'Sending');
    await user.click(screen.getByRole('button', { name: 'Save draft' }));
    expect(post).toHaveBeenCalledTimes(1);
    expect(screen.getByRole('button', { name: 'Switch team' })).toBeDisabled();
    await user.click(screen.getByRole('link', { name: 'Leave draft' }));
    expect(screen.getByText('Finish the current request before leaving this page.')).toBeVisible();
    expect(visit).not.toHaveBeenCalled();
    expect(post).toHaveBeenCalledTimes(1);
});

test('accepted drafts become the form-owned baseline and later edits are protected again', async () => {
    const user = userEvent.setup();
    let callbacks: Parameters<typeof router.post>[2];
    vi.spyOn(router, 'post').mockImplementation((_url, _data, options) => {
        callbacks = options;
    });
    const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
    renderInertia('Signalbox', TeamFixture, identity);
    await user.type(screen.getByLabelText('Draft message'), 'Accepted');
    await user.click(screen.getByRole('button', { name: 'Save draft' }));
    await act(async () => {
        callbacks?.onSuccess?.({} as Parameters<NonNullable<typeof callbacks.onSuccess>>[0]);
        await Promise.resolve();
    });
    await user.click(screen.getByRole('link', { name: 'Leave draft' }));
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect(visit).toHaveBeenCalledTimes(1);
    await user.type(screen.getByLabelText('Draft message'), ' edited');
    await user.click(screen.getByRole('link', { name: 'Leave draft' }));
    expect(screen.getByRole('dialog')).toBeVisible();
});

test('raw pending work blocks departure and full unload without placing fields into history', async () => {
    const user = userEvent.setup();
    const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
    renderInertia('Signalbox', FetchFixture);
    await user.click(screen.getByRole('button', { name: 'Start request' }));
    await user.click(screen.getByRole('link', { name: 'Leave page' }));
    expect(visit).not.toHaveBeenCalled();
    const unload = new Event('beforeunload', { cancelable: true });
    window.dispatchEvent(unload);
    expect(unload.defaultPrevented).toBe(true);
    await user.click(screen.getByRole('button', { name: 'Finish request' }));
    await user.click(screen.getByRole('link', { name: 'Leave page' }));
    expect(visit).toHaveBeenCalledTimes(1);
});

test('history stays on the source entry until a departure is approved and restores native methods on unmount', async () => {
    const user = userEvent.setup();
    const original =
        Object.getOwnPropertyDescriptor(history, 'pushState') ??
        Object.getOwnPropertyDescriptor(History.prototype, 'pushState');
    const nativePush: unknown = original?.value;
    const view = renderInertia('Signalbox', TeamFixture, identity);
    const source = history.state as Record<string, unknown>;
    history.pushState({ page: 'opaque' }, '', '/draft');
    const active = history.state as Record<string, unknown>;
    const go = vi.spyOn(history, 'go').mockImplementation(() => undefined);
    await user.type(screen.getByLabelText('Draft message'), 'Never-store-this-draft');
    expect(JSON.stringify(history.state)).not.toContain('Never-store-this-draft');
    fireEvent.popState(window, { state: source });
    expect(go).toHaveBeenLastCalledWith(1);
    fireEvent.popState(window, { state: active });
    expect(screen.getByRole('dialog')).toBeVisible();
    await user.click(screen.getByRole('button', { name: 'Stay' }));
    expect(screen.getByLabelText('Draft message')).toHaveValue('Never-store-this-draft');
    fireEvent.popState(window, { state: source });
    fireEvent.popState(window, { state: active });
    await user.click(screen.getByRole('button', { name: 'Discard and leave' }));
    await waitFor(() => expect(go).toHaveBeenLastCalledWith(-1));
    view.unmount();
    const restored =
        Object.getOwnPropertyDescriptor(history, 'pushState') ??
        Object.getOwnPropertyDescriptor(History.prototype, 'pushState');
    expect(restored?.value).toBe(nativePush);
});

test.each(['account', 'platform'] as const)(
    'keeps the %s workspace independent of inspected team membership',
    (context) => {
        function ContextFixture() {
            return (
                <PrivateContextLayout title="Workspace" context={context}>
                    <p>Private context content</p>
                </PrivateContextLayout>
            );
        }
        renderInertia('Configured name', ContextFixture, identity);
        expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('Workspace');
        expect(screen.queryByRole('combobox')).not.toBeInTheDocument();
        expect(screen.queryByText('Current team')).not.toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Configured name' })).toHaveAttribute(
            'href',
            '/account',
        );
        expect(
            screen.getByText(context === 'platform' ? 'Platform administration' : 'Account'),
        ).toBeVisible();
    },
);

test('authoritative access loss removes the draft and cancels an outstanding departure decision', async () => {
    let revoke: () => void = () => undefined;
    function RevocableFixture() {
        const [denied, setDenied] = useState(false);
        useEffect(() => {
            revoke = () => setDenied(true);
        }, []);
        return (
            <AppLayout
                title="Private draft"
                team={{ id: 12, name: 'Alpha' }}
                section="monitors"
                accessDenied={denied}
            >
                <DraftForm />
            </AppLayout>
        );
    }
    const user = userEvent.setup();
    const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
    renderInertia('Signalbox', RevocableFixture, identity);
    await user.type(screen.getByLabelText('Draft message'), 'Remove on revocation');
    await user.click(screen.getByRole('link', { name: 'Leave draft' }));
    expect(screen.getByRole('dialog')).toBeVisible();
    act(revoke);
    expect(screen.queryByLabelText('Draft message')).not.toBeInTheDocument();
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(visit).not.toHaveBeenCalled();
});
