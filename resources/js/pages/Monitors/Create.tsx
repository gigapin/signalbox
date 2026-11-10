/** Create a monitor of any supported type within the route team. */
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
import { monitorsPath } from '../../components/monitors/urls';

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

/** Warn about a full quota up front; the server still decides and reports the refusal on submit. */
function PageContent() {
    const { team, defaults, options, usage, maximumIntervalSeconds } = usePage<CreateProps>().props;
    return (
        <>
            <nav aria-label="Monitor navigation" className="mb-6 text-sm text-emerald-300">
                <Link href={monitorsPath(team.id)}>Back to monitors</Link>
            </nav>
            {usage.full && (
                <p className="mb-6 rounded border border-amber-700 p-3 text-amber-200">
                    Your plan allows {usage.limit} monitors and all are in use. Saving will be
                    refused until you delete a monitor or upgrade.
                </p>
            )}
            <MonitorForm
                initial={initialFormData(defaults)}
                originalType={null}
                stored={null}
                action={monitorsPath(team.id)}
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
    const { team } = usePage<CreateProps>().props;
    return (
        <AppLayout
            team={team}
            section="monitors"
            ancestors={[{ label: 'Monitors', href: `/teams/${team.id}/monitors` }]}
            title="New monitor"
            description={`Add a monitor to ${team.name}.`}
        >
            <PageContent />
        </AppLayout>
    );
}
