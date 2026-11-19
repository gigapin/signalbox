/** Compose the dedicated policy page from the shared policy editors and lifecycle guards. */
import Alert from '../ui/Alert';
import NotificationCollection from './NotificationCollection';
import { usePage } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../app/AppLayout';
import PolicyForm from './PolicyForm';
import PolicyRow from './PolicyRow';
import type { NotificationsPageProps } from './types';
import { policiesPath, policyPath } from './urls';

/**
 * Every member can inspect policies and their safe channel labels; owners and admins get the forms.
 * Domain refusals arrive on the policy key and are announced above the policy collection.
 */
function PageContent({ actionsTarget }: { actionsTarget: HTMLElement | null }) {
    const { team, channels, policies, unnotifiedMonitors, limits, can, errors } =
        usePage<NotificationsPageProps>().props;

    return (
        <>
            {unnotifiedMonitors > 0 && (
                <Alert title="Monitors without notifications" tone="warning">
                    {unnotifiedMonitors} monitor{unnotifiedMonitors === 1 ? '' : 's'} notify nobody:
                    mark a policy as the team default or choose one on each monitor.
                </Alert>
            )}

            {errors.policy && (
                <Alert title="Policy change refused" tone="danger" announce="assertive">
                    {errors.policy}
                </Alert>
            )}
            <NotificationCollection
                items={policies}
                actionsTarget={actionsTarget}
                canManage={can.manage}
                limit={limits.policiesPerTeam}
                searchLabel="Find escalation policies"
                addLabel="Add policy"
                modalTitle="Add a policy"
                listLabel="Escalation policies"
                cardPrefix="policy"
                emptyText="No escalation policies yet."
                noMatchText="No escalation policies match this search."
                renderItem={(policy, hidden) => (
                    <PolicyRow
                        key={policy.id}
                        path={policyPath(team.id, policy.id)}
                        policy={policy}
                        hidden={hidden}
                        channels={channels}
                        limits={limits}
                        canManage={can.manage}
                    />
                )}
                renderCreate={(onSaved) => (
                    <PolicyForm
                        idPrefix="policy-new"
                        action={policiesPath(team.id)}
                        method="post"
                        policy={null}
                        channels={channels}
                        limits={limits}
                        submitLabel="Add policy"
                        onSaved={onSaved}
                    />
                )}
            />
        </>
    );
}

/** Keep all page-owned forms and live sessions inside the removable authorized team boundary. */
export default function ConfigurationPage() {
    const { team } = usePage<NotificationsPageProps>().props;
    const [actionsTarget, setActionsTarget] = useState<HTMLDivElement | null>(null);
    return (
        <AppLayout
            team={team}
            section="notifications"
            notificationSection="policies"
            title="Escalation policies"
            actions={<div ref={setActionsTarget} className="contents" />}
            ancestors={[{ label: 'Notifications', href: `/teams/${team.id}/notifications` }]}
        >
            <PageContent actionsTarget={actionsTarget} />
        </AppLayout>
    );
}
