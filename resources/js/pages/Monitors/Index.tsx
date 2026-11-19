/** List, search and filter one team's monitors with pagination and explicit empty and error states. */
import SelectField from '../../components/ui/SelectField';
import InputField from '../../components/ui/InputField';
import DataTable from '../../components/ui/DataTable';
import Alert from '../../components/ui/Alert';
import Button from '../../components/ui/Button';
import { useMonitorSearch } from '../../components/monitors/useMonitorSearch';
import { Link, usePage } from '@inertiajs/react';
import LiveUpdates from '../../components/LiveUpdates';
import { useLivePage } from '../../hooks/useLivePage';
import { useEffect, useState, type FormEvent } from 'react';
import AppLayout from '../../components/app/AppLayout';
import { StateBadge, StatusBadge } from '../../components/monitors/MonitorBadges';
import Pagination from '../../components/monitors/Pagination';
import {
    intervalLabel,
    regionLabel,
    stateLabel,
    statusLabel,
    typeLabel,
} from '../../components/monitors/labels';
import type {
    MonitorFilters,
    MonitorOptions,
    MonitorPage,
    MonitorPageProps,
    MonitorUsage,
} from '../../components/monitors/types';
import {
    listUrl,
    monitorPath,
    monitorsPath,
    withMonitorListReturn,
} from '../../components/monitors/urls';

/** List page response from MonitorPageController::index. */
interface IndexProps extends MonitorPageProps {
    /** Current page of rows. */
    monitors: MonitorPage;
    /** Filters the server accepted. */
    filters: MonitorFilters;
    /** Whether any filter narrowed the list, distinguishing "no monitors" from "no matches". */
    filtered: boolean;
    /** Query keys the server ignored because their values were invalid. */
    rejectedFilters: string[];
    /** Enum values offered by the filter selects. */
    options: MonitorOptions;
    /** Informational plan quota. */
    usage: MonitorUsage;
    /** Interface hints derived from MonitorPolicy. */
    can: { create: boolean };
}

/** Inputs for one "any or exact value" filter select. */
interface FilterSelectProps {
    /** Query key and element identity. */
    name: 'type' | 'status' | 'state';
    /** Visible label. */
    label: string;
    /** Selected value; empty string means any. */
    value: string;
    /** Offered server values. */
    values: string[];
    /**
     * @param value Raw value to display.
     * @returns Human label.
     */
    format: (value: string) => string;
    /**
     * @param value Newly selected value; empty string means any.
     */
    onChange: (value: string) => void;
}

/** Inputs for the creation control. */
interface CreateControlProps {
    /** Route team. */
    teamId: number;
    /** Informational plan quota. */
    usage: MonitorUsage;
    /** Whether MonitorPolicy grants creation to the actor. */
    canCreate: boolean;
    /** Canonical accepted list selection, used when cancelling creation. */
    returnTo: string;
}

/** Render a labelled select whose first option clears the filter. */
function FilterSelect({ name, label, value, values, format, onChange }: FilterSelectProps) {
    return (
        <SelectField
            label={label}
            id={`filter-${name}`}
            name={name}
            value={value}
            onChange={(event) => onChange(event.target.value)}
        >
            <option value="">Any</option>
            {values.map((option) => (
                <option key={option} value={option}>
                    {format(option)}
                </option>
            ))}
        </SelectField>
    );
}

/** Explain quota and offer creation only when the actor may create and the plan has room. */
function CreateControl({ teamId, usage, canCreate, returnTo }: CreateControlProps) {
    if (!canCreate) {
        return <p className="ui-help">Your role has read-only access to monitors.</p>;
    }

    if (usage.full) {
        return (
            <p className="ui-description">
                Your plan allows {usage.limit} monitors. Delete a monitor or upgrade to add another.
            </p>
        );
    }

    return (
        <Link
            href={withMonitorListReturn(`${monitorsPath(teamId)}/create`, teamId, returnTo)}
            className="ui-button"
        >
            New monitor
        </Link>
    );
}

