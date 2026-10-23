/** Platform operator directory of every team, searchable by team name, slug or owner email. */
import { Link, router, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { planLabel, sourceLabel } from '../../../components/admin/labels';
import type { AdminTeamPage } from '../../../components/admin/types';
import AuthLayout, { type AuthProps } from '../../../components/auth/AuthLayout';
import Pagination from '../../../components/monitors/Pagination';

/** Directory response from TeamDirectoryController::index. */
interface IndexProps extends AuthProps {
    /** Current page of teams. */
    teams: AdminTeamPage;
    /** Search text the server applied. */
    filters: { q: string };
}

/** Field styling shared with the other list pages. */
const INPUT =
    'w-full rounded border border-slate-600 bg-slate-900 px-3 py-2 focus:outline-2 focus:outline-emerald-400';

/**
 * @param q Search text.
 * @param page One-based page.
 * @returns Directory URL keeping only non-default parameters.
 */
function directoryUrl(q: string, page = 1): string {
    const params = new URLSearchParams();

    if (q !== '') {
        params.set('q', q);
    }

    if (page > 1) {
        params.set('page', String(page));
    }

    const query = params.toString();

    return query === '' ? '/admin/teams' : `/admin/teams?${query}`;
}

/** List teams with their stored and effective plans; searching is an explicit GET submission. */
export default function Index() {
    const { teams, filters } = usePage<IndexProps>().props;
    const [q, setQ] = useState(filters.q);
    const [loading, setLoading] = useState(false);

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        router.get('/admin/teams', q.trim() === '' ? {} : { q: q.trim() }, {
            preserveState: true,
            replace: true,
            onStart: () => setLoading(true),
            onFinish: () => setLoading(false),
        });
    }

    return (
        <AuthLayout
            title="Platform admin"
            description="Every team on the platform, with the plan that currently applies."
            wide
        >
            <form
                role="search"
                aria-label="Search teams"
                onSubmit={submit}
                className="flex flex-wrap items-end gap-4 rounded border border-slate-700 p-4"
            >
                <div className="min-w-64 flex-1">
                    <label htmlFor="team-search" className="mb-2 block text-sm font-medium">
                        Search
                    </label>
                    <input
                        id="team-search"
                        type="search"
                        maxLength={100}
                        value={q}
                        onChange={(event) => setQ(event.target.value)}
                        aria-describedby="team-search-hint"
                        className={INPUT}
                    />
                    <p id="team-search-hint" className="mt-1 text-xs text-slate-400">
                        Matches team name, slug or owner email.
                    </p>
                </div>
                <button
                    disabled={loading}
                    className="rounded bg-emerald-400 px-4 py-2 font-semibold text-slate-950 disabled:opacity-50"
                >
                    {loading ? 'Searching…' : 'Search'}
                </button>
            </form>

            <section aria-label="Team results" aria-busy={loading} className="mt-6">
                <p aria-live="polite" className="mb-3 text-sm text-slate-400">
                    {teams.total === 1 ? '1 team' : `${teams.total} teams`}.
                </p>

                {teams.data.length === 0 ? (
                    <p className="rounded border border-slate-700 p-6 text-slate-300">
                        {filters.q === '' ? 'No teams yet.' : 'No team matches this search.'}{' '}
                        {(filters.q !== '' || teams.currentPage > 1) && (
                            <Link href="/admin/teams" className="underline">
                                Show all teams
                            </Link>
                        )}
                    </p>
                ) : (
                    <table className="w-full text-left text-sm">
                        <caption className="sr-only">Teams and their plans</caption>
                        <thead className="text-slate-400">
                            <tr>
                                <th scope="col" className="py-2">
                                    Team
                                </th>
                                <th scope="col">Owner</th>
                                <th scope="col">Stored plan</th>
                                <th scope="col">Effective plan</th>
                                <th scope="col">Monitors</th>
                            </tr>
                        </thead>
                        <tbody>
                            {teams.data.map((team) => (
                                <tr key={team.id} className="border-t border-slate-800">
                                    <td className="py-2">
                                        <Link
                                            href={`/admin/teams/${team.id}`}
                                            className="text-emerald-300 underline"
                                        >
                                            {team.name}
                                        </Link>
                                        <span className="block text-xs text-slate-400">
                                            {team.slug}
                                        </span>
                                    </td>
                                    <td>{team.owner.email}</td>
                                    <td>{planLabel(team.storedPlan)}</td>
                                    <td>
                                        {planLabel(team.entitlement.plan)}
                                        <span className="block text-xs text-slate-400">
                                            {sourceLabel(team.entitlement.source)}
                                        </span>
                                    </td>
                                    <td>{team.monitors}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}

                <Pagination
                    current={teams.currentPage}
                    last={teams.lastPage}
                    href={(page) => directoryUrl(filters.q, page)}
                />
            </section>
        </AuthLayout>
    );
}
