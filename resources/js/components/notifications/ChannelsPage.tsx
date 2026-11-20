/** Compose channel cards, local search/pagination and a guarded creation modal in the authorized team shell. */
import { usePage } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../app/AppLayout';
import Alert from '../ui/Alert';
import NotificationCollection from './NotificationCollection';
import ChannelForm from './ChannelForm';
import ChannelRow from './ChannelRow';
import type { NotificationsPageProps } from './types';
import { channelPath, channelsPath } from './urls';

/** Destination for controls whose state belongs to the removable team content. */
interface WorkspaceProps {
    /** Null until the shell heading's action slot mounts. */
    actionsTarget: HTMLDivElement | null;
}

/** Keep domain forms and alerts within the removable authorized team boundary. */
function Workspace({ actionsTarget }: WorkspaceProps) {
    const { team, channels, channelTypes, limits, can, errors, unnotifiedMonitors } =
        usePage<NotificationsPageProps>().props;
    return (
        <>
            {unnotifiedMonitors > 0 && (
                <Alert title="Monitors without notifications" tone="warning">
                    {unnotifiedMonitors} monitor{unnotifiedMonitors === 1 ? '' : 's'} notify nobody:
                    mark a policy as the team default or choose one on each monitor.
                </Alert>
            )}
            {errors.channel && (
                <Alert title="Channel change refused" tone="danger" announce="assertive">
                    {errors.channel}
                </Alert>
            )}
            <NotificationCollection
                items={channels}
                actionsTarget={actionsTarget}
                canManage={can.manage}
                limit={limits.channelsPerTeam}
                searchLabel="Find channels"
                addLabel="Add channel"
                modalTitle="Add a channel"
                listLabel="Notification channels"
                cardPrefix="channel"
                emptyText="No channels yet."
                noMatchText="No channels match this search."
                renderItem={(channel, hidden) => (
                    <ChannelRow
                        key={channel.id}
                        path={channelPath(team.id, channel.id)}
                        channel={channel}
                        hidden={hidden}
                        type={channelTypes.find((option) => option.value === channel.type)}
                        canManage={can.manage}
                        nameMaxLength={limits.nameMaxLength}
                        codeLength={limits.codeLength}
                    />
                )}
                renderCreate={(onSaved) => (
                    <ChannelForm
                        idPrefix="channel-new"
                        action={channelsPath(team.id)}
                        method="post"
                        types={channelTypes}
                        name=""
                        editable={{}}
                        nameMaxLength={limits.nameMaxLength}
                        submitLabel="Add channel"
                        onSaved={onSaved}
                    />
                )}
            />
        </>
    );
}

/** Expose heading actions without moving forms or sensitive drafts outside the authorized boundary. */
export default function ChannelsPage() {
    const { team } = usePage<NotificationsPageProps>().props;
    const [actionsTarget, setActionsTarget] = useState<HTMLDivElement | null>(null);
    return (
        <AppLayout
            team={team}
            section="notifications"
            notificationSection="channels"
            title="Channels"
            ancestors={[{ label: 'Notifications', href: `/teams/${team.id}/notifications` }]}
            actions={<div ref={setActionsTarget} className="contents" />}
        >
            <Workspace actionsTarget={actionsTarget} />
        </AppLayout>
    );
}
