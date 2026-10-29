/** Rotate and reveal a webhook signing secret once, keeping it in ephemeral component state only. */
import { useEffect, useRef, useState } from 'react';
import { csrfHeader } from '../csrf';

/** Control inputs. */
interface SigningSecretProps {
    /** Channel path; the rotation endpoint is below it. */
    path: string;
}

/** Message shown for any failure, without echoing server details. */
const FAILURE = 'Unable to rotate the signing secret. Reload the page and check your permissions.';

/**
 * The secret is fetched through a same-origin JSON mutation so it never enters Inertia props, history or the
 * session. Rotating invalidates the previous secret immediately, so the action asks for confirmation.
 */
export default function SigningSecret({ path }: SigningSecretProps) {
    const [secret, setSecret] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const pending = useRef<AbortController | null>(null);

    /** Cancel a pending rotation and forget the secret when leaving the page. */
    useEffect(() => {
        return () => pending.current?.abort();
    }, []);

    /** @returns Completion after the secret is shown, the request is cancelled or a failure is announced. */
    async function rotate() {
        if (
            pending.current ||
            !window.confirm(
                'Create a new signing secret? The current one stops working immediately.',
            )
        ) {
            return;
        }

        setSecret(null);
        setError('');
        setBusy(true);
        const operation = new AbortController();
        pending.current = operation;

        try {
            const response = await fetch(`${path}/signing-secret`, {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                signal: operation.signal,
                headers: { Accept: 'application/json', 'X-XSRF-TOKEN': csrfHeader(FAILURE) },
            });

            if (!response.ok || response.redirected) {
                throw new Error(FAILURE);
            }

            const body: unknown = await response.json();

            if (
                typeof body !== 'object' ||
                body === null ||
                !('secret' in body) ||
                typeof body.secret !== 'string' ||
                !/^[a-f0-9]{64}$/.test(body.secret)
            ) {
                throw new Error(FAILURE);
            }

            if (!operation.signal.aborted) {
                setSecret(body.secret);
            }
        } catch {
            if (!operation.signal.aborted) {
                setError(FAILURE);
            }
        } finally {
            if (pending.current === operation) {
                pending.current = null;
            }

            if (!operation.signal.aborted) {
                setBusy(false);
            }
        }
    }

    return (
        <div className="mt-2">
            <button
                type="button"
                disabled={busy}
                onClick={() => void rotate()}
                className="rounded border border-slate-600 px-3 py-1 text-sm disabled:opacity-50"
            >
                {busy ? 'Rotating…' : 'Create new signing secret'}
            </button>
            {secret !== null && (
                <div className="mt-2 rounded border border-amber-700 p-3" role="status">
                    <p className="text-sm text-amber-200">
                        Copy this secret now. It is shown only once; use it to verify the signature
                        of each request.
                    </p>
                    <code className="mt-1 block break-all">{secret}</code>
                    <button
                        type="button"
                        onClick={() => setSecret(null)}
                        className="mt-2 text-sm underline"
                    >
                        Hide secret
                    </button>
                </div>
            )}
            {error && (
                <p role="alert" className="mt-1 text-red-300">
                    {error}
                </p>
            )}
        </div>
    );
}
