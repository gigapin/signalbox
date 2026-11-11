/** Transfer primary ownership explicitly to an existing owner after recent-password confirmation. */
import { useFormProtection, useNavigationGuard } from '../../components/app/NavigationGuard';
import { Link, useForm, usePage } from '@inertiajs/react';
import { useOwnedMutation } from '../../hooks/useOwnedMutation';
import MutationFeedback from '../../components/MutationFeedback';
import Panel from '../../components/ui/Panel';
import type { FormEvent } from 'react';
import type { AuthProps } from '../../components/auth/AuthLayout';
import AppLayout from '../../components/app/AppLayout';

/** Server-authorized ownership transfer response. */
interface OwnershipProps extends AuthProps {
    /** Team being transferred. */
    team: { id: number; name: string };
    /** Existing owners other than the current primary owner. */
    owners: { id: number; name: string }[];
}

/** Keep the previous primary owner as an owner; transfer does not remove membership. */
function PageContent() {
    const { team, owners } = usePage<OwnershipProps>().props;

    const form = useForm({ user_id: '' });
    useFormProtection(form);
    const mutation = useOwnedMutation();
    const guard = useNavigationGuard();
    const blocked = mutation.locked || guard.busy;

    /** Transfer once after confirmation; expired password confirmation follows the existing server flow. */
    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (blocked || !owners.some((owner) => String(owner.id) === form.data.user_id)) return;

        if (
            !window.confirm(`Transfer primary ownership of ${team.name}? You will remain an owner.`)
        ) {
            return;
        }

        const path = `/teams/${team.id}/ownership`;
        mutation.run(path, (options) => form.post(path, options));
    }

    return (
        <Panel
            title="Primary ownership"
            description="Choose an existing owner. You may need to confirm your password again. After confirming, review the current ownership before submitting another transfer."
        >
            {owners.length === 0 ? (
                <p>Promote another member to owner before transferring primary ownership.</p>
            ) : (
                <form onSubmit={submit}>
                    <fieldset disabled={blocked} className="grid min-w-0 gap-4">
                        <label>
                            New primary owner
                            <select
                                required
                                className="ui-input"
                                value={form.data.user_id}
                                onChange={(event) => form.setData('user_id', event.target.value)}
                            >
                                <option value="">Choose an owner</option>
                                {owners.map((owner) => (
                                    <option key={owner.id} value={owner.id}>
                                        {owner.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <button
                            className="ui-button"
                            disabled={
                                blocked ||
                                !owners.some((owner) => String(owner.id) === form.data.user_id)
                            }
                        >
                            Transfer ownership
                        </button>
                    </fieldset>
                </form>
            )}
            {Object.entries(form.errors).map(([field, message]) => (
                <p key={field} role="alert">
                    {message}
                </p>
            ))}
            <MutationFeedback
                phase={mutation.phase}
                reviewUrl={`/teams/${team.id}`}
                success="Ownership transfer accepted"
                onReviewed={mutation.acknowledge}
            />
            <Link className="mt-6 block text-emerald-300" href={`/teams/${team.id}`}>
                Back to team
            </Link>
        </Panel>
    );
}

/** Keep all page-owned forms and live sessions inside the removable authorized team boundary. */
export default function Page() {
    const { team } = usePage<OwnershipProps>().props;
    return (
        <AppLayout
            team={team}
            section="team"
            title="Transfer primary ownership"
            description="Choose another existing owner. You will retain your owner role."
        >
            <PageContent />
        </AppLayout>
    );
}
