/** Render the dedicated notification channels workflow using shared configuration components. */
import ConfigurationPage from '../../components/notifications/ConfigurationPage';

/** Keep channel and policy editors isolated to their own authorized page lifecycle. */
export default function Page() {
    return <ConfigurationPage scope="channels" />;
}
