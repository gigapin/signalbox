/** Choose one authorized team monitor and reuse its existing policy assignment workflow. */
import { usePage } from '@inertiajs/react';
import AppLayout from '../../components/app/AppLayout';
import { EscalationEditor } from '../../components/notifications/EscalationPanel';
import type { MonitorEscalationView } from '../../components/notifications/types';
import { monitorEscalationPath } from '../../components/notifications/urls';
import type { MonitorPageProps } from '../../components/monitors/types';
import Alert from '../../components/ui/Alert';
import SearchPicker from '../../components/ui/SearchPicker';
import Badge, { type Tone } from '../../components/ui/Badge';
import Button from '../../components/ui/Button';
import Modal from '../../components/ui/Modal';
import { useNavigationGuard } from '../../components/app/NavigationGuard';
import { useState } from 'react';
import { createPortal } from 'react-dom';
import PageLoader from '../../components/ui/PageLoader';
import { useReadNavigation } from '../../hooks/useReadNavigation';

/** Safe selected-monitor response, without transport configuration or secrets. */
interface AssignmentsProps extends MonitorPageProps {
    /** Available identities from the current route team only. */
    monitors: { id: number; name: string }[];
    /** One selected monitor and its existing effective-policy projection. */
    selectedMonitor: {
        id: number;
        name: string;
        escalation: MonitorEscalationView;
        canAssign: boolean;
    } | null;
}

/** Describe the current effective assignment without implying that a delivery was received. */
function assignmentState(effective: MonitorEscalationView['effective']): {
    label: string;
    tone: Tone;
} {
    if (effective === null) return { label: 'No notifications', tone: 'danger' };
    if (effective.source === 'default') return { label: 'Team default', tone: 'success' };
    return { label: 'Monitor override', tone: 'info' };
}

/** Keep reads, draft protection and the editor inside the removable authorized team boundary. */
function PageContent({ actionsTarget }: { actionsTarget: HTMLElement | null }) {
    const { team, monitors, selectedMonitor, errors } = usePage<AssignmentsProps>().props;
    const read = useReadNavigation();
    const guard = useNavigationGuard();
    const [query, setQuery] = useState('');
    const [editing, setEditing] = useState(false);
    const [version, setVersion] = useState(0);
    const canEdit =
        selectedMonitor?.canAssign === true && selectedMonitor.escalation.options.length > 0;
    const [previousCanEdit, setPreviousCanEdit] = useState(canEdit);
    if (canEdit !== previousCanEdit) {
        setPreviousCanEdit(canEdit);
        if (!canEdit) setEditing(false);
    }
    const matching = monitors.filter((monitor) =>
        monitor.name.toLocaleLowerCase().includes(query.trim().toLocaleLowerCase()),
    );
    const effective = selectedMonitor?.escalation.effective;
    const state = assignmentState(effective ?? null);
    const path = `/teams/${team.id}/notifications/assignments`;

    return (
        <>
            <PageLoader active={read.loading} />
            {actionsTarget &&
                createPortal(
                    <div className="ui-channel-toolbar">
                        <SearchPicker
                            label="Find monitors"
                            query={query}
                            choices={matching.map((monitor) => ({
                                id: monitor.id,
                                label: monitor.name,
                            }))}
                            disabled={guard.busy || read.loading}
                            onQueryChange={setQuery}
                            onSelect={(choice) =>
                                read.navigate(path, { monitor_id: String(choice.id) })
                            }
                        />
                        {canEdit && (
                            <Button
                                aria-haspopup="dialog"
                                disabled={guard.busy || read.loading}
                                onClick={() => setEditing(true)}
                            >
                                Assign policy
                            </Button>
                        )}
                    </div>,
                    actionsTarget,
                )}
            {read.failed && (
                <Alert title="Monitor could not be loaded" tone="warning" announce="assertive">
                    Select a monitor again to retry. The previous assignment is still shown.
                </Alert>
            )}
            {monitors.length === 0 && <p className="ui-panel">No monitors available.</p>}
            {selectedMonitor !== null && (
                <section
                    className="ui-panel ui-notification-card"
                    aria-label={`Policy assignment for ${selectedMonitor.name}`}
                >
                    <h2 className="ui-notification-name">{selectedMonitor.name}</h2>
                    <Badge tone={state.tone}>{state.label}</Badge>
                    <p className="ui-notification-primary mt-3">
                        {effective?.name ??
                            'Nobody is notified when this monitor opens an incident.'}
                    </p>
                    <Modal
                        open={editing && canEdit}
                        title="Assign policy"
                        onRequestClose={() => {
                            if (!guard.busy) setEditing(false);
                        }}
                        closeDisabled={guard.busy}
                    >
                        {errors.policy && (
                            <Alert title="Assignment refused" tone="danger" announce="assertive">
                                {errors.policy}
                            </Alert>
                        )}
                        {canEdit && (
                            <EscalationEditor
                                key={`${selectedMonitor.id}-${version}`}
                                action={monitorEscalationPath(team.id, selectedMonitor.id)}
                                escalation={selectedMonitor.escalation}
                                onSaved={() => {
                                    setEditing(false);
                                    setVersion((value) => value + 1);
                                }}
                            />
                        )}
                    </Modal>
                    {!selectedMonitor.canAssign && (
                        <p className="ui-help">
                            Your role has read-only access to policy assignments.
                        </p>
                    )}
                </section>
            )}
        </>
    );
}

/** Render the dedicated assignment destination without granting any additional mutation permission. */
export default function Page() {
    const { team } = usePage<AssignmentsProps>().props;
    const [actionsTarget, setActionsTarget] = useState<HTMLDivElement | null>(null);
    return (
        <AppLayout
            team={team}
            section="notifications"
            notificationSection="assignments"
            title="Assign a policy to a monitor"
            actions={<div ref={setActionsTarget} className="contents" />}
            ancestors={[{ label: 'Notifications', href: `/teams/${team.id}/notifications` }]}
        >
            <PageContent actionsTarget={actionsTarget} />
        </AppLayout>
    );
}
