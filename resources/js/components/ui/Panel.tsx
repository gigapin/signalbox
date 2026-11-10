/** Group page content under explicit headings without introducing domain or loading state. */
import { useId, type ReactNode } from 'react';

/** A named section that composes into the page's existing heading hierarchy. */
interface PanelProps {
    /** Visible section heading and accessible region name. */
    title: string;
    /** Optional short context, including units or the requested window. */
    description?: string;
    /** Choose h3 only when the panel is nested under an h2 section. */
    headingLevel?: 2 | 3;
    /** Section content, including empty or unavailable explanations supplied by the caller. */
    children: ReactNode;
}

/**
 * @param props Named section, heading level and content.
 * @returns A section with a unique heading reference, allowing repeated panel titles.
 */
export default function Panel({ title, description, headingLevel = 2, children }: PanelProps) {
    const headingId = useId();
    const Heading = headingLevel === 2 ? 'h2' : 'h3';

    return (
        <section className="ui-panel" aria-labelledby={headingId}>
            <Heading id={headingId} className="ui-section-title">
                {title}
            </Heading>
            {description && <p className="ui-description">{description}</p>}
            <div className="ui-panel-content">{children}</div>
        </section>
    );
}

/** The single page heading with nearby contextual text and authorized actions. */
interface PageHeadingProps {
    /** Document's visible h1; the layout separately sets the document title. */
    title: string;
    /** Safe context such as the team name or redacted monitor destination. */
    description?: string;
    /** Caller-authorized action controls that wrap below the heading on narrow screens. */
    actions?: ReactNode;
}

/**
 * @param props Page identity, context and optional controls.
 * @returns A wrapping heading group; it does not decide permissions or navigation.
 */
export function PageHeading({ title, description, actions }: PageHeadingProps) {
    return (
        <header className="ui-page-heading">
            <div className="ui-heading-copy">
                <h1 className="ui-page-title">{title}</h1>
                {description && <p className="ui-description">{description}</p>}
            </div>
            {actions && <div className="ui-actions">{actions}</div>}
        </header>
    );
}
