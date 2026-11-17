/** Ask a visitor for the password of a protected status page. */
import InputField from '../../components/ui/InputField';
import { router, useForm, usePage } from '@inertiajs/react';
import { type FormEvent, useEffect, useRef, useState } from 'react';
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
    const pending = useRef(false);
    const [refusal, setRefusal] = useState<string | null>(null);
    const path = `/status/${slug}/unlock`;

    useEffect(() => {
        return router.on('invalid', (event) => {
            const response = event.detail.response;
            if (
                !pending.current ||
                !response.config.url ||
                new URL(response.config.url, location.href).href !==
                    new URL(path, location.href).href ||
                ![429, 503].includes(response.status)
            )
                return;
            event.preventDefault();
            setRefusal(
                response.status === 429
                    ? 'Too many password attempts. Please wait before trying again.'
                    : 'Password verification is temporarily unavailable. Please try again shortly.',
            );
        });
    }, [path]);

    /** @param event Explicit password submission, retaining normal CSRF protection. */
    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (pending.current) return;
        pending.current = true;
        setRefusal(null);
        form.post(path, {
            onFinish: () => {
                pending.current = false;
                form.reset('password');
            },
        });
    }

    return (
        <>
            <StatusPageHead metadata={metadata} fallbackTitle="Protected status page" />
            <main className="min-h-screen bg-slate-950 px-6 py-12 text-slate-100">
                <div className="mx-auto max-w-lg">
                    <h1 className="text-2xl font-semibold">This status page is protected</h1>
                    <p className="mt-2 text-slate-300">Enter the password to read it.</p>
                    {refusal && (
                        <p role="alert" className="mt-4 text-amber-200">
                            {refusal}
                        </p>
                    )}
                    <form onSubmit={submit} noValidate className="mt-6 grid gap-4">
                        <InputField
                            label="Password"
                            error={form.errors.password}
                            id="status-page-password"
                            type="password"
                            required
                            disabled={form.processing}
                            autoComplete="current-password"
                            value={form.data.password}
                            onChange={(event) => form.setData('password', event.target.value)}
                        />
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
