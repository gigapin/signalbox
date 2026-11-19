/** Share bounded GET filters and explicit refresh across maintenance and delivery read views. */
import { createPortal } from 'react-dom';
import InputField from '../ui/InputField';
import SelectField from '../ui/SelectField';
import { Link, usePage } from '@inertiajs/react';
import { useState, type FormEvent, type ReactNode } from 'react';
import { useReadNavigation } from '../../hooks/useReadNavigation';
import Alert from '../ui/Alert';
import Button from '../ui/Button';
import PageLoader from '../ui/PageLoader';
import { formatInstant } from '../incidents/time';
import type { MonitorPageProps } from '../monitors/types';

/** Canonical query controls; raw values are never used as redirect destinations. */
export interface OperationalFilters {
    /** Literal monitor-name search. */
    q: string;
    /** Exact monitor selection for lists offering a team monitor picker. */
    monitorId?: number | null;
    /** Accepted domain state, or no restriction. */
    state: string | null;
}

/** Shared safe response envelope for a fixed twenty-row page. */
export interface OperationalPageProps<T> extends MonitorPageProps {
    /** Current bounded collection and exact pagination metadata. */
    listing: { data: T[]; currentPage: number; lastPage: number; total: number };
    /** Normalized selection accepted by the server. */
    filters: OperationalFilters;
    /** Invalid field names discarded without reflecting their values. */
    rejectedFilters: string[];
    /** Supported domain states. */
    states: string[];
    /** UTC snapshot instant; these views refresh only on navigation or explicit request. */
    generatedAt: string;
}

/** Shared view inputs, independent of row shape and domain presentation. */
interface ListProps {
    /** Team-authorized monitor identities; omitted lists retain free-text search. */
    monitorOptions?: { id: number; name: string }[];
    /** Heading destination owned by the authorized shell; null waits for mounting. */
    refreshTarget?: HTMLElement | null;
    /** Hide redundant snapshot text and single-page pagination. */
    compact?: boolean;
    /** Application-built collection path. */
    path: string;
    /** Current accepted selection. */
    filters: OperationalFilters;
    /** Domain states offered by the server. */
    states: string[];
    /** Invalid control names only. */
    rejectedFilters: string[];
    /** Fixed-page metadata. */
    listing: { currentPage: number; lastPage: number; total: number };
    /** Snapshot UTC timestamp. */
    generatedAt: string;
    /** Domain table or empty explanation, supplied by the page. */
    children: ReactNode;
}

/** Build relative page links using accepted controls only. */
export function operationalUrl(path: string, filters: OperationalFilters, page = 1): string {
    const query = new URLSearchParams();
    if (filters.q) query.set('q', filters.q);
    if (filters.monitorId) query.set('monitor_id', String(filters.monitorId));
    if (filters.state) query.set('state', filters.state);
    if (page > 1) query.set('page', String(page));
    return `${path}${query.size ? `?${query.toString()}` : ''}`;
}

