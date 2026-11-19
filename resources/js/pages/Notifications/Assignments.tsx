/** Choose one authorized team monitor and reuse its existing policy assignment workflow. */
import { usePage } from '@inertiajs/react';
import AppLayout from '../../components/app/AppLayout';
import EscalationPanel from '../../components/notifications/EscalationPanel';
import type { MonitorEscalationView } from '../../components/notifications/types';
import { monitorEscalationPath } from '../../components/notifications/urls';
import type { MonitorPageProps } from '../../components/monitors/types';
import Alert from '../../components/ui/Alert';
import SelectField from '../../components/ui/SelectField';
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

/** Keep reads, draft protection and the editor inside the removable authorized team boundary. */
function PageContent() {
    const { team, monitors, selectedMonitor } = usePage<AssignmentsProps>().props;
    const read = useReadNavigation();
    const path = `/teams/${team.id}/notifications/assignments`;

    return (
        <>
            <PageLoader active={read.loading} />
            <SelectField
                label="Monitor"
                wrapperClassName="ui-panel"
                value={selectedMonitor === null ? '' : String(selectedMonitor.id)}
                disabled={read.loading}
                onChange={(event) =>
                    read.navigate(
                        path,
                        event.target.value === '' ? {} : { monitor_id: event.target.value },
                    )
                }
            >
                <option value="">Choose a monitor</option>
                {monitors.map((monitor) => (
                    <option key={monitor.id} value={monitor.id}>
                        {monitor.name}
                    </option>
                ))}
            </SelectField>
            {read.failed && (
                <Alert title="Monitor could not be loaded" tone="warning" announce="assertive">
                    Select a monitor again to retry. The previous assignment is still shown.
                </Alert>
            )}
            {monitors.length === 0 && <p className="ui-panel">No monitors available.</p>}
            {selectedMonitor !== null && (
                <section
                    className="ui-panel"
                    aria-label={`Policy assignment for ${selectedMonitor.name}`}
                >
                    <h2 className="ui-section-title">{selectedMonitor.name}</h2>
                    <EscalationPanel
                        key={selectedMonitor.id}
                        teamId={team.id}
                        action={monitorEscalationPath(team.id, selectedMonitor.id)}
                        escalation={selectedMonitor.escalation}
                        canManage={selectedMonitor.canAssign}
                    />
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
    return (
        <AppLayout
            team={team}
            section="notifications"
            notificationSection="assignments"
            title="Assign a policy to a monitor"
            ancestors={[{ label: 'Notifications', href: `/teams/${team.id}/notifications` }]}
        >
            <PageContent />
        </AppLayout>
    );
}
