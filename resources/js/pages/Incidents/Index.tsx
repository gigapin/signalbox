/** List one team's incidents, open first, with state and severity filters and explicit empty and error states. */
import { Link, usePage } from '@inertiajs/react';
import LiveUpdates from '../../components/LiveUpdates';
import Button from '../../components/ui/Button';
import Alert from '../../components/ui/Alert';
import { useReadNavigation } from '../../hooks/useReadNavigation';
import { useLivePage } from '../../hooks/useLivePage';
import { useState, type FormEvent } from 'react';
import AppLayout from '../../components/app/AppLayout';
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
const INPUT = 'ui-input';

/** Filter through GET navigation so results stay bookmarkable and keyboard-operable. */
function PageContent() {
    const page = usePage<IndexProps>();
    const { team } = page.props;
    const live = useLivePage<IndexProps>(team.id, 'Incidents/Index');
    const { incidents, filters, filtered, rejectedFilters, options } = live.props;
    const [draft, setDraft] = useState<IncidentFilters>(filters);
    const [selection, setSelection] = useState(filters);
    const [acceptedUrl, setAcceptedUrl] = useState(page.url);
    const { loading, failed, navigate: read } = useReadNavigation();
    if (
        acceptedUrl !== page.url ||
        selection.state !== filters.state ||
        selection.severity !== filters.severity
    ) {
        setAcceptedUrl(page.url);
        setSelection(filters);
        setDraft(filters);
    }

    /**
     * @param next Filters to apply from the first page.
     */
    function navigate(next: IncidentFilters) {
        read(incidentsPath(team.id), incidentListQuery(next), { preserveState: true });
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
        <>
            <LiveUpdates phase={live.phase} retry={live.retry} />
            <form
                aria-label="Filter incidents"
                onSubmit={submit}
                className="ui-panel ui-incident-filters"
            >
                <div>
                    <label htmlFor="filter-state" className="ui-label">
                        State
                    </label>
                    <select
                        id="filter-state"
                        disabled={loading}
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
                    <label htmlFor="filter-severity" className="ui-label">
                        Severity
                    </label>
                    <select
                        id="filter-severity"
                        disabled={loading}
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
                <div className="ui-actions">
                    <Button type="submit" busy={loading} busyLabel="Loading…">
                        Apply filters
                    </Button>
                    {filtered && (
                        <button
                            type="button"
                            disabled={loading}
                            onClick={clear}
                            className="ui-button"
                            data-variant="secondary"
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

            {failed && (
                <Alert
                    title="Incident filters could not be loaded"
                    tone="warning"
                    announce="assertive"
                >
                    Your selection and previous results are preserved. Apply filters again to retry.
                </Alert>
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
                        returnTo={incidentListUrl(team.id, filters, incidents.currentPage)}
                    />
                )}

                <Pagination
                    current={incidents.currentPage}
                    last={incidents.lastPage}
                    href={(page) => incidentListUrl(team.id, filters, page)}
                />
            </section>
        </>
    );
}

/** Keep all page-owned forms and live sessions inside the removable authorized team boundary. */
export default function Page() {
    const { team } = usePage<IndexProps>().props;
    return (
        <AppLayout
            team={team}
            section="incidents"
            title="Incidents"
            description={`Incidents of ${team.name}.`}
        >
            <PageContent />
        </AppLayout>
    );
}