/** Explicit reads preserve previous rows and drafts on failure; no polling or mutation is introduced. */
export default function OperationalList({
    path,
    filters,
    states,
    rejectedFilters,
    listing,
    generatedAt,
    monitorOptions,
    refreshTarget,
    compact = false,
    children,
}: ListProps) {
    const { url } = usePage();
    const [acceptedUrl, setAcceptedUrl] = useState(url);
    const [draft, setDraft] = useState(filters);
    const [accepted, setAccepted] = useState(filters);
    const read = useReadNavigation();
    if (
        acceptedUrl !== url ||
        accepted.q !== filters.q ||
        accepted.state !== filters.state ||
        accepted.monitorId !== filters.monitorId
    ) {
        setAcceptedUrl(url);
        setAccepted(filters);
        setDraft(filters);
    }

    /** Submit a new selection from page one through the shared owned GET lifecycle. */
    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        read.navigate(operationalUrl(path, draft), {}, { preserveState: true });
    }

    let monitorSelection = '';
    if (draft.monitorId !== null && draft.monitorId !== undefined) {
        monitorSelection = String(draft.monitorId);
    } else if (draft.q !== '') {
        monitorSelection = `q:${draft.q}`;
    }

    const refresh = (
        <Button
            variant="secondary"
            aria-label="Refresh results"
            disabled={read.loading}
            icon={
                <svg
                    width="20"
                    height="20"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="2"
                    aria-hidden="true"
                >
                    <path d="M20 7v5h-5M4 17v-5h5M6.1 8a7 7 0 0 1 11.6-2L20 9M4 15l2.3 3A7 7 0 0 0 17.9 16" />
                </svg>
            }
            onClick={() =>
                read.navigate(
                    operationalUrl(path, filters, listing.currentPage),
                    {},
                    { preserveState: true, preserveScroll: true },
                )
            }
        >
            {refreshTarget === undefined ? 'Refresh results' : 'Refresh'}
        </Button>
    );

    return (
        <>
            <PageLoader active={read.loading} />
            <form
                aria-label="Filter results"
                onSubmit={submit}
                className="ui-panel ui-incident-filters"
            >
                {monitorOptions === undefined ? (
                    <InputField
                        label="Monitor name"
                        id="operations-q"
                        maxLength={100}
                        disabled={read.loading}
                        value={draft.q}
                        onChange={(event) => setDraft({ ...draft, q: event.target.value })}
                    />
                ) : (
                    <SelectField
                        label="Monitor name"
                        id="operations-monitor"
                        disabled={read.loading}
                        value={monitorSelection}
                        onChange={(event) =>
                            setDraft({
                                ...draft,
                                q: event.target.value.startsWith('q:') ? draft.q : '',
                                monitorId:
                                    event.target.value === '' || event.target.value.startsWith('q:')
                                        ? null
                                        : Number(event.target.value),
                            })
                        }
                    >
                        <option value="">All monitors</option>
                        {draft.q && !draft.monitorId && (
                            <option value={`q:${draft.q}`}>Search: {draft.q}</option>
                        )}
                        {draft.monitorId &&
                            !monitorOptions.some((monitor) => monitor.id === draft.monitorId) && (
                                <option value={draft.monitorId}>Unavailable monitor</option>
                            )}
                        {monitorOptions.map((monitor) => (
                            <option key={monitor.id} value={monitor.id}>
                                {monitor.name}
                            </option>
                        ))}
                    </SelectField>
                )}
                <SelectField
                    label="State"
                    id="operations-state"
                    disabled={read.loading}
                    value={draft.state ?? ''}
                    onChange={(event) => setDraft({ ...draft, state: event.target.value || null })}
                >
                    <option value="">Any</option>
                    {states.map((state) => (
                        <option key={state} value={state}>
                            {state.charAt(0).toUpperCase() + state.slice(1)}
                        </option>
                    ))}
                </SelectField>
                <div className="ui-actions">
                    <Button type="submit" disabled={read.loading}>
                        Apply filters
                    </Button>
                    {(filters.q || filters.state || filters.monitorId) && (
                        <Link className="ui-button" data-variant="secondary" href={path}>
                            Clear filters
                        </Link>
                    )}
                </div>
            </form>
            {rejectedFilters.length > 0 && (
                <Alert title="Some filters were ignored" tone="warning" announce="assertive">
                    Invalid controls: {rejectedFilters.join(', ')}.
                </Alert>
            )}
            {read.failed && (
                <Alert title="Results could not be loaded" tone="warning" announce="assertive">
                    Your selection and previous results are preserved. Apply filters again or
                    refresh to retry.
                </Alert>
            )}
            {(!compact || refreshTarget === undefined) && (
                <div className="ui-actions my-4">
                    {!compact && (
                        <p className="ui-help" aria-live="polite">
                            {listing.total} results. Updated {formatInstant(generatedAt, 'Unknown')}
                            . Refresh to check for changes.
                        </p>
                    )}
                    {refreshTarget === undefined && refresh}
                </div>
            )}
            {refreshTarget && createPortal(refresh, refreshTarget)}
            <section aria-label="Results" aria-busy={read.loading}>
                {children}
            </section>
            {(!compact || listing.lastPage > 1 || listing.currentPage > 1) && (
                <nav aria-label="Pagination" className="ui-actions mt-4">
                    {listing.currentPage > 1 && (
                        <Link
                            className="ui-button"
                            data-variant="secondary"
                            href={operationalUrl(path, filters, listing.currentPage - 1)}
                        >
                            Previous
                        </Link>
                    )}
                    <span>
                        Page {listing.currentPage} of {listing.lastPage}
                    </span>
                    {listing.currentPage < listing.lastPage && (
                        <Link
                            className="ui-button"
                            data-variant="secondary"
                            href={operationalUrl(path, filters, listing.currentPage + 1)}
                        >
                            Next
                        </Link>
                    )}
                    {listing.currentPage > listing.lastPage && (
                        <Link className="ui-link" href={operationalUrl(path, filters)}>
                            Back to the first page
                        </Link>
                    )}
                </nav>
            )}
        </>
    );
}
