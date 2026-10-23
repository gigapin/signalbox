/** Edit one monitor without ever receiving or resending its stored secrets implicitly. */
import { Link, usePage } from '@inertiajs/react';
import AuthLayout from '../../components/auth/AuthLayout';
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
}

/** Submit a PATCH whose secrets are present only when the user chose to replace them. */
export default function Edit() {
    const { team, monitor, defaults, options, usage } = usePage<EditProps>().props;
    return (
        <AuthLayout title={`Edit ${monitor.name}`} wide>
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
                submitLabel="Save changes"
            />
        </AuthLayout>
    );
}
