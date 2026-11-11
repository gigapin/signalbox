/** Browse the operator-only directory without creating tenant membership or changing the active team. */
import { Link, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { planLabel, sourceLabel } from '../../../components/admin/labels';
import type { AdminTeamPage } from '../../../components/admin/types';
import { directoryUrl, withDirectoryReturn } from '../../../components/admin/urls';
import type { AuthProps } from '../../../components/auth/AuthLayout';
import PrivateContextLayout from '../../../components/app/PrivateContextLayout';
import DataTable from '../../../components/ui/DataTable';
import Button from '../../../components/ui/Button';
import Alert from '../../../components/ui/Alert';
import { useReadNavigation } from '../../../hooks/useReadNavigation';

/** Deliberate directory response, including tolerated invalid control names. */
interface IndexProps extends AuthProps {
    /** Fixed twenty-five-row page with owner identity and aggregate counts. */
    teams: AdminTeamPage;
    /** Search accepted by the server. */
    filters: { q: string };
    /** Ignored control names; optional for old history entries. */
    rejectedFilters?: string[];
}

/** Keep reads inside the removable operator boundary; failures retain search and previous results. */
function PageContent() {
    const { props, url } = usePage<IndexProps>();
    const { teams, filters, rejectedFilters = [] } = props;
    const [q, setQ] = useState(filters.q);
    const [accepted, setAccepted] = useState({ q: filters.q, url });
    const read = useReadNavigation();
    if (accepted.q !== filters.q || accepted.url !== url) {
        setAccepted({ q: filters.q, url });
        setQ(filters.q);
    }
    const returnTo = directoryUrl(filters.q, teams.currentPage);

    /** Apply a manual GET once from page one; a network recovery never submits a write. */
    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        read.navigate('/admin/teams', q.trim() ? { q: q.trim() } : {}, { preserveState: true });
    }

    return (
        <>
            <p className="ui-description mb-4">
                Platform operators inspect teams independently of membership. Opening a team here
                does not select it as your working team.
            </p>
            <form
                role="search"
                aria-label="Search teams"
                onSubmit={submit}
                className="ui-panel ui-settings-fields"
            >
                <div>
                    <label htmlFor="team-search" className="ui-label">
                        Search
                    </label>
                    <input
                        id="team-search"
                        type="search"
                        maxLength={100}
                        value={q}
                        onChange={(event) => setQ(event.target.value)}
                        disabled={read.loading}
                        aria-describedby="team-search-hint"
                        className="ui-input"
                    />
                    <p id="team-search-hint" className="ui-help">
                        Matches team name, slug or owner email.
                    </p>
                </div>
                <div className="ui-actions">
                    <Button type="submit" busy={read.loading} busyLabel="Searching…">
                        Search
                    </Button>
                    {filters.q && (
                        <Link className="ui-link" href="/admin/teams">
                            Clear search
                        </Link>
                    )}
                </div>
            </form>
            {rejectedFilters.length > 0 && (
                <Alert title="Some controls were ignored" tone="warning" announce="assertive">
                    Invalid controls: {rejectedFilters.join(', ')}.
                </Alert>
            )}
            {read.failed && (
                <Alert title="Team search could not be loaded" tone="warning" announce="assertive">
                    Your search and previous results are preserved. Search again to retry.
                </Alert>
            )}
            <section aria-label="Team results" aria-busy={read.loading} className="mt-6">
                <p aria-live="polite" className="ui-help mb-3">
                    {teams.total === 1 ? '1 team' : `${teams.total} teams`}.
                </p>
                {teams.data.length === 0 ? (
                    <div className="ui-panel">
                        <p>
                            {teams.currentPage > 1
                                ? 'No teams on this page.'
                                : filters.q
                                  ? 'No team matches this search.'
                                  : 'No teams yet.'}
                        </p>
                        {(filters.q || teams.currentPage > 1) && (
                            <Link href="/admin/teams" className="ui-link">
                                Show all teams
                            </Link>
                        )}
                    </div>
                ) : (
                    <DataTable caption="Teams and their plans">
                        <thead>
                            <tr>
                                <th scope="col">Team</th>
                                <th scope="col">Owner</th>
                                <th scope="col">Stored plan</th>
                                <th scope="col">Effective plan</th>
                                <th scope="col">Monitors</th>
                            </tr>
                        </thead>
                        <tbody>
                            {teams.data.map((team) => (
                                <tr key={team.id}>
                                    <th scope="row">
                                        <Link
                                            className="ui-link"
                                            href={withDirectoryReturn(
                                                `/admin/teams/${team.id}`,
                                                returnTo,
                                            )}
                                        >
                                            {team.name}
                                        </Link>
                                        <span className="block ui-help">{team.slug}</span>
                                    </th>
                                    <td>{team.owner.email}</td>
                                    <td>{planLabel(team.storedPlan)}</td>
                                    <td>
                                        {planLabel(team.entitlement.plan)}
                                        <span className="block ui-help">
                                            {sourceLabel(team.entitlement.source)}
                                        </span>
                                    </td>
                                    <td>{team.monitors}</td>
                                </tr>
                            ))}
                        </tbody>
                    </DataTable>
                )}
                <nav aria-label="Pagination" className="ui-actions mt-4">
                    {teams.currentPage > 1 && (
                        <Link
                            className="ui-button"
                            data-variant="secondary"
                            href={directoryUrl(filters.q, teams.currentPage - 1)}
                        >
                            Previous
                        </Link>
                    )}
                    <span>
                        Page {teams.currentPage} of {teams.lastPage}
                    </span>
                    {teams.currentPage < teams.lastPage && (
                        <Link
                            className="ui-button"
                            data-variant="secondary"
                            href={directoryUrl(filters.q, teams.currentPage + 1)}
                        >
                            Next
                        </Link>
                    )}
                    {teams.currentPage > teams.lastPage && (
                        <Link className="ui-link" href={directoryUrl(filters.q)}>
                            Back to the first page
                        </Link>
                    )}
                </nav>
            </section>
        </>
    );
}

/** The directory has no route team, tenant selector or membership-derived operator authority. */
export default function Index() {
    return (
        <PrivateContextLayout
            context="platform"
            title="Platform admin"
            description="Every team on the platform, with the plan that currently applies."
        >
            <PageContent />
        </PrivateContextLayout>
    );
}
