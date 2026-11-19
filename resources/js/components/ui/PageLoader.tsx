/** Announce foreground loading in a fixed viewport overlay without moving content or focus. */
import { createPortal } from 'react-dom';
import LoadingSpinner from './LoadingSpinner';

/** Caller-owned loading state and accessible request description. */
interface PageLoaderProps {
    /** Show only while the caller owns a pending request. */
    active: boolean;
    /** Short accessible loading announcement; never a raw transport error. */
    label?: string;
}

/**
 * @param props Pending request state and announcement.
 * @returns A viewport-centered status outside document flow; no scroll locking or focus changes.
 */
export default function PageLoader({ active, label = 'Loading results…' }: PageLoaderProps) {
    if (!active || typeof document === 'undefined') return null;

    return createPortal(
        <div className="ui-page-loader" role="status" aria-live="polite" aria-label={label}>
            <div className="ui-page-loader-indicator">
                <LoadingSpinner />
                <span className="sr-only">{label}</span>
            </div>
        </div>,
        document.body,
    );
}
