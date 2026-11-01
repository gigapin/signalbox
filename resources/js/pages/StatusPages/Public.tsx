/** Show a published status page to anonymous visitors, in the appearance its team chose. */
import { Head, usePage } from '@inertiajs/react';
import type { PublicStatusPage } from '../../components/status-pages/types';

/** Page props: the explicit visitor projection only. */
interface PublicProps {
    /** Visitor projection. */
    page: PublicStatusPage;
    /** Inertia page props are an open record. */
    [key: string]: unknown;
}

/** Complete class lists per color scheme, so the build keeps them and no server value reaches a class name. */
const MODES: Record<string, string> = {
    system: 'bg-white text-slate-900 dark:bg-slate-950 dark:text-slate-100',
    light: 'bg-white text-slate-900',
    dark: 'bg-slate-950 text-slate-100',
};

/** Decorative bar color per accent; text never uses it, so contrast does not depend on the choice. */
const ACCENTS: Record<string, string> = {
    emerald: 'bg-emerald-500',
    blue: 'bg-blue-500',
    violet: 'bg-violet-500',
    amber: 'bg-amber-500',
    slate: 'bg-slate-500',
};

/** Components are listed by public name; their current status is added with the published history. */
export default function Public() {
    const { page } = usePage<PublicProps>().props;

    return (
        <>
            <Head title={page.title} />
            <main className={`min-h-screen px-6 py-12 ${MODES[page.theme.mode] ?? MODES.system}`}>
                <div className="mx-auto max-w-3xl">
                    <div
                        data-testid="accent"
                        aria-hidden="true"
                        className={`mb-4 h-1 w-16 rounded ${ACCENTS[page.theme.accent] ?? ACCENTS.emerald}`}
                    />
                    <h1 className="text-3xl font-semibold">{page.title}</h1>
                    {page.description !== null && <p className="mt-3">{page.description}</p>}
                    {page.components.length === 0 ? (
                        <p className="mt-8">This page has no components yet.</p>
                    ) : (
                        <ul aria-label="Components" className="mt-8">
                            {page.components.map((component) => (
                                <li
                                    key={component.name}
                                    className="border-b border-slate-300 py-3 dark:border-slate-700"
                                >
                                    {component.name}
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </main>
        </>
    );
}
