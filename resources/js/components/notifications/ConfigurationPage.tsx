/** Compose dedicated channel and policy pages from the existing shared editors and lifecycle guards. */
import Alert from '../ui/Alert';
import InputField from '../ui/InputField';
import { usePage } from '@inertiajs/react';
import { useState } from 'react';
import { useNavigationGuard } from '../app/NavigationGuard';
import AppLayout from '../app/AppLayout';
import ChannelForm from './ChannelForm';
import ChannelRow from './ChannelRow';
import PolicyForm from './PolicyForm';
import PolicyRow from './PolicyRow';
import type { NotificationsPageProps } from './types';
import { channelPath, channelsPath, policiesPath, policyPath } from './urls';

/** One configuration workflow per page; domain editors and transport remain shared. */
interface ConfigurationPageProps {
    /** Chooses the independently routable configuration section. */
    scope: 'channels' | 'policies';
}

/**
 * Every member sees channels with masked destinations and the policies; owners and admins get the forms.
 * Domain refusals arrive on the `channel` and `policy` keys and are announced at the top of their section.
 */
function PageContent({ scope }: ConfigurationPageProps) {
    const { team, channels, policies, unnotifiedMonitors, channelTypes, limits, can, errors } =
        usePage<NotificationsPageProps>().props;

    const [search, setSearch] = useState('');
    const guard = useNavigationGuard();
    const term = search.trim().toLocaleLowerCase();
    const channelMatch = (name: string) => name.toLocaleLowerCase().includes(term);

    return (
        <>
            <InputField
                label={scope === 'channels' ? 'Find channels' : 'Find escalation policies'}
                hint="Filters names on this page. Hidden editors retain their drafts."
                wrapperClassName="ui-panel mb-6"
                id="notification-search"
                type="search"
                disabled={guard.busy}
                value={search}
                onChange={(event) => setSearch(event.target.value)}
            />
            {scope === 'channels' &&
                channels.length > 0 &&
                !channels.some((item) => channelMatch(item.name)) && (
                    <p role="status">No channels match this search.</p>
                )}
            {scope === 'policies' &&
                policies.length > 0 &&
                !policies.some((item) => channelMatch(item.name)) && (
                    <p role="status">No escalation policies match this search.</p>
                )}

            {unnotifiedMonitors > 0 && (
                <Alert title="Monitors without notifications" tone="warning">
                    {unnotifiedMonitors} monitor{unnotifiedMonitors === 1 ? '' : 's'} notify nobody:
                    mark a policy as the team default or choose one on each monitor.
                </Alert>
            )}

            {scope === 'channels' && (
                <section aria-labelledby="channel-configuration-heading">
                    <h2 id="channel-configuration-heading" className="sr-only">
                        Channel configuration
                    </h2>
                    <p className="mb-4 text-sm text-[var(--color-ui-muted)]">
                        First add a channel, verify its destination, then include it in a policy.
                        Assign that policy to a monitor or make it the team default. Credentials are
                        stored encrypted and never shown again. Verification sends a code, not a
                        test incident.
                    </p>
                    {errors.channel && (
                        <Alert title="Channel change refused" tone="danger" announce="assertive">
                            {errors.channel}
                        </Alert>
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
                                    type={channelTypes.find(
                                        (option) => option.value === channel.type,
                                    )}
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
            )}

            {scope === 'policies' && (
                <section aria-labelledby="policy-configuration-heading">
                    <h2 id="policy-configuration-heading" className="sr-only">
                        Policy configuration
                    </h2>
                    <p className="mb-4 text-sm text-[var(--color-ui-muted)]">
                        Each step waits a number of minutes after the incident is confirmed, then
                        notifies its active channels. A policy does not guarantee delivery: review
                        verification, activation, quiet hours and recorded outcomes.
                    </p>
                    {errors.policy && (
                        <Alert title="Policy change refused" tone="danger" announce="assertive">
                            {errors.policy}
                        </Alert>
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
            )}

            {!can.manage && (
                <p className="mt-8 text-sm text-[var(--color-ui-muted)]">
                    Only owners and admins can change channels and escalation policies.
                </p>
            )}
        </>
    );
}

/** Keep all page-owned forms and live sessions inside the removable authorized team boundary. */
export default function ConfigurationPage({ scope }: ConfigurationPageProps) {
    const { team } = usePage<NotificationsPageProps>().props;
    return (
        <AppLayout
            team={team}
            section="notifications"
            notificationSection={scope}
            title={scope === 'channels' ? 'Channels' : 'Escalation policies'}
            ancestors={[{ label: 'Notifications', href: `/teams/${team.id}/notifications` }]}
        >
            <PageContent key={scope} scope={scope} />
        </AppLayout>
    );
}
