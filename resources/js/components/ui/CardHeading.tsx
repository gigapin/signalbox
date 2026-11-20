/** Align a card title with static status badges at the opposite edge. */
import type { ReactNode } from 'react';

/** Semantic title content and independent, noninteractive status labels. */
interface CardHeadingProps {
    /** Caller-owned heading preserves the card's existing accessible name and hierarchy. */
    children: ReactNode;
    /** Status labels wrap at the right edge when horizontal space is limited. */
    badges: ReactNode;
}

/** Preserve title-first reading order while keeping status separate from action controls. */
export default function CardHeading({ children, badges }: CardHeadingProps) {
    return (
        <div className="ui-card-heading">
            {children}
            <div className="ui-card-heading-badges">{badges}</div>
        </div>
    );
}
