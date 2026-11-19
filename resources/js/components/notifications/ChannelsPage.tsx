/** Compose channel cards, local search/pagination and a guarded creation modal in the authorized team shell. */
import { createPortal } from 'react-dom';
import { usePage } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../app/AppLayout';
import { useNavigationGuard } from '../app/NavigationGuard';
import Alert from '../ui/Alert';
import Button from '../ui/Button';
import Modal from '../ui/Modal';
import SearchPicker, { type SearchChoice } from '../ui/SearchPicker';
import Pagination from '../monitors/Pagination';
import ChannelForm from './ChannelForm';
import ChannelRow from './ChannelRow';
import type { NotificationsPageProps } from './types';
import { channelPath, channelsPath } from './urls';

/** Local page size; the server already bounds each team's channel collection. */
const PAGE_SIZE = 10;

/** Destination for controls whose state belongs to the removable team content. */
interface WorkspaceProps {
    /** Null until the shell heading's action slot mounts. */
    actionsTarget: HTMLDivElement | null;
}

/** Keep every row mounted so local filtering and pagination preserve verification/editor drafts. */
function Workspace({ actionsTarget }: WorkspaceProps) {
    const { team, channels, channelTypes, limits, can, errors, unnotifiedMonitors } =
        usePage<NotificationsPageProps>().props;
    const guard = useNavigationGuard();
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(1);
    const [creating, setCreating] = useState(false);
    const [creationVersion, setCreationVersion] = useState(0);
    const canCreate = can.manage && channels.length < limits.channelsPerTeam;
    const [previousCanCreate, setPreviousCanCreate] = useState(canCreate);
    if (previousCanCreate !== canCreate) {
        setPreviousCanCreate(canCreate);
        if (!canCreate) setCreating(false);
    }

    const term = search.trim().toLocaleLowerCase();
    const matching = channels.filter((channel) => channel.name.toLocaleLowerCase().includes(term));
    const last = Math.max(1, Math.ceil(matching.length / PAGE_SIZE));
    const current = Math.min(page, last);
    if (page !== current) setPage(current);
    const visible = new Set(
        matching.slice((current - 1) * PAGE_SIZE, current * PAGE_SIZE).map((channel) => channel.id),
    );

    /** Begin filtering from page one; no row or unsent form is disposed. */
    function searchFor(value: string): void {
        setSearch(value);
        setPage(1);
    }

    /** Reveal an existing card and move focus after React removes its hidden attribute. */
    function selectChannel(choice: SearchChoice): void {
        searchFor(choice.label);
        window.requestAnimationFrame(() => {
            const card = document.getElementById(`channel-${choice.id}`);
            if (!card || card.hidden) return;
            card.focus({ preventScroll: true });
            card.scrollIntoView({ block: 'nearest' });
        });
    }

    /** Closing preserves the creation draft and is unavailable during foreground work. */
    function closeCreation(): void {
        if (!guard.busy) setCreating(false);
    }

    /** Only confirmed acceptance closes and resets the creation editor. */
    function saved(): void {
        setCreating(false);
        setCreationVersion((value) => value + 1);
    }

    const controls = (
        <div className="ui-channel-toolbar">
            <SearchPicker
                label="Find channels"
                query={search}
                choices={matching.map((channel) => ({ id: channel.id, label: channel.name }))}
                disabled={guard.busy}
                onQueryChange={searchFor}
                onSelect={selectChannel}
            />
            {can.manage && (
                <Button
                    disabled={guard.busy || !canCreate}
                    onClick={() => setCreating(true)}
                    aria-haspopup="dialog"
                >
                    Add channel
                </Button>
            )}
        </div>
    );

    return (
        <>
            {actionsTarget && createPortal(controls, actionsTarget)}
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
            <section aria-labelledby="channel-configuration-heading">
                <h2 id="channel-configuration-heading" className="sr-only">
                    Channel configuration
                </h2>
                {channels.length === 0 && <p>No channels yet.</p>}
                {channels.length > 0 && matching.length === 0 && (
                    <p role="status">No channels match this search.</p>
                )}
                <ul aria-label="Notification channels" className="ui-channel-list">
                    {channels.map((channel) => (
                        <ChannelRow
                            key={channel.id}
                            path={channelPath(team.id, channel.id)}
                            channel={channel}
                            hidden={!visible.has(channel.id)}
                            type={channelTypes.find((option) => option.value === channel.type)}
                            canManage={can.manage}
                            nameMaxLength={limits.nameMaxLength}
                            codeLength={limits.codeLength}
                        />
                    ))}
                </ul>
                <Pagination
                    current={current}
                    last={last}
                    disabled={guard.busy}
                    onPageChange={setPage}
                />
                {can.manage && !canCreate && (
                    <p className="ui-help">
                        The team has reached its limit of {limits.channelsPerTeam} channels.
                    </p>
                )}
                {!can.manage && (
                    <p className="ui-help">
                        Only owners and admins can change channels and escalation policies.
                    </p>
                )}
            </section>
            <Modal
                open={creating && canCreate}
                title="Add a channel"
                onRequestClose={closeCreation}
                closeDisabled={guard.busy}
            >
                {canCreate && (
                    <ChannelForm
                        key={creationVersion}
                        idPrefix="channel-new"
                        action={channelsPath(team.id)}
                        method="post"
                        types={channelTypes}
                        name=""
                        editable={{}}
                        nameMaxLength={limits.nameMaxLength}
                        submitLabel="Add channel"
                        onSaved={saved}
                    />
                )}
            </Modal>
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
