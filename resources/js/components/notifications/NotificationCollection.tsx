/** Adapt notification names and quota explanations to the shared settings collection. */
import SettingsCollection from '../ui/SettingsCollection';
import type { ComponentProps } from 'react';

/** Notification collections use their bounded complete list for quota consumption. */
type NotificationCollectionProps<T extends { id: number; name: string }> = Omit<
    ComponentProps<typeof SettingsCollection<T>>,
    'itemLabel' | 'usedCount' | 'limitMessage' | 'readOnlyMessage'
>;

/** Preserve the notification callers while sharing search, modal and pagination behavior. */
export default function NotificationCollection<T extends { id: number; name: string }>(
    props: NotificationCollectionProps<T>,
) {
    return (
        <SettingsCollection
            {...props}
            itemLabel={(item) => item.name}
            usedCount={props.items.length}
            limitMessage={`The team has reached its limit of ${props.limit} ${props.cardPrefix === 'channel' ? 'channels' : 'policies'}.`}
            readOnlyMessage="Only owners and admins can change channels and escalation policies."
        />
    );
}
