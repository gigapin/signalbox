/** List, search and filter one team's monitors with pagination and explicit empty and error states. */
import { Link, router, usePage } from '@inertiajs/react';
import LiveUpdates from '../../components/LiveUpdates';
import { useLivePage } from '../../hooks/useLivePage';
import { useState, type FormEvent } from 'react';
import AuthLayout from '../../components/auth/AuthLayout';
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
import { listQuery, listUrl, monitorPath, monitorsPath } from '../../components/monitors/urls';

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
}

/** Field styling shared by the filter inputs. */
const INPUT =
    'w-full rounded border border-slate-600 bg-slate-900 px-3 py-2 focus:outline-2 focus:outline-emerald-400';

/** Render a labelled select whose first option clears the filter. */
function FilterSelect({ name, label, value, values, format, onChange }: FilterSelectProps) {
    return (
        <div>
            <label htmlFor={`filter-${name}`} className="mb-2 block text-sm font-medium">
                {label}
            </label>
            <select
                id={`filter-${name}`}
                name={name}
                value={value}
                onChange={(event) => onChange(event.target.value)}
                className={INPUT}
            >
                <option value="">Any</option>
                {values.map((option) => (
                    <option key={option} value={option}>
                        {format(option)}
                    </option>
                ))}
            </select>
        </div>
    );
}

/** Explain quota and offer creation only when the actor may create and the plan has room. */
function CreateControl({ teamId, usage, canCreate }: CreateControlProps) {
    if (!canCreate) {
        return (
            <p className="text-sm text-slate-400">Your role has read-only access to monitors.</p>
        );
    }

    if (usage.full) {
        return (
            <p className="text-sm text-amber-200">
                Your plan allows {usage.limit} monitors. Delete a monitor or upgrade to add another.
            </p>
        );
    }

    return (
        <Link
            href={`${monitorsPath(teamId)}/create`}
            className="rounded bg-emerald-400 px-4 py-2 font-semibold text-slate-950 focus:outline-2 focus:outline-emerald-200"
        >
            New monitor
        </Link>
    );
}

