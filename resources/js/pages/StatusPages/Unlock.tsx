/** Ask a visitor for the password of a protected status page. */
import { useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import StatusPageHead from '../../components/status-pages/StatusPageHead';
import type { StatusPageMetadata } from '../../components/status-pages/types';

/** Locked props contain only the address and generic metadata, excluding protected page content. */
interface UnlockProps {
    /** Public address segment. */
    slug: string;
    /** Generic crawler policy without protected page data. */
    metadata?: StatusPageMetadata;
    /** Inertia page props are an open record. */
    [key: string]: unknown;
}

/** The title is generic on purpose: the page's own title is part of what the password protects. */
export default function Unlock() {
    const { slug, metadata } = usePage<UnlockProps>().props;
    const form = useForm({ password: '' });

    /** @param event Explicit password submission, retaining normal CSRF protection. */
    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post(`/status/${slug}/unlock`, { onFinish: () => form.reset('password') });
    }

    return (
        <>
            <StatusPageHead metadata={metadata} fallbackTitle="Protected status page" />
            <main className="min-h-screen bg-slate-950 px-6 py-12 text-slate-100">
                <div className="mx-auto max-w-lg">
                    <h1 className="text-2xl font-semibold">This status page is protected</h1>
                    <p className="mt-2 text-slate-300">Enter the password to read it.</p>
                    <form onSubmit={submit} noValidate className="mt-6 grid gap-4">
                        <div>
                            <label
                                htmlFor="status-page-password"
                                className="mb-2 block text-sm font-medium"
                            >
                                Password
                            </label>
                            <input
                                id="status-page-password"
                                type="password"
                                required
                                autoComplete="current-password"
                                value={form.data.password}
                                onChange={(event) => form.setData('password', event.target.value)}
                                aria-invalid={form.errors.password !== undefined}
                                aria-describedby="status-page-password-error"
                                className="w-full rounded border border-slate-600 bg-slate-900 px-3 py-2 focus:outline-2 focus:outline-emerald-400"
                            />
                            {form.errors.password && (
                                <p
                                    id="status-page-password-error"
                                    role="alert"
                                    className="mt-1 text-red-300"
                                >
                                    {form.errors.password}
                                </p>
                            )}
                        </div>
                        <button
                            disabled={form.processing}
                            className="justify-self-start rounded bg-emerald-400 px-4 py-2 font-semibold text-slate-950 disabled:opacity-50"
                        >
                            {form.processing ? 'Checking…' : 'Open page'}
                        </button>
                    </form>
                </div>
            </main>
        </>
    );
}
