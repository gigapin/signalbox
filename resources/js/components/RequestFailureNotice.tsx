/** Report recoverable Inertia transport failures without exposing request data or retrying mutations. */
import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

/**
 * @param exception Error emitted after Inertia has handled HTTP responses and cancellations.
 * @returns Whether the browser transport failed without a response; programming errors remain unhandled.
 */
function isTransportFailure(exception: unknown): boolean {
    return (
        typeof exception === 'object' &&
        exception !== null &&
        'isAxiosError' in exception &&
        exception.isAxiosError === true &&
        'code' in exception &&
        typeof exception.code === 'string' &&
        ['ERR_NETWORK', 'ECONNABORTED', 'ETIMEDOUT'].includes(exception.code) &&
        'request' in exception &&
        exception.request instanceof XMLHttpRequest &&
        (!('response' in exception) || exception.response === undefined)
    );
}

/**
 * Render a viewport-visible alert for private and public pages without consuming their props.
 * A missing response cannot establish whether a mutation committed, so never retry or reload automatically.
 * Inertia still finishes the visit and releases form processing; successful navigation or dismissal clears the alert.
 * @returns A connection alert, or null when no transport failure needs attention.
 */
export default function RequestFailureNotice() {
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        const stopException = router.on('exception', (event) => {
            if (isTransportFailure(event.detail.exception)) {
                event.preventDefault();
                setFailed(true);
            }
        });
        const stopSuccess = router.on('success', () => setFailed(false));

        return () => {
            stopException();
            stopSuccess();
        };
    }, []);

    if (!failed) {
        return null;
    }

    return (
        <div
            role="alert"
            className="fixed inset-x-4 top-4 z-50 mx-auto flex max-w-2xl items-start gap-4 rounded border border-amber-600 bg-slate-950 p-4 text-amber-100 shadow-lg"
        >
            <p className="min-w-0">
                We couldn&apos;t confirm the request. Check your connection, then reload to check
                the latest data before trying again.
            </p>
            <button
                type="button"
                aria-label="Dismiss connection error"
                onClick={() => setFailed(false)}
                className="shrink-0 underline focus:outline-2 focus:outline-amber-200"
            >
                Dismiss
            </button>
        </div>
    );
}
