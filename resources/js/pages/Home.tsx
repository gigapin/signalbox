/** Render the initial public entry point without invented monitoring data. */
import { Head, usePage } from '@inertiajs/react';

/** Shared public props supplied by the Laravel Inertia middleware. */
interface HomePageProps {
    /** Public product name configured by the server. */
    appName: string;
    /** Standard protocol props provided by Inertia and its middleware. */
    [key: string]: unknown;
}

/**
 * Present the product identity while authenticated features are implemented.
 * @returns Accessible initial application page.
 */
export default function Home() {
    /** Public product identity received through the Inertia response. */
    const { appName } = usePage<HomePageProps>().props;

    return (
        <>
            <Head title={appName} />
            <main className="flex min-h-screen items-center bg-slate-950 px-6 py-16 text-slate-100">
                <section aria-labelledby="page-title" className="mx-auto w-full max-w-3xl">
                    <p className="mb-5 text-sm font-semibold uppercase tracking-widest text-emerald-400">
                        Application monitoring
                    </p>
                    <h1
                        id="page-title"
                        className="text-5xl font-semibold tracking-tight sm:text-7xl"
                    >
                        {appName}
                    </h1>
                    <p className="mt-6 max-w-xl text-lg leading-8 text-slate-300">
                        A clear view of your services, incidents, and public status.
                    </p>
                    <p className="mt-10 border-t border-slate-800 pt-6 text-sm text-slate-400">
                        The application is being built. Monitoring and account features will follow.
                    </p>
                </section>
            </main>
        </>
    );
}
