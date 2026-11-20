/** Keep delivery status labels and semantic colors consistent in every notification history. */
import Badge, { type Tone } from '../ui/Badge';
import { deliveryStatusLabel } from './labels';

/** Unknown historical values remain readable with a neutral tone. */
const STATUS_TONES: Record<string, Tone> = {
    pending: 'warning',
    sent: 'success',
    failed: 'danger',
    suppressed: 'info',
};

/** Render the recorded outcome without implying recipient acknowledgement. */
export default function DeliveryStatusBadge({ status }: { status: string }) {
    return <Badge tone={STATUS_TONES[status] ?? 'neutral'}>{deliveryStatusLabel(status)}</Badge>;
}