/** Search and filter through GET navigation so results stay bookmarkable and keyboard-operable. */
export default function Index() {
    const { team } = usePage<IndexProps>().props;
    const live = useLivePage<IndexProps>(team.id, 'Monitors/Index');
    const { monitors, filters, filtered, rejectedFilters, options, usage, can } = live.props;
    const [draft, setDraft] = useState<MonitorFilters>(filters);
    const [loading, setLoading] = useState(false);

    /**
     * @param next Filters to apply from the first page.
     */
    function navigate(next: MonitorFilters) {
        router.get(monitorsPath(team.id), listQuery(next), {
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
        const empty: MonitorFilters = { q: '', type: null, status: null, state: null };
        setDraft(empty);
        navigate(empty);
    }

    return (
        <AuthLayout title="Monitors" description={`Monitors of ${team.name}.`} wide>
            <LiveUpdates phase={live.phase} retry={live.retry} />
            <nav aria-label="Team navigation" className="mb-6 flex gap-4 text-sm text-emerald-300">
                <Link href={`/teams/${team.id}/dashboard`}>Dashboard</Link>
                <Link href={`/teams/${team.id}`}>Team members</Link>
                <Link href={`/teams/${team.id}/incidents`}>Incidents</Link>
                <Link href={`/teams/${team.id}/notifications`}>Notifications</Link>
            </nav>

            <div className="mb-6 flex flex-wrap items-center justify-between gap-4">
                <p className="text-sm text-slate-300">
                    {usage.used} of {usage.limit} monitors used. Minimum interval{' '}
                    {intervalLabel(usage.minimumIntervalSeconds)}.
                </p>
                <CreateControl teamId={team.id} usage={usage} canCreate={can.create} />
            </div>

            <form
                role="search"
                aria-label="Search monitors"
                onSubmit={submit}
                className="grid gap-4 rounded border border-slate-700 p-4 md:grid-cols-5"
            >
                <div className="md:col-span-2">
                    <label htmlFor="filter-q" className="mb-2 block text-sm font-medium">
                        Search
                    </label>
                    <input
                        id="filter-q"
                        name="q"
                        type="search"
                        maxLength={100}
                        value={draft.q}
                        onChange={(event) => setDraft({ ...draft, q: event.target.value })}
                        aria-describedby="filter-q-hint"
                        className={INPUT}
                    />
                    <p id="filter-q-hint" className="mt-1 text-xs text-slate-400">
                        Matches name, TLS host or DNS name. HTTP URLs are stored encrypted and are
                        not searchable.
                    </p>
                </div>
                <FilterSelect
                    name="type"
                    label="Type"
                    value={draft.type ?? ''}
                    values={options.types}
                    format={typeLabel}
                    onChange={(value) => setDraft({ ...draft, type: value === '' ? null : value })}
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
                    onChange={(value) => setDraft({ ...draft, state: value === '' ? null : value })}
                />
                <div className="flex gap-3 md:col-span-5">
                    <button
                        disabled={loading}
                        className="rounded bg-emerald-400 px-4 py-2 font-semibold text-slate-950 disabled:opacity-50"
                    >
                        {loading ? 'Searching…' : 'Apply filters'}
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

            <section aria-label="Monitor results" aria-busy={loading} className="mt-6">
                <p aria-live="polite" className="mb-3 text-sm text-slate-400">
                    {monitors.total === 1 ? '1 monitor' : `${monitors.total} monitors`}
                    {monitors.from !== null && monitors.to !== null
                        ? `, showing ${monitors.from}–${monitors.to}`
                        : ''}
                    .
                </p>

                {monitors.data.length === 0 && !filtered && monitors.currentPage === 1 && (
                    <p className="rounded border border-slate-700 p-6 text-slate-300">
                        No monitors yet.
                        {can.create && !usage.full
                            ? ' Create one to start tracking availability.'
                            : ''}
                    </p>
                )}

                {monitors.data.length === 0 && (filtered || monitors.currentPage > 1) && (
                    <div className="rounded border border-slate-700 p-6 text-slate-300">
                        <p>No monitors match these filters on this page.</p>
                        <Link
                            href={listUrl(team.id, filters)}
                            className="mt-2 inline-block underline"
                        >
                            Back to the first page
                        </Link>
                    </div>
                )}

                {monitors.data.length > 0 && (
                    <table className="w-full text-left text-sm">
                        <caption className="sr-only">Monitors of {team.name}</caption>
                        <thead>
                            <tr className="border-b border-slate-700">
                                <th scope="col" className="py-2">
                                    Name
                                </th>
                                <th scope="col">Type</th>
                                <th scope="col">Status</th>
                                <th scope="col">State</th>
                                <th scope="col">Interval</th>
                                <th scope="col">Regions</th>
                                <th scope="col">Last check</th>
                            </tr>
                        </thead>
                        <tbody>
                            {monitors.data.map((monitor) => (
                                <tr key={monitor.id} className="border-b border-slate-800">
                                    <th scope="row" className="py-2 font-medium">
                                        <Link
                                            href={monitorPath(team.id, monitor.id)}
                                            className="text-emerald-300 underline"
                                        >
                                            {monitor.name}
                                        </Link>
                                    </th>
                                    <td>{typeLabel(monitor.type)}</td>
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
                                            'Never'
                                        ) : (
                                            <time dateTime={monitor.lastCheckedAt}>
                                                {new Date(monitor.lastCheckedAt).toLocaleString()}
                                            </time>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}

                <Pagination
                    current={monitors.currentPage}
                    last={monitors.lastPage}
                    href={(page) => listUrl(team.id, filters, page)}
                />
            </section>
        </AuthLayout>
    );
}
