/** Share full-width repeated fields and inline add/remove controls without owning collection state. */
import type { ReactNode } from 'react';
import Button from './Button';

/** Caller-owned fields, accessible action names and permitted collection operations. */
interface RepeaterRowProps {
    /** One or more fields that share all space remaining beside the actions. */
    children?: ReactNode;
    /** Accessible name for the optional add action. */
    addLabel: string;
    /** Add a row; omitted on rows where creation is not offered. */
    onAdd?: (() => void) | undefined;
    /** Accessible name for the optional removal action. */
    removeLabel?: string | undefined;
    /** Remove this row; omitted when the collection minimum prohibits removal. */
    onRemove?: (() => void) | undefined;
}

/**
 * @param props Fields and caller-authorized collection operations.
 * @returns A fluid row with native, keyboard-operable icon buttons and decorative SVGs.
 */
export default function RepeaterRow({
    children,
    addLabel,
    onAdd,
    removeLabel,
    onRemove,
}: RepeaterRowProps) {
    return (
        <div className="ui-repeater-row">
            {children && <div className="ui-repeater-fields">{children}</div>}
            <div className="ui-repeater-actions">
                {onAdd && (
                    <Button
                        variant="secondary"
                        className="ui-icon-button"
                        aria-label={addLabel}
                        title={addLabel}
                        onClick={onAdd}
                    >
                        <svg
                            width="20"
                            height="20"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            strokeWidth="2"
                            aria-hidden="true"
                        >
                            <path d="M12 5v14M5 12h14" />
                        </svg>
                    </Button>
                )}
                {onRemove && (
                    <Button
                        variant="secondary"
                        className="ui-icon-button"
                        aria-label={removeLabel}
                        title={removeLabel}
                        onClick={onRemove}
                    >
                        <svg
                            width="20"
                            height="20"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            strokeWidth="2"
                            aria-hidden="true"
                        >
                            <path d="M3 6h18M9 6V4h6v2M5 6l1 14h12l1-14M10 10v6M14 10v6" />
                        </svg>
                    </Button>
                )}
            </div>
        </div>
    );
}
