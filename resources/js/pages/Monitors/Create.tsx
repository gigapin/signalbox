/** Create a monitor of any supported type within the route team. */
import { Link, usePage } from '@inertiajs/react';
import AuthLayout from '../../components/auth/AuthLayout';
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
}

/** Warn about a full quota up front; the server still decides and reports the refusal on submit. */
export default function Create() {
    const { team, defaults, options, usage } = usePage<CreateProps>().props;
    return (
        <AuthLayout title="New monitor" description={`Add a monitor to ${team.name}.`} wide>
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
                submitLabel="Create monitor"
            />
        </AuthLayout>
    );
}
