/** Share bounded GET filters and explicit refresh across maintenance and delivery read views. */
import { Link, usePage } from '@inertiajs/react';
import { useState, type FormEvent, type ReactNode } from 'react';
import { useReadNavigation } from '../../hooks/useReadNavigation';
import Alert from '../ui/Alert';
import Button from '../ui/Button';
import { formatInstant } from '../incidents/time';
import type { MonitorPageProps } from '../monitors/types';

/** Canonical query controls; raw values are never used as redirect destinations. */
export interface OperationalFilters {
    /** Literal monitor-name search. */
    q: string;
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
    children,
}: ListProps) {
    const { url } = usePage();
    const [acceptedUrl, setAcceptedUrl] = useState(url);
    const [draft, setDraft] = useState(filters);
    const [accepted, setAccepted] = useState(filters);
    const read = useReadNavigation();
    if (acceptedUrl !== url || accepted.q !== filters.q || accepted.state !== filters.state) {
        setAcceptedUrl(url);
        setAccepted(filters);
        setDraft(filters);
    }

    /** Submit a new selection from page one through the shared owned GET lifecycle. */
    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        read.navigate(operationalUrl(path, draft), {}, { preserveState: true });
    }

    return (
        <>
            <form
                aria-label="Filter results"
                onSubmit={submit}
                className="ui-panel ui-incident-filters"
            >
                <div>
                    <label className="ui-label" htmlFor="operations-q">
                        Monitor name
                    </label>
                    <input
                        id="operations-q"
                        className="ui-input"
                        maxLength={100}
                        disabled={read.loading}
                        value={draft.q}
                        onChange={(event) => setDraft({ ...draft, q: event.target.value })}
                    />
                </div>
                <div>
                    <label className="ui-label" htmlFor="operations-state">
                        State
                    </label>
                    <select
                        id="operations-state"
                        className="ui-input"
                        disabled={read.loading}
                        value={draft.state ?? ''}
                        onChange={(event) =>
                            setDraft({ ...draft, state: event.target.value || null })
                        }
                    >
                        <option value="">Any</option>
                        {states.map((state) => (
                            <option key={state} value={state}>
                                {state.charAt(0).toUpperCase() + state.slice(1)}
                            </option>
                        ))}
                    </select>
                </div>
                <div className="ui-actions">
                    <Button type="submit" busy={read.loading} busyLabel="Loading…">
                        Apply filters
                    </Button>
                    {(filters.q || filters.state) && (
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
            <div className="ui-actions my-4">
                <p className="ui-help" aria-live="polite">
                    {listing.total} results. Updated {formatInstant(generatedAt, 'Unknown')}.
                    Refresh to check for changes.
                </p>
                <Button
                    variant="secondary"
                    disabled={read.loading}
                    onClick={() =>
                        read.navigate(
                            operationalUrl(path, filters, listing.currentPage),
                            {},
                            { preserveState: true, preserveScroll: true },
                        )
                    }
                >
                    Refresh results
                </Button>
            </div>
            <section aria-label="Results" aria-busy={read.loading}>
                {children}
            </section>
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
        </>
    );
}
