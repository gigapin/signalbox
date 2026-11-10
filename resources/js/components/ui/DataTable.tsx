/** Retain native table semantics and keyboard access inside a locally scrolling viewport. */
import { useId, type ReactNode } from 'react';
import TableScrollArea from '../TableScrollArea';

/** Native table sections supplied by the caller; no row fetching or domain formatting is owned here. */
interface DataTableProps {
    /** Visible caption identifying the table and its scroll region. */
    caption: string;
    /** Native thead/tbody/tfoot children, with scoped column and row headers. */
    children: ReactNode;
}

/**
 * @param props Descriptive caption and semantic table sections.
 * @returns A labelled viewport, visible scroll guidance and native table.
 */
export default function DataTable({ caption, children }: DataTableProps) {
    const hintId = useId();

    return (
        <div className="ui-table-container">
            <p id={hintId} className="ui-help">
                Scroll horizontally to view all columns when needed.
            </p>
            <TableScrollArea label={caption} describedBy={hintId}>
                <table className="ui-table">
                    <caption>{caption}</caption>
                    {children}
                </table>
            </TableScrollArea>
        </div>
    );
}
