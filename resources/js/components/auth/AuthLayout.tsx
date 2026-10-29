/** Provide the shared accessible layout and server status for authentication screens. */
import { Head, Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';

/** Minimal explicit identity projection shared by Laravel. */
export interface AuthProps {
    /** Public product name. */
    appName: string;
    /** Current identity only; never a serialized model or secret. */
    auth?: { user: { name: string; email: string; isPlatformAdmin?: boolean } | null };
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
    /** Widen the content column for tables and multi-column forms; defaults to the narrow form width. */
    wide?: boolean;
}

/** Render a keyboard-accessible shell using the existing dark and emerald palette. */
export default function AuthLayout({ title, description, children, wide = false }: LayoutProps) {
    /** Safe status message supplied by the server for this response. */
    const { status, auth } = usePage<AuthProps>().props;
    return (
        <>
            <Head title={title} />
            <main className="min-h-screen bg-slate-950 px-6 py-12 text-slate-100">
                <div className={wide ? 'mx-auto max-w-5xl' : 'mx-auto max-w-lg'}>
                    <div className="flex items-center justify-between gap-4">
                        <Link href="/" className="font-semibold tracking-widest text-emerald-400">
                            SIGNALBOX
                        </Link>
                        {auth?.user?.isPlatformAdmin === true && (
                            <Link
                                href="/admin/teams"
                                className="text-sm text-emerald-300 underline"
                            >
                                Admin
                            </Link>
                        )}
                    </div>
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
