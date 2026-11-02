/** Confirm signed email capabilities without mutating consent when an email scanner follows a link. */
import { Head, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';

/** Generic capability props deliberately exclude mailbox, page title and tenant data. */
interface SubscriptionProps {
    /** Closed action authorized by the signed URL. */
    action: 'verify' | 'unsubscribe';
    /** Relative URL retaining its revocable signature and optional expiry. */
    actionUrl: string;
    /** True only after the visitor explicitly submits the form. */
    completed: boolean;
    /** Inertia props are an open record. */
    [key: string]: unknown;
}

/** Render keyboard-accessible confirmation and terminal success without exposing any private page content. */
export default function Subscription() {
    const { action, actionUrl, completed } = usePage<SubscriptionProps>().props;
    const form = useForm({});
    const title = action === 'verify' ? 'Confirm email updates' : 'Unsubscribe from updates';

    /** @param event Explicit visitor consent submitted through Inertia with CSRF protection. */
    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post(actionUrl);
    }

    return (
        <>
            <Head title={title}>
                <meta name="robots" content="noindex" />
            </Head>
            <main className="mx-auto max-w-xl px-6 py-12">
                <h1 className="text-2xl font-semibold">{title}</h1>
                {completed ? (
                    <p role="status" className="mt-4">
                        {action === 'verify'
                            ? 'Your email is confirmed. You will receive future published updates.'
                            : 'You are unsubscribed. No further updates will be scheduled.'}
                    </p>
                ) : (
                    <form onSubmit={submit} className="mt-4">
                        <p>
                            {action === 'verify'
                                ? 'Confirm that you want to receive published updates for this status page.'
                                : 'Confirm that you want to stop receiving updates for this status page.'}
                        </p>
                        <button
                            type="submit"
                            disabled={form.processing}
                            className="mt-4 rounded border border-slate-400 px-4 py-2"
                        >
                            {form.processing ? 'Saving…' : title}
                        </button>
                    </form>
                )}
            </main>
        </>
    );
}
