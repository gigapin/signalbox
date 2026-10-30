/** Present connection/recovery feedback without pretending that a stale page is receiving live updates. */
import type { LivePhase } from '../realtime/types';

/** User-facing descriptions contain no infrastructure details or raw transport errors. */
const LABELS: Record<LivePhase, string> = {
    off: 'Live updates are disabled.',
    connecting: 'Connecting to live updates…',
    syncing: 'Refreshing current data…',
    live: 'Live updates connected.',
    disconnected: 'Connection lost. Reconnecting…',
    error: 'Live updates could not refresh. Displayed data may be out of date.',
    denied: 'Live access is no longer available. Reload the page to check your access.',
};

/** Connection feedback and a deliberate retry action supplied by the scoped page hook. */
interface LiveUpdatesProps {
    /** Current transport or authoritative-read state. */
    phase: LivePhase;
    /** Reauthorize and recover after a transport/read failure. */
    retry: () => void;
}

/** Show an announced status and keyboard-accessible retry/reload when recovery needs user intervention. */
export default function LiveUpdates({ phase, retry }: LiveUpdatesProps) {
    return (
        <div className="mb-5 flex flex-wrap items-center gap-3 text-sm text-slate-400">
            <p role="status" aria-live="polite">
                {LABELS[phase]}
            </p>
            {(phase === 'error' || phase === 'disconnected') && (
                <button type="button" onClick={retry} className="text-emerald-300 underline">
                    Retry live updates
                </button>
            )}
            {(phase === 'denied' || phase === 'error') && (
                <button
                    type="button"
                    onClick={() => window.location.reload()}
                    className="text-emerald-300 underline"
                >
                    Reload page
                </button>
            )}
        </div>
    );
}
