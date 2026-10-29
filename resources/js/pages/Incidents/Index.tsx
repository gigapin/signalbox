/** List one team's incidents, open first, with state and severity filters and explicit empty and error states. */
import { Link, router, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import AuthLayout from '../../components/auth/AuthLayout';
import IncidentTable from '../../components/incidents/IncidentTable';
import { incidentStateLabel, severityLabel } from '../../components/incidents/labels';
import type {
    IncidentFilters,
    IncidentOptions,
    IncidentPage,
    IncidentPageProps,
} from '../../components/incidents/types';
import { incidentListQuery, incidentListUrl, incidentsPath } from '../../components/incidents/urls';
import Pagination from '../../components/monitors/Pagination';
import { monitorsPath } from '../../components/monitors/urls';

/** List page response from IncidentPageController::index. */
interface IndexProps extends IncidentPageProps {
    /** Current page of rows. */
    incidents: IncidentPage;
    /** Filters the server accepted. */
    filters: IncidentFilters;
    /** Whether any filter narrowed the list, distinguishing "no incidents" from "no matches". */
    filtered: boolean;
    /** Query keys the server ignored because their values were invalid. */
    rejectedFilters: string[];
    /** Enum values offered by the filter selects. */
    options: IncidentOptions;
}

/** Field styling shared by the filter selects. */
const INPUT =
    'w-full rounded border border-slate-600 bg-slate-900 px-3 py-2 focus:outline-2 focus:outline-emerald-400';

/** Filter through GET navigation so results stay bookmarkable and keyboard-operable. */
export default function Index() {
    const { team, incidents, filters, filtered, rejectedFilters, options } =
        usePage<IndexProps>().props;
    const [draft, setDraft] = useState<IncidentFilters>(filters);
    const [loading, setLoading] = useState(false);

    /**
     * @param next Filters to apply from the first page.
     */
    function navigate(next: IncidentFilters) {
        router.get(incidentsPath(team.id), incidentListQuery(next), {
            preserveState: true,
            replace: true,
            onStart: () => setLoading(true),
            onFinish: () => setLoading(false),
        });
    }

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        navigate(draft);
    }

    function clear() {
        const empty: IncidentFilters = { state: null, severity: null };
        setDraft(empty);
        navigate(empty);
    }

    return (
        <AuthLayout title="Incidents" description={`Incidents of ${team.name}.`} wide>
            <nav aria-label="Team navigation" className="mb-6 flex gap-4 text-sm text-emerald-300">
                <Link href={monitorsPath(team.id)}>Monitors</Link>
                <Link href={`/teams/${team.id}`}>Team members</Link>
            </nav>

            <form
                aria-label="Filter incidents"
                onSubmit={submit}
                className="grid gap-4 rounded border border-slate-700 p-4 md:grid-cols-3"
            >
                <div>
                    <label htmlFor="filter-state" className="mb-2 block text-sm font-medium">
                        State
                    </label>
                    <select
                        id="filter-state"
                        value={draft.state ?? ''}
                        onChange={(event) =>
                            setDraft({
                                ...draft,
                                state: event.target.value === '' ? null : event.target.value,
                            })
                        }
                        className={INPUT}
                    >
                        <option value="">Any</option>
                        {options.states.map((value) => (
                            <option key={value} value={value}>
                                {incidentStateLabel(value)}
                            </option>
                        ))}
                    </select>
                </div>
                <div>
                    <label htmlFor="filter-severity" className="mb-2 block text-sm font-medium">
                        Severity
                    </label>
                    <select
                        id="filter-severity"
                        value={draft.severity ?? ''}
                        onChange={(event) =>
                            setDraft({
                                ...draft,
                                severity: event.target.value === '' ? null : event.target.value,
                            })
                        }
                        className={INPUT}
                    >
                        <option value="">Any</option>
                        {options.severities.map((value) => (
                            <option key={value} value={value}>
                                {severityLabel(value)}
                            </option>
                        ))}
                    </select>
                </div>
                <div className="flex items-end gap-3">
                    <button
                        disabled={loading}
                        className="rounded bg-emerald-400 px-4 py-2 font-semibold text-slate-950 disabled:opacity-50"
                    >
                        {loading ? 'Loading…' : 'Apply filters'}
                    </button>
                    {filtered && (
                        <button
                            type="button"
                            disabled={loading}
                            onClick={clear}
                            className="underline"
                        >
                            Clear filters
                        </button>
                    )}
                </div>
            </form>

            {rejectedFilters.length > 0 && (
                <p role="alert" className="mt-4 text-red-300">
                    Some filters were invalid and have been ignored: {rejectedFilters.join(', ')}.
                </p>
            )}

            <section aria-label="Incident results" aria-busy={loading} className="mt-6">
                <p aria-live="polite" className="mb-3 text-sm text-slate-400">
                    {incidents.total === 1 ? '1 incident' : `${incidents.total} incidents`}
                    {incidents.from !== null && incidents.to !== null
                        ? `, showing ${incidents.from}–${incidents.to}`
                        : ''}
                    .
                </p>

                {incidents.data.length === 0 && !filtered && incidents.currentPage === 1 && (
                    <p className="rounded border border-slate-700 p-6 text-slate-300">
                        No incidents yet. An incident opens when a monitor&apos;s failure is
                        confirmed.
                    </p>
                )}

                {incidents.data.length === 0 && (filtered || incidents.currentPage > 1) && (
                    <div className="rounded border border-slate-700 p-6 text-slate-300">
                        <p>No incidents match these filters on this page.</p>
                        <Link
                            href={incidentListUrl(team.id, filters)}
                            className="mt-2 inline-block underline"
                        >
                            Back to the first page
                        </Link>
                    </div>
                )}

                {incidents.data.length > 0 && (
                    <IncidentTable
                        teamId={team.id}
                        incidents={incidents.data}
                        caption={`Incidents of ${team.name}`}
                        showMonitor
                    />
                )}

                <Pagination
                    current={incidents.currentPage}
                    last={incidents.lastPage}
                    href={(page) => incidentListUrl(team.id, filters, page)}
                />
            </section>
        </AuthLayout>
    );
}
