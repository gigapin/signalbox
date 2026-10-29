/** Render keyboard-navigable page links for the monitor and incident lists. */
import { Link } from '@inertiajs/react';

/** Pagination inputs. */
interface PaginationProps {
    /** One-based current page. */
    current: number;
    /** Last available page. */
    last: number;
    /**
     * Build the URL of one page while preserving the active filters.
     * @param page One-based target page.
     * @returns Relative URL.
     */
    href: (page: number) => string;
}

/** Link styling shared by page links. */
const LINK = 'rounded border border-slate-700 px-3 py-1 focus:outline-2 focus:outline-emerald-400';

/** Show previous/next and numbered links; renders nothing when a single page exists. */
export default function Pagination({ current, last, href }: PaginationProps) {
    if (last <= 1) {
        return null;
    }

    const pages = Array.from({ length: last }, (_, index) => index + 1);

    return (
        <nav aria-label="Pagination" className="mt-6">
            <ul className="flex flex-wrap gap-2">
                {current > 1 && (
                    <li>
                        <Link href={href(current - 1)} className={LINK} preserveScroll>
                            Previous
                        </Link>
                    </li>
                )}
                {pages.map((page) => (
                    <li key={page}>
                        <Link
                            href={href(page)}
                            aria-label={`Page ${page}`}
                            aria-current={page === current ? 'page' : undefined}
                            className={
                                page === current ? `${LINK} bg-emerald-400 text-slate-950` : LINK
                            }
                        >
                            {page}
                        </Link>
                    </li>
                ))}
                {current < last && (
                    <li>
                        <Link href={href(current + 1)} className={LINK} preserveScroll>
                            Next
                        </Link>
                    </li>
                )}
            </ul>
        </nav>
    );
}
