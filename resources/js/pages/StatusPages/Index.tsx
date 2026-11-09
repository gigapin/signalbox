/** Manage the team's public status pages. */
import { Link, usePage } from '@inertiajs/react';
import AuthLayout from '../../components/auth/AuthLayout';
import StatusPageForm from '../../components/status-pages/StatusPageForm';
import StatusPageRow from '../../components/status-pages/StatusPageRow';
import type { StatusPagesPageProps } from '../../components/status-pages/types';

/** Every member sees the pages; owners and admins get the forms. Domain refusals arrive on the `page` key. */
export default function Index() {
    const { team, pages, monitors, themeChoices, limits, can, errors } =
        usePage<StatusPagesPageProps>().props;
    const collectionPath = `/teams/${team.id}/status-pages`;

    return (
        <AuthLayout title="Status pages" description={`Public pages of ${team.name}.`} wide>
            <nav
                aria-label="Team navigation"
                className="mb-6 flex flex-wrap gap-x-4 gap-y-2 text-sm text-emerald-300"
            >
                <Link href={`/teams/${team.id}/dashboard`}>Dashboard</Link>
                <Link href={`/teams/${team.id}`}>Team members</Link>
                <Link href={`/teams/${team.id}/monitors`}>Monitors</Link>
                <Link href={`/teams/${team.id}/incidents`}>Incidents</Link>
                <Link href={`/teams/${team.id}/notifications`}>Notifications</Link>
            </nav>

            <p className="mb-4 text-sm text-slate-300">
                {limits.used} of {limits.pages} status pages used, drafts included. Visitors see the
                names of the components, never the names of the monitors inside them.
            </p>
            {errors.page && (
                <p role="alert" className="mb-4 text-red-300">
                    {errors.page}
                </p>
            )}
            {pages.length === 0 ? (
                <p className="text-slate-300">No status pages yet.</p>
            ) : (
                <ul aria-label="Status pages">
                    {pages.map((page) => (
                        <StatusPageRow
                            key={page.id}
                            path={`${collectionPath}/${page.id}`}
                            page={page}
                            monitors={monitors}
                            themeChoices={themeChoices}
                            limits={limits}
                            canManage={can.manage}
                        />
                    ))}
                </ul>
            )}
            {can.manage && limits.used < limits.pages && (
                <div className="mt-4 rounded border border-slate-700 p-4">
                    <h2 className="mb-3 font-medium">Add a status page</h2>
                    <StatusPageForm
                        idPrefix="page-new"
                        action={collectionPath}
                        method="post"
                        page={null}
                        monitors={monitors}
                        themeChoices={themeChoices}
                        limits={limits}
                        submitLabel="Add status page"
                    />
                </div>
            )}
            {can.manage && limits.used >= limits.pages && (
                <p className="mt-4 text-sm text-slate-400">
                    The team has reached its plan limit of {limits.pages} status pages.
                </p>
            )}
            {!can.manage && (
                <p className="mt-8 text-sm text-slate-400">
                    Only owners and admins can change status pages.
                </p>
            )}
        </AuthLayout>
    );
}
