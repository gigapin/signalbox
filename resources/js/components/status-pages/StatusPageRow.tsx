/** Show one status page with its visibility and components, and offer editing and deletion to managers. */
import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import StatusPageForm from './StatusPageForm';
import type { ManagedStatusPage, SelectableMonitor, StatusPageLimits, ThemeChoices } from './types';

/** Row inputs. */
interface StatusPageRowProps {
    /** Path of this page's management endpoint. */
    path: string;
    /** Displayed page. */
    page: ManagedStatusPage;
    /** Monitors of the team. */
    monitors: SelectableMonitor[];
    /** Appearance choices. */
    themeChoices: ThemeChoices;
    /** Form bounds. */
    limits: StatusPageLimits;
    /** Whether management controls are offered; the server decides again. */
    canManage: boolean;
}

/** Only a published page links to its address, since a draft answers 404. */
export default function StatusPageRow({
    path,
    page,
    monitors,
    themeChoices,
    limits,
    canManage,
}: StatusPageRowProps) {
    const [editing, setEditing] = useState(false);
    const remove = useForm<Record<string, never>>({});

    function destroy() {
        if (!window.confirm(`Delete the status page ${page.title}? Its address stops working.`)) {
            return;
        }

        remove.delete(path, { preserveScroll: true });
    }

    return (
        <li className="border-b border-slate-800 py-4">
            <p className="font-medium">
                {page.title}
                <span className="ml-2 text-sm text-slate-300">
                    {page.isPublic ? 'Published' : 'Draft'}
                </span>
                {page.hasPassword && (
                    <span className="ml-2 text-sm text-amber-200">Password protected</span>
                )}
            </p>
            {page.isPublic ? (
                <a href={page.path} className="text-sm text-emerald-300 underline">
                    {page.path}
                </a>
            ) : (
                <p className="text-sm text-slate-400">{page.path} (not reachable yet)</p>
            )}
            {page.components.length === 0 ? (
                <p className="mt-2 text-sm text-slate-400">No components yet.</p>
            ) : (
                <ol className="mt-2 grid gap-1 text-sm" aria-label={`Components of ${page.title}`}>
                    {page.components.map((component) => (
                        <li key={component.name}>
                            <span className="text-slate-300">{component.name}:</span>{' '}
                            {component.monitors.length === 0
                                ? 'no monitors left, choose at least one to save the page again'
                                : component.monitors.map((monitor) => monitor.name).join(', ')}
                        </li>
                    ))}
                </ol>
            )}
            {canManage && (
                <div className="mt-3 flex flex-wrap gap-3 text-sm">
                    <button
                        type="button"
                        onClick={() => setEditing(!editing)}
                        aria-expanded={editing}
                        className="underline"
                    >
                        {editing ? 'Close editor' : `Edit ${page.title}`}
                    </button>
                    <button
                        type="button"
                        disabled={remove.processing}
                        onClick={destroy}
                        className="text-red-200 underline"
                    >
                        Delete {page.title}
                    </button>
                </div>
            )}
            {canManage && editing && (
                <div className="mt-4 rounded border border-slate-700 p-4">
                    <StatusPageForm
                        idPrefix={`page-${page.id}`}
                        action={path}
                        method="patch"
                        page={page}
                        monitors={monitors}
                        themeChoices={themeChoices}
                        limits={limits}
                        submitLabel="Save page"
                        onSaved={() => setEditing(false)}
                    />
                </div>
            )}
        </li>
    );
}
