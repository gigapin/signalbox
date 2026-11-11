/** Manage the team's notification channels and escalation policies. */
import { Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { useNavigationGuard } from '../../components/app/NavigationGuard';
import AppLayout from '../../components/app/AppLayout';
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
function PageContent() {
    const { team, channels, policies, unnotifiedMonitors, channelTypes, limits, can, errors } =
        usePage<NotificationsPageProps>().props;

    const [search, setSearch] = useState('');
    const guard = useNavigationGuard();
    const term = search.trim().toLocaleLowerCase();
    const channelMatch = (name: string) => name.toLocaleLowerCase().includes(term);

    return (
        <>
            <nav aria-label="Notification workflows" className="ui-actions mb-4">
                <a className="ui-link" href="#channels-heading">
                    Channels
                </a>
                <a className="ui-link" href="#policies-heading">
                    Escalation policies
                </a>
                <Link className="ui-link" href={`/teams/${team.id}/notifications/deliveries`}>
                    Notification deliveries
                </Link>
                <Link className="ui-link" href={`/teams/${team.id}/monitors`}>
                    Assign a policy to a monitor
                </Link>
            </nav>
            <div className="ui-panel mb-6">
                <label className="ui-label" htmlFor="notification-search">
                    Find channels or policies
                </label>
                <input
                    className="ui-input"
                    id="notification-search"
                    type="search"
                    disabled={guard.busy}
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                />
                <p className="ui-help">
                    Filters names on this page. Hidden editors retain their drafts.
                </p>
            </div>
            {channels.length > 0 && !channels.some((item) => channelMatch(item.name)) && (
                <p role="status">No channels match this search.</p>
            )}
            {policies.length > 0 && !policies.some((item) => channelMatch(item.name)) && (
                <p role="status">No escalation policies match this search.</p>
            )}

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
                <p className="mb-4 text-sm text-[var(--color-ui-muted)]">
                    First add a channel, verify its destination, then include it in a policy. Assign
                    that policy to a monitor or make it the team default. Credentials are stored
                    encrypted and never shown again. Verification sends a code, not a test incident.
                </p>
                {errors.channel && (
                    <p role="alert" className="mb-4 text-red-300">
                        {errors.channel}
                    </p>
                )}
                {channels.length === 0 ? (
                    <p className="text-[var(--color-ui-text)]">No channels yet.</p>
                ) : (
                    <ul aria-label="Notification channels">
                        {channels.map((channel) => (
                            <ChannelRow
                                key={channel.id}
                                path={channelPath(team.id, channel.id)}
                                channel={channel}
                                hidden={!channelMatch(channel.name)}
                                type={channelTypes.find((option) => option.value === channel.type)}
                                canManage={can.manage}
                                nameMaxLength={limits.nameMaxLength}
                                codeLength={limits.codeLength}
                            />
                        ))}
                    </ul>
                )}
                {can.manage && channels.length < limits.channelsPerTeam && (
                    <div className="mt-4 ui-panel">
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
                    <p className="mt-4 text-sm text-[var(--color-ui-muted)]">
                        The team has reached its limit of {limits.channelsPerTeam} channels.
                    </p>
                )}
            </section>

            <section aria-labelledby="policies-heading" className="mt-10">
                <h2 id="policies-heading" className="mb-2 text-lg font-semibold">
                    Escalation policies
                </h2>
                <p className="mb-4 text-sm text-[var(--color-ui-muted)]">
                    Each step waits a number of minutes after the incident is confirmed, then
                    notifies its active channels. A policy does not guarantee delivery: review
                    verification, activation, quiet hours and recorded outcomes.
                </p>
                {errors.policy && (
                    <p role="alert" className="mb-4 text-red-300">
                        {errors.policy}
                    </p>
                )}
                {policies.length === 0 ? (
                    <p className="text-[var(--color-ui-text)]">No escalation policies yet.</p>
                ) : (
                    <ul aria-label="Escalation policies">
                        {policies.map((policy) => (
                            <PolicyRow
                                key={policy.id}
                                path={policyPath(team.id, policy.id)}
                                policy={policy}
                                hidden={!channelMatch(policy.name)}
                                channels={channels}
                                limits={limits}
                                canManage={can.manage}
                            />
                        ))}
                    </ul>
                )}
                {can.manage && policies.length < limits.policiesPerTeam && (
                    <div className="mt-4 ui-panel">
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
                <p className="mt-8 text-sm text-[var(--color-ui-muted)]">
                    Only owners and admins can change channels and escalation policies.
                </p>
            )}
        </>
    );
}

/** Keep all page-owned forms and live sessions inside the removable authorized team boundary. */
export default function Page() {
    const { team } = usePage<NotificationsPageProps>().props;
    return (
        <AppLayout
            team={team}
            section="notifications"
            title="Notifications"
            description={`Channels and escalation of ${team.name}.`}
        >
            <PageContent />
        </AppLayout>
    );
}
