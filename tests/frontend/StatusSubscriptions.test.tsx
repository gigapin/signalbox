/** Exercise email subscription and explicit mailbox capability confirmation through real Inertia forms. */
import { router } from '@inertiajs/react';
import { act, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, expect, test, vi } from 'vitest';
import SubscribeForm from '../../resources/js/components/status-pages/SubscribeForm';
import Subscription from '../../resources/js/pages/StatusPages/Subscription';
import { renderInertia } from './renderInertia';

/** Mount the public form with the live server-projected path and a generic acknowledgement. */
function PublicSubscription() {
    return (
        <SubscribeForm
            path="/status/acme-status/subscribe"
            status="Check your inbox to confirm updates."
        />
    );
}

afterEach(() => vi.restoreAllMocks());

test('submits an email with loading feedback and shows the generic acknowledgement', async () => {
    const user = userEvent.setup();
    const visit = vi.spyOn(router, 'visit').mockImplementation((_url, options) => {
        options?.onStart?.({} as Parameters<NonNullable<typeof options.onStart>>[0]);
    });
    renderInertia('Signalbox', PublicSubscription);
    expect(screen.getByRole('status')).toHaveTextContent('Check your inbox to confirm updates.');
    await user.type(screen.getByLabelText('Email address'), 'reader@example.test');
    await user.click(screen.getByRole('button', { name: 'Subscribe to updates' }));
    expect(visit).toHaveBeenCalledWith(
        '/status/acme-status/subscribe',
        expect.objectContaining({
            method: 'post',
            data: { email: 'reader@example.test' },
            preserveScroll: true,
        }),
    );
    expect(screen.getByRole('button', { name: 'Requesting…' })).toBeDisabled();
});

test('associates server email errors with the input', async () => {
    const user = userEvent.setup();
    const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
    renderInertia('Signalbox', PublicSubscription);
    await user.type(screen.getByLabelText('Email address'), 'reader@example.test');
    await user.click(screen.getByRole('button', { name: 'Subscribe to updates' }));
    act(() => visit.mock.calls[0]?.[1]?.onError?.({ email: 'Choose a valid email address.' }));
    expect(screen.getByRole('alert')).toHaveTextContent('Choose a valid email address.');
    expect(screen.getByLabelText('Email address')).toHaveAttribute('aria-invalid', 'true');
    expect(screen.getByLabelText('Email address')).toHaveAccessibleDescription(
        'Choose a valid email address.',
    );
});

test.each(['verify', 'unsubscribe'] as const)(
    'requires an explicit button press for %s and retains the signed URL',
    async (action) => {
        const user = userEvent.setup();
        const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
        const path = `/status/subscriptions/1/2/3/generation/${action}?signature=capability`;
        renderInertia('Signalbox', Subscription, { action, actionUrl: path, completed: false });
        expect(visit).not.toHaveBeenCalled();
        await user.click(
            screen.getByRole('button', {
                name: action === 'verify' ? 'Confirm email updates' : 'Unsubscribe from updates',
            }),
        );
        expect(visit).toHaveBeenCalledWith(
            path,
            expect.objectContaining({ method: 'post', data: {} }),
        );
    },
);

test.each(['verify', 'unsubscribe'] as const)(
    'shows completion without another form after %s',
    (action) => {
        renderInertia('Signalbox', Subscription, {
            action,
            actionUrl: '/signed-action',
            completed: true,
        });
        expect(screen.getByRole('status')).toHaveTextContent(
            action === 'verify' ? 'Your email is confirmed.' : 'You are unsubscribed.',
        );
        expect(screen.queryByRole('button')).not.toBeInTheDocument();
    },
);