/** Creation projection owned by a specific Inertia page and refreshed by its live session. */
interface CreationProjection {
    /** Initial response identity prevents stale actions during navigation or account changes. */
    source: IndexProps;
    /** Latest authorized creation inputs, including the canonical list return URL. */
    control: CreateControlProps;
}

/** Inputs for publishing live creation permissions into the page heading. */
interface PageContentProps {
    /** Publish only creation metadata; the live session remains inside the removable team boundary. */
    onCreationChange: (projection: CreationProjection) => void;
}

/** Search and filter through GET navigation so results stay bookmarkable and keyboard-operable. */
function PageContent({ onCreationChange }: PageContentProps) {
    const source = usePage<IndexProps>().props;
    const { team } = source;
    const live = useLivePage<IndexProps>(team.id, 'Monitors/Index');
    const { monitors, filters, filtered, rejectedFilters, options, usage, can } = live.props;
    const { draft, setDraft, loading, failed, navigate, clear } = useMonitorSearch(
        team.id,
        filters,
    );
    const returnTo = listUrl(team.id, filters, monitors.currentPage);

    useEffect(() => {
        onCreationChange({
            source,
            control: { teamId: team.id, usage, canCreate: can.create, returnTo },
        });
    }, [source, team.id, usage, can.create, returnTo, onCreationChange]);

    /** Submit only the current filter draft, beginning at the first page. */
    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        navigate(draft);
    }

    return (
        <>
            <LiveUpdates phase={live.phase} retry={live.retry} />
            <div className="ui-overview-toolbar mb-6">
                <p className="ui-description">
                    {usage.used} of {usage.limit} monitors used. Minimum interval{' '}
                    {intervalLabel(usage.minimumIntervalSeconds)}.
                </p>
            </div>

            <form role="search" aria-label="Search monitors" onSubmit={submit} className="ui-panel">
                <fieldset disabled={loading} className="ui-monitor-filters">
                    <legend className="ui-section-title mb-4">Find monitors</legend>
                    <InputField
                        label="Search"
                        hint="Matches name, TLS host or DNS name. HTTP URLs are stored encrypted and are not searchable."
                        wrapperClassName="ui-monitor-search"
                        id="filter-q"
                        name="q"
                        type="search"
                        maxLength={100}
                        value={draft.q}
                        onChange={(event) => setDraft({ ...draft, q: event.target.value })}
                    />
                    <FilterSelect
                        name="type"
                        label="Type"
                        value={draft.type ?? ''}
                        values={options.types}
                        format={typeLabel}
                        onChange={(value) =>
                            setDraft({ ...draft, type: value === '' ? null : value })
                        }
                    />
                    <FilterSelect
                        name="status"
                        label="Status"
                        value={draft.status ?? ''}
                        values={options.statuses}
                        format={statusLabel}
                        onChange={(value) =>
                            setDraft({ ...draft, status: value === '' ? null : value })
                        }
                    />
                    <FilterSelect
                        name="state"
                        label="State"
                        value={draft.state ?? ''}
                        values={options.states}
                        format={stateLabel}
                        onChange={(value) =>
                            setDraft({ ...draft, state: value === '' ? null : value })
                        }
                    />
                    <div className="ui-actions ui-monitor-filter-actions">
                        <Button type="submit" busy={loading} busyLabel="Searching…">
                            Apply filters
                        </Button>
                        {filtered && (
                            <Button variant="secondary" disabled={loading} onClick={clear}>
                                Clear filters
                            </Button>
                        )}
                    </div>
                </fieldset>
            </form>

            {failed && (
                <Alert tone="danger" title="Search could not be completed" announce="assertive">
                    <p>
                        Previous results are still shown. Your filter inputs have been kept. Try
                        Apply filters again.
                    </p>
                </Alert>
            )}
            {rejectedFilters.length > 0 && (
                <p role="alert" className="mt-4 text-ui-warning">
                    Some filters were invalid and have been ignored: {rejectedFilters.join(', ')}.
                </p>
            )}

            <section aria-label="Monitor results" aria-busy={loading} className="mt-6">
                {monitors.data.length === 0 &&
                    !filtered &&
                    monitors.currentPage === 1 &&
                    monitors.total === 0 && (
                        <p className="ui-panel">
                            No monitors yet.
                            {can.create && !usage.full
                                ? ' Create one to start tracking availability.'
                                : ''}
                        </p>
                    )}

                {monitors.data.length === 0 &&
                    (filtered || monitors.currentPage > 1 || monitors.total > 0) && (
                        <div className="ui-panel">
                            {monitors.total > 0 || monitors.currentPage > 1 ? (
                                <p>No monitors on this page.</p>
                            ) : (
                                <p>No monitors match these filters.</p>
                            )}
                            {monitors.total === 0 && filtered && (
                                <p className="ui-help">
                                    Adjust or clear the filters to search again.
                                </p>
                            )}
                            <Link
                                href={listUrl(team.id, filters)}
                                className="mt-2 inline-block ui-link"
                            >
                                Back to the first page
                            </Link>
                        </div>
                    )}

                {monitors.data.length > 0 && (
                    <DataTable caption="Monitors" compact>
                        <thead>
                            <tr className="border-b border-ui-control-border">
                                <th scope="col">Name</th>
                                <th scope="col">Type</th>
                                <th scope="col">Destination</th>
                                <th scope="col">Status</th>
                                <th scope="col">State</th>
                                <th scope="col">Interval</th>
                                <th scope="col">Regions</th>
                                <th scope="col">Last check (local time)</th>
                            </tr>
                        </thead>
                        <tbody>
                            {monitors.data.map((monitor) => (
                                <tr key={monitor.id} className="border-b border-ui-border">
                                    <th scope="row" className="font-medium">
                                        <Link
                                            href={withMonitorListReturn(
                                                monitorPath(team.id, monitor.id),
                                                team.id,
                                                returnTo,
                                            )}
                                            className="ui-link"
                                        >
                                            {monitor.name}
                                        </Link>
                                    </th>
                                    <td>{typeLabel(monitor.type)}</td>
                                    <td className="ui-monitor-destination">
                                        {monitor.destination ??
                                            (monitor.type === 'heartbeat'
                                                ? 'Receives heartbeat signals'
                                                : 'Destination unavailable')}
                                    </td>
                                    <td>
                                        <StatusBadge status={monitor.lastStatus} />
                                    </td>
                                    <td>
                                        <StateBadge state={monitor.state} />
                                    </td>
                                    <td>{intervalLabel(monitor.intervalSeconds)}</td>
                                    <td>{monitor.regions.map(regionLabel).join(', ')}</td>
                                    <td>
                                        {monitor.lastCheckedAt === null ? (
                                            'No check recorded'
                                        ) : (
                                            <time dateTime={monitor.lastCheckedAt}>
                                                {new Date(monitor.lastCheckedAt).toLocaleString()}
                                            </time>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </DataTable>
                )}

                <Pagination
                    current={monitors.currentPage}
                    last={monitors.lastPage}
                    href={(page) => listUrl(team.id, filters, page)}
                />
            </section>
        </>
    );
}

/** Keep all page-owned forms and live sessions inside the removable authorized team boundary. */
export default function Page() {
    const page = usePage<IndexProps>();
    const { team, filters, usage, can, monitors } = page.props;
    const [creation, setCreation] = useState<CreationProjection | null>(null);
    const control =
        creation?.source === page.props
            ? creation.control
            : {
                  teamId: team.id,
                  usage,
                  canCreate: can.create,
                  returnTo: listUrl(team.id, filters, monitors.currentPage),
              };
    return (
        <AppLayout
            team={team}
            section="monitors"
            title="Monitors"
            actions={<CreateControl {...control} />}
        >
            <PageContent
                key={`${page.url}:${JSON.stringify(filters)}`}
                onCreationChange={setCreation}
            />
        </AppLayout>
    );
}
