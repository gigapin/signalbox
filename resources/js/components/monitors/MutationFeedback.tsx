/** Explain confirmed refusal versus uncertain commit without clearing drafts or replaying writes. */
import type { MutationPhase } from '../../hooks/useMonitorMutation';
import Alert from '../ui/Alert';
import Button from '../ui/Button';

/** Outcome presentation has no request payload or transport diagnostics. */
interface FeedbackProps {
    /** Current owned mutation outcome. */
    phase: MutationPhase;
    /** Safe GET destination to inspect current saved state in a separate tab without losing the draft. */
    reviewUrl: string;
    /** Caller-selected acknowledgement text on confirmed acceptance. */
    success: string;
    /** Explicitly permit another attempt after checking saved data; never submits. */
    onReviewed: () => void;
}

/**
 * @param props Outcome, safe review destination and non-mutating acknowledgement.
 * @returns An announced notice; network recovery alone never claims success or enables replay.
 */
export default function MutationFeedback({ phase, reviewUrl, success, onReviewed }: FeedbackProps) {
    if (phase === 'idle') return null;
    if (phase === 'pending') return <p role="status">Waiting for the server to confirm…</p>;
    if (phase === 'success')
        return (
            <Alert title={success} tone="success" announce="polite">
                The server accepted the change.
            </Alert>
        );
    if (phase === 'refused')
        return (
            <Alert title="Change refused" tone="danger" announce="assertive">
                The server refused this request. Review the errors, permissions or plan limits
                before trying again. Your draft is preserved.
            </Alert>
        );

    return (
        <Alert title="Outcome not confirmed" tone="warning" announce="assertive">
            <p>
                The change may already have been saved. Your draft is preserved. Check the saved
                data before making another attempt; this request will not be retried automatically.
            </p>
            <div className="ui-actions">
                <a className="ui-link" href={reviewUrl} target="_blank" rel="noopener noreferrer">
                    Review saved data (new tab)
                </a>
                <Button variant="secondary" onClick={onReviewed}>
                    I checked the saved data
                </Button>
            </div>
        </Alert>
    );
}
