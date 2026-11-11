/** Build operator directory/detail URLs from accepted search and server-normalized return context. */

/** Canonical directory URL; the server bounds q and page independently of these presentation helpers. */
export function directoryUrl(q: string, page = 1): string {
    const query = new URLSearchParams();
    if (q) query.set('q', q);
    if (page > 1) query.set('page', String(page));
    return `/admin/teams${query.size ? `?${query.toString()}` : ''}`;
}

/** Carry only an application-built or server-normalized list URL, never a raw browser return hint. */
export function withDirectoryReturn(path: string, list: string): string {
    return list === '/admin/teams'
        ? path
        : `${path}?${new URLSearchParams({ returnTo: list }).toString()}`;
}
