/** Transfer primary ownership explicitly to an existing owner after recent-password confirmation. */
import { useFormProtection } from '../../components/app/NavigationGuard';
import { Link, useForm, usePage } from '@inertiajs/react';
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

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        if (
            !window.confirm(`Transfer primary ownership of ${team.name}? You will remain an owner.`)
        ) {
            return;
        }

        form.post(`/teams/${team.id}/ownership`);
    }

    return (
        <>
            {owners.length === 0 ? (
                <p>Promote another member to owner before transferring primary ownership.</p>
            ) : (
                <form onSubmit={submit} className="space-y-4">
                    <label>
                        New primary owner
                        <select
                            required
                            className="ml-2 bg-slate-800"
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
                    <button disabled={form.processing}>Transfer ownership</button>
                </form>
            )}
            {Object.entries(form.errors).map(([field, message]) => (
                <p key={field} role="alert">
                    {message}
                </p>
            ))}
            <Link className="mt-6 block text-emerald-300" href={`/teams/${team.id}`}>
                Back to team
            </Link>
        </>
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
