/** Request double opt-in email updates without exposing whether a mailbox already subscribed. */
import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

/** Subscription URL comes from the live page, outside its cached content. */
interface SubscribeFormProps {
    /** Relative same-origin subscription endpoint. */
    path: string;
    /** Generic server acknowledgement after any accepted request. */
    status?: string | undefined;
}

/** Render an accessible email form with progress, validation feedback and generic success. */
export default function SubscribeForm({ path, status }: SubscribeFormProps) {
    const form = useForm({ email: '' });

    /**
     * @param event Browser form submission, intercepted for the CSRF-aware Inertia request.
     */
    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post(path, { preserveScroll: true, onSuccess: () => form.reset() });
    }

    return (
        <section aria-labelledby="subscribe-heading" className="mt-10">
            <h2 id="subscribe-heading" className="text-xl font-semibold">
                Email updates
            </h2>
            <p className="mt-2">
                Receive published incident updates after confirming your email. Unsubscribe at any
                time.
            </p>
            {status && (
                <p role="status" className="mt-2">
                    {status}
                </p>
            )}
            <form onSubmit={submit} className="mt-4 grid gap-2">
                <label htmlFor="subscriber-email">Email address</label>
                <input
                    id="subscriber-email"
                    type="email"
                    autoComplete="email"
                    required
                    maxLength={254}
                    value={form.data.email}
                    onChange={(event) => form.setData('email', event.target.value)}
                    aria-invalid={Boolean(form.errors.email)}
                    aria-describedby={form.errors.email ? 'subscriber-email-error' : undefined}
                    className="rounded border border-slate-400 bg-transparent p-2"
                />
                {form.errors.email && (
                    <p id="subscriber-email-error" role="alert">
                        {form.errors.email}
                    </p>
                )}
                <button
                    type="submit"
                    disabled={form.processing}
                    className="w-fit rounded border border-slate-400 px-4 py-2"
                >
                    {form.processing ? 'Requesting…' : 'Subscribe to updates'}
                </button>
            </form>
        </section>
    );
}
