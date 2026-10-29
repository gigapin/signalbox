/** Show one escalation policy with its steps and offer editing and deletion to managers. */
import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { delayLabel } from './labels';
import PolicyForm from './PolicyForm';
import type { ChannelView, NotificationLimits, PolicyView } from './types';

/** Row inputs. */
interface PolicyRowProps {
    /** Path of this policy. */
    path: string;
    /** Displayed policy. */
    policy: PolicyView;
    /** Channels the editor may offer. */
    channels: ChannelView[];
    /** Form bounds. */
    limits: NotificationLimits;
    /** Whether management controls are offered; the server decides again. */
    canManage: boolean;
}

/** Steps are listed in delay order; inactive channels are marked because deliveries skip them. */
export default function PolicyRow({ path, policy, channels, limits, canManage }: PolicyRowProps) {
    const [editing, setEditing] = useState(false);
    const remove = useForm<Record<string, never>>({});

    function destroy() {
        const usage =
            policy.monitorCount === 0
                ? ''
                : ` ${policy.monitorCount} monitor${policy.monitorCount === 1 ? '' : 's'} will fall back to the team default.`;

        if (!window.confirm(`Delete the policy ${policy.name}?${usage}`)) {
            return;
        }

        remove.delete(path, { preserveScroll: true });
    }

    return (
        <li className="border-b border-slate-800 py-4">
            <p className="font-medium">
                {policy.name}
                {policy.isDefault && (
                    <span className="ml-2 text-sm text-emerald-200">Team default</span>
                )}
            </p>
            <p className="text-xs text-slate-400">
                {policy.monitorCount === 0
                    ? 'No monitor chose it explicitly.'
                    : `Chosen by ${policy.monitorCount} monitor${policy.monitorCount === 1 ? '' : 's'}.`}
            </p>
            <ol className="mt-2 grid gap-1 text-sm" aria-label={`Steps of ${policy.name}`}>
                {policy.steps.map((step, index) => (
                    <li key={index}>
                        <span className="text-slate-300">{delayLabel(step.afterMinutes)}:</span>{' '}
                        {step.channels.length === 0
                            ? 'no channel'
                            : step.channels
                                  .map((channel) =>
                                      channel.isActive
                                          ? channel.name
                                          : `${channel.name} (not active)`,
                                  )
                                  .join(', ')}
                    </li>
                ))}
            </ol>
            {canManage && (
                <div className="mt-2 flex flex-wrap gap-3 text-sm">
                    <button
                        type="button"
                        aria-expanded={editing}
                        onClick={() => setEditing(!editing)}
                        className="underline"
                    >
                        {editing ? 'Close editor' : 'Edit policy'}
                    </button>
                    <button
                        type="button"
                        disabled={remove.processing}
                        onClick={destroy}
                        className="text-red-200 underline disabled:opacity-50"
                    >
                        Delete policy
                    </button>
                </div>
            )}
            {editing && (
                <div className="mt-3 rounded border border-slate-700 p-3">
                    <PolicyForm
                        idPrefix={`policy-${policy.id}`}
                        action={path}
                        method="patch"
                        policy={policy}
                        channels={channels}
                        limits={limits}
                        submitLabel="Save policy"
                        onSaved={() => setEditing(false)}
                    />
                </div>
            )}
        </li>
    );
}
