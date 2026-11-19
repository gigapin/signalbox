/** Create a monitor of any supported type within the route team. */
import { useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import AppLayout from '../../components/app/AppLayout';
import MonitorForm from '../../components/monitors/MonitorForm';
import { initialFormData } from '../../components/monitors/formData';
import type {
    MonitorDefaults,
    MonitorOptions,
    MonitorPageProps,
    MonitorUsage,
} from '../../components/monitors/types';
import { monitorsPath, withMonitorListReturn } from '../../components/monitors/urls';

/** Creation page response from MonitorPageController::create. */
interface CreateProps extends MonitorPageProps {
    /** Starting values, with the interval raised to the plan minimum. */
    defaults: MonitorDefaults;
    /** Enum values for selects and checkboxes. */
    options: MonitorOptions;
    /** Informational plan quota. */
    usage: MonitorUsage;
    /** Operational cadence ceiling in seconds from the server configuration. */
    maximumIntervalSeconds: number;
}

/** Destination of form-owned actions within the authorized page heading. */
interface PageContentProps {
    /** Null until the heading action container mounts. */
    actionsTarget: HTMLDivElement | null;
}

/** Warn about a full quota up front; the server still decides and reports the refusal on submit. */
function PageContent({ actionsTarget }: PageContentProps) {
    const { team, defaults, options, usage, maximumIntervalSeconds, monitorListUrl } =
        usePage<CreateProps>().props;
    return (
        <>
            {usage.full && (
                <p className="mb-6 rounded border border-amber-700 p-3 text-ui-warning">
                    Your plan allows {usage.limit} monitors and all are in use. Saving will be
                    refused until you delete a monitor or upgrade.
                </p>
            )}
            <MonitorForm
                actionsTarget={actionsTarget}
                initial={initialFormData(defaults)}
                originalType={null}
                stored={null}
                action={withMonitorListReturn(
                    monitorsPath(team.id),
                    team.id,
                    monitorListUrl ?? monitorsPath(team.id),
                )}
                reviewUrl={monitorListUrl ?? monitorsPath(team.id)}
                cancelUrl={monitorListUrl ?? monitorsPath(team.id)}
                method="post"
                options={options}
                usage={usage}
                maximumIntervalSeconds={maximumIntervalSeconds}
                submitLabel="Create monitor"
            />
        </>
    );
}

/** Keep all page-owned forms and live sessions inside the removable authorized team boundary. */
export default function Page() {
    const { team, monitorListUrl } = usePage<CreateProps>().props;
    const [actionsTarget, setActionsTarget] = useState<HTMLDivElement | null>(null);
    return (
        <AppLayout
            team={team}
            section="monitors"
            ancestors={[{ label: 'Monitors', href: monitorListUrl ?? monitorsPath(team.id) }]}
            title="New monitor"
            actions={
                <>
                    <div ref={setActionsTarget} className="contents" />
                    <Link
                        className="ui-button"
                        data-variant="secondary"
                        href={monitorListUrl ?? monitorsPath(team.id)}
                    >
                        Back
                    </Link>
                </>
            }
        >
            <PageContent actionsTarget={actionsTarget} />
        </AppLayout>
    );
}
