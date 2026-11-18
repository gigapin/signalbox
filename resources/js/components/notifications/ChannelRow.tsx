/** Show one notification channel with its verification state and the changes its state admits. */
import Button from '../ui/Button';
import InputField from '../ui/InputField';
import { useOwnedMutation } from '../../hooks/useOwnedMutation';
import MutationFeedback from '../MutationFeedback';
import { useFormProtection, useNavigationGuard } from '../app/NavigationGuard';
import { useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { formatInstant } from '../incidents/time';
import ChannelForm from './ChannelForm';
import QuietHoursForm from './QuietHoursForm';
import SigningSecret from './SigningSecret';
import type { ChannelTypeOption, ChannelView } from './types';

/** Row inputs. */
interface ChannelRowProps {
    /** Path of this channel. */
    path: string;
    /** Displayed channel. */
    channel: ChannelView;
    /** Family description of the channel. */
    type: ChannelTypeOption | undefined;
    /** Whether management controls are offered; the server decides again. */
    canManage: boolean;
    /** Hide a filtered row without discarding its draft. */
    hidden?: boolean;
    /** Longest accepted label. */
    nameMaxLength: number;
    /** Digits of a verification code. */
    codeLength: number;
}

/**
 * @param channel Displayed channel.
 * @returns Readable state combining verification and activation.
 */
export function channelStateLabel(channel: ChannelView): string {
    if (channel.isActive) {
        return 'Active';
    }

    if (channel.verifiedAt !== null) {
        return 'Paused';
    }

    return channel.verificationPending ? 'Waiting for code' : 'Not verified';
}

/** Verification sends a code through the channel; typing it back verifies and activates the channel. */
export default function ChannelRow({
    path,
    channel,
    type,
    canManage,
    nameMaxLength,
    codeLength,
    hidden = false,
}: ChannelRowProps) {
    const [editing, setEditing] = useState(false);
    const action = useForm<Record<string, never>>({});
    useFormProtection(action);
    const activation = useForm({ active: !channel.isActive });
    useFormProtection(activation);
    const confirm = useForm({ code: '' });
    useFormProtection(confirm);
    const mutation = useOwnedMutation();
    const guard = useNavigationGuard();
    const blocked = mutation.locked || guard.busy;
    const codeId = `channel-${channel.id}-code`;

    function sendCode() {
        if (blocked) return;
        mutation.run(`${path}/verification`, (options) =>
            action.post(`${path}/verification`, options),
        );
    }

    function toggle() {
        if (blocked) return;
        activation.transform(() => ({ active: !channel.isActive }));
        mutation.run(`${path}/activation`, (options) =>
            activation.patch(`${path}/activation`, options),
        );
    }

    function destroy() {
        if (blocked) return;
        if (!window.confirm(`Delete the channel ${channel.name}? Delivery history is kept.`)) {
            return;
        }

        mutation.run(path, (options) => action.delete(path, options));
    }

    function submitCode(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (blocked) return;
        mutation.run(
            `${path}/verification/confirm`,
            (options) => confirm.post(`${path}/verification/confirm`, options),
            undefined,
            () => confirm.reset('code'),
        );
    }

    return (
        <li hidden={hidden} className="ui-operation-row">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <p className="font-medium">
                    {channel.name}{' '}
                    <span className="text-sm text-[var(--color-ui-muted)]">
                        · {type?.label ?? channel.type}
                    </span>
                </p>
                <p className="text-sm">{channelStateLabel(channel)}</p>
            </div>
            <p className="text-sm text-[var(--color-ui-text)]">{channel.hint}</p>
            <p className="text-xs text-[var(--color-ui-muted)]">
                {channel.quietHours === null
                    ? 'No quiet hours.'
                    : `Quiet hours daily: ${channel.quietHours.start}–${channel.quietHours.end} (${channel.quietHours.timezone}).`}
            </p>
            {channel.verifiedAt !== null && (
                <p className="text-xs text-[var(--color-ui-muted)]">
                    Verified {formatInstant(channel.verifiedAt, '')}
                </p>
            )}
            {channel.isStub && (
                <p className="text-xs text-ui-warning">
                    Stub channel: deliveries are recorded but never sent.
                </p>
            )}
            <p className="text-xs text-[var(--color-ui-muted)]">
                {channel.usedBy.length === 0
                    ? 'Not used by any escalation policy.'
                    : `Used by: ${channel.usedBy.join(', ')}`}
            </p>

            {canManage && (
                <div className="mt-3 flex flex-wrap gap-3 text-sm">
                    <Button type="button" disabled={blocked} onClick={sendCode} variant="secondary">
                        {channel.verificationPending ? 'Send a new code' : 'Send verification code'}
                    </Button>
                    {channel.verifiedAt !== null && (
                        <Button
                            type="button"
                            disabled={blocked}
                            onClick={toggle}
                            variant="secondary"
                        >
                            {channel.isActive ? 'Pause channel' : 'Activate channel'}
                        </Button>
                    )}
                    <Button
                        type="button"
                        disabled={blocked}
                        aria-expanded={editing}
                        onClick={() => setEditing(!editing)}
                        variant="secondary"
                    >
                        {editing ? 'Close editor' : 'Edit channel'}
                    </Button>
                    <Button type="button" disabled={blocked} onClick={destroy} variant="danger">
                        Delete channel
                    </Button>
                </div>
            )}

            {canManage && channel.verificationPending && (
                <form
                    onSubmit={submitCode}
                    noValidate
                    className="mt-3 flex flex-wrap items-end gap-3"
                >
                    <InputField
                        error={confirm.errors.code}
                        label="Verification code"
                        hint={<>Expires {formatInstant(channel.verificationExpiresAt, 'soon')} .</>}
                        id={codeId}
                        disabled={blocked}
                        inputMode="numeric"
                        autoComplete="one-time-code"
                        pattern="[0-9]*"
                        maxLength={codeLength}
                        value={confirm.data.code}
                        onChange={(event) => confirm.setData('code', event.target.value)}
                        aria-invalid={confirm.errors.code !== undefined}
                        wrapperClassName="w-32"
                    />
                    <Button disabled={blocked} variant="primary" type="submit">
                        {confirm.processing ? 'Checking…' : 'Verify'}
                    </Button>
                </form>
            )}

            {canManage && channel.type === 'webhook' && <SigningSecret path={path} />}

            {canManage && (
                <details className="mt-3 ui-panel">
                    <summary className="cursor-pointer text-sm">Configure quiet hours</summary>
                    <QuietHoursForm
                        path={path}
                        idPrefix={`channel-${channel.id}`}
                        hours={channel.quietHours}
                    />
                </details>
            )}

            {editing && canManage && type !== undefined && (
                <div className="mt-3 ui-panel">
                    <ChannelForm
                        idPrefix={`channel-${channel.id}`}
                        action={path}
                        method="patch"
                        types={[type]}
                        name={channel.name}
                        editable={channel.editable ?? {}}
                        nameMaxLength={nameMaxLength}
                        submitLabel="Save channel"
                        onSaved={() => setEditing(false)}
                    />
                </div>
            )}
            <MutationFeedback
                phase={mutation.phase}
                reviewUrl={path.split('/notification-channels')[0] + '/notifications'}
                success="Request accepted"
                onReviewed={mutation.acknowledge}
            />
        </li>
    );
}
