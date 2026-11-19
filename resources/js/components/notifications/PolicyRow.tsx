/** Show one escalation policy with its steps and offer editing and deletion to managers. */
import Badge from '../ui/Badge';
import Button from '../ui/Button';
import { useOwnedMutation } from '../../hooks/useOwnedMutation';
import MutationFeedback from '../MutationFeedback';
import { useFormProtection, useNavigationGuard } from '../app/NavigationGuard';
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
    /** Hide a filtered row without discarding its draft. */
    hidden?: boolean;
}

/** Steps are listed in delay order; inactive channels are marked because deliveries skip them. */
export default function PolicyRow({
    path,
    policy,
    channels,
    limits,
    canManage,
    hidden = false,
}: PolicyRowProps) {
    const [editing, setEditing] = useState(false);
    const remove = useForm<Record<string, never>>({});
    useFormProtection(remove);
    const mutation = useOwnedMutation();
    const guard = useNavigationGuard();
    const blocked = mutation.locked || guard.busy;

    function destroy() {
        if (blocked) return;
        const usage = policy.isDefault
            ? ' Monitors without another policy will notify nobody until a new default or override is assigned.'
            : policy.monitorCount === 0
              ? ''
              : ` ${policy.monitorCount} monitor${policy.monitorCount === 1 ? '' : 's'} will fall back to the team default.`;

        if (!window.confirm(`Delete the policy ${policy.name}?${usage}`)) {
            return;
        }

        mutation.run(path, (options) => remove.delete(path, options));
    }

    return (
        <li
            id={`policy-${policy.id}`}
            tabIndex={-1}
            aria-labelledby={`policy-${policy.id}-heading`}
            hidden={hidden}
            className="ui-panel ui-notification-card"
        >
            <h3 id={`policy-${policy.id}-heading`} className="ui-notification-name">
                {policy.name}
                <Badge tone={policy.isDefault ? 'success' : 'info'}>
                    {policy.isDefault ? 'Team default' : 'Custom policy'}
                </Badge>
            </h3>
            <p className="text-base text-[var(--color-ui-muted)]">
                {policy.monitorCount === 0
                    ? 'No monitor chose it explicitly.'
                    : `Chosen by ${policy.monitorCount} monitor${policy.monitorCount === 1 ? '' : 's'}.`}
            </p>
            <ol className="mt-3 grid gap-2 text-base" aria-label={`Steps of ${policy.name}`}>
                {policy.steps.map((step, index) => (
                    <li key={index}>
                        <span className="text-[var(--color-ui-text)]">
                            {delayLabel(step.afterMinutes)}:
                        </span>{' '}
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
                    <Button
                        type="button"
                        disabled={blocked}
                        aria-expanded={editing}
                        onClick={() => setEditing(!editing)}
                        variant="secondary"
                    >
                        {editing ? 'Close editor' : 'Edit policy'}
                    </Button>
                    <Button type="button" disabled={blocked} onClick={destroy} variant="danger">
                        Delete policy
                    </Button>
                </div>
            )}
            {editing && canManage && (
                <div className="mt-3 ui-panel">
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
            <MutationFeedback
                phase={mutation.phase}
                reviewUrl={path.split('/escalation-policies')[0] + '/notifications/policies'}
                success="Request accepted"
                onReviewed={mutation.acknowledge}
            />
        </li>
    );
}
