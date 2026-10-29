/** Let a monitor manager choose the escalation policy of one monitor. */
import { Link, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import type { MonitorEscalationView } from './types';
import { notificationsPath } from './urls';

/** Panel inputs. */
interface EscalationPanelProps {
    /** Route team. */
    teamId: number;
    /** Endpoint saving the choice. */
    action: string;
    /** Current choice and its effect. */
    escalation: MonitorEscalationView;
    /** Whether MonitorPolicy lets the actor configure the monitor; the server decides again. */
    canManage: boolean;
}

/** An empty choice follows the team default; without a default the monitor notifies nobody. */
export default function EscalationPanel({
    teamId,
    action,
    escalation,
    canManage,
}: EscalationPanelProps) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const form = useForm({
        escalation_policy_id: escalation.policyId === null ? '' : String(escalation.policyId),
    });
    const fallback = escalation.options.find((option) => option.isDefault);

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.transform((data) => ({
            escalation_policy_id:
                data.escalation_policy_id === '' ? null : Number(data.escalation_policy_id),
        }));
        form.patch(action, { preserveScroll: true });
    }

    return (
        <section aria-labelledby="escalation-heading" className="mt-8">
            <h2 id="escalation-heading" className="mb-2 text-lg font-semibold">
                Notifications
            </h2>
            <p className="text-slate-300">
                {escalation.effective === null
                    ? 'Nobody is notified when this monitor opens an incident.'
                    : `Incidents follow the policy ${escalation.effective.name}${escalation.effective.source === 'default' ? ' (team default)' : ''}.`}
            </p>
            {errors.policy && (
                <p role="alert" className="mt-2 text-red-300">
                    {errors.policy}
                </p>
            )}
            {canManage && escalation.options.length > 0 && (
                <form onSubmit={submit} noValidate className="mt-3 flex flex-wrap items-end gap-3">
                    <div>
                        <label
                            htmlFor="monitor-escalation"
                            className="mb-2 block text-sm font-medium"
                        >
                            Escalation policy
                        </label>
                        <select
                            id="monitor-escalation"
                            value={form.data.escalation_policy_id}
                            onChange={(event) =>
                                form.setData('escalation_policy_id', event.target.value)
                            }
                            className="rounded border border-slate-600 bg-slate-900 px-3 py-2"
                        >
                            <option value="">
                                {fallback === undefined
                                    ? 'Team default (none set)'
                                    : `Team default (${fallback.name})`}
                            </option>
                            {escalation.options.map((option) => (
                                <option key={option.id} value={String(option.id)}>
                                    {option.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    <button
                        disabled={form.processing}
                        className="rounded bg-emerald-400 px-4 py-2 font-semibold text-slate-950 disabled:opacity-50"
                    >
                        {form.processing ? 'Saving…' : 'Save'}
                    </button>
                </form>
            )}
            <p className="mt-2 text-sm">
                <Link href={notificationsPath(teamId)} className="text-emerald-300 underline">
                    Manage channels and policies
                </Link>
            </p>
        </section>
    );
}
