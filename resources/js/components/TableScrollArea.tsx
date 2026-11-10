/** Contain wide data tables within their page column while retaining keyboard access to every column. */
import type { ReactNode } from 'react';

/** Inputs for the named table viewport. */
interface TableScrollAreaProps {
    /** Accessible region name identifying the table whose columns can be scrolled. */
    label: string;
    /** Table and its caption; native table semantics remain intact. */
    children: ReactNode;
    /** Optional scroll guidance associated with this viewport. */
    describedBy?: string;
}

/**
 * Keep overflow local to the table and support native horizontal keyboard scrolling.
 * @param props Accessible label and native table content.
 * @returns A named, focusable viewport that retains its child's table semantics.
 */
export default function TableScrollArea({ label, children, describedBy }: TableScrollAreaProps) {
    return (
        <div
            role="region"
            aria-label={label}
            aria-describedby={describedBy}
            tabIndex={0}
            className="ui-table-viewport"
        >
            {children}
        </div>
    );
}
