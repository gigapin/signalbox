/** Show one status page with its visibility and components, and offer editing and deletion to managers. */
import { useFormProtection, useNavigationGuard } from '../app/NavigationGuard';
import { useForm } from '@inertiajs/react';
import { useOwnedMutation } from '../../hooks/useOwnedMutation';
import MutationFeedback from '../MutationFeedback';
import { useState } from 'react';
import StatusPageForm from './StatusPageForm';
import StatusDomainForm from './StatusDomainForm';
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
    /** Hide a filtered row without discarding its mounted editor. */
    hidden?: boolean;
}

/** Only a published page links to its address, since a draft answers 404. */
export default function StatusPageRow({
    path,
    page,
    monitors,
    themeChoices,
    limits,
    canManage,
    hidden = false,
}: StatusPageRowProps) {
    const [editing, setEditing] = useState(false);
    const remove = useForm<Record<string, never>>({});
    useFormProtection(remove);
    const mutation = useOwnedMutation();
    const guard = useNavigationGuard();
    const blocked = mutation.locked || guard.busy;

    /** Delete only after explicit confirmation; preserve uncertain outcomes for manual review. */
    function destroy() {
        if (blocked) return;
        if (!window.confirm(`Delete the status page ${page.title}? Its address stops working.`)) {
            return;
        }

        mutation.run(path, (options) => remove.delete(path, options));
    }

    return (
        <li hidden={hidden} className="ui-operation-row">
            <p className="font-medium">
                {page.title}
                <span className="ml-2 text-sm text-[var(--color-ui-text)]">
                    {page.isPublic ? 'Published' : 'Draft'}
                </span>
                {page.hasPassword && (
                    <span className="ml-2 text-sm text-amber-200">Password protected</span>
                )}
            </p>
            {page.isPublic ? (
                <a href={page.path} className="text-sm text-emerald-300 underline">
                    Open public page · {page.path}
                </a>
            ) : (
                <p className="text-sm text-[var(--color-ui-muted)]">
                    {page.path} (not reachable yet)
                </p>
            )}
            {page.components.length === 0 ? (
                <p className="mt-2 text-sm text-[var(--color-ui-muted)]">No components yet.</p>
            ) : (
                <ol className="mt-2 grid gap-1 text-sm" aria-label={`Components of ${page.title}`}>
                    {page.components.map((component) => (
                        <li key={component.name}>
                            <span className="text-[var(--color-ui-text)]">{component.name}:</span>{' '}
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
                        disabled={blocked}
                        onClick={() => setEditing(!editing)}
                        aria-expanded={editing}
                        className="ui-link"
                    >
                        {editing ? 'Close editor' : `Edit ${page.title}`}
                    </button>
                    <button
                        type="button"
                        disabled={blocked}
                        onClick={destroy}
                        className="text-red-200 underline"
                    >
                        Delete {page.title}
                    </button>
                </div>
            )}
            {canManage && editing && (
                <div className="mt-4 ui-panel">
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
            {canManage && <StatusDomainForm path={path} domain={page.domain} pageId={page.id} />}
            {!canManage && page.domain && (
                <p className="mt-2 text-sm">
                    Custom domain: {page.domain.hostname} (
                    {page.domain.verifiedUntil ? 'verified' : 'not verified'})
                </p>
            )}
            <MutationFeedback
                phase={mutation.phase}
                reviewUrl={path.split('/status-pages')[0] + '/status-pages'}
                success="Status page deleted"
                onReviewed={mutation.acknowledge}
            />
        </li>
    );
}
