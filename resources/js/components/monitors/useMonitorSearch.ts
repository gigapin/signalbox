/** Own filter inputs while the shared foreground GET lifecycle coordinates requests and access loss. */
import { useState } from 'react';
import { useReadNavigation } from '../../hooks/useReadNavigation';
import type { MonitorFilters } from './types';
import { listQuery, monitorsPath } from './urls';

/**
 * @param teamId Authorized route team.
 * @param filters Accepted filters for the mounted URL.
 * @returns Draft controls, safe request state and explicit first-page search/clear operations.
 */
export function useMonitorSearch(teamId: number, filters: MonitorFilters) {
    const [draft, setDraft] = useState(filters);
    const read = useReadNavigation();

    /** Apply accepted filter fields through an owned GET without replacing native history. */
    function navigate(next: MonitorFilters): void {
        read.navigate(monitorsPath(teamId), listQuery(next), { preserveState: true });
    }

    /** Clear all narrowing fields and explicitly search from the first page. */
    function clear(): void {
        const empty = { q: '', type: null, status: null, state: null };
        setDraft(empty);
        navigate(empty);
    }

    return { draft, setDraft, loading: read.loading, failed: read.failed, navigate, clear };
}
