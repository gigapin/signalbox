/** Share keyboard-navigable pagination for accepted route URLs or caller-owned local collections. */
import Button from '../ui/Button';
import type { ReactNode } from 'react';
import { Link } from '@inertiajs/react';

/** Page bounds shared by route and local collections. */
interface PageBounds {
    /** One-based current page. */
    current: number;
    /** Last available page. */
    last: number;
}

/** Exactly one navigation strategy: real links or local buttons that retain mounted rows. */
type PaginationProps = PageBounds &
    (
        | {
              /** Build an accepted team-relative URL while preserving server filters. */
              href: (page: number) => string;
              /** Route pagination does not own local state. */
              onPageChange?: never;
              /** Route navigation retains its existing guard lifecycle. */
              disabled?: never;
          }
        | {
              /** Local collections do not synthesize navigation URLs. */
              href?: never;
              /** Reveal a local page without unmounting its rows. */
              onPageChange: (page: number) => void;
              /** Freeze local controls while a foreground request is pending. */
              disabled?: boolean;
          }
    );

/** Link styling shared by page links. */
const LINK = 'ui-button';

/** Show previous/next and numbered controls; render nothing when a single page exists. */
export default function Pagination({
    current,
    last,
    href,
    onPageChange,
    disabled,
}: PaginationProps) {
    if (last <= 1) return null;
    const pages = Array.from({ length: last }, (_, index) => index + 1);

    /** Render the caller's navigation strategy with the same visible and accessible page identity. */
    function control(
        page: number,
        label: string | undefined,
        content: ReactNode,
        preserveScroll = false,
    ) {
        const selected = page === current;
        if (href) {
            return (
                <Link
                    href={href(page)}
                    aria-label={label}
                    aria-current={selected ? 'page' : undefined}
                    className={LINK}
                    data-variant={selected ? 'primary' : 'secondary'}
                    preserveScroll={preserveScroll}
                >
                    {content}
                </Link>
            );
        }
        return (
            <Button
                disabled={disabled}
                onClick={() => onPageChange?.(page)}
                aria-label={label}
                aria-current={selected ? 'page' : undefined}
                variant={selected ? 'primary' : 'secondary'}
            >
                {content}
            </Button>
        );
    }

    return (
        <nav aria-label="Pagination" className="mt-6">
            <ul className="flex flex-wrap gap-2">
                {current > 1 && <li>{control(current - 1, undefined, 'Previous', true)}</li>}
                {pages.map((page) => (
                    <li key={page}>{control(page, `Page ${page}`, page)}</li>
                ))}
                {current < last && <li>{control(current + 1, undefined, 'Next', true)}</li>}
            </ul>
        </nav>
    );
}
