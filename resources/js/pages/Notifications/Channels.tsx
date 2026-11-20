/** Render the dedicated notification channels workflow using shared configuration components. */
import ChannelsPage from '../../components/notifications/ChannelsPage';

/** Keep channel and policy editors isolated to their own authorized page lifecycle. */
export default function Page() {
    return <ChannelsPage />;
}
