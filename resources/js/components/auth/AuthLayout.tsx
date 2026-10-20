/** Provide the shared accessible layout and server status for authentication screens. */
import { Head, Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';

/** Minimal explicit identity projection shared by Laravel. */
export interface AuthProps {
    /** Public product name. */
    appName: string;
    /** Current identity only; never a serialized model or secret. */
    auth?: { user: { name: string; email: string } | null };
    /** Safe server acknowledgement after an authentication transition. */
    status?: string | null;
    /** Additional page-specific protocol data. */
    [key: string]: unknown;
}

/** Parameters for a single authentication or account screen. */
interface LayoutProps {
    /** Screen heading and document title. */
    title: string;
    /** Short description of the current step. */
    description?: string;
    /** Form, account details or second-factor management controls. */
    children: ReactNode;
}

/** Render a keyboard-accessible shell using the existing dark and emerald palette. */
export default function AuthLayout({ title, description, children }: LayoutProps) {
    /** Safe status message supplied by the server for this response. */
    const { status } = usePage<AuthProps>().props;
    return (
        <>
            <Head title={title} />
            <main className="min-h-screen bg-slate-950 px-6 py-12 text-slate-100">
                <div className="mx-auto max-w-lg">
                    <Link href="/" className="font-semibold tracking-widest text-emerald-400">
                        SIGNALBOX
                    </Link>
                    <h1 className="mt-10 text-3xl font-semibold">{title}</h1>
                    {description && <p className="mt-3 text-slate-300">{description}</p>}
                    {status && (
                        <p
                            role="status"
                            className="mt-5 rounded border border-emerald-800 p-3 text-emerald-200"
                        >
                            {status}
                        </p>
                    )}
                    <section className="mt-8" aria-label={title}>
                        {children}
                    </section>
                </div>
            </main>
        </>
    );
}
