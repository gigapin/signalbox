/** Edit one monitor without ever receiving or resending its stored secrets implicitly. */
import { Link, usePage } from '@inertiajs/react';
import AppLayout from '../../components/app/AppLayout';
import MonitorForm from '../../components/monitors/MonitorForm';
import { editFormData } from '../../components/monitors/formData';
import type {
    MonitorDefaults,
    MonitorDetail,
    MonitorOptions,
    MonitorPageProps,
    MonitorUsage,
} from '../../components/monitors/types';
import { monitorPath } from '../../components/monitors/urls';

/** Edit page response from MonitorPageController::edit. */
interface EditProps extends MonitorPageProps {
    /** Safe detail projection used for prefilling. */
    monitor: MonitorDetail;
    /** Values for fields of other types, used if the type changes. */
    defaults: MonitorDefaults;
    /** Enum values for selects and checkboxes. */
    options: MonitorOptions;
    /** Informational plan quota. */
    usage: MonitorUsage;
    /** Operational cadence ceiling in seconds from the server configuration. */
    maximumIntervalSeconds: number;
}

/** Submit a PATCH whose secrets are present only when the user chose to replace them. */
function PageContent() {
    const { team, monitor, defaults, options, usage, maximumIntervalSeconds } =
        usePage<EditProps>().props;
    return (
        <>
            <nav aria-label="Monitor navigation" className="mb-6 text-sm text-emerald-300">
                <Link href={monitorPath(team.id, monitor.id)}>Back to monitor</Link>
            </nav>
            <MonitorForm
                initial={editFormData(monitor, defaults)}
                originalType={monitor.type}
                stored={monitor.configuration}
                action={monitorPath(team.id, monitor.id)}
                method="patch"
                options={options}
                usage={usage}
                maximumIntervalSeconds={maximumIntervalSeconds}
                submitLabel="Save changes"
            />
        </>
    );
}

/** Keep all page-owned forms and live sessions inside the removable authorized team boundary. */
export default function Page() {
    const { team, monitor } = usePage<EditProps>().props;
    return (
        <AppLayout team={team} section="monitors" title={`Edit ${monitor.name}`}>
            <PageContent />
        </AppLayout>
    );
}
