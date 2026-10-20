/** Exercise real Inertia forms, second-factor method switching and ephemeral security details. */
import { router } from '@inertiajs/react';
import { act, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { expect, test, vi } from 'vitest';
import Login from '../../resources/js/pages/Auth/Login';
import Register from '../../resources/js/pages/Auth/Register';
import TwoFactorChallenge from '../../resources/js/pages/Auth/TwoFactorChallenge';
import VerifyEmail from '../../resources/js/pages/Auth/VerifyEmail';
import Security from '../../resources/js/pages/Account/Security';
import Overview from '../../resources/js/pages/Account/Overview';
import { renderInertia } from './renderInertia';

/** Submit the actual login useForm through a mocked transport boundary, retaining its validation state. */
async function submitsLoginAndShowsErrors(): Promise<void> {
    /** User-event session models actual keyboard and text input. */
    const user = userEvent.setup();
    /** Captured Inertia callbacks allow deterministic success/error without HTTP traffic. */
    let callbacks: Parameters<typeof router.post>[2];
    /** Router spy preserves the form hook while intercepting its network boundary. */
    const post = vi.spyOn(router, 'post').mockImplementation((_url, _data, options) => {
        callbacks = options;
        options?.onStart?.({} as Parameters<NonNullable<typeof options.onStart>>[0]);
    });
    renderInertia('Signalbox', Login);
    expect(screen.getByLabelText('Email')).toHaveFocus();
    await user.type(screen.getByLabelText('Email'), 'owner@example.test');
    await user.type(screen.getByLabelText('Password'), 'invalid-password');
    await user.click(screen.getByLabelText('Remember me'));
    await user.click(screen.getByRole('button', { name: 'Sign in' }));
    expect(post).toHaveBeenCalledWith(
        '/login',
        expect.objectContaining({
            email: 'owner@example.test',
            password: 'invalid-password',
            remember: '1',
        }),
        expect.any(Object),
    );
    expect(screen.getByRole('button', { name: 'Please wait…' })).toBeDisabled();
    act(() => {
        callbacks?.onError?.({ email: 'These credentials do not match our records.' });
        callbacks?.onFinish?.(
            {} as Parameters<NonNullable<NonNullable<typeof callbacks>['onFinish']>>[0],
        );
    });
    expect(screen.getByRole('alert')).toHaveTextContent(
        'These credentials do not match our records.',
    );
    expect(screen.getByLabelText('Email')).toHaveAttribute('aria-invalid', 'true');
    expect(screen.getByLabelText('Password')).toHaveValue('');
}

/** Verify registration asks for team presentation only and exposes account navigation. */
function describesRegistrationInputs(): void {
    renderInertia('Signalbox', Register);
    expect(screen.getByLabelText('Your name')).toHaveFocus();
    expect(screen.getByLabelText('Team name')).toHaveAttribute('autocomplete', 'organization');
    expect(screen.getByLabelText('Email')).toHaveAttribute('type', 'email');
    expect(screen.getByLabelText('Password (12–128 characters)')).toHaveAttribute(
        'autocomplete',
        'new-password',
    );
    expect(screen.queryByLabelText(/slug/i)).not.toBeInTheDocument();
    expect(screen.getByRole('link', { name: /Already have an account/ })).toHaveAttribute(
        'href',
        '/login',
    );
}

/** Switching second-factor methods removes the previous credential field and its value. */
async function switchesChallengeMethods(): Promise<void> {
    /** User session for interacting with the challenge controls. */
    const user = userEvent.setup();
    renderInertia('Signalbox', TwoFactorChallenge);
    await user.type(screen.getByLabelText('Authentication code'), '123456');
    await user.tab();
    await user.tab();
    await user.tab();
    expect(screen.getByRole('button', { name: 'Use a recovery code' })).toHaveFocus();
    await user.keyboard('{Enter}');
    expect(screen.queryByLabelText('Authentication code')).not.toBeInTheDocument();
    expect(screen.getByLabelText('Recovery code')).toHaveValue('');
    expect(screen.getByLabelText('Recovery code')).toHaveFocus();
    await user.type(screen.getByLabelText('Recovery code'), 'private-recovery');
    await user.click(screen.getByRole('button', { name: 'Use an authentication code' }));
    expect(screen.getByLabelText('Authentication code')).toHaveValue('');
}

/** Verification resend and logout use CSRF-managed POST actions instead of navigation GETs. */
async function resendsVerification(): Promise<void> {
    /** User session exercising the verification action. */
    const user = userEvent.setup();
    /** Prevent the real form transport from sending an email or network request. */
    const post = vi.spyOn(router, 'post').mockImplementation(() => undefined);
    renderInertia('Signalbox', VerifyEmail, {
        status: 'A new verification link has been sent to your email.',
    });
    expect(screen.getByRole('status')).toHaveTextContent('A new verification link');
    await user.click(screen.getByRole('button', { name: 'Resend verification email' }));
    expect(post).toHaveBeenCalledWith('/email/verification-notification', {}, expect.any(Object));
}

/** Recovery credentials are fetched only on demand, hidden explicitly and absent on remount. */
async function keepsRecoveryCodesEphemeral(): Promise<void> {
    /** User session for security controls. */
    const user = userEvent.setup();
    /** Sensitive endpoint double; no actual HTTP request occurs. */
    const fetch = vi.spyOn(globalThis, 'fetch').mockResolvedValue(
        new Response(JSON.stringify(['fixture-recovery-code']), {
            headers: { 'Content-Type': 'application/json' },
        }),
    );
    /** Mounted security page with only non-secret setup flags. */
    const view = renderInertia('Signalbox', Security, {
        twoFactorEnabled: true,
        twoFactorPending: false,
    });
    expect(fetch).not.toHaveBeenCalled();
    expect(screen.queryByText('fixture-recovery-code')).not.toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Show recovery codes' }));
    await waitFor(() => expect(screen.getByText('fixture-recovery-code')).toBeVisible());
    expect(fetch).toHaveBeenCalledWith(
        '/user/two-factor-recovery-codes',
        expect.objectContaining({ cache: 'no-store', credentials: 'same-origin' }),
    );
    await user.click(screen.getByRole('button', { name: 'Hide recovery codes' }));
    expect(screen.queryByText('fixture-recovery-code')).not.toBeInTheDocument();
    view.unmount();
    renderInertia('Signalbox', Security, { twoFactorEnabled: true, twoFactorPending: false });
    expect(screen.queryByText('fixture-recovery-code')).not.toBeInTheDocument();
}

/** Failed or expired security loads show a safe error and initiate password confirmation through the server. */
async function handlesExpiredSecurityAccess(): Promise<void> {
    /** User session triggering an explicit sensitive fetch. */
    const user = userEvent.setup();
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response('{}', { status: 423 }));
    /** Prevent live navigation while retaining the routing decision under test. */
    const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
    renderInertia('Signalbox', Security, { twoFactorEnabled: true, twoFactorPending: false });
    await user.click(screen.getByRole('button', { name: 'Show recovery codes' }));
    await waitFor(() =>
        expect(screen.getByRole('alert')).toHaveTextContent('Unable to load recovery codes'),
    );
    expect(visit).toHaveBeenCalledWith('/account/security');
    expect(screen.queryByRole('region', { name: 'Recovery codes' })).not.toBeInTheDocument();
}

/** An existing account without an accessible team has an explicit, non-misleading empty state. */
function showsMissingTeam(): void {
    renderInertia('Signalbox', Overview, {
        auth: { user: { name: 'Example', email: 'owner@example.test' } },
        currentTeam: null,
    });
    expect(screen.getByText('No accessible team is currently selected.')).toBeVisible();
    expect(screen.getByRole('link', { name: 'Account security' })).toHaveAttribute(
        'href',
        '/account/security',
    );
}

test(
    'submits login, prevents duplicate submission and displays accessible field errors',
    submitsLoginAndShowsErrors,
);
test(
    'collects registration and team names without a user-selected slug',
    describesRegistrationInputs,
);
test('clears credentials when changing second-factor methods', switchesChallengeMethods);
test('resends verification with an explicit POST action', resendsVerification);
test(
    'loads recovery codes on demand and clears them on hide or remount',
    keepsRecoveryCodesEphemeral,
);
test(
    'handles expired password confirmation without exposing recovery data',
    handlesExpiredSecurityAccess,
);
test('shows an explicit empty state when a team is unavailable', showsMissingTeam);
