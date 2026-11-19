/** Compose the dedicated policy page from the shared policy editors and lifecycle guards. */
import Alert from '../ui/Alert';
import InputField from '../ui/InputField';
import { usePage } from '@inertiajs/react';
import { useState } from 'react';
import { useNavigationGuard } from '../app/NavigationGuard';
import AppLayout from '../app/AppLayout';
import PolicyForm from './PolicyForm';
import PolicyRow from './PolicyRow';
import type { NotificationsPageProps } from './types';
import { policiesPath, policyPath } from './urls';

/**
 * Every member can inspect policies and their safe channel labels; owners and admins get the forms.
 * Domain refusals arrive on the policy key and are announced above the policy collection.
 */
function PageContent() {
    const { team, channels, policies, unnotifiedMonitors, limits, can, errors } =
        usePage<NotificationsPageProps>().props;

    const [search, setSearch] = useState('');
    const guard = useNavigationGuard();
    const term = search.trim().toLocaleLowerCase();
    const channelMatch = (name: string) => name.toLocaleLowerCase().includes(term);

    return (
        <>
            <InputField
                label="Find escalation policies"
                hint="Filters names on this page. Hidden editors retain their drafts."
                wrapperClassName="ui-panel mb-6"
                id="notification-search"
                type="search"
                disabled={guard.busy}
                value={search}
                onChange={(event) => setSearch(event.target.value)}
            />
            {policies.length > 0 && !policies.some((item) => channelMatch(item.name)) && (
                <p role="status">No escalation policies match this search.</p>
            )}

            {unnotifiedMonitors > 0 && (
                <Alert title="Monitors without notifications" tone="warning">
                    {unnotifiedMonitors} monitor{unnotifiedMonitors === 1 ? '' : 's'} notify nobody:
                    mark a policy as the team default or choose one on each monitor.
                </Alert>
            )}

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

            {!can.manage && (
                <p className="mt-8 text-sm text-[var(--color-ui-muted)]">
                    Only owners and admins can change channels and escalation policies.
                </p>
            )}
        </>
    );
}

/** Keep all page-owned forms and live sessions inside the removable authorized team boundary. */
export default function ConfigurationPage() {
    const { team } = usePage<NotificationsPageProps>().props;
    return (
        <AppLayout
            team={team}
            section="notifications"
            notificationSection="policies"
            title="Escalation policies"
            ancestors={[{ label: 'Notifications', href: `/teams/${team.id}/notifications` }]}
        >
            <PageContent />
        </AppLayout>
    );
}
