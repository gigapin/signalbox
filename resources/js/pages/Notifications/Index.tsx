/** Manage the team's notification channels and escalation policies. */
import { Link, usePage } from '@inertiajs/react';
import AuthLayout from '../../components/auth/AuthLayout';
import ChannelForm from '../../components/notifications/ChannelForm';
import ChannelRow from '../../components/notifications/ChannelRow';
import PolicyForm from '../../components/notifications/PolicyForm';
import PolicyRow from '../../components/notifications/PolicyRow';
import type { NotificationsPageProps } from '../../components/notifications/types';
import {
    channelPath,
    channelsPath,
    policiesPath,
    policyPath,
} from '../../components/notifications/urls';

/**
 * Every member sees channels with masked destinations and the policies; owners and admins get the forms.
 * Domain refusals arrive on the `channel` and `policy` keys and are announced at the top of their section.
 */
export default function Index() {
    const { team, channels, policies, unnotifiedMonitors, channelTypes, limits, can, errors } =
        usePage<NotificationsPageProps>().props;

    return (
        <AuthLayout
            title="Notifications"
            description={`Channels and escalation of ${team.name}.`}
            wide
        >
            <nav aria-label="Team navigation" className="mb-6 flex gap-4 text-sm text-emerald-300">
                <Link href={`/teams/${team.id}`}>Team members</Link>
                <Link href={`/teams/${team.id}/monitors`}>Monitors</Link>
                <Link href={`/teams/${team.id}/incidents`}>Incidents</Link>
            </nav>

            {unnotifiedMonitors > 0 && (
                <p className="mb-6 rounded border border-amber-700 p-3 text-amber-200">
                    {unnotifiedMonitors} monitor{unnotifiedMonitors === 1 ? '' : 's'} notify nobody:
                    mark a policy as the team default or choose one on each monitor.
                </p>
            )}

            <section aria-labelledby="channels-heading">
                <h2 id="channels-heading" className="mb-2 text-lg font-semibold">
                    Channels
                </h2>
                <p className="mb-4 text-sm text-slate-400">
                    A channel receives notifications only after it is verified with the code it
                    receives. Credentials are stored encrypted and never shown again.
                </p>
                {errors.channel && (
                    <p role="alert" className="mb-4 text-red-300">
                        {errors.channel}
                    </p>
                )}
                {channels.length === 0 ? (
                    <p className="text-slate-300">No channels yet.</p>
                ) : (
                    <ul aria-label="Notification channels">
                        {channels.map((channel) => (
                            <ChannelRow
                                key={channel.id}
                                path={channelPath(team.id, channel.id)}
                                channel={channel}
                                type={channelTypes.find((option) => option.value === channel.type)}
                                canManage={can.manage}
                                nameMaxLength={limits.nameMaxLength}
                                codeLength={limits.codeLength}
                            />
                        ))}
                    </ul>
                )}
                {can.manage && channels.length < limits.channelsPerTeam && (
                    <div className="mt-4 rounded border border-slate-700 p-4">
                        <h3 className="mb-3 font-medium">Add a channel</h3>
                        <ChannelForm
                            idPrefix="channel-new"
                            action={channelsPath(team.id)}
                            method="post"
                            types={channelTypes}
                            name=""
                            editable={{}}
                            nameMaxLength={limits.nameMaxLength}
                            submitLabel="Add channel"
                        />
                    </div>
                )}
                {can.manage && channels.length >= limits.channelsPerTeam && (
                    <p className="mt-4 text-sm text-slate-400">
                        The team has reached its limit of {limits.channelsPerTeam} channels.
                    </p>
                )}
            </section>

            <section aria-labelledby="policies-heading" className="mt-10">
                <h2 id="policies-heading" className="mb-2 text-lg font-semibold">
                    Escalation policies
                </h2>
                <p className="mb-4 text-sm text-slate-400">
                    Each step waits a number of minutes after the incident is confirmed, then
                    notifies its channels.
                </p>
                {errors.policy && (
                    <p role="alert" className="mb-4 text-red-300">
                        {errors.policy}
                    </p>
                )}
                {policies.length === 0 ? (
                    <p className="text-slate-300">No escalation policies yet.</p>
                ) : (
                    <ul aria-label="Escalation policies">
                        {policies.map((policy) => (
                            <PolicyRow
                                key={policy.id}
                                path={policyPath(team.id, policy.id)}
                                policy={policy}
                                channels={channels}
                                limits={limits}
                                canManage={can.manage}
                            />
                        ))}
                    </ul>
                )}
                {can.manage && policies.length < limits.policiesPerTeam && (
                    <div className="mt-4 rounded border border-slate-700 p-4">
                        <h3 className="mb-3 font-medium">Add a policy</h3>
                        <PolicyForm
                            idPrefix="policy-new"
                            action={policiesPath(team.id)}
                            method="post"
                            policy={null}
                            channels={channels}
                            limits={limits}
                            submitLabel="Add policy"
                        />
                    </div>
                )}
            </section>

            {!can.manage && (
                <p className="mt-8 text-sm text-slate-400">
                    Only owners and admins can change channels and escalation policies.
                </p>
            )}
        </AuthLayout>
    );
}
