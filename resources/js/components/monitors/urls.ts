/** Build monitor page and mutation URLs in one place; the application uses no route helper. */
import type { MonitorFilters } from './types';

/**
 * @param teamId Route team.
 * @returns Base path of the team's monitor collection.
 */
export function monitorsPath(teamId: number): string {
    return `/teams/${teamId}/monitors`;
}

/**
 * @param teamId Route team.
 * @param monitorId Team-local monitor.
 * @returns Path of one monitor's detail page and update/delete endpoint.
 */
export function monitorPath(teamId: number, monitorId: number): string {
    return `${monitorsPath(teamId)}/${monitorId}`;
}

/**
 * Keep only filters that narrow the list, so URLs stay short and shareable.
 * @param filters Current filter values; empty text and null values are omitted.
 * @param page One-based page; the first page is implicit.
 * @returns Query parameters in a stable key order.
 */
export function listQuery(filters: MonitorFilters, page = 1): Record<string, string> {
    const query: Record<string, string> = {};

    if (filters.q.trim() !== '') {
        query.q = filters.q.trim();
    }

    for (const key of ['type', 'status', 'state'] as const) {
        const value = filters[key];

        if (value !== null && value !== '') {
            query[key] = value;
        }
    }

    if (page > 1) {
        query.page = String(page);
    }

    return query;
}

/**
 * @param teamId Route team.
 * @param filters Active filters.
 * @param page One-based page.
 * @returns Relative list URL carrying the filters and page.
 */
export function listUrl(teamId: number, filters: MonitorFilters, page = 1): string {
    const search = new URLSearchParams(listQuery(filters, page)).toString();

    return search === '' ? monitorsPath(teamId) : `${monitorsPath(teamId)}?${search}`;
}
