/** Exercise connection failures through the real Inertia transport and preserve unrelated exceptions. */
import { useForm } from '@inertiajs/react';
import { act, fireEvent, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { FormEvent } from 'react';
import { expect, test, vi } from 'vitest';
import RequestFailureNotice from '../../resources/js/components/RequestFailureNotice';
import { renderInertia } from './renderInertia';

/** Synthetic request failure fields supplied by the installed Axios browser transport. */
interface TransportException extends Error {
    /** Axios's transport-error marker. */
    isAxiosError: boolean;
    /** Stable failure category rather than the provider's potentially sensitive message. */
    code: string;
    /** Browser request responsible for the failure. */
    request: XMLHttpRequest;
    /** Present only when the server actually returned an HTTP response. */
    response?: { status: number };
}

/**
 * Render an actual Inertia form without server-side domain effects.
 * @returns A private draft form alongside the production connection notice.
 */
function FormFixture() {
    const form = useForm({ message: '' });

    /** @param event Explicit submission; retain the draft unless the server confirms success. */
    function submit(event: FormEvent<HTMLFormElement>): void {
        event.preventDefault();
        form.post('/synthetic-update');
    }

    return (
        <>
            <RequestFailureNotice />
            <form onSubmit={submit}>
                <label htmlFor="draft-message">Message</label>
                <textarea
                    id="draft-message"
                    value={form.data.message}
                    onChange={(event) => form.setData('message', event.target.value)}
                />
                <button disabled={form.processing}>
                    {form.processing ? 'Saving…' : 'Add update'}
                </button>
            </form>
        </>
    );
}

/**
 * @param code Failure category produced by the browser adapter.
 * @returns An error carrying no usable server response, with deliberately sensitive diagnostic text.
 */
function transportException(code = 'ERR_NETWORK'): TransportException {
    return Object.assign(new Error('Private URL, credential and payload must stay hidden.'), {
        name: 'AxiosError',
        isAxiosError: true,
        code,
        request: new XMLHttpRequest(),
    });
}

/**
 * @param exception Error emitted by Inertia after its HTTP/transport handling.
 * @returns The cancelable event, allowing assertions on whether propagation remains enabled.
 */
function exceptionEvent(exception: unknown): CustomEvent<{ exception: unknown }> {
    const event = new CustomEvent('inertia:exception', {
        cancelable: true,
        detail: { exception },
    });

    act(() => {
        document.dispatchEvent(event);
    });

    return event;
}

test('handles a real Inertia XHR failure while preserving the draft and releasing processing', async () => {
    const send = vi.spyOn(XMLHttpRequest.prototype, 'send').mockImplementation(function (
        this: XMLHttpRequest,
    ): void {
        queueMicrotask(() => this.dispatchEvent(new ProgressEvent('error')));
    });
    const user = userEvent.setup();
    renderInertia('Signalbox', FormFixture);
    await user.type(screen.getByLabelText('Message'), 'Keep this private draft');
    await user.click(screen.getByRole('button', { name: 'Add update' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('Check your connection');
    expect(screen.getByLabelText('Message')).toHaveValue('Keep this private draft');
    await waitFor(() => {
        expect(screen.getByRole('button', { name: 'Add update' })).toBeEnabled();
    });
    expect(send).toHaveBeenCalledOnce();
    expect(window.location.pathname).toBe('/');
});

test.each(['ERR_NETWORK', 'ECONNABORTED', 'ETIMEDOUT'])(
    'handles %s without rendering transport details or replaying the request',
    (code) => {
        renderInertia('Signalbox', RequestFailureNotice);
        const event = exceptionEvent(transportException(code));

        expect(event.defaultPrevented).toBe(true);
        expect(screen.getByRole('alert')).toHaveTextContent("We couldn't confirm the request");
        expect(screen.getByRole('alert')).not.toHaveTextContent('Private URL');
    },
);

test('keeps the warning after reconnecting and clears it only on success or explicit dismissal', async () => {
    const user = userEvent.setup();
    renderInertia('Signalbox', RequestFailureNotice);
    exceptionEvent(transportException());
    fireEvent.online(window);
    expect(screen.getByRole('alert')).toBeInTheDocument();

    act(() => {
        document.dispatchEvent(new CustomEvent('inertia:success'));
    });
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();

    exceptionEvent(transportException());
    await user.tab();
    expect(screen.getByRole('button', { name: 'Dismiss connection error' })).toHaveFocus();
    await user.keyboard('{Enter}');
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
});

test('preserves programming errors, cancellations and HTTP-response failures for their own handlers', () => {
    renderInertia('Signalbox', RequestFailureNotice);
    const httpError = transportException();
    httpError.response = { status: 500 };

    for (const error of [
        new TypeError('Application rendering failed'),
        transportException('ERR_CANCELED'),
        httpError,
        Object.assign(new Error('Unrelated network error'), { code: 'ERR_NETWORK' }),
        null,
        undefined,
        'Unexpected non-Error rejection',
    ]) {
        expect(exceptionEvent(error).defaultPrevented).toBe(false);
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    }
});

test('removes both router subscriptions when the notice unmounts', () => {
    const view = renderInertia('Signalbox', RequestFailureNotice);
    view.unmount();

    expect(exceptionEvent(transportException()).defaultPrevented).toBe(false);
});
