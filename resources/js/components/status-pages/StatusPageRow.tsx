/** Show one status page with its visibility and components, and offer editing and deletion to managers. */
import { useFormProtection, useNavigationGuard } from '../app/NavigationGuard';
import { useForm } from '@inertiajs/react';
import { useOwnedMutation } from '../../hooks/useOwnedMutation';
import MutationFeedback from '../MutationFeedback';
import Badge from '../ui/Badge';
import Button from '../ui/Button';
import Modal from '../ui/Modal';
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
    const [editStarted, setEditStarted] = useState(false);
    const [domainStarted, setDomainStarted] = useState(false);
    const [editing, setEditing] = useState(false);
    const [editVersion, setEditVersion] = useState(0);
    const [domainOpen, setDomainOpen] = useState(false);
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
        <li
            id={`status-page-${page.id}`}
            tabIndex={-1}
            aria-labelledby={`status-page-${page.id}-heading`}
            hidden={hidden}
            className="ui-panel ui-notification-card"
        >
            <h3 id={`status-page-${page.id}-heading`} className="ui-notification-name">
                {page.title}
            </h3>
            <div className="flex flex-wrap gap-2">
                <Badge tone={page.isPublic ? 'success' : 'neutral'}>
                    {page.isPublic ? 'Published' : 'Draft'}
                </Badge>
                {page.hasPassword && <Badge tone="warning">Password protected</Badge>}
            </div>
            {page.isPublic ? (
                <a href={page.path} className="ui-channel-destination ui-link">
                    Open public page · {page.path}
                </a>
            ) : (
                <p className="break-words text-ui-muted">{page.path} (not reachable yet)</p>
            )}
            {page.components.length === 0 ? (
                <p className="mt-2 text-sm text-[var(--color-ui-muted)]">No components yet.</p>
            ) : (
                <ol className="mt-2 grid gap-1" aria-label={`Components of ${page.title}`}>
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
            {page.domain && (
                <p className="mt-3 break-words">
                    Custom domain: {page.domain.hostname}{' '}
                    <Badge tone={page.domain.verifiedUntil ? 'success' : 'warning'}>
                        {page.domain.verifiedUntil ? 'Ownership verified' : 'Not verified'}
                    </Badge>
                </p>
            )}
            {canManage && (
                <>
                    <div className="ui-actions mt-4">
                        <Button
                            variant="secondary"
                            disabled={blocked}
                            onClick={() => {
                                setEditStarted(true);
                                setEditing(true);
                            }}
                            aria-haspopup="dialog"
                        >
                            Edit {page.title}
                        </Button>
                        <Button
                            variant="secondary"
                            disabled={blocked}
                            onClick={() => {
                                setDomainStarted(true);
                                setDomainOpen(true);
                            }}
                            aria-haspopup="dialog"
                        >
                            Custom domain
                        </Button>
                        <Button variant="danger" disabled={blocked} onClick={destroy}>
                            Delete {page.title}
                        </Button>
                    </div>
                    <Modal
                        open={editing}
                        title={`Edit ${page.title}`}
                        closeDisabled={blocked}
                        onRequestClose={() => {
                            if (!blocked) setEditing(false);
                        }}
                    >
                        {editStarted && (
                            <StatusPageForm
                                key={editVersion}
                                idPrefix={`page-${page.id}`}
                                action={path}
                                method="patch"
                                page={page}
                                monitors={monitors}
                                themeChoices={themeChoices}
                                limits={limits}
                                submitLabel="Save page"
                                onSaved={() => {
                                    setEditing(false);
                                    setEditVersion((value) => value + 1);
                                }}
                            />
                        )}
                    </Modal>
                    <Modal
                        open={domainOpen}
                        title={`Custom domain · ${page.title}`}
                        closeDisabled={blocked}
                        onRequestClose={() => {
                            if (!blocked) setDomainOpen(false);
                        }}
                    >
                        {domainStarted && (
                            <StatusDomainForm path={path} domain={page.domain} pageId={page.id} />
                        )}
                    </Modal>
                </>
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
