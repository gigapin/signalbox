/** Confirm a password inline without navigating away from a pending, explicitly requested operation. */
import { useLeaveProtection } from '../app/NavigationGuard';
import { useContext, useEffect, useRef, useState, type FormEvent } from 'react';
import { TeamAccessContext } from '../app/TeamAccessContext';
import { csrfHeader } from '../csrf';

/** Callbacks retain the caller's non-secret operation draft outside the password form. */
interface PasswordConfirmationProps {
    /** Run the original operation once, only after a validated server acknowledgement. */
    onConfirmed: () => void;
    /** Discard the pending operation without changing the caller's editable draft. */
    onCancel: () => void;
}

/** Safe fallback without response bodies or submitted credentials. */
const FAILURE = 'Unable to confirm your password. Check your connection or sign in again.';

/** Keep credentials in mounted component state and clear them after every attempt or cancellation. */
export default function PasswordConfirmationForm({
    onConfirmed,
    onCancel,
}: PasswordConfirmationProps) {
    const deny = useContext(TeamAccessContext);
    const [password, setPassword] = useState('');
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    useLeaveProtection(password !== '', busy);
    const input = useRef<HTMLInputElement>(null);
    const pending = useRef<AbortController | null>(null);

    /** Focus the prompt after rendering or a failed attempt, once the input is enabled again. */
    useEffect(() => {
        input.current?.focus();
    }, [error]);

    /** Cancel credential work when the caller leaves or cancels. */
    useEffect(() => () => pending.current?.abort(), []);

    /** Submit only the password; the protected operation and its draft remain with the caller. */
    async function confirm(event: FormEvent<HTMLFormElement>): Promise<void> {
        event.preventDefault();
        if (pending.current) return;
        if (password === '') {
            setError('Enter your current password.');
            input.current?.focus();
            return;
        }

        const operation = new AbortController();
        pending.current = operation;
        setBusy(true);
        setError('');

        try {
            const response = await fetch('/user/confirm-password', {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                signal: operation.signal,
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': csrfHeader(FAILURE),
                },
                body: JSON.stringify({ password }),
            });
            if (operation.signal.aborted) return;
            setPassword('');
            if ([401, 403, 404].includes(response.status)) {
                deny();
                return;
            }

            if (response.status === 422) {
                if (!operation.signal.aborted)
                    setError('The password is incorrect. Please try again.');
                return;
            }
            if (!response.ok || response.redirected) throw new Error(FAILURE);
            const body: unknown = await response.json();
            if (
                typeof body !== 'object' ||
                body === null ||
                !('confirmed' in body) ||
                body.confirmed !== true
            ) {
                throw new Error(FAILURE);
            }
            if (!operation.signal.aborted) onConfirmed();
        } catch {
            if (!operation.signal.aborted) {
                setPassword('');
                setError(FAILURE);
                input.current?.focus();
            }
        } finally {
            if (pending.current === operation) pending.current = null;
            if (!operation.signal.aborted) setBusy(false);
        }
    }

    return (
        <section
            aria-labelledby="override-confirm-heading"
            className="mt-6 rounded border border-amber-600 p-4"
        >
            <h3 id="override-confirm-heading" className="font-semibold">
                Confirm your password
            </h3>
            <p id="override-confirm-hint" className="mt-2 text-sm text-slate-300">
                Your change is ready. Confirm your password to apply it without leaving this page.
            </p>
            <form onSubmit={(event) => void confirm(event)} className="mt-4 space-y-3">
                <label htmlFor="override-password" className="block">
                    Current password
                </label>
                <input
                    ref={input}
                    id="override-password"
                    name="password"
                    type="password"
                    autoComplete="current-password"
                    value={password}
                    onChange={(event) => {
                        setPassword(event.target.value);
                        setError('');
                    }}
                    disabled={busy}
                    aria-invalid={error !== ''}
                    aria-describedby={
                        error
                            ? 'override-confirm-hint override-password-error'
                            : 'override-confirm-hint'
                    }
                    className="ui-input"
                />
                {error && (
                    <p id="override-password-error" role="alert" className="text-red-300">
                        {error}
                    </p>
                )}
                <div className="flex gap-4">
                    <button disabled={busy} className="ui-button">
                        {busy ? 'Confirming…' : 'Confirm password and save override'}
                    </button>
                    <button type="button" onClick={onCancel} className="ui-link">
                        Cancel
                    </button>
                </div>
            </form>
        </section>
    );
}
